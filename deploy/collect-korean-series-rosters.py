"""Collect Korean Series roster facts for manual player-ID validation.

This is read-only and deliberately does not update the database. It extracts
only the player position rows from each year's published roster table.
"""

import html
import json
import re
import sys
import time
from html.parser import HTMLParser
from urllib.parse import quote
from urllib.request import Request, urlopen


PLAYER_ROLES = {"투수", "포수", "내야수", "외야수"}


class RosterParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.tables = []
        self.table_stack = []
        self.cell = None
        self.link = None

    def handle_starttag(self, tag, attrs):
        if tag == "table":
            table = {"rows": []}
            self.table_stack.append(table)
        elif tag == "tr" and self.table_stack:
            self.table_stack[-1]["row"] = []
        elif tag in ("td", "th") and self.table_stack:
            self.cell = {"text": "", "links": []}
        elif tag == "a" and self.cell is not None:
            self.link = {"text": "", "href": dict(attrs).get("href", "")}

    def handle_data(self, data):
        if self.cell is not None:
            self.cell["text"] += data
        if self.link is not None:
            self.link["text"] += data

    def handle_endtag(self, tag):
        if tag == "a" and self.link is not None:
            if self.link["text"].strip():
                self.cell["links"].append(self.link)
            self.link = None
        elif tag in ("td", "th") and self.cell is not None and self.table_stack:
            self.cell["text"] = re.sub(r"\s+", " ", html.unescape(self.cell["text"])).strip()
            self.table_stack[-1].setdefault("row", []).append(self.cell)
            self.cell = None
        elif tag == "tr" and self.table_stack:
            row = self.table_stack[-1].pop("row", [])
            if row:
                self.table_stack[-1]["rows"].append(row)
        elif tag == "table" and self.table_stack:
            table = self.table_stack.pop()
            self.tables.append(table)


def collect_year(year):
    title = f"{year}년 한국시리즈"
    url = "https://namu.moe/w/" + quote(title)
    with urlopen(Request(url, headers={"User-Agent": "Mozilla/5.0"}), timeout=25) as response:
        page = response.read().decode("utf-8", "replace")
    parser = RosterParser()
    parser.feed(page)
    found = []
    for table in parser.tables:
        rows = table["rows"]
        if not rows:
            continue
        header = " ".join(cell["text"] for row in rows[:3] for cell in row)
        if not (str(year) in header and "한국시리즈" in header and "엔트리" in header):
            continue
        team = re.sub(rf"{year}년?\s*한국시리즈.*", "", header).strip()
        if len(team) > 40:
            team = team[:40]
        players = []
        expected = 0
        for row in rows[1:]:
            role = row[0]["text"] if row else ""
            if role not in PLAYER_ROLES:
                continue
            count_match = re.search(r"(\d+)명", row[1]["text"] if len(row) > 1 else "")
            if count_match:
                expected += int(count_match.group(1))
            for cell in row[2:]:
                for link in cell["links"]:
                    linked_name = link["text"].strip()
                    jersey_match = re.fullmatch(r"(.+?)\((\d+)\)", linked_name)
                    name = jersey_match.group(1) if jersey_match else linked_name
                    if re.fullmatch(r"[가-힣A-Za-z·.() ]{2,30}", name) or name == "콜":
                        player = {"name": name, "role": role, "source_path": link["href"]}
                        if jersey_match:
                            player["jersey"] = int(jersey_match.group(2))
                        players.append(player)
        if players:
            found.append({"year": year, "team": team, "players": players,
                          "expected": expected, "count": len(players), "source": url})
    return found


def main():
    args = sys.argv[1:]
    summary = "--summary" in args
    if summary:
        args.remove("--summary")
    output = None
    if "--output" in args:
        index = args.index("--output")
        output = args[index + 1]
        del args[index:index + 2]
    years = [int(arg) for arg in args] or [year for year in range(1982, 2026) if year != 1985]
    result = {"rosters": [], "errors": []}
    for year in years:
        try:
            rosters = collect_year(year)
            result["rosters"].extend(rosters)
            if len(rosters) < 2:
                result["errors"].append({"year": year, "reason": f"found {len(rosters)} roster tables"})
            for roster in rosters:
                if roster["expected"] and roster["expected"] != roster["count"]:
                    result["errors"].append({"year": year, "team": roster["team"],
                                             "reason": f"expected {roster['expected']}, parsed {roster['count']}"})
        except Exception as exc:
            result["errors"].append({"year": year, "reason": f"{type(exc).__name__}: {exc}"})
        time.sleep(0.8)
    if output:
        with open(output, "w", encoding="utf-8") as handle:
            json.dump(result, handle, ensure_ascii=False, indent=2)
    if summary:
        print(json.dumps({"rosters": [{"year": roster["year"], "team": roster["team"],
                                       "expected": roster["expected"], "count": roster["count"]}
                                      for roster in result["rosters"]], "errors": result["errors"]},
                         ensure_ascii=False))
    else:
        print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
