"""Collect official KBO Futures box scores into the shared season tables.

The official Futures pages expose player names but not player IDs.  IDs are
resolved against ``kbo_player_data`` and existing first-team season records.
Ambiguous or missing identities abort the write instead of guessing.

Examples:
  python kbo_futures_crawl.py --years 2026 --dry-run
  python kbo_futures_crawl.py --years 2022-2026 --write
  python kbo_futures_crawl.py --game-id 20260902OBLT0 --year 2026 --dry-run
"""

from __future__ import annotations

import argparse
import gzip
import hashlib
import html as html_lib
import json
import os
import re
import time
from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from zoneinfo import ZoneInfo
from pathlib import Path
from typing import Iterable

import pymysql
import requests


LEAGUE_LEVEL = 2
SERIES_IDS = (0, 6, 1, 3, 5, 7, 9)
SCHEDULE_URL = "https://www.koreabaseball.com/ws/Schedule.asmx/GetScheduleList"
BOX_URL = "https://www.koreabaseball.com/Futures/Schedule/BoxScore.aspx"
OVERRIDE_PATH = Path(__file__).resolve().with_name("kbo_futures_player_overrides.json")
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
    "Referer": "https://www.koreabaseball.com/Futures/Schedule/FuturesList.aspx",
}
TEAM_CODES = {
    "두산": "doo", "롯데": "lot", "삼성": "sam", "SSG": "ssg", "SK": "ssg",
    "LG": "lg", "KT": "kt", "NC": "nc", "KIA": "kia", "한화": "han",
    "키움": "kiw", "고양": "kiw", "울산": "ul",
}
POSITION_KIND = {
    "투수": "pitcher", "포수": "batter", "내야수": "batter", "외야수": "batter",
}
POSITION_GROUP = {
    "투수": "pitcher", "포수": "catcher", "내야수": "infielder", "외야수": "outfielder",
}
REGION_TEAMS = {
    "북부": {"SSG", "LG", "두산", "한화", "고양", "키움"},
    "남부": {"KIA", "롯데", "NC", "삼성", "KT", "상무", "울산"},
}


class SourceDataUnavailable(ValueError):
    """The official schedule has a result, but its box score has no data."""


@dataclass(frozen=True)
class Game:
    game_id: str
    game_date: str
    away_team: str
    home_team: str
    away_score: int
    home_score: int
    tv: str | None
    stadium: str


def db_config() -> dict:
    return {
        "host": os.environ["DB_HOST"],
        "port": int(os.getenv("DB_PORT", "3306")),
        "user": os.environ["DB_USER"],
        "password": os.environ["DB_PASSWORD"],
        "database": os.environ["DB_NAME"],
        "charset": "utf8mb4",
        "autocommit": False,
    }


def clean(fragment: str | None) -> str:
    fragment = re.sub(r"<br\s*/?>", " / ", fragment or "", flags=re.I)
    fragment = re.sub(r"<[^>]+>", " ", fragment)
    return re.sub(r"\s+", " ", html_lib.unescape(fragment)).strip()


def normalize_team(value: str) -> str:
    value = clean(value)
    suffixes = (" 베어스", " 라이온즈", " 자이언츠", " 트윈스", " 위즈", " 다이노스", " 타이거즈", " 이글스", " 히어로즈", " 랜더스")
    for suffix in suffixes:
        if value.endswith(suffix):
            value = value[: -len(suffix)]
            break
    return {"SK": "SSG"}.get(value, value)


def request_schedule(session: requests.Session, year: int, month: int) -> dict:
    response = session.post(
        SCHEDULE_URL,
        data={
            "leId": "2", "srIdList": "0,1,3,5,6,7,9", "seasonId": str(year),
            "gameMonth": f"{month:02d}", "teamId": "",
        },
        headers={**HEADERS, "Origin": "https://www.koreabaseball.com", "X-Requested-With": "XMLHttpRequest"},
        timeout=30,
    )
    response.raise_for_status()
    payload = response.json()
    if not isinstance(payload.get("rows"), list):
        raise ValueError(f"{year}-{month:02d}: invalid schedule response")
    return payload


def parse_schedule(payload: dict, year: int, month: int) -> list[Game]:
    games: list[Game] = []
    current_date: date | None = None
    for record in payload["rows"]:
        cells = record.get("row") or []
        day = next((cell for cell in cells if cell.get("Class") == "day"), None)
        if day:
            match = re.search(r"(\d{2})\.(\d{2})", clean(day.get("Text")))
            if not match:
                raise ValueError(f"{year}-{month:02d}: invalid day cell")
            current_date = date(year, int(match[1]), int(match[2]))
        play_index = next((i for i, cell in enumerate(cells) if cell.get("Class") == "play"), None)
        if play_index is None:
            continue
        if current_date is None or current_date.month != month:
            raise ValueError(f"{year}-{month:02d}: missing or unexpected date")
        play = cells[play_index].get("Text") or ""
        parts = [clean(item) for item in re.findall(r"<span\b[^>]*>(.*?)</span>", play, re.I | re.S)]
        if len(parts) != 5 or parts[2].lower() != "vs":
            # Unplayed and cancelled games have no final score and no usable box score.
            continue
        combined = " ".join(str(cell.get("Text") or "") for cell in cells)
        codes = set(re.findall(r"gameId=([A-Z0-9]+)", html_lib.unescape(combined), re.I))
        if len(codes) != 1:
            raise ValueError(f"{current_date}: completed game has {len(codes)} IDs")
        game_id = next(iter(codes)).upper()
        if not re.fullmatch(r"\d{8}[A-Z]{4}\d", game_id):
            raise ValueError(f"invalid Futures game ID: {game_id}")
        if game_id[:8] != current_date.strftime("%Y%m%d"):
            raise ValueError(f"{game_id}: date mismatch")
        tv_index, stadium_index = play_index + 3, play_index + 5
        games.append(Game(
            game_id=game_id,
            game_date=current_date.isoformat(),
            away_team=normalize_team(parts[0]),
            home_team=normalize_team(parts[4]),
            away_score=int(parts[1]),
            home_score=int(parts[3]),
            tv=clean(cells[tv_index].get("Text")) or None if tv_index < len(cells) else None,
            stadium=clean(cells[stadium_index].get("Text")) if stadium_index < len(cells) else "",
        ))
    return games


def discover_games(session: requests.Session, years: Iterable[int], cache_root: Path) -> list[Game]:
    all_games: list[Game] = []
    cache_root.mkdir(parents=True, exist_ok=True)
    for year in years:
        cache_path = cache_root / f"schedule-{year}.json"
        games: list[Game] = []
        for month in range(1, 13):
            payload = request_schedule(session, year, month)
            month_games = parse_schedule(payload, year, month)
            games.extend(month_games)
            print(f"{year}-{month:02d}: completed={len(month_games)}", flush=True)
            time.sleep(0.1)
        unique = {game.game_id: game for game in games}
        if len(unique) != len(games):
            duplicates = [game_id for game_id, count in Counter(game.game_id for game in games).items() if count > 1]
            raise ValueError(f"{year}: duplicate game IDs: {duplicates[:20]}")
        ordered = sorted(unique.values(), key=lambda game: game.game_id)
        cache_path.write_text(json.dumps([game.__dict__ for game in ordered], ensure_ascii=False, indent=2), encoding="utf-8")
        print(f"{year}: discovered={len(ordered)}", flush=True)
        all_games.extend(ordered)
    return all_games


def parse_tables(page: str) -> list[dict]:
    tables = []
    for table_match in re.finditer(r"<table\b([^>]*)>(.*?)</table>", page, re.I | re.S):
        attrs, body = table_match.groups()
        rows = []
        for row_match in re.finditer(r"<tr\b([^>]*)>(.*?)</tr>", body, re.I | re.S):
            row_attrs, row_body = row_match.groups()
            cells = []
            for cell_match in re.finditer(r"<t([hd])\b([^>]*)>(.*?)</t\1>", row_body, re.I | re.S):
                kind, cell_attrs, raw = cell_match.groups()
                value = clean(raw)
                cells.append({"kind": kind.lower(), "attrs": cell_attrs, "raw": raw, "text": value})
            if cells:
                rows.append({"attrs": row_attrs, "cells": cells})
        tables.append({"attrs": attrs, "body": body, "rows": rows})
    return tables


def table_id(table: dict) -> str | None:
    match = re.search(r"\bid=[\"']([^\"']+)", table["attrs"], re.I)
    return match.group(1) if match else None


def row_values(row: dict) -> list[str]:
    return [cell["text"] for cell in row["cells"]]


def result_cells(row: dict) -> list[list[str]]:
    results = []
    for cell in row["cells"]:
        values = []
        for fragment in re.split(r"<br\s*/?>", cell["raw"], flags=re.I):
            values.extend(clean(value) for value in fragment.split("/"))
        results.append([value for value in values if value])
    return results


def find_table(tables: list[dict], wanted_id: str) -> tuple[int, dict]:
    matches = [(index, table) for index, table in enumerate(tables) if table_id(table) == wanted_id]
    if len(matches) != 1:
        raise ValueError(f"expected one table {wanted_id}, got {len(matches)}")
    return matches[0]


def parse_note_rows(tables: list[dict]) -> dict[str, str]:
    for table in tables:
        rows = [row_values(row) for row in table["rows"]]
        notes = {row[0]: row[1] for row in rows if len(row) == 2}
        if "결승타" in notes:
            return notes
    raise ValueError("game note table is absent")


def parse_running(notes: dict[str, str], label: str) -> dict[tuple[str, int], int]:
    lookup: dict[tuple[str, int], int] = defaultdict(int)
    value = notes.get(label, "")
    for name, explicit, innings in re.findall(r"([가-힣A-Za-z.·]+?)(\d*)\s*\(([\d,\s]+)회\)", value):
        parsed = [int(item) for item in re.findall(r"\d+", innings)]
        count = int(explicit) if explicit else len(parsed) or 1
        if len(parsed) > 1 and count == len(parsed):
            for inning in parsed:
                lookup[(name, inning)] += 1
        elif parsed:
            lookup[(name, parsed[0])] += count
    return dict(lookup)


def resolve_run_out_players(lookup: dict[tuple[str, int], int], lineups: dict[str, list[dict]],
                            game_id: str) -> dict[tuple[int, int], int]:
    name_overrides = {
        # The official game note says 김동주, while the lineup and 7th-inning
        # walk identify the runner as 김동준.
        ("20220426HHOB0", "김동주", 7): "김동준",
    }
    batters = [batter for team in lineups.values() for batter in team]
    resolved: dict[tuple[int, int], int] = defaultdict(int)
    for (name, inning), count in lookup.items():
        resolved_name = name_overrides.get((game_id, name, inning), name)
        candidates = [batter for batter in batters if batter["name"] == resolved_name]
        if not candidates:
            candidates = [
                batter for batter in batters
                if resolved_name.startswith(batter["name"])
                or batter["name"].startswith(resolved_name)
            ]
        if len(candidates) > 1:
            reached = [
                batter for batter in candidates
                if inning <= len(batter["innings"])
                and any(_reached_base(result) for result in batter["innings"][inning - 1])
            ]
            if len(reached) == 1:
                candidates = reached
        if len(candidates) != 1:
            details = [(batter.get("player_id"), batter["name"], batter["order"])
                       for batter in candidates]
            raise ValueError(
                f"{game_id}: 주루사 선수 ID를 확정할 수 없습니다: "
                f"{name} {inning}회 후보={details}"
            )
        resolved[(int(candidates[0]["player_id"]), inning)] += count
    return dict(resolved)


def _reached_base(pa_result: str) -> bool:
    text = str(pa_result or "").replace(" ", "")
    return (
        "안" in text or "홈" in text or "4구" in text or "고4" in text
        or "사구" in text or "실" in text or "야선" in text
        or "스낫" in text or "낫아웃" in text
        or text.endswith("2") or text.endswith("3")
    )


def parse_lineup_group(tables: list[dict], side: str) -> list[dict]:
    prefix = "tblAwayHitter" if side == "away" else "tblHomeHitter"
    lineup_index, lineup_table = find_table(tables, prefix + "1")
    _, stat_table = find_table(tables, prefix + "3")
    if lineup_index + 1 >= len(tables):
        raise ValueError(f"{side}: result table is absent")
    result_table = tables[lineup_index + 1]
    lineup_rows = [row_values(row) for row in lineup_table["rows"]]
    lineup_rows = [row for row in lineup_rows if len(row) >= 3 and row[0].isdigit() and row[2]]
    result_rows = result_table["rows"][1:1 + len(lineup_rows)]
    stat_rows = [row_values(row) for row in stat_table["rows"]]
    stat_rows = [row for row in stat_rows if len(row) >= 5 and row[0].isdigit()][:len(lineup_rows)]
    if not (len(lineup_rows) == len(result_rows) == len(stat_rows)):
        raise ValueError(
            f"{side}: lineup/result/stat mismatch "
            f"{len(lineup_rows)}/{len(result_rows)}/{len(stat_rows)}"
        )
    batters = []
    starters: set[int] = set()
    for lineup_order, result_row, stat_row in zip(lineup_rows, result_rows, stat_rows):
        order = int(lineup_order[0])
        is_starter = order not in starters
        starters.add(order)
        innings = result_cells(result_row)
        if len(innings) < 9:
            innings.extend([[] for _ in range(9 - len(innings))])
        batters.append({
            "order": order, "pos": lineup_order[1], "name": lineup_order[2],
            "is_gs": int(is_starter), "innings": innings,
            "rbi": int(stat_row[2]), "r": int(stat_row[3]),
        })
    return batters


def ordered_events(
    batters: list[dict], game_id: str, team: str, *, allow_missing: bool = True,
) -> list[dict]:
    by_inning: dict[int, list[dict]] = defaultdict(list)
    for lineup_index, batter in enumerate(batters):
        for inning_index, cell_results in enumerate(batter["innings"], 1):
            for result_index, pa_result in enumerate(cell_results):
                by_inning[inning_index].append({
                    "batter": batter, "inning": inning_index, "pa_result": pa_result,
                    "lineup_index": lineup_index, "result_index": result_index,
                })
    ordered = []
    next_order = 1
    for inning in sorted(by_inning):
        remaining = list(by_inning[inning])
        guard = 0
        while remaining:
            guard += 1
            if guard > len(remaining) + 20:
                raise ValueError(f"{game_id} {team}: cannot order inning {inning}")
            candidates = [event for event in remaining if event["batter"]["order"] == next_order]
            if not candidates:
                if allow_missing:
                    ordered.append({"missing": True, "inning": inning, "order": next_order})
                    next_order = next_order % 9 + 1
                    continue
                # A handful of official Futures pages have internally
                # inconsistent batting-order labels.  When every visible PA
                # is nevertheless accounted for by pitcher BF, keep only the
                # recorded events and choose the next order in cyclic order.
                event = min(
                    remaining,
                    key=lambda item: (
                        (item["batter"]["order"] - next_order) % 9,
                        item["lineup_index"], item["result_index"],
                    ),
                )
                remaining.remove(event)
                ordered.append(event)
                next_order = event["batter"]["order"] % 9 + 1
                continue
            event = min(candidates, key=lambda item: (item["lineup_index"], item["result_index"]))
            remaining.remove(event)
            ordered.append(event)
            next_order = next_order % 9 + 1
    return ordered


def pa_match_score(detail: str, pa_result: str) -> int:
    detail = detail.replace(" ", "")
    pa_result = pa_result.replace(" ", "")
    score = 0
    for word, tokens in (
        ("희생플라이", ("희비",)), ("희생번트", ("희번",)), ("야수선택", ("야선",)),
        ("3루타", ("3",)), ("2루타", ("2",)), ("홈런", ("홈",)), ("안타", ("안",)),
        ("땅볼", ("땅", "야선")), ("병살", ("병",)), ("볼넷", ("4구", "고4")),
        ("4구", ("4구", "고4")), ("사구", ("사구",)), ("실책", ("실",)),
    ):
        if word in detail and any(token in pa_result for token in tokens):
            score += 100
            break
    for word, token in (
        ("좌중", "좌중"), ("우중", "우중"), ("좌전", "좌"), ("좌월", "좌"),
        ("우전", "우"), ("우월", "우"), ("중전", "중"), ("중월", "중"),
        ("유격수", "유"), ("2루수", "2"), ("1루수", "1"), ("3루수", "3"),
        ("투수", "투"), ("포수", "포"),
    ):
        if word in detail and pa_result.startswith(token):
            score += 10
            break
    return score


def winning_event(
    notes: dict[str, str], events: list[dict], game_id: str,
) -> tuple[str, int, int] | None:
    value = notes.get("결승타", "").strip()
    if not value or value in ("없음", "-"):
        return None
    match = re.fullmatch(r"(.+?)\s*\((\d+)회\s*(.*?)\)", value)
    if not match:
        raise ValueError(f"{game_id}: invalid winning hit: {value}")
    name, inning, detail = match.group(1).strip(), int(match.group(2)), match.group(3)
    candidates = []
    for sequence, event in enumerate(events):
        if event.get("missing") or event["inning"] != inning:
            continue
        batter = event["batter"]
        if batter["name"] != name:
            continue
        candidates.append((
            pa_match_score(detail, event["pa_result"]), sequence,
            batter["_key"], inning, event["result_index"],
        ))
    if not candidates:
        raise ValueError(f"{game_id}: winning PA not found: {value}")
    best = max(score for score, *_ in candidates)
    candidates = [candidate for candidate in candidates if candidate[0] == best]
    # If the same batter recorded the same result twice in one inning, the
    # slash-separated second result occurred only after the lineup wrapped.
    # The first matching ordered event is therefore the earlier candidate.
    _, _, lineup_key, inning, result_index = min(candidates, key=lambda item: item[1])
    return lineup_key, inning, result_index


def parse_pitchers(tables: list[dict], expected_teams: tuple[str, str]) -> dict[str, list[dict]]:
    pitcher_tables = []
    for table in tables:
        rows = [row_values(row) for row in table["rows"]]
        if rows and rows[0] and rows[0][0] == "선수명" and "투구수" in rows[0] and "자책" in rows[0]:
            pitcher_tables.append(rows)
    if len(pitcher_tables) != 2:
        raise ValueError(f"expected two pitcher tables, got {len(pitcher_tables)}")
    result = {}
    for team, rows in zip(expected_teams, pitcher_tables):
        pitchers = []
        for row in rows[1:]:
            if len(row) < 17 or row[0] == "TOTAL":
                continue
            pitchers.append({
                "name": row[0], "record": row[2] or None, "inning": row[6],
                "pitched": int(row[7]), "r": int(row[14]), "er": int(row[15]),
            })
        result[team] = pitchers
    return result


class PlayerResolver:
    def __init__(self, cursor, session: requests.Session, cache_root: Path, allow_unresolved: bool = False):
        self.session = session
        self.search_cache = cache_root / "player-search"
        self.search_cache.mkdir(parents=True, exist_ok=True)
        self.daily_cache = cache_root / "player-daily"
        self.daily_cache.mkdir(parents=True, exist_ok=True)
        self.daily_memory: dict[tuple[int, int, str], set[tuple[str, str]] | None] = {}
        cursor.execute(
            """SELECT player_id,name,oldname,fullname,pos,team,img
               FROM kbo_player_data
               WHERE player_id NOT BETWEEN 1000 AND 9999"""
        )
        columns = [item[0] for item in cursor.description]
        self.players = [dict(zip(columns, row)) for row in cursor.fetchall()]
        self.by_name: dict[str, list[dict]] = defaultdict(list)
        for player in self.players:
            names = {str(player.get(key) or "").strip() for key in ("name", "oldname", "fullname")}
            for name in names - {""}:
                self.by_name[name].append(player)
        cursor.execute(
            """SELECT player_name,player_id,team,YEAR(game_date) season
               FROM kbo_season_records
               WHERE league_level=1 AND player_id IS NOT NULL
                 AND player_id NOT BETWEEN 1000 AND 9999
               GROUP BY player_name,player_id,team,YEAR(game_date)"""
        )
        batter_history = cursor.fetchall()
        self.first_team: set[tuple[str, int, str, int]] = {
            (str(name), int(player_id), normalize_team(str(team)), int(season))
            for name, player_id, team, season in batter_history
        }
        cursor.execute(
            """SELECT player_id,team,YEAR(game_date) season
               FROM kbo_season_pitch_records
               WHERE league_level=1 AND player_id IS NOT NULL
                 AND player_id NOT BETWEEN 1000 AND 9999
               GROUP BY player_id,team,YEAR(game_date)"""
        )
        pitcher_history = cursor.fetchall()
        self.first_team_pitch: set[tuple[int, str, int]] = {
            (int(player_id), normalize_team(str(team)), int(season))
            for player_id, team, season in pitcher_history
        }
        self.player_history: dict[int, set[tuple[int, str]]] = defaultdict(set)
        for _, player_id, team, history_season in batter_history:
            self.player_history[int(player_id)].add((int(history_season), normalize_team(str(team))))
        for player_id, team, history_season in pitcher_history:
            self.player_history[int(player_id)].add((int(history_season), normalize_team(str(team))))
        self.cache: dict[
            tuple[str, str, str, int, str, str, str, int | None, str, int | None], int
        ] = {}
        self.allow_unresolved = allow_unresolved
        self.identity_issues: dict[tuple[str, str, str, int, str, str], str] = {}
        self.overrides = json.loads(OVERRIDE_PATH.read_text(encoding="utf-8")) if OVERRIDE_PATH.exists() else []

    def override(
        self, game_id: str, name: str, team: str, season: int, role: str,
        appearance_order: int | None = None, inning: str = "", pitched: int | None = None,
    ) -> int | None:
        matches = []
        for rule in self.overrides:
            if int(rule.get("season", season)) != season:
                continue
            if rule.get("team", team) != team or rule.get("name", name) != name:
                continue
            if rule.get("role", role) != role:
                continue
            prefix = str(rule.get("game_id_prefix", ""))
            if prefix and not game_id.startswith(prefix):
                continue
            prefixes = [str(value) for value in rule.get("game_id_prefixes", [])]
            if prefixes and not any(game_id.startswith(value) for value in prefixes):
                continue
            if "appearance_order" in rule and int(rule["appearance_order"]) != appearance_order:
                continue
            if "inning" in rule and str(rule["inning"]) != inning:
                continue
            if "pitched" in rule and int(rule["pitched"]) != pitched:
                continue
            specificity = sum(
                field in rule
                for field in (
                    "season", "team", "name", "role", "game_id_prefix", "game_id_prefixes",
                    "appearance_order", "inning", "pitched",
                )
            )
            matches.append((specificity, int(rule["player_id"]), rule))
        if not matches:
            return None
        best = max(item[0] for item in matches)
        best_ids = {player_id for specificity, player_id, _ in matches if specificity == best}
        if len(best_ids) != 1:
            raise ValueError(f"conflicting player overrides: {game_id} {team} {name} {role}")
        return next(iter(best_ids))

    def unresolved(self, key: tuple, message: str) -> int:
        if not self.allow_unresolved:
            raise LookupError(message)
        self.identity_issues[key] = message
        digest = hashlib.sha256("|".join(map(str, key)).encode("utf-8")).hexdigest()
        value = -(int(digest[:8], 16) % 2_000_000_000 + 1)
        self.cache[key] = value
        return value

    def official_candidates(self, name: str) -> list[dict]:
        digest = hashlib.sha256(name.encode("utf-8")).hexdigest()
        path = self.search_cache / f"{digest}.html.gz"
        if path.exists() and time.time() - path.stat().st_mtime < 86400:
            with gzip.open(path, "rt", encoding="utf-8") as handle:
                page = handle.read()
        else:
            response = self.session.get(
                "https://www.koreabaseball.com/Player/Search.aspx",
                params={"searchWord": name}, headers=HEADERS, timeout=30,
            )
            response.raise_for_status()
            response.encoding = "utf-8"
            page = response.text
            with gzip.open(path, "wt", encoding="utf-8") as handle:
                handle.write(page)
            time.sleep(0.1)
        candidates = []
        for row in re.findall(r"<tr\b[^>]*>(.*?)</tr>", page, re.I | re.S):
            id_match = re.search(r"playerId=(\d+)", row, re.I)
            if not id_match:
                continue
            player_id = int(id_match.group(1))
            if 1000 <= player_id <= 9999:
                continue
            cells = [clean(value) for value in re.findall(r"<td\b[^>]*>(.*?)</td>", row, re.I | re.S)]
            if len(cells) < 4:
                continue
            candidates.append({
                "player_id": player_id, "name": cells[1],
                "team": normalize_team(cells[2]), "pos": cells[3], "img": None,
                "official_search": True, "official_alias": cells[1] != name,
            })
        return candidates

    @staticmethod
    def tag_attributes(fragment: str) -> dict[str, str]:
        return {
            key.lower(): html_lib.unescape(value)
            for key, _, value in re.findall(
                r"([:\w-]+)\s*=\s*(['\"])(.*?)\2", fragment, re.S,
            )
        }

    def daily_appearances(
        self, player_id: int, season: int, role: str,
    ) -> set[tuple[str, str]] | None:
        key = (player_id, season, role)
        if key in self.daily_memory:
            return self.daily_memory[key]
        endpoint = "Hitter" if role == "batter" else "Pitcher"
        cache_path = self.daily_cache / f"{endpoint.lower()}-{player_id}-{season}.json"
        # Current-season identity evidence must include recently played games.
        current_season = datetime.now(ZoneInfo('Asia/Seoul')).year
        fresh = cache_path.exists() and (season < current_season or time.time() - cache_path.stat().st_mtime < 3600)
        if fresh:
            payload = json.loads(cache_path.read_text(encoding="utf-8"))
            appearances = {
                (str(item[0]), normalize_team(str(item[1])))
                for item in payload.get("appearances", [])
            }
            self.daily_memory[key] = appearances
            return appearances
        url = (
            f"https://www.koreabaseball.com/Futures/Player/{endpoint}Daily.aspx"
            f"?playerId={player_id}"
        )
        try:
            response = self.session.get(url, headers=HEADERS, timeout=30)
            response.raise_for_status()
            response.encoding = "utf-8"
            page = response.text
            heading = re.search(r"<h6>\s*(\d{4})\s+일자별 성적", page)
            if not heading or int(heading.group(1)) != season:
                select = next(
                    (
                        match for match in re.finditer(
                            r"<select\b([^>]*)>(.*?)</select>", page, re.I | re.S,
                        )
                        if "ddlYear" in (
                            self.tag_attributes(match.group(1)).get("id", "")
                            + self.tag_attributes(match.group(1)).get("name", "")
                        )
                    ),
                    None,
                )
                if select is None or not re.search(
                    rf"<option\b[^>]*value=['\"]{season}['\"]", select.group(2), re.I,
                ):
                    appearances: set[tuple[str, str]] = set()
                else:
                    form = {}
                    for input_match in re.finditer(r"<input\b([^>]*)>", page, re.I | re.S):
                        attributes = self.tag_attributes(input_match.group(1))
                        if attributes.get("type", "").lower() == "hidden" and attributes.get("name"):
                            form[attributes["name"]] = attributes.get("value", "")
                    select_name = self.tag_attributes(select.group(1))["name"]
                    form["__EVENTTARGET"] = select_name
                    form["__EVENTARGUMENT"] = ""
                    form[select_name] = str(season)
                    response = self.session.post(
                        url, data=form, headers={**HEADERS, "Referer": url}, timeout=30,
                    )
                    response.raise_for_status()
                    response.encoding = "utf-8"
                    page = response.text
                    heading = re.search(r"<h6>\s*(\d{4})\s+일자별 성적", page)
                    if not heading or int(heading.group(1)) != season:
                        raise ValueError(f"daily record year switch failed: {player_id} {season}")
                    appearances = self.parse_daily_appearances(page)
            else:
                appearances = self.parse_daily_appearances(page)
            cache_path.write_text(
                json.dumps(
                    {"player_id": player_id, "season": season, "role": role,
                     "appearances": sorted(appearances)},
                    ensure_ascii=False, indent=2,
                ),
                encoding="utf-8",
            )
            self.daily_memory[key] = appearances
            time.sleep(0.05)
            return appearances
        except (requests.RequestException, TypeError, ValueError, KeyError, json.JSONDecodeError):
            # Daily records are an identity discriminator. A temporary page
            # failure must not make an otherwise resolvable game unusable.
            self.daily_memory[key] = None
            return None

    @staticmethod
    def parse_daily_appearances(page: str) -> set[tuple[str, str]]:
        appearances = set()
        for table in parse_tables(page):
            rows = [row_values(row) for row in table["rows"]]
            if not rows or not rows[0] or not re.fullmatch(r"\d{1,2}월", rows[0][0]):
                continue
            for row in rows[1:]:
                if len(row) >= 2 and re.fullmatch(r"\d{2}\.\d{2}", row[0]):
                    appearances.add((row[0], normalize_team(row[1])))
        return appearances

    def daily_candidate_matches(
        self, candidates: list[dict], game_id: str, opponent: str, season: int, role: str,
    ) -> list[dict]:
        if len(candidates) < 2 or not opponent:
            return candidates
        game_day = f"{game_id[4:6]}.{game_id[6:8]}"
        expected = (game_day, normalize_team(opponent))
        matches = []
        for player in candidates:
            player_id = int(player["player_id"])
            appearances = self.daily_appearances(player_id, season, role)
            if appearances is not None and expected in appearances:
                matches.append(player)
        return matches or candidates

    def resolve(
        self, game_id: str, name: str, team: str, season: int, role: str,
        lineup_pos: str = "", opponent: str = "", appearance_order: int | None = None,
        inning: str = "", pitched: int | None = None,
    ) -> int:
        key = (
            game_id, name, team, season, role, lineup_pos, opponent,
            appearance_order, inning, pitched,
        )
        if key in self.cache:
            return self.cache[key]
        override = self.override(
            game_id, name, team, season, role,
            appearance_order=appearance_order, inning=inning, pitched=pitched,
        )
        if override is not None:
            self.cache[key] = override
            return override
        candidates = list(self.by_name.get(name, []))
        candidates = self.daily_candidate_matches(candidates, game_id, opponent, season, role)
        def lineup_group() -> str | None:
            if "투" in lineup_pos:
                return "pitcher"
            if "포" in lineup_pos:
                return "catcher"
            if any(value in lineup_pos for value in "一二三유"):
                return "infielder"
            if any(value in lineup_pos for value in "좌중우"):
                return "outfielder"
            return None

        expected_group = lineup_group()

        def position_score(player: dict) -> int:
            registered = POSITION_GROUP.get(str(player.get("official_pos") or player.get("pos") or ""))
            if not expected_group or not registered:
                return 0
            # Registered position is a positive discriminator, not a veto:
            # Futures players legitimately appear at secondary positions.
            return 160 if registered == expected_group else 0

        def history_score(player_id: int) -> int:
            history = self.player_history.get(player_id, set())
            if not history:
                return 0
            if team in REGION_TEAMS:
                distances = [abs(history_season - season) for history_season, history_team in history
                             if history_team in REGION_TEAMS[team]]
                return max(0, 120 - 20 * min(distances)) if distances else 0
            if team == "상무":
                distances = [abs(history_season - season) for history_season, _ in history]
                return max(0, 70 - 15 * min(distances)) if distances else 0
            distances = [abs(history_season - season) for history_season, history_team in history
                         if history_team == team]
            return max(0, 100 - 20 * min(distances)) if distances else 0

        def base_score(player: dict) -> int:
            score = 0
            player_id = int(player["player_id"])
            positions = str(player.get("pos") or "")
            kind = POSITION_KIND.get(positions)
            if kind == role:
                score += 20
            elif kind and kind != role:
                score -= 50
            score += position_score(player)
            if (name, player_id, team, season) in self.first_team:
                score += 200
            elif any(item[0] == name and item[1] == player_id and item[3] == season for item in self.first_team):
                score += 80
            if (player_id, team, season) in self.first_team_pitch:
                score += 200
            elif any(item[0] == player_id and item[2] == season for item in self.first_team_pitch):
                score += 80
            score += history_score(player_id)
            team_code = TEAM_CODES.get(team)
            current_code = str(player.get("team") or "").lower()
            image = str(player.get("img") or "").lower()
            if team_code and (current_code == team_code or f"_{team_code}_" in image):
                score += 40
            return score
        if candidates:
            preliminary = sorted(
                ((base_score(player), int(player["player_id"])) for player in candidates),
                reverse=True,
            )
            if ((len(preliminary) == 1 and preliminary[0][0] >= 0)
                    or (len(preliminary) > 1 and preliminary[0][0] > preliminary[1][0] and preliminary[0][0] > 0)):
                self.cache[key] = preliminary[0][1]
                return preliminary[0][1]
        official = self.official_candidates(name)
        official_by_id = {int(player["player_id"]): player for player in official}
        candidates = [
            ({
                **player,
                "official_search": True,
                "official_team": official_by_id[int(player["player_id"])]["team"],
                "official_pos": official_by_id[int(player["player_id"])]["pos"],
            } if int(player["player_id"]) in official_by_id else player)
            for player in candidates
        ]
        known_ids = {int(player["player_id"]) for player in candidates}
        candidates.extend(player for player in official if int(player["player_id"]) not in known_ids)
        candidates = self.daily_candidate_matches(candidates, game_id, opponent, season, role)
        if not candidates:
            return self.unresolved(key, f"no official player ID: {season} {team} {name} ({role})")
        scored = []
        for player in candidates:
            score = 0
            player_id = int(player["player_id"])
            positions = str(player.get("official_pos") or player.get("pos") or "")
            kind = POSITION_KIND.get(positions)
            if kind == role:
                score += 20
            elif kind and kind != role:
                score -= 50
            score += position_score(player)
            exact = (name, player_id, team, season) in self.first_team
            any_team = any(item[0] == name and item[1] == player_id and item[3] == season for item in self.first_team)
            if exact:
                score += 200
            elif any_team:
                score += 80
            if (player_id, team, season) in self.first_team_pitch:
                score += 200
            elif any(item[0] == player_id and item[2] == season for item in self.first_team_pitch):
                score += 80
            score += history_score(player_id)
            team_code = TEAM_CODES.get(team)
            current_code = str(player.get("team") or "").lower()
            image = str(player.get("img") or "").lower()
            if team_code and (current_code == team_code or f"_{team_code}_" in image):
                score += 40
            official_team = normalize_team(str(player.get("official_team") or player.get("team") or ""))
            if player.get("official_search") and official_team == team:
                score += 300
            scored.append((score, player_id, player))
        scored.sort(key=lambda item: (item[0], item[1]), reverse=True)
        if len(scored) == 1:
            self.cache[key] = scored[0][1]
            return scored[0][1]
        if len(scored) > 1 and scored[0][0] == scored[1][0]:
            ids = [(score, player_id, player.get("pos"), player.get("team"), player.get("img")) for score, player_id, player in scored]
            return self.unresolved(key, f"ambiguous player ID: {season} {team} {name} ({role}) => {ids}")
        if scored[0][0] < 0 or (len(scored) > 1 and scored[0][0] <= 0):
            ids = [(score, player_id, player.get("pos"), player.get("team"), player.get("img")) for score, player_id, player in scored]
            return self.unresolved(key, f"unsafe player ID: {season} {team} {name} ({role}) => {ids}")
        self.cache[key] = scored[0][1]
        return scored[0][1]


def parse_box(page: str, game: Game, series_id: int, resolver: PlayerResolver) -> dict:
    tables = parse_tables(page)
    _, scoreboard = find_table(tables, "tblScordboard2")
    score_rows = [row_values(row) for row in scoreboard["rows"]]
    if len(score_rows) != 2:
        raise ValueError(f"{game.game_id}: invalid inning scoreboard")
    combined_away = score_rows[0]
    home_raw = score_rows[1]
    # The official markup places the inning header row and away-score row in
    # one physical <tr>.  Derive the header width from the separate home row;
    # scanning 1,2,... is unsafe when the away club scored 10 in the first.
    header_count = len(combined_away) - len(home_raw)
    if not header_count:
        raise ValueError(f"{game.game_id}: inning header is absent")
    header = combined_away[:header_count]
    away_raw = combined_away[header_count:]
    if header != [str(index) for index in range(1, header_count + 1)]:
        raise ValueError(f"{game.game_id}: invalid inning header: {header}")
    if len(away_raw) != len(home_raw) or len(away_raw) < header_count:
        raise ValueError(
            f"{game.game_id}: inning cell mismatch header={header_count} "
            f"away={len(away_raw)} home={len(home_raw)}"
        )
    def inning_value(value: str) -> int | None:
        return None if value in ("", "-", "–") else int(value)
    away_innings = [inning_value(value) for value in away_raw]
    home_innings = [inning_value(value) for value in home_raw]
    if sum(value or 0 for value in away_innings) != game.away_score or sum(value or 0 for value in home_innings) != game.home_score:
        raise ValueError(
            f"{game.game_id}: inning totals {sum(value or 0 for value in away_innings)}-"
            f"{sum(value or 0 for value in home_innings)} "
            f"!= schedule {game.away_score}-{game.home_score}"
        )
    notes = parse_note_rows(tables)
    lineups = {
        game.away_team: parse_lineup_group(tables, "away"),
        game.home_team: parse_lineup_group(tables, "home"),
    }
    for team, batters in lineups.items():
        for index, batter in enumerate(batters):
            batter["_key"] = f"{team}:{index}"
    try:
        pitchers = parse_pitchers(tables, (game.away_team, game.home_team))
        pitcher_source_issue = next(
            (f"{team}: no pitchers" for team, rows in pitchers.items() if not rows), None,
        )
    except ValueError as error:
        pitchers = {game.away_team: [], game.home_team: []}
        pitcher_source_issue = str(error)

    team_events: dict[str, list[dict]] = {}
    for team in (game.away_team, game.home_team):
        events = ordered_events(lineups[team], game.game_id, team)
        opponent = game.home_team if team == game.away_team else game.away_team
        pitcher_slots = sum((pitcher["pitched"] for pitcher in pitchers[opponent]), 0)
        visible = sum(not event.get("missing") for event in events)
        if not pitcher_source_issue and len(events) != pitcher_slots:
            if visible == pitcher_slots:
                events = ordered_events(
                    lineups[team], game.game_id, team, allow_missing=False,
                )
            else:
                pitcher_source_issue = (
                    f"{game.game_id} {team}: ordered PA {len(events)} "
                    f"(visible {visible}) != opponent BF {pitcher_slots}"
                )
        team_events[team] = events

    if pitcher_source_issue:
        # Do not manufacture matchups from a partial or internally
        # inconsistent pitcher table.  Batter order/results remain usable.
        team_events = {
            team: ordered_events(lineups[team], game.game_id, team, allow_missing=False)
            for team in (game.away_team, game.home_team)
        }

    winning_team = (
        game.away_team if game.away_score > game.home_score
        else game.home_team if game.home_score > game.away_score
        else None
    )
    winning = winning_event(
        notes, team_events[winning_team] if winning_team else [], game.game_id,
    )
    sb_lookup = parse_running(notes, "도루")
    cs_lookup = parse_running(notes, "도루자")
    run_out_lookup = parse_running(notes, "주루사")

    for team, batters in lineups.items():
        opponent = game.home_team if team == game.away_team else game.away_team
        for batter in batters:
            batter["player_id"] = resolver.resolve(
                game.game_id, batter["name"], team, int(game.game_date[:4]), "batter", batter["pos"],
                opponent=opponent,
            )
    if not pitcher_source_issue:
        for team, team_pitchers in pitchers.items():
            opponent = game.home_team if team == game.away_team else game.away_team
            for appearance_order, pitcher in enumerate(team_pitchers, 1):
                pitcher["order"] = appearance_order
                pitcher["player_id"] = resolver.resolve(
                    game.game_id, pitcher["name"], team, int(game.game_date[:4]), "pitcher",
                    opponent=opponent,
                    appearance_order=appearance_order,
                    inning=pitcher["inning"],
                    pitched=pitcher["pitched"],
                )

    run_out_player_lookup = resolve_run_out_players(
        run_out_lookup, lineups, game.game_id,
    )

    batter_rows = []
    for team, batting_side in ((game.away_team, "away"), (game.home_team, "home")):
        batters = lineups[team]
        events = team_events[team]
        opponent = game.home_team if batting_side == "away" else game.away_team
        pitcher_slots = []
        if pitcher_source_issue:
            pitcher_slots = [None] * len(events)
        else:
            for pitcher in pitchers[opponent]:
                pitcher_slots.extend([pitcher] * pitcher["pitched"])
        player_rows: dict[int, list[dict]] = defaultdict(list)
        running_applied: set[tuple[str, int]] = set()
        run_out_applied: set[tuple[int, int]] = set()
        batting_index = 0
        for event, pitcher in zip(events, pitcher_slots):
            if event.get("missing"):
                continue
            batting_index += 1
            batter = event["batter"]
            running_key = (batter["name"], event["inning"])
            run_out_key = (int(batter["player_id"]), event["inning"])
            first_running = running_key not in running_applied
            first_run_out = run_out_key not in run_out_applied
            row = {
                "league_level": LEAGUE_LEVEL, "game_id": game.game_id, "game_date": game.game_date,
                "player_id": batter["player_id"], "player_name": batter["name"],
                "inning": event["inning"], "pa_result": event["pa_result"],
                "sb": sb_lookup.get(running_key, 0) if first_running else 0,
                "cs": cs_lookup.get(running_key, 0) if first_running else 0,
                "run_out": run_out_player_lookup.get(run_out_key, 0) if first_run_out else 0,
                "pitcher_id": pitcher["player_id"] if pitcher else None,
                "pitcher_name": pitcher["name"] if pitcher else None,
                "team": team, "pos": batter["pos"], "rbi": 0, "r": 0,
                "is_gwrbi": int(winning == (batter["_key"], event["inning"], event["result_index"])),
                "order": batter["order"], "is_gs": batter["is_gs"], "batting_index": batting_index,
            }
            batter_rows.append(row)
            player_rows[batter["player_id"]].append(row)
            running_applied.add(running_key)
            run_out_applied.add(run_out_key)
        # Pinch runners can be put out without recording a plate appearance.
        # Preserve those events on an inning-specific non-PA row.
        for batter in batters:
            for inning in range(1, 26):
                running_key = (batter["name"], inning)
                run_out_key = (int(batter["player_id"]), inning)
                run_out = run_out_player_lookup.get(run_out_key, 0)
                if not run_out or run_out_key in run_out_applied:
                    continue
                row = {
                    "league_level": LEAGUE_LEVEL, "game_id": game.game_id, "game_date": game.game_date,
                    "player_id": batter["player_id"], "player_name": batter["name"], "inning": inning,
                    "pa_result": None, "sb": 0, "cs": 0, "run_out": run_out,
                    "pitcher_id": None, "pitcher_name": None,
                    "team": team, "pos": batter["pos"], "rbi": 0, "r": 0,
                    "is_gwrbi": 0, "order": batter["order"], "is_gs": batter["is_gs"],
                    "batting_index": None,
                }
                batter_rows.append(row)
                player_rows[batter["player_id"]].append(row)
        for batter in batters:
            if player_rows[batter["player_id"]]:
                first = player_rows[batter["player_id"]][0]
                first["rbi"], first["r"] = batter["rbi"], batter["r"]
                continue
            row = {
                "league_level": LEAGUE_LEVEL, "game_id": game.game_id, "game_date": game.game_date,
                "player_id": batter["player_id"], "player_name": batter["name"], "inning": None,
                "pa_result": None, "sb": 0, "cs": 0, "run_out": 0,
                "pitcher_id": None, "pitcher_name": None,
                "team": team, "pos": batter["pos"], "rbi": batter["rbi"], "r": batter["r"],
                "is_gwrbi": 0, "order": batter["order"], "is_gs": batter["is_gs"], "batting_index": None,
            }
            batter_rows.append(row)
            player_rows[batter["player_id"]].append(row)

    pitcher_rows = []
    if not pitcher_source_issue:
        for team, team_pitchers in pitchers.items():
            for pitcher in team_pitchers:
                pitcher_rows.append({
                    "league_level": LEAGUE_LEVEL, "game_id": game.game_id, "game_date": game.game_date,
                    "team": team, "player_id": pitcher["player_id"], "player_name": pitcher["name"],
                    "inning": pitcher["inning"],
                    "record": pitcher["record"], "pitched": pitcher["pitched"], "order": pitcher["order"],
                    "er": pitcher["er"], "r": pitcher["r"],
                })
    if sum(row["is_gwrbi"] for row in batter_rows) not in (0, 1):
        raise ValueError(f"{game.game_id}: invalid winning-hit flag count")
    return {
        "game": game, "series_id": series_id, "batter_rows": batter_rows, "pitcher_rows": pitcher_rows,
        "away_innings": away_innings, "home_innings": home_innings,
        "source_issue": pitcher_source_issue,
    }


def valid_box_page(page: str) -> bool:
    try:
        tables = parse_tables(page)
        _, scoreboard = find_table(tables, "tblScordboard2")
        if len(scoreboard["rows"]) != 2:
            return False
        return bool(parse_lineup_group(tables, "away") and parse_lineup_group(tables, "home"))
    except (TypeError, ValueError):
        return False


def fetch_box(session: requests.Session, game: Game, cache_root: Path, refresh: bool = False) -> tuple[str, int]:
    year_dir = cache_root / game.game_date[:4]
    year_dir.mkdir(parents=True, exist_ok=True)
    for series_id in SERIES_IDS:
        path = year_dir / f"{game.game_id}-s{series_id}.html.gz"
        if path.exists() and not refresh:
            with gzip.open(path, "rt", encoding="utf-8") as handle:
                page = handle.read()
            if valid_box_page(page):
                return page, series_id
        response = session.get(
            BOX_URL,
            params={"leagueId": 2, "seriesId": series_id, "seasonId": game.game_date[:4], "gameId": game.game_id},
            headers=HEADERS,
            timeout=30,
        )
        response.raise_for_status()
        response.encoding = "utf-8"
        page = response.text
        if valid_box_page(page):
            with gzip.open(path, "wt", encoding="utf-8") as handle:
                handle.write(page)
            return page, series_id
        time.sleep(0.05)
    raise SourceDataUnavailable(
        f"{game.game_id}: official box score is empty for series IDs {SERIES_IDS}"
    )


def schedule_only(game: Game, reason: str) -> dict:
    return {
        "game": game, "series_id": None, "batter_rows": [], "pitcher_rows": [],
        "away_innings": None, "home_innings": None, "source_issue": reason,
    }


def validate_schema(cursor) -> None:
    for table in ("kbo_season_records", "kbo_season_pitch_records", "kbo_schedule"):
        cursor.execute(f"SHOW COLUMNS FROM `{table}`")
        columns = {row[0] for row in cursor.fetchall()}
        if "league_level" not in columns:
            raise RuntimeError(f"{table}.league_level is missing; run migration first")
        required = set(BATTER_COLUMNS) if table == 'kbo_season_records' else set(PITCHER_COLUMNS) if table == 'kbo_season_pitch_records' else {'is_allstar', 'away_inning_scores', 'home_inning_scores'}
        if required - columns:
            raise RuntimeError(f'{table} missing columns: {sorted(required - columns)}')


BATTER_COLUMNS = (
    "league_level", "game_id", "game_date", "player_id", "player_name", "inning", "pa_result", "sb", "cs", "run_out",
    "pitcher_id", "pitcher_name", "team", "pos", "rbi", "r", "is_gwrbi", "order", "is_gs", "batting_index",
)
PITCHER_COLUMNS = (
    "league_level", "game_id", "game_date", "team", "player_id", "inning", "record", "pitched", "order", "er", "r",
)


def write_games(connection, parsed_games: list[dict]) -> dict:
    duplicate_pitchers = []
    for parsed in parsed_games:
        seen: dict[int, dict] = {}
        for row in parsed["pitcher_rows"]:
            previous = seen.get(row["player_id"])
            if previous is not None:
                duplicate_pitchers.append({
                    "game_id": row["game_id"], "player_id": row["player_id"],
                    "first": (previous["team"], previous["player_name"], previous["order"], previous["inning"]),
                    "second": (row["team"], row["player_name"], row["order"], row["inning"]),
                })
            else:
                seen[row["player_id"]] = row
    if duplicate_pitchers:
        raise ValueError(f"duplicate pitcher identities: {duplicate_pitchers}")

    cursor = connection.cursor()
    try:
        validate_schema(cursor)
        from player_ingest import ensure_players
        ensure_players(connection, [r for g in parsed_games for r in g['batter_rows']],
                       [r for g in parsed_games for r in g['pitcher_rows']])
        batter_sql = (
            "INSERT INTO kbo_season_records (`" + "`,`".join(BATTER_COLUMNS) + "`) VALUES ("
            + ",".join(["%s"] * len(BATTER_COLUMNS)) + ")"
        )
        pitcher_sql = (
            "INSERT INTO kbo_season_pitch_records (`" + "`,`".join(PITCHER_COLUMNS) + "`) VALUES ("
            + ",".join(["%s"] * len(PITCHER_COLUMNS)) + ")"
        )
        schedule_sql = """INSERT INTO kbo_schedule
            (league_level,game_code,game_date,away_team,home_team,away_score,home_score,tv,stadium,away_inning_scores,home_inning_scores,is_allstar)
            VALUES (2,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE game_date=VALUES(game_date),away_team=VALUES(away_team),home_team=VALUES(home_team),
            away_score=VALUES(away_score),home_score=VALUES(home_score),tv=VALUES(tv),stadium=VALUES(stadium),
            away_inning_scores=VALUES(away_inning_scores),home_inning_scores=VALUES(home_inning_scores),
            is_allstar=VALUES(is_allstar)"""
        for parsed in parsed_games:
            game = parsed["game"]
            cursor.execute("DELETE FROM kbo_season_records WHERE league_level=2 AND game_id=%s", (game.game_id,))
            cursor.execute("DELETE FROM kbo_season_pitch_records WHERE league_level=2 AND game_id=%s", (game.game_id,))
            cursor.executemany(batter_sql, [tuple(row[column] for column in BATTER_COLUMNS) for row in parsed["batter_rows"]])
            cursor.executemany(pitcher_sql, [tuple(row[column] for column in PITCHER_COLUMNS) for row in parsed["pitcher_rows"]])
            cursor.execute(schedule_sql, (
                game.game_id, game.game_date, game.away_team, game.home_team, game.away_score, game.home_score,
                game.tv, game.stadium,
                json.dumps(parsed["away_innings"], ensure_ascii=False) if parsed["away_innings"] is not None else None,
                json.dumps(parsed["home_innings"], ensure_ascii=False) if parsed["home_innings"] is not None else None,
                int(game.away_team in REGION_TEAMS or game.home_team in REGION_TEAMS),
            ))
        result = audit(connection, parsed_games)
        connection.commit()
        return result
    except Exception:
        connection.rollback()
        raise
    finally:
        cursor.close()


def audit(connection, parsed_games: list[dict]) -> dict:
    game_ids = [item["game"].game_id for item in parsed_games]
    if not game_ids:
        return {}
    batter_game_ids = [item["game"].game_id for item in parsed_games if item["batter_rows"]]
    pitcher_game_ids = [item["game"].game_id for item in parsed_games if item["pitcher_rows"]]
    placeholders = ",".join(["%s"] * len(game_ids))
    with connection.cursor() as cursor:
        cursor.execute(
            f"""SELECT COUNT(*),COUNT(DISTINCT game_id),
                SUM(player_id IS NULL OR player_name IS NULL OR team IS NULL OR pos IS NULL OR `order` IS NULL OR is_gs IS NULL),
                SUM(pa_result IS NOT NULL AND batting_index IS NULL),
                SUM((pitcher_id IS NULL) <> (pitcher_name IS NULL)),
                SUM(rbi IS NULL OR r IS NULL OR is_gwrbi IS NULL OR run_out IS NULL)
                FROM kbo_season_records WHERE league_level=2 AND game_id IN ({placeholders})""",
            game_ids,
        )
        batter = cursor.fetchone()
        cursor.execute(
            f"""SELECT COUNT(*),COUNT(DISTINCT game_id),
                SUM(player_id IS NULL OR team IS NULL OR inning IS NULL OR pitched IS NULL OR `order` IS NULL OR er IS NULL OR r IS NULL)
                FROM kbo_season_pitch_records WHERE league_level=2 AND game_id IN ({placeholders})""",
            game_ids,
        )
        pitcher = cursor.fetchone()
        cursor.execute(
            f"SELECT COUNT(*) FROM kbo_schedule WHERE league_level=2 AND game_code IN ({placeholders})",
            game_ids,
        )
        schedule = cursor.fetchone()[0]
        missing_matchups = 0
        if pitcher_game_ids:
            pitcher_placeholders = ",".join(["%s"] * len(pitcher_game_ids))
            cursor.execute(
                f"""SELECT COUNT(*) FROM kbo_season_records
                    WHERE league_level=2 AND game_id IN ({pitcher_placeholders})
                      AND pa_result IS NOT NULL AND (pitcher_id IS NULL OR pitcher_name IS NULL)""",
                pitcher_game_ids,
            )
            missing_matchups = cursor.fetchone()[0]
    result = {
        "batters": {
            "rows": batter[0], "games": batter[1], "identity_bad": batter[2],
            "batting_index_bad": batter[3], "partial_matchup_bad": batter[4],
            "totals_bad": batter[5], "missing_matchups_with_pitchers": missing_matchups,
        },
        "pitchers": {"rows": pitcher[0], "games": pitcher[1], "bad": pitcher[2]},
        "schedule_games": schedule,
    }
    bad_values = (batter[2], batter[3], batter[4], batter[5], missing_matchups, pitcher[2])
    if any(value or 0 for value in bad_values) or not (
        batter[1] == len(batter_game_ids)
        and pitcher[1] == len(pitcher_game_ids)
        and schedule == len(game_ids)
    ):
        raise ValueError(f"post-write audit failed: {result}")
    return result


def parse_years(value: str) -> list[int]:
    if "-" in value:
        start, end = map(int, value.split("-", 1))
        return list(range(start, end + 1))
    return sorted({int(item) for item in value.split(",")})


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--years", default=str(datetime.now(ZoneInfo("Asia/Seoul")).year))
    parser.add_argument("--year", type=int)
    parser.add_argument("--game-id")
    parser.add_argument("--daily", action="store_true", help="Refresh last 7 KST days and missing current-season schedules")
    parser.add_argument("--write", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--refresh", action="store_true")
    parser.add_argument("--cache-root", type=Path, default=Path(__file__).resolve().parent / "futures-cache")
    parser.add_argument("--limit", type=int)
    parser.add_argument("--collect-identity-issues", action="store_true")
    parser.add_argument("--progress-every", type=int, default=1)
    args = parser.parse_args()
    if args.write == args.dry_run:
        parser.error("choose exactly one of --write or --dry-run")
    if args.collect_identity_issues and not args.dry_run:
        parser.error("--collect-identity-issues requires --dry-run")
    years = [args.year] if args.year else parse_years(args.years)
    session = requests.Session()
    games = discover_games(session, years, args.cache_root)
    if args.game_id:
        games = [game for game in games if game.game_id == args.game_id]
        if len(games) != 1:
            raise ValueError(f"game not found in schedule: {args.game_id}")
    if args.limit:
        games = games[:args.limit]
    if not games:
        print(json.dumps({"ok": True, "games": 0, "reason": "no completed Futures games selected"}), flush=True)
        return

    connection = pymysql.connect(**db_config())
    try:
        if args.daily:
            cutoff = (datetime.now(ZoneInfo('Asia/Seoul')).date() - timedelta(days=7)).isoformat()
            with connection.cursor() as cursor:
                cursor.execute('SELECT game_code FROM kbo_schedule WHERE league_level=2 AND YEAR(game_date) IN (' + ','.join(['%s'] * len(years)) + ')', years)
                stored = {row[0] for row in cursor.fetchall()}
            games = [game for game in games if game.game_date >= cutoff or game.game_id not in stored]
            print(f'Daily scope: {len(games)} games (since {cutoff} or absent schedule)', flush=True)
            if not games:
                print(json.dumps({'ok': True, 'games': 0, 'reason': 'current-season records are up to date'}), flush=True)
                return
        with connection.cursor() as cursor:
            validate_schema(cursor)
            resolver = PlayerResolver(cursor, session, args.cache_root, args.collect_identity_issues)
        parsed_games = []
        failures = []
        source_issues = []
        for index, game in enumerate(games, 1):
            try:
                today = datetime.now(ZoneInfo('Asia/Seoul')).date()
                refresh_recent = game.game_date >= (today - timedelta(days=7)).isoformat()
                page, series_id = fetch_box(session, game, args.cache_root, args.refresh or refresh_recent)
                parsed = parse_box(page, game, series_id, resolver)
                parsed_games.append(parsed)
                if parsed["source_issue"]:
                    source_issues.append({"game_id": game.game_id, "error": parsed["source_issue"]})
                if args.progress_every <= 1 or index == len(games) or index % args.progress_every == 0:
                    print(
                        f"[{index}/{len(games)}] {game.game_id} s{series_id}: "
                        f"batters={len(parsed['batter_rows'])} pitchers={len(parsed['pitcher_rows'])}",
                        flush=True,
                    )
            except SourceDataUnavailable as error:
                parsed = schedule_only(game, str(error))
                parsed_games.append(parsed)
                source_issues.append({"game_id": game.game_id, "error": str(error)})
                print(f"[{index}/{len(games)}] SCHEDULE_ONLY {game.game_id}: {error}", flush=True)
            except Exception as error:
                failures.append({"game_id": game.game_id, "error": str(error)})
                print(f"[{index}/{len(games)}] FAILED {game.game_id}: {error}", flush=True)
            time.sleep(0.1)
        report_path = args.cache_root / "last-failures.json"
        report_path.write_text(json.dumps(failures, ensure_ascii=False, indent=2), encoding="utf-8")
        identity_path = args.cache_root / "identity-issues.json"
        identity_rows = [
            {
                "game_id": key[0], "name": key[1], "team": key[2],
                "season": key[3], "role": key[4], "pos": key[5], "error": message,
            }
            for key, message in sorted(
                resolver.identity_issues.items(),
                key=lambda item: (item[0][3], item[0][0], item[0][2], item[0][1], item[0][4]),
            )
        ]
        identity_path.write_text(json.dumps(identity_rows, ensure_ascii=False, indent=2), encoding="utf-8")
        source_issue_path = args.cache_root / "source-issues.json"
        source_issue_path.write_text(
            json.dumps(source_issues, ensure_ascii=False, indent=2), encoding="utf-8",
        )
        if failures:
            raise RuntimeError(f"{len(failures)} games failed; database unchanged; see {report_path}")
        from player_ingest import ensure_players
        if args.dry_run and not args.collect_identity_issues:
            ensure_players(connection, [r for g in parsed_games for r in g['batter_rows']],
                           [r for g in parsed_games for r in g['pitcher_rows']], dry_run=True)
        if args.write:
            result = write_games(connection, parsed_games)
            print("AUDIT_OK " + json.dumps(result, ensure_ascii=False, default=str), flush=True)
        else:
            print(json.dumps({
                "dry_run_ok": True, "games": len(parsed_games),
                "batter_rows": sum(len(item["batter_rows"]) for item in parsed_games),
                "pitcher_rows": sum(len(item["pitcher_rows"]) for item in parsed_games),
                "resolved_players": len(resolver.cache),
                "identity_issues": len(identity_rows), "identity_report": str(identity_path),
                "source_issues": len(source_issues), "source_issue_report": str(source_issue_path),
            }, ensure_ascii=False), flush=True)
    finally:
        connection.close()


if __name__ == "__main__":
    main()
