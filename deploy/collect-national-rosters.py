"""Read-only extractor for KBO's official international tournament rosters."""

import json
import re
import sys
from html import unescape
from urllib.request import Request, urlopen


PAGES = {
    "프리미어12:2015": "https://www.koreabaseball.com/Schedule/International/Etc/Premier2015.aspx",
    "프리미어12:2019": "https://www.koreabaseball.com/Schedule/International/Etc/Premier2019.aspx",
    "프리미어12:2024": "https://www.koreabaseball.com/Schedule/International/Etc/Premier2024.aspx",
    "APBC:2017": "https://www.koreabaseball.com/Schedule/International/APBC/Main2017.aspx",
    "APBC:2023": "https://www.koreabaseball.com/Schedule/International/APBC/Main2023.aspx",
    "아시안게임:2018": "https://www.koreabaseball.com/Schedule/International/AsianGames/Main2018.aspx",
    "아시안게임:2023": "https://www.koreabaseball.com/Schedule/International/AsianGames/Main2022.aspx",
    "아시안게임:2026": "https://www.koreabaseball.com/Schedule/International/AsianGames/Main2026.aspx",
    "올림픽:2008": "https://www.koreabaseball.com/Schedule/International/Olympic/Main2008.aspx",
    "올림픽:2020": "https://www.koreabaseball.com/Schedule/International/Olympic/Main2021.aspx",
}


def collect(key, url):
    with urlopen(Request(url, headers={"User-Agent": "Mozilla/5.0"}), timeout=25) as response:
        page = response.read().decode("utf-8", "replace")
    # The last roster heading avoids the global navigation's "대표팀 명단" label.
    match = list(re.finditer(r"대표팀\s*명단|국가대표팀\s*명단", page))
    if not match:
        return {"event": key, "source": url, "error": "roster heading not found"}
    roster = page[match[-1].start():]
    players = []
    seen = set()
    for player_id, label in re.findall(
        r"<a\b[^>]*href=[\"'][^\"']*playerId=(\d+)[^\"']*[\"'][^>]*>(.*?)</a>",
        roster, re.I | re.S,
    ):
        name = re.sub(r"<[^>]+>", "", label)
        name = unescape(name).split("(", 1)[0].strip()
        if not name or player_id in seen:
            continue
        seen.add(player_id)
        players.append({"player_id": int(player_id), "name": name})
    return {"event": key, "source": url, "players": players, "count": len(players)}


def main():
    args = sys.argv[1:]
    output = None
    if "--output" in args:
        index = args.index("--output")
        output = args[index + 1]
        del args[index:index + 2]
    result = []
    for key in args or PAGES:
        try:
            result.append(collect(key, PAGES[key]))
        except Exception as exc:
            result.append({"event": key, "source": PAGES[key],
                           "error": f"{type(exc).__name__}: {exc}"})
    if output:
        with open(output, "w", encoding="utf-8") as handle:
            json.dump(result, handle, ensure_ascii=False, indent=2)
    print(json.dumps([{"event": item["event"], "count": item.get("count"),
                       "error": item.get("error")} for item in result], ensure_ascii=False))


if __name__ == "__main__":
    main()
