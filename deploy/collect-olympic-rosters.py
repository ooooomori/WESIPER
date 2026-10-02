"""Extract final Korean Olympic baseball player rosters from the supplied page.

No database writes. 1992 (qualification only) is deliberately excluded.
"""

import json
import re
import sys
from html import unescape
from html.parser import HTMLParser
from urllib.parse import quote
from urllib.request import Request, urlopen


URL = "https://namu.moe/w/" + quote("대한민국 야구 국가대표팀/올림픽", safe="/")
YEARS = {1984, 1988, 1996, 2000, 2008, 2020}
ROLES = {"투수", "포수", "내야수", "외야수"}


class Tables(HTMLParser):
    def __init__(self):
        super().__init__()
        self.tables = []
        self.stack = []
        self.cell = None

    def handle_starttag(self, tag, attrs):
        if tag == "table":
            self.stack.append({"rows": []})
        elif tag == "tr" and self.stack:
            self.stack[-1]["row"] = []
            self.stack[-1]["deleted"] = False
        elif tag in {"td", "th"} and self.stack:
            self.cell = ""
        elif tag in {"del", "s", "strike"} and self.stack:
            self.stack[-1]["deleted"] = True

    def handle_data(self, data):
        if self.cell is not None:
            self.cell += data

    def handle_endtag(self, tag):
        if tag in {"td", "th"} and self.cell is not None and self.stack:
            self.stack[-1].setdefault("row", []).append(re.sub(r"\s+", " ", unescape(self.cell)).strip())
            self.cell = None
        elif tag == "tr" and self.stack:
            row = self.stack[-1].pop("row", [])
            deleted = self.stack[-1].pop("deleted", False)
            if row and not deleted:
                self.stack[-1]["rows"].append(row)
        elif tag == "table" and self.stack:
            self.tables.append(self.stack.pop()["rows"])


def main():
    with urlopen(Request(URL, headers={"User-Agent": "Mozilla/5.0"}), timeout=25) as response:
        page = response.read().decode("utf-8", "replace")
    headings = [(m.start(), int(year.group(1))) for m in re.finditer(r"<h3\b[^>]*>.*?</h3>", page, re.S)
                if (year := re.search(r"\b(19\d\d|20\d\d)\b", re.sub(r"<[^>]*>", " ", m.group())))
                and "올림픽" in re.sub(r"<[^>]*>", " ", m.group())]
    result = []
    for index, (start, year) in enumerate(headings):
        if year not in YEARS:
            continue
        end = headings[index + 1][0] if index + 1 < len(headings) else len(page)
        parser = Tables()
        parser.feed(page[start:end])
        candidates = []
        for table in parser.tables:
            players = []
            for row in table:
                role_index = next((i for i in (1, 2) if len(row) > i and row[i] in ROLES), None)
                if role_index is None:
                    continue
                name = re.sub(r"[^가-힣A-Za-z·]", "", row[role_index - 1])
                if 2 <= len(name) <= 20:
                    players.append({"name": name, "role": row[role_index],
                                    "team": row[role_index + 1] if len(row) > role_index + 1 else ""})
            if len(players) >= 10:
                candidates.append(players)
        result.append({"year": year, "players": max(candidates, key=len) if candidates else [],
                       "source": URL, "candidate_tables": [len(c) for c in candidates]})
    if "--output" in sys.argv:
        path = sys.argv[sys.argv.index("--output") + 1]
        with open(path, "w", encoding="utf-8") as handle:
            json.dump(result, handle, ensure_ascii=False, indent=2)
    print(json.dumps([{"year": item["year"], "count": len(item["players"]),
                       "candidate_tables": item["candidate_tables"]} for item in result], ensure_ascii=False))


if __name__ == "__main__":
    main()
