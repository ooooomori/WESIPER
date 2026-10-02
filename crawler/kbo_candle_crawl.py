import os
import argparse
import json
from zoneinfo import ZoneInfo
import requests
import time
import tempfile
from pathlib import Path
from datetime import datetime, timedelta
import re
import pymysql
from collections import defaultdict

DB_CONFIG = {
    "host": os.environ["DB_HOST"],
    "port": int(os.getenv("DB_PORT", "3306")),
    "user": os.environ["DB_USER"],
    "password": os.environ["DB_PASSWORD"],
    "database": os.environ["DB_NAME"],
    "charset": "utf8mb4",
}

def publish_ranking_revision():
    """Invalidate shared ranking caches only after all database work succeeds."""
    path = Path(os.environ.get('WESIPER_CANDLE_REVISION_FILE', '/tmp/wesiper-candle-data-revision'))
    with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, prefix='candle-revision-', delete=False) as marker:
        marker.write(str(time.time_ns()))
        temporary_path = marker.name
    try:
        os.chmod(temporary_path, 0o644)
        os.replace(temporary_path, path)
    finally:
        if os.path.exists(temporary_path):
            os.unlink(temporary_path)

def fetch_yesterdays_kbo_game_ids(target_date=None):
    team_codes = ['SS', 'HT', 'NC', 'KT', 'LG', 'SK', 'OB', 'WO', 'LT', 'HH']
    unique_game_ids = set()

    yesterday = datetime.strptime(target_date, "%Y-%m-%d") if target_date else datetime.now(ZoneInfo("Asia/Seoul")) - timedelta(days=1)
    yesterday_str = yesterday.strftime('%Y-%m-%d')
    target_month_str = yesterday.strftime('%Y-%m-01')
    
    print(f"--- KBO 스케줄 단기 추적 개시 (타깃일: {yesterday_str}) ---")

    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        "Accept": "application/json",
        "Referer": "https://m.sports.naver.com/"
    }

    for team in team_codes:
        url = f"https://api-gw.sports.naver.com/schedule/calendar?upperCategoryId=kbaseball&categoryIds=kbo&date={target_month_str}&teamCode={team}"
        try:
            response = requests.get(url, headers=headers, timeout=10)
            response.raise_for_status()
            data = response.json()

            if data.get('code') != 200 or not data.get('success'):
                raise RuntimeError('Incomplete schedule response; prediction publication stopped')

            for day_data in data['result'].get('dates', []):
                game_date = day_data.get('ymd', '')
                if game_date != yesterday_str or day_data.get('gameCount', 0) == 0:
                    continue

                for game in day_data.get('gameInfos', []):
                    game_id = game.get('gameId')
                    status = game.get('statusCode', '')
                    if status not in ["CANCEL", "BEFORE"] and game_id:
                        unique_game_ids.add(game_id)
        except Exception as e:
            print(f"[{team}] API 교신 오류: {e}")
            raise
        time.sleep(0.5)

    sorted_game_ids = sorted(list(unique_game_ids))
    print(f"--- 작전 종료. 실효 경기 총 {len(sorted_game_ids)}건 확보 ---")
    return sorted_game_ids, yesterday_str

def _running_lookups(record_data):
    sb_lookup, cs_lookup, run_out_lookup = {}, {}, {}

    for record in record_data.get('etcRecords', []):
        how = record.get('how', '')
        is_sb = ('도루' in how and '도루자' not in how)
        is_cs = ('도루자' in how or '견제도루자' in how)
        is_run_out = ('주루사' in how)
        if not (is_sb or is_cs or is_run_out):
            continue

        matches = re.findall(r'([가-힣a-zA-Z]+)(\d*)\s*\(([\d\s,]+)회\)', record.get('result', ''))
        for name, count_str, inn_str in matches:
            innings = [int(x) for x in inn_str.replace(',', ' ').split() if x.isdigit()]
            explicit_count = int(count_str) if count_str.isdigit() else len(innings) or 1
            lookup = sb_lookup if is_sb else cs_lookup if is_cs else run_out_lookup
            if len(innings) > 1 and explicit_count == len(innings):
                for inning in innings:
                    lookup[(name, inning)] = lookup.get((name, inning), 0) + 1
            else:
                target_inn = innings[0] if innings else 1
                lookup[(name, target_inn)] = lookup.get((name, target_inn), 0) + explicit_count

    return sb_lookup, cs_lookup, run_out_lookup


_RUN_OUT_PLAYER_OVERRIDES = {
    # The record API misspells 용덕한 as 용덕환 in this game.
    ('20090918HHOB0', '용덕환', 3): {'74223': 1},
    # Same-name players are identified from the official KBO live-text order.
    ('20100525HTLG0', '이병규', 1): {'76100': 1},
    ('20120529LGLT0', '이병규', 5): {'76100': 1},
    ('20110824WOLG0', '이병규', 4): {'97109': 1, '76100': 1},
    ('20161008OBLG02016', '이병규', 4): {'76100': 1},
    ('20250701WOKT02025', '이주형', 9): {'50167': 1},
}


def _reached_base(pa_result):
    text = str(pa_result or '').replace(' ', '')
    return (
        '안' in text or '홈' in text or '4구' in text or '고4' in text
        or '사구' in text or '실' in text or '야선' in text
        or '스낫' in text or '낫아웃' in text
        or text.endswith('2') or text.endswith('3')
    )


def _run_out_player_lookups(record_data, name_lookup, game_id):
    batters = [
        batter
        for side in ('away', 'home')
        for batter in (record_data.get('battersBoxscore', {}).get(side, []) or [])
    ]
    resolved = {}
    for (name, inning), count in name_lookup.items():
        override = _RUN_OUT_PLAYER_OVERRIDES.get((game_id, name, inning))
        if override:
            if sum(override.values()) != count:
                raise ValueError(
                    f'{game_id}: 주루사 수동 식별 합계가 API와 다릅니다: '
                    f'{name} {inning}회 API={count}, override={override}'
                )
            for player_id, player_count in override.items():
                resolved[(player_id, inning)] = (
                    resolved.get((player_id, inning), 0) + player_count
                )
            continue
        candidates = [batter for batter in batters if batter.get('name') == name]
        if not candidates:
            candidates = [
                batter for batter in batters
                if name.startswith(str(batter.get('name') or ''))
                or str(batter.get('name') or '').startswith(name)
            ]
        if len(candidates) > 1:
            reached = [
                batter for batter in candidates
                if any(_reached_base(result) for result in
                       str(batter.get(f'inn{inning}') or '').split('/'))
            ]
            if len(reached) == 1:
                candidates = reached
        if len(candidates) != 1:
            details = [
                (batter.get('playerCode'), batter.get('name'),
                 batter.get(f'inn{inning}')) for batter in candidates
            ]
            raise ValueError(
                f'{game_id}: 주루사 선수 ID를 확정할 수 없습니다: '
                f'{name} {inning}회 후보={details}'
            )
        player_id = str(candidates[0].get('playerCode') or '')
        resolved[(player_id, inning)] = resolved.get((player_id, inning), 0) + count
    return resolved


def _winning_hit_pa_score(detail, pa_result):
    """Score how closely a detailed winning-hit description matches a PA code."""
    detail = str(detail or '').replace(' ', '')
    pa_result = str(pa_result or '').replace(' ', '')
    category_score = 0
    for word, tokens in (
        ('희생플라이', ('희비',)), ('희생번트', ('희번',)),
        ('야수선택', ('야선',)), ('3루타', ('3',)), ('2루타', ('2',)),
        ('홈런', ('홈',)), ('안타', ('안',)), ('땅볼', ('땅', '야선')),
        ('병살', ('병',)), ('4구', ('4구', '고44')), ('사구', ('사구',)),
        ('실책', ('실',)),
    ):
        if word in detail and any(token in pa_result for token in tokens):
            category_score = 100
            break

    direction_score = 0
    for word, token in (
        ('좌중', '좌중'), ('우중', '우중'),
        ('좌전', '좌'), ('좌월', '좌'), ('좌익수', '좌'),
        ('우전', '우'), ('우월', '우'), ('우익수', '우'),
        ('중전', '중'), ('중월', '중'), ('중견수', '중'),
        ('유격수', '유'), ('2루수', '2'), ('1루수', '1'),
        ('3루수', '3'), ('투수', '투'), ('포수', '포'),
    ):
        if word in detail and pa_result.startswith(token):
            direction_score = 10
            break
    return category_score + direction_score


def _pa_outs(pa_result):
    """Conservative out estimate used only to disambiguate duplicate PA codes."""
    value = str(pa_result or '')
    if '삼중' in value:
        return 3
    if '병' in value:
        return 2
    if any(token in value for token in ('삼진', '땅', '비', '직', '파', '희번', '희비')):
        return 1
    return 0


def _winning_hit_target(record_data, game_id):
    """Return ``(player_code, inning, slash_index)`` for the exact winning PA."""
    records = [
        row for row in record_data.get('etcRecords', [])
        if '결승타' in str(row.get('how') or '')
    ]
    if not records:
        return None
    if len(records) != 1:
        raise ValueError(f'{game_id}: expected one winning-hit record, got {len(records)}')
    result = str(records[0].get('result') or '').strip()
    if not result or result in ('없음', '-'):
        return None
    match = re.fullmatch(r'\s*(.+?)\s*\((\d+)회\s*(.*?)\)\s*', result)
    if not match:
        raise ValueError(f'{game_id}: invalid winning-hit result: {result}')
    name, inning, detail = match.group(1).strip(), int(match.group(2)), match.group(3)
    compact_name = name.replace(' ', '')

    lineups = []
    for side, team_key in (('away', 'aName'), ('home', 'hName')):
        team = record_data.get('gameInfo', {}).get(team_key)
        batters = _normalize_batting_orders(
            record_data.get('battersBoxscore', {}).get(side, []) or [], game_id, team,
        )
        lineups.append((side, team, batters))
    batter_entries = [
        (side, team, batters, batter)
        for side, team, batters in lineups for batter in batters
    ]
    matched_batters = [
        entry for entry in batter_entries
        if str(entry[3].get('name') or '').replace(' ', '') == compact_name
    ]
    if not matched_batters:
        matched_batters = [
            entry for entry in batter_entries
            if compact_name in str(entry[3].get('name') or '').replace(' ', '')
            or str(entry[3].get('name') or '').replace(' ', '') in compact_name
        ]
    matched_batters = [entry for entry in matched_batters if entry[3].get(f'inn{inning}')]
    if not matched_batters:
        raise ValueError(f'{game_id}: winning hitter not found: {result}')

    candidates = []
    for side, team, batters, batter in matched_batters:
        for result_index, raw_pa in enumerate(str(batter.get(f'inn{inning}') or '').split('/')):
            pa_result = raw_pa.strip()
            if pa_result:
                candidates.append({
                    'side': side, 'team': team, 'batters': batters, 'batter': batter,
                    'player_code': str(batter.get('playerCode') or ''),
                    'inning': inning, 'result_index': result_index, 'pa_result': pa_result,
                    'score': _winning_hit_pa_score(detail, pa_result),
                })
    if not candidates:
        raise ValueError(f'{game_id}: winning-hit PA is absent: {result}')

    if len(candidates) > 1:
        best_score = max(candidate['score'] for candidate in candidates)
        if best_score:
            candidates = [candidate for candidate in candidates if candidate['score'] == best_score]

    out_match = re.search(r'(무사|1사|2사)', detail)
    if len(candidates) > 1 and out_match:
        target_outs = {'무사': 0, '1사': 1, '2사': 2}[out_match.group(1)]
        outs_by_key = {}
        for side, team, batters in lineups:
            ordered, _ = _ordered_plate_appearances(batters, game_id, team)
            outs = 0
            for event in ordered:
                if event.get('inning') != inning or event.get('missing'):
                    continue
                batter = event['batter']
                key = (str(batter.get('playerCode') or ''), event['result_index'])
                outs_by_key[key] = min(outs, 2)
                outs += _pa_outs(event['pa_result'])
        out_matches = [
            candidate for candidate in candidates
            if outs_by_key.get((candidate['player_code'], candidate['result_index'])) == target_outs
        ]
        if out_matches:
            candidates = out_matches

    if len(candidates) > 1:
        rbi_players = {
            candidate['player_code'] for candidate in candidates
            if int(candidate['batter'].get('rbi') or 0) > 0
        }
        if len(rbi_players) == 1:
            candidates = [candidate for candidate in candidates if candidate['player_code'] in rbi_players]

    # Both Russell singles in this inning occurred with two outs and share the
    # same compact result.  The official description's runner-on-second state
    # identifies the first occurrence; recordData contains no per-PA base state.
    if len(candidates) > 1 and game_id == '20201008NCWO02020':
        candidates = [candidate for candidate in candidates if candidate['result_index'] == 0]

    if len(candidates) != 1:
        summary = [
            (candidate['player_code'], candidate['result_index'], candidate['pa_result'])
            for candidate in candidates
        ]
        raise ValueError(f'{game_id}: ambiguous winning-hit PA {result}: {summary}')
    target = candidates[0]
    return target['player_code'], target['inning'], target['result_index']


def _starter_codes(batters):
    starters = {}
    for batter in batters:
        order = int(batter.get('batOrder') or 0)
        if order and order not in starters:
            starters[order] = str(batter.get('playerCode') or '')
    return starters


def _normalize_batting_orders(batters, game_id, team):
    """Fill batting orders used by the legacy box-score response.

    Older Naver responses omit ``batOrder`` entirely.  In that format the
    batting box is already grouped by lineup slot: a starter row is followed by
    zero or more substitute rows whose position is ``교``.  Preserve modern
    responses as-is and only derive orders when every row omits the field.
    """
    orders = [int(batter.get('batOrder') or 0) for batter in batters]
    if all(1 <= order <= 9 for order in orders):
        return batters
    if any(order for order in orders):
        raise ValueError(f'{game_id} {team}: partially missing batting order')

    normalized = []
    order = 0
    for batter in batters:
        if str(batter.get('pos') or '').strip() != '교':
            order += 1
        if not 1 <= order <= 9:
            raise ValueError(
                f'{game_id} {team}: cannot derive batting order for '
                f'{batter.get("name")} ({batter.get("pos")})'
            )
        row = dict(batter)
        row['batOrder'] = order
        normalized.append(row)
    if order != 9:
        raise ValueError(f'{game_id} {team}: derived {order} batting-order slots, expected 9')
    return normalized


def _ordered_plate_appearances(batters, game_id, team):
    """Return actual plate appearances and slash-delimited non-PA markers.

    Naver stores results by batter and inning. The batting order continues across
    innings, so sorting each inning from 1 to 9 would produce the wrong sequence.
    A leading empty segment such as ``/4구`` means the player entered the game
    before later taking that plate appearance.  It must remain a separate non-PA
    row, but it must not consume a batting index or an opposing pitcher's PA.
    """
    events_by_inning = defaultdict(list)
    non_pa_markers = []
    for lineup_index, batter in enumerate(batters):
        order = int(batter.get('batOrder') or 0)
        if not 1 <= order <= 9:
            raise ValueError(f'{game_id} {team}: invalid batting order for {batter.get("name")}: {order}')
        for inning in range(1, 26):
            raw_result = batter.get(f'inn{inning}', '')
            if not raw_result:
                continue
            for result_index, pa_text in enumerate(str(raw_result).split('/')):
                pa_result = pa_text.strip()
                if not pa_result:
                    non_pa_markers.append({
                        'batter': batter,
                        'inning': inning,
                        'order': order,
                        'lineup_index': lineup_index,
                        'result_index': result_index,
                    })
                    continue
                events_by_inning[inning].append({
                    'batter': batter,
                    'inning': inning,
                    'order': order,
                    'pa_result': pa_result,
                    'lineup_index': lineup_index,
                    'result_index': result_index,
                })

    ordered = []
    next_order = 1
    for inning in range(1, 26):
        remaining = events_by_inning.get(inning, [])[:]
        while remaining:
            candidates = [event for event in remaining if event['order'] == next_order]
            if not candidates:
                # Some historical record responses omit a batter result even
                # though pitchersBoxscore.pa still includes that batter faced.
                # Keep a placeholder so subsequent visible PAs stay aligned to
                # the correct opposing pitcher.
                ordered.append({
                    'missing': True,
                    'inning': inning,
                    'order': next_order,
                })
                next_order = next_order % 9 + 1
                continue
            event = min(candidates, key=lambda item: (item['lineup_index'], item['result_index']))
            remaining.remove(event)
            ordered.append(event)
            next_order = next_order % 9 + 1
    return ordered, non_pa_markers


def _opposing_pitchers(record_data, batting_side):
    pitching_side = 'home' if batting_side == 'away' else 'away'
    return record_data.get('pitchersBoxscore', {}).get(pitching_side, []) or []


def _pitcher_code(pitcher):
    """Return the player code from modern or legacy Naver pitcher rows."""
    return pitcher.get('pcode') or pitcher.get('pCode')


def extract_baseball_data(api_response, game_id, include_pitcher_matchups=True):
    record_data = api_response.get('result', {}).get('recordData', {})
    game_info = record_data.get('gameInfo', {})
    batters_box = record_data.get('battersBoxscore', {})
    sb_lookup, cs_lookup, run_out_lookup = _running_lookups(record_data)
    run_out_player_lookup = _run_out_player_lookups(
        record_data, run_out_lookup, game_id,
    )
    winning_hit_target = _winning_hit_target(record_data, game_id)
    extracted_rows = []

    for team_type, team_key in (('away', 'aName'), ('home', 'hName')):
        team = game_info.get(team_key)
        batters = batters_box.get(team_type, []) or []
        if not team or not batters:
            raise ValueError(f'{game_id}: missing {team_type} team or batter box score')

        batters = _normalize_batting_orders(batters, game_id, team)
        starters = _starter_codes(batters)
        ordered_events, non_pa_markers = _ordered_plate_appearances(batters, game_id, team)
        if include_pitcher_matchups:
            pitchers = _opposing_pitchers(record_data, team_type)
            pitcher_slots = []
            for pitcher in pitchers:
                appearances = int(pitcher.get('pa') or 0)
                pitcher_slots.extend([pitcher] * appearances)
            if len(pitcher_slots) != len(ordered_events):
                visible_appearances = sum(not event.get('missing') for event in ordered_events)
                raise ValueError(
                    f'{game_id} {team}: 확인 타석 {visible_appearances}개와 '
                    f'누락 보정 포함 {len(ordered_events)}개가 상대 투수 pa 합계 '
                    f'{len(pitcher_slots)}개가 일치하지 않습니다.'
                )
        else:
            pitcher_slots = [None] * len(ordered_events)

        player_rows = defaultdict(list)
        leading_marker_rows = defaultdict(list)
        running_applied = set()
        run_out_applied = set()
        batting_index = 0
        for event, pitcher in zip(ordered_events, pitcher_slots):
            if event.get('missing'):
                continue
            batting_index += 1
            batter = event['batter']
            name = batter.get('name')
            running_key = (name, event['inning'])
            first_pa_in_inning = running_key not in running_applied
            row = {
                'player_id': batter.get('playerCode'),
                'player_name': name,
                'inning': event['inning'],
                'pa_result': event['pa_result'],
                'sb': sb_lookup.get(running_key, 0) if first_pa_in_inning else 0,
                'cs': cs_lookup.get(running_key, 0) if first_pa_in_inning else 0,
                'run_out': 0,
                'pitcher_id': _pitcher_code(pitcher) if pitcher else None,
                'pitcher_name': pitcher.get('name') if pitcher else None,
                'team': team,
                'pos': batter.get('pos'),
                'rbi': 0,
                'r': 0,
                'is_gwrbi': int(winning_hit_target == (
                    str(batter.get('playerCode') or ''), event['inning'], event['result_index'],
                )),
                'order': int(batter.get('batOrder') or 0),
                'is_gs': int(starters.get(int(batter.get('batOrder') or 0)) == str(batter.get('playerCode') or '')),
                'batting_index': batting_index,
            }
            running_applied.add(running_key)
            extracted_rows.append(row)
            player_rows[str(batter.get('playerCode') or '')].append(row)

        # Preserve the empty segment in values such as "/4구".  It records a
        # substitution/running appearance that occurred before the later PA.
        for marker in non_pa_markers:
            batter = marker['batter']
            pcode = str(batter.get('playerCode') or '')
            row = {
                'player_id': batter.get('playerCode'),
                'player_name': batter.get('name'),
                'inning': marker['inning'],
                'pa_result': None,
                'sb': 0,
                'cs': 0,
                'run_out': 0,
                'pitcher_id': None,
                'pitcher_name': None,
                'team': team,
                'pos': batter.get('pos'),
                'rbi': 0,
                'r': 0,
                'is_gwrbi': 0,
                'order': int(batter.get('batOrder') or 0),
                'is_gs': int(starters.get(int(batter.get('batOrder') or 0)) == pcode),
                'batting_index': None,
            }
            extracted_rows.append(row)
            player_rows[pcode].append(row)
            if marker['result_index'] == 0:
                leading_marker_rows[pcode].append((marker['inning'], row))

        # A baserunning out belongs to the plate appearance that put the runner
        # on base in that inning.  In a batting-around inning this is the first
        # PA, not the later PA stored in the same inning cell.  A leading empty
        # segment (for example "/4구") represents a pinch-running appearance,
        # so keep the flag on that non-PA row instead.
        for batter in batters:
            name = batter.get('name')
            pcode = str(batter.get('playerCode') or '')
            for inning in range(1, 26):
                running_key = (name, inning)
                run_out_key = (pcode, inning)
                run_out = run_out_player_lookup.get(run_out_key, 0)
                if not run_out:
                    continue
                leading = [row for marker_inning, row in leading_marker_rows[pcode]
                           if marker_inning == inning]
                actual_pas = [row for row in player_rows[pcode]
                              if row.get('inning') == inning
                              and row.get('batting_index') is not None]
                target = leading[0] if leading else actual_pas[0] if actual_pas else None
                if target is not None:
                    target['run_out'] = run_out
                    run_out_applied.add(run_out_key)

        # Preserve running-only records that are not attached to a plate appearance.
        for batter in batters:
            name = batter.get('name')
            pcode = str(batter.get('playerCode') or '')
            for inning in range(1, 26):
                running_key = (name, inning)
                run_out_key = (pcode, inning)
                sb = sb_lookup.get(running_key, 0)
                cs = cs_lookup.get(running_key, 0)
                run_out = run_out_player_lookup.get(run_out_key, 0)
                if running_key in running_applied:
                    sb = cs = 0
                if run_out_key in run_out_applied:
                    run_out = 0
                if not (sb or cs or run_out):
                    continue
                row = {
                    'player_id': batter.get('playerCode'),
                    'player_name': name,
                    'inning': inning,
                    'pa_result': None,
                    'sb': sb,
                    'cs': cs,
                    'run_out': run_out,
                    'pitcher_id': None,
                    'pitcher_name': None,
                    'team': team,
                    'pos': batter.get('pos'),
                    'rbi': 0,
                    'r': 0,
                    'is_gwrbi': 0,
                    'order': int(batter.get('batOrder') or 0),
                    'is_gs': int(starters.get(int(batter.get('batOrder') or 0)) == pcode),
                    'batting_index': None,
                }
                extracted_rows.append(row)
                player_rows[pcode].append(row)

        # A pinch runner or defensive replacement can appear in the box score
        # without a plate appearance or a steal/caught-stealing event.
        for batter in batters:
            pcode = str(batter.get('playerCode') or '')
            if player_rows[pcode]:
                continue
            row = {
                'player_id': batter.get('playerCode'),
                'player_name': batter.get('name'),
                'inning': None,
                'pa_result': None,
                'sb': 0,
                'cs': 0,
                'run_out': 0,
                'pitcher_id': None,
                'pitcher_name': None,
                'team': team,
                'pos': batter.get('pos'),
                'rbi': 0,
                'r': 0,
                'is_gwrbi': 0,
                'order': int(batter.get('batOrder') or 0),
                'is_gs': int(starters.get(int(batter.get('batOrder') or 0)) == pcode),
                'batting_index': None,
            }
            extracted_rows.append(row)
            player_rows[pcode].append(row)

        # Store game totals only on each player's first row.
        for batter in batters:
            pcode = str(batter.get('playerCode') or '')
            first_row = player_rows[pcode][0]
            if leading_marker_rows[pcode]:
                marker_inning, marker_row = min(leading_marker_rows[pcode], key=lambda item: item[0])
                first_inning = first_row.get('inning')
                if first_inning is None or marker_inning <= first_inning:
                    first_row = marker_row
            first_row['rbi'] = int(batter.get('rbi') or 0)
            first_row['r'] = int(batter.get('run') or 0)

    for (player_id, inning), expected in run_out_player_lookup.items():
        applied = sum(
            int(row.get('run_out') or 0) for row in extracted_rows
            if str(row.get('player_id') or '') == player_id and row.get('inning') == inning
        )
        if applied != expected:
            raise ValueError(
                f'{game_id}: 주루사 선수 식별이 모호합니다: '
                f'{player_id} {inning}회 API={expected}, 매핑={applied}'
            )

    return extracted_rows


def extract_pitcher_data(api_response, game_id):
    record_data = api_response.get('result', {}).get('recordData', {})
    game_info = record_data.get('gameInfo', {})
    pitchers_box = record_data.get('pitchersBoxscore', {})
    record_names = {'W': '승', 'L': '패', 'H': '홀', 'S': '세', '홀드': '홀'}
    rows = []

    for team_type, team_key in (('away', 'aName'), ('home', 'hName')):
        team = game_info.get(team_key)
        for appearance_order, pitcher in enumerate(pitchers_box.get(team_type, []) or [], 1):
            pcode = _pitcher_code(pitcher)
            inning = pitcher.get('inn')
            pitched = pitcher.get('bf')
            er = pitcher.get('er')
            runs = pitcher.get('r')
            if (not team or not pcode or inning is None or pitched is None
                    or er is None or runs is None):
                raise ValueError(f'{game_id}: incomplete {team_type} pitcher row: {pitcher}')
            raw_record = str(pitcher.get('wls') or '').strip()
            rows.append({
                'team': team,
                'player_id': pcode,
                'inning': str(inning),
                'record': record_names.get(raw_record, raw_record or None),
                'pitched': int(pitched),
                'order': appearance_order,
                'er': int(er),
                'r': int(runs),
            })
    return rows

def fetch_baseball_records(game_ids):
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    crawled_data = []
    for game_id in game_ids:
        try:
            response = requests.get(f"https://api-gw.sports.naver.com/schedule/games/{game_id}/record", headers=headers, timeout=10)
            if response.status_code == 200:
                crawled_data.append({"game_id": game_id, "raw_data": response.json()})
        except Exception as e:
            print(f"데이터 추출 실패 ({game_id}): {e}")
        time.sleep(1.0)
    return crawled_data

def load_to_mysql(dataset, pitch_dataset):
    if not dataset and not pitch_dataset:
        print("적재할 타깃 데이터가 존재하지 않습니다.")
        return

    print(
        f"--- DB 인서트 작전 개시: 타자 {len(dataset)}개 행 / "
        f"투수 {len(pitch_dataset)}개 행 ---"
    )
    
    try:
        conn = pymysql.connect(**DB_CONFIG)
        from player_ingest import ensure_players, validate_record_schema
        validate_record_schema(conn)
        ensure_players(conn, dataset, pitch_dataset)
        cursor = conn.cursor()
        # Replace only the complete, validated games in this transaction.
        game_ids = sorted({row['game_id'] for row in dataset + pitch_dataset})
        for game_id in game_ids:
            cursor.execute('DELETE FROM kbo_season_records WHERE league_level=1 AND game_id=%s', (game_id,))
            cursor.execute('DELETE FROM kbo_season_pitch_records WHERE league_level=1 AND game_id=%s', (game_id,))

        batter_sql = """
            INSERT INTO kbo_season_records 
            (league_level, game_id, game_date, player_id, player_name, inning, pa_result, sb, cs, run_out,
             pitcher_id, pitcher_name, team, pos, rbi, r, is_gwrbi,
             `order`, is_gs, batting_index)
            VALUES (1, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                    %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """

        batter_values = [
            (
                row['game_id'], row['game_date'], row['player_id'], row['player_name'],
                row['inning'], row['pa_result'], row['sb'], row['cs'], row['run_out'],
                row['pitcher_id'], row['pitcher_name'], row['team'], row['pos'],
                row['rbi'], row['r'], row['is_gwrbi'], row['order'], row['is_gs'],
                row['batting_index']
            )
            for row in dataset
        ]
        if batter_values:
            cursor.executemany(batter_sql, batter_values)

        pitcher_sql = """
            INSERT INTO kbo_season_pitch_records
            (league_level, game_id, game_date, team, player_id, inning, record, pitched, `order`, er, r)
            VALUES (1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                game_date = VALUES(game_date),
                team = VALUES(team),
                inning = VALUES(inning),
                record = VALUES(record),
                pitched = VALUES(pitched),
                `order` = VALUES(`order`),
                er = VALUES(er),
                r = VALUES(r)
        """
        pitcher_values = [
            (
                row['game_id'], row['game_date'], row['team'], row['player_id'],
                row['inning'], row['record'], row['pitched'], row['order'], row['er'], row['r']
            )
            for row in pitch_dataset
        ]
        if pitcher_values:
            cursor.executemany(pitcher_sql, pitcher_values)

        conn.commit()
        print("✅ 성공적으로 개인 타석 및 투수 기록 갱신을 완료했습니다.")

    except Exception as e:
        print(f"데이터베이스 접근 중 치명적 에러 발생: {e}")
        if 'conn' in locals() and conn.open: conn.rollback()
        raise
    finally:
        if 'conn' in locals() and conn.open: conn.close()

def parse_kbo_result(pa_txt):
    """기초 스탯 판독기"""
    if pa_txt is None:
        return None
    clean = str(pa_txt).strip()
    if not clean: return None
    
    if clean in ['4구', '사구', '고4', '볼넷'] or '사사구' in clean or '볼넷' in clean:
        return {'ab': 0, 'h': 0, 'tb': 0, 'obp': 1}
    if '타방' in clean:
        return {'ab': 0, 'h': 0, 'tb': 0, 'obp': 0}
    if clean == '야선' or clean.endswith('희선'):
        return {'ab': 1, 'h': 0, 'tb': 0, 'obp': 0}
    if '희비' in clean or '희플' in clean:
        return {'ab': 0, 'h': 0, 'tb': 0, 'obp': 0}
    if '희번' in clean or '희타' in clean or '희실' in clean:
        return {'ab': 0, 'h': 0, 'tb': 0, 'obp': 0}

    last_char = clean[-1] if len(clean) > 0 else ''
    if last_char == '안': return {'ab': 1, 'h': 1, 'tb': 1, 'obp': 1}
    if last_char == '2': return {'ab': 1, 'h': 1, 'tb': 2, 'obp': 1}
    if last_char == '3': return {'ab': 1, 'h': 1, 'tb': 3, 'obp': 1}
    if last_char == '홈': return {'ab': 1, 'h': 1, 'tb': 4, 'obp': 1}

    return {'ab': 1, 'h': 0, 'tb': 0, 'obp': 0}

def aggregate_league_eff_stats(target_year):
    print(f"--- {target_year}시즌 리그 일자별 유효 스탯 집계 개시 ---")
    try:
        conn = pymysql.connect(**DB_CONFIG)
        cursor = conn.cursor(pymysql.cursors.DictCursor)
        
        # 1. 비율 스탯이 완벽하게 소거된 순수 누적 스탯 전용 테이블 정의
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS kbo_league_records (
                year INT,
                game_date DATE,
                cum_ab INT, cum_h INT, cum_ob INT, cum_sf INT, cum_tb INT,
                cum_eff_ab INT, cum_eff_tb INT, cum_eff_h INT, cum_eff_ob INT,
                PRIMARY KEY (year, game_date)
            )
        """)
        
        # 2. 해당 시즌 전 구단 타석 데이터 시간순 조회
        sql = """
            SELECT game_date, pa_result, sb, cs 
            FROM kbo_season_records 
            WHERE league_level = 1 AND YEAR(game_date) = %s
            ORDER BY game_date ASC, game_id ASC, inning ASC
        """
        cursor.execute(sql, (target_year,))
        rows = cursor.fetchall()
        
        if not rows:
            print(f"[{target_year}] 해당 연도 데이터가 존재하지 않습니다.")
            return

        # 3. 전역 누적 변수 초기화
        cum_ab = 0; cum_h = 0; cum_ob = 0; cum_sf = 0; cum_tb = 0
        cum_eff_ab = 0; cum_eff_tb = 0; cum_eff_h = 0; cum_eff_ob = 0
        
        rows_by_date = defaultdict(list)
        for r in rows:
            rows_by_date[r['game_date']].append(r)
            
        # 4. 시간 흐름에 따른 일일 누적 연산 진행
        for d in sorted(rows_by_date.keys()):
            for row in rows_by_date[d]:
                parsed = parse_kbo_result(row['pa_result'])
                if not parsed: continue
                
                sb = int(row['sb'] or 0)
                cs = int(row['cs'] or 0)
                pa_txt = str(row['pa_result']).strip()
                
                eff_ab = parsed['ab']; eff_tb = parsed['tb']; eff_h = parsed['h']
                is_on_base = (parsed['h'] > 0 or parsed['obp'] > 0)
                
                if is_on_base:
                    if sb > 0 and cs > 0:
                        eff_h = 0; eff_tb = 0
                        if parsed['h'] == 0:
                            eff_ab = 1; cum_eff_ob -= 1
                    elif cs > 0:
                        eff_h = 0; eff_tb = 0
                        if parsed['h'] == 0:
                            eff_ab = 1; cum_eff_ob -= 1
                    elif sb > 0:
                        eff_tb += sb
                else:
                    if sb > 0: eff_ab = 0
                
                bb = 1 if pa_txt in ['4구', '볼넷', '고4'] or '볼넷' in pa_txt else 0
                hbp = 1 if '사구' in pa_txt else 0
                sf = 1 if '희비' in pa_txt or '희플' in pa_txt else 0
                        
                cum_ab += parsed['ab']; cum_h += parsed['h']; cum_tb += parsed['tb']
                cum_ob += (bb + hbp); cum_sf += sf
                
                cum_eff_ab += eff_ab; cum_eff_tb += eff_tb; cum_eff_h += eff_h
                cum_eff_ob += (bb + hbp)
            
            # 5. 비율 연산 제외 후 순수 누적 데이터 INSERT
            insert_sql = """
                REPLACE INTO kbo_league_records (
                    year, game_date, 
                    cum_ab, cum_h, cum_ob, cum_sf, cum_tb,
                    cum_eff_ab, cum_eff_tb, cum_eff_h, cum_eff_ob
                ) VALUES (
                    %s, %s,
                    %s, %s, %s, %s, %s,
                    %s, %s, %s, %s
                )
            """
            cursor.execute(insert_sql, (
                target_year, d,
                cum_ab, cum_h, cum_ob, cum_sf, cum_tb,
                cum_eff_ab, cum_eff_tb, cum_eff_h, cum_eff_ob
            ))
        
        conn.commit()
        print(f"✅ {target_year}시즌 리그 일자별 유효 스탯 적재를 완벽히 마쳤습니다.")
        
    except Exception as e:
        print(f"리그 데이터 집계 중 치명적 에러 발생: {e}")
        if 'conn' in locals() and conn.open: conn.rollback()
        raise
    finally:
        if 'conn' in locals() and conn.open: conn.close()

def main():
    parser = argparse.ArgumentParser(description='Update completed first-team games, KST yesterday by default')
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--dry-run', action='store_true')
    mode.add_argument('--write', action='store_true')
    parser.add_argument('--date', help='Target completed game date YYYY-MM-DD')
    parser.add_argument('--skip-predictions', action='store_true')
    args = parser.parse_args()
    # 1. 전일자 경기 조회 및 개인별 타석 기록 크롤링
    yesterday_games, target_date = fetch_yesterdays_kbo_game_ids(args.date)
    fetched_results = fetch_baseball_records(yesterday_games)

    complete = len(fetched_results) == len(yesterday_games) and all(
        match['raw_data'].get('success') and
        all(match['raw_data'].get('result', {}).get('recordData', {}).get('battersBoxscore', {}).get(side)
            for side in ('away', 'home')) and
        all(match['raw_data'].get('result', {}).get('recordData', {}).get('pitchersBoxscore', {}).get(side)
            for side in ('away', 'home'))
        for match in fetched_results)
    if not complete:
        raise RuntimeError('Incomplete game responses; ingestion and predictions stopped before writes')

    from update_kbo_scoreboard import parse_naver_scoreboard
    # Suspended/in-progress games must never replace previously complete records.
    fetched_results = [match for match in fetched_results
                       if parse_naver_scoreboard(match['raw_data'], match['game_id']) is not None]

    final_dataset = []
    final_pitch_dataset = []
    for match in fetched_results:
        parsed_records = extract_baseball_data(match["raw_data"], match['game_id'])
        for record in parsed_records:
            record['game_id'] = match['game_id']
            record['game_date'] = target_date
        final_dataset.extend(parsed_records)

        parsed_pitchers = extract_pitcher_data(match["raw_data"], match['game_id'])
        for record in parsed_pitchers:
            record['game_id'] = match['game_id']
            record['game_date'] = target_date
        final_pitch_dataset.extend(parsed_pitchers)

    # 2. 크롤링 데이터 DB 반영 (시즌 기록 갱신)
    # Reuse Naver record payloads before predictions; no additional KBO API calls.
    from update_kbo_scoreboard import update_scoreboards_from_records
    if args.dry_run:
        from update_kbo_scoreboard import parse_naver_scoreboard
        from player_ingest import ensure_players, validate_record_schema
        scoreboards = [parse_naver_scoreboard(match['raw_data'], match['game_id']) for match in fetched_results]
        connection = pymysql.connect(**DB_CONFIG)
        try:
            validate_record_schema(connection)
            ensure_players(connection, final_dataset, final_pitch_dataset, dry_run=True)
        finally:
            connection.rollback()
            connection.close()
        print(json.dumps({'dry_run_ok': True, 'date': target_date, 'league_level': 1,
                          'games': len(fetched_results), 'scoreboards': sum(row is not None for row in scoreboards),
                          'batter_rows': len(final_dataset), 'pitcher_rows': len(final_pitch_dataset)}))
        return
    update_scoreboards_from_records(fetched_results, DB_CONFIG)
    load_to_mysql(final_dataset, final_pitch_dataset)
    
    # 3. 당일 추가된 데이터 기반으로 해당 시즌 리그 기록 통째로 재갱신
    if final_dataset:
        current_year = datetime.strptime(target_date, '%Y-%m-%d').year
        aggregate_league_eff_stats(current_year)
        publish_ranking_revision()
    else:
        print("새롭게 적재된 데이터가 없어 리그 집계는 스킵합니다.")

    # The existing 02:00 crawl publishes predictions after its DB update.
    # On off-days, refresh the stored result without fetching extra data.
    if not args.skip_predictions:
        import subprocess
        import sys
        from prediction_data import schedule_path
        from prediction_model import regular_bounds
        year = int(target_date[:4])
        bounds = regular_bounds(schedule_path().read_text(encoding='utf-8')).get(year)
        if bounds and bounds[0] <= target_date <= bounds[1]:
            subprocess.run([sys.executable, str(Path(__file__).with_name('predict_next_game.py')),
                            '--init-schema', '--write-db', '--through', target_date], check=True)


if __name__ == "__main__":
    main()
