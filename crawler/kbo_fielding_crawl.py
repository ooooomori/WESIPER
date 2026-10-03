"""Collect official KBO regular-season fielding records (2001-) into kbo_fielding_records.

python crawler/kbo_fielding_crawl.py --year 2026                           # dry run: summary only
python crawler/kbo_fielding_crawl.py --from-year 2001 --to-year 2026 --write
python crawler/kbo_fielding_crawl.py --write                               # daily: current season
"""
import argparse
import html
import http.cookiejar
import json
import os
import re
import time
from datetime import datetime, timedelta, timezone
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, Request, build_opener

URL = 'https://www.koreabaseball.com/Record/Player/Defense/Basic.aspx'
PREFIX = 'ctl00$ctl00$ctl00$cphContents$cphContents$cphContents$'
SEASON, SERIES, TEAM, POSITION = (PREFIX + name for name in ('ddlSeason$ddlSeason', 'ddlSeries$ddlSeries', 'ddlTeam$ddlTeam', 'ddlPos$ddlPos'))
FIRST_YEAR = 2001
SCHEMA = '''CREATE TABLE IF NOT EXISTS kbo_fielding_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    year SMALLINT UNSIGNED NOT NULL,
    player_id INT UNSIGNED NOT NULL,
    player_name VARCHAR(40) NOT NULL,
    team VARCHAR(20) NOT NULL,
    team_code VARCHAR(8) NOT NULL,
    position VARCHAR(12) NOT NULL,
    games SMALLINT UNSIGNED NOT NULL,
    starts SMALLINT UNSIGNED NOT NULL,
    innings VARCHAR(12) NOT NULL,
    innings_outs INT UNSIGNED NOT NULL,
    errors SMALLINT UNSIGNED NOT NULL,
    pickoffs SMALLINT UNSIGNED NOT NULL,
    putouts SMALLINT UNSIGNED NOT NULL,
    assists SMALLINT UNSIGNED NOT NULL,
    double_plays SMALLINT UNSIGNED NOT NULL,
    fielding_pct DECIMAL(4,3) NULL,
    passed_balls SMALLINT UNSIGNED NOT NULL,
    stolen_bases SMALLINT UNSIGNED NOT NULL,
    caught_stealing SMALLINT UNSIGNED NOT NULL,
    caught_stealing_pct DECIMAL(4,1) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fielding_row (year, player_id, team_code, position),
    KEY idx_fielding_player (player_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'''
COLUMNS = ('year', 'player_id', 'player_name', 'team', 'team_code', 'position', 'games', 'starts', 'innings', 'innings_outs',
           'errors', 'pickoffs', 'putouts', 'assists', 'double_plays', 'fielding_pct', 'passed_balls', 'stolen_bases',
           'caught_stealing', 'caught_stealing_pct')
# Official cell data-id -> column, for whole-number cells.
COUNTS = {'GAME_CN': 'games', 'START_GAME_CN': 'starts', 'ERR_CN': 'errors', 'POFF_CN': 'pickoffs', 'PO_CN': 'putouts',
          'ASS_CN': 'assists', 'GDP_CN': 'double_plays', 'PB_CN': 'passed_balls', 'SB_CN': 'stolen_bases', 'CS_CN': 'caught_stealing'}
RATES = {'FPCT_RT': 'fielding_pct', 'CS_RT': 'caught_stealing_pct'}


def clean(value):
    return html.unescape(re.sub(r'<[^>]+>', ' ', value or '')).strip()


def attrs(text):
    return {m[1]: html.unescape(m[3]) for m in re.finditer(r'([\w:$-]+)\s*=\s*(["\'])(.*?)\2', text, re.S)}


def selects(page):
    out = {}
    for raw, body in re.findall(r'<select\b([^>]*)>(.*?)</select>', page, re.I | re.S):
        options = [(attrs(option).get('value', ''), clean(text), bool(re.search(r'\bselected\b', option)))
                   for option, text in re.findall(r'<option\b([^>]*)>(.*?)</option>', body, re.I | re.S)]
        selected = next((value for value, _, on in options if on), options[0][0] if options else '')
        out[attrs(raw)['name']] = {'value': selected, 'options': [(value, text) for value, text, _ in options]}
    return out


def form(page, target, values=None):
    data = {}
    for raw in re.findall(r'<input\b([^>]*)>', page, re.I | re.S):
        field = attrs(raw)
        if field.get('name') and field.get('type', '').lower() == 'hidden':
            data[field['name']] = field.get('value', '')
    data.update({name: select['value'] for name, select in selects(page).items()})
    data.update(values or {})
    data.update(__EVENTTARGET=target, __EVENTARGUMENT='')
    return data


def innings_outs(text):
    # "1091", "1091 1/3" or just "2/3"
    match = re.fullmatch(r'(\d+)?\s*(?:([12])/3)?', text)
    if not match or not text:
        raise ValueError(f'Unexpected innings: {text!r}')
    return int(match[1] or 0) * 3 + int(match[2] or 0)


def parse_rows(page, year, team_code):
    table = re.search(r'<table class="tData01 tt".*?<tbody>(.*?)</tbody>', page, re.S)
    if not table:
        raise ValueError('Fielding table not found')
    rows = []
    for body in re.findall(r'<tr[^>]*>(.*?)</tr>', table[1], re.S):
        cells = re.findall(r'<td\b([^>]*)>(.*?)</td>', body, re.S)
        if len(cells) == 1:  # "기록이 없습니다"
            return []
        link = re.search(r'playerId=(\d+)', cells[1][1])
        if not link:
            raise ValueError(f'Player without an ID in {year} {team_code}: {clean(cells[1][1])}')
        named = {attrs(raw).get('data-id'): clean(text) for raw, text in cells[3:]}
        if set(named) != {'POS_SC', 'DEFEN_INN2_CN', *COUNTS, *RATES}:
            raise ValueError(f'Unexpected fielding columns: {sorted(map(str, named))}')
        row = {'year': year, 'player_id': int(link[1]), 'player_name': clean(cells[1][1]), 'team': clean(cells[2][1]),
               'team_code': team_code, 'position': named['POS_SC'], 'innings': named['DEFEN_INN2_CN'],
               'innings_outs': innings_outs(named['DEFEN_INN2_CN'])}
        for source, column in COUNTS.items():
            if not named[source].isdigit():
                raise ValueError(f'Unexpected {source}: {named[source]!r}')
            row[column] = int(named[source])
        for source, column in RATES.items():
            value = named[source]
            if value != '-' and not re.fullmatch(r'\d*\.?\d+', value):
                raise ValueError(f'Unexpected {source}: {value!r}')
            row[column] = None if value == '-' else value
        if not row['player_name'] or not row['team'] or not row['position']:
            raise ValueError(f'Incomplete fielding row: {row}')
        rows.append(row)
    return rows


def pager_links(page):
    links = []
    for raw, body in re.findall(r'<a\b([^>]*)>(.*?)</a>', page, re.S):
        link = attrs(raw)
        match = re.search(r"__doPostBack\('([^']*ucPager\$btn(No\d+|Next))'", link.get('href', ''))
        if match:
            links.append((match[1], match[2], 'on' in link.get('class', '').split(), clean(body)))
    return links


def active_page(page):
    return next((int(link[3]) for link in pager_links(page) if link[1].startswith('No') and link[2] and link[3].isdigit()), None)


def next_page_target(page):
    """Postback target of the page after the active one, or None on the last page."""
    links = pager_links(page)
    numbers = [link for link in links if link[1].startswith('No')]
    active = next((index for index, link in enumerate(numbers) if link[2]), None)
    if active is None:
        return None
    if active + 1 < len(numbers):
        return numbers[active + 1][0]
    return next((link[0] for link in links if link[1] == 'Next'), None)


class Site:
    def __init__(self, delay=0.3):
        self.opener = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.delay = delay

    def request(self, data=None):
        for attempt in range(3):
            try:
                request = Request(URL, data=urlencode(data).encode() if data else None, headers={
                    'User-Agent': 'Mozilla/5.0', 'Referer': URL, 'Content-Type': 'application/x-www-form-urlencoded'})
                with self.opener.open(request, timeout=30) as response:
                    page = response.read().decode('utf-8')
                time.sleep(self.delay)
                return page
            except OSError:
                if attempt == 2:
                    raise
                time.sleep(2 * (attempt + 1))

    def select(self, page, name, value):
        if selects(page)[name]['value'] == value:
            return page
        page = self.request(form(page, name, {name: value}))
        if selects(page)[name]['value'] != value:
            raise ValueError(f'Could not select {name.rsplit("$", 1)[-1]}={value}')
        return page


def crawl_season(site, year):
    page = site.select(site.request(), SEASON, str(year))
    page = site.select(page, SERIES, '0')
    teams = [(code, name) for code, name in selects(page)[TEAM]['options'] if code]
    if not teams:
        raise ValueError(f'No teams listed for {year}')
    rows, seen = [], set()
    for code, name in teams:
        # The form keeps the previous team's page number, so always start a team from page 1.
        page = site.request(form(page, TEAM, {TEAM: code, POSITION: '', PREFIX + 'hfPage': '1'}))
        chosen = selects(page)
        if chosen[TEAM]['value'] != code or chosen[POSITION]['value'] != '' or chosen[SEASON]['value'] != str(year) or chosen[SERIES]['value'] != '0':
            raise ValueError(f'Unexpected filters after selecting {year} {name}')
        if active_page(page) not in (None, 1):
            raise ValueError(f'{year} {name} did not open on page 1')
        team_rows = 0
        for _ in range(60):
            found = parse_rows(page, year, code)
            keys = {(row['player_id'], row['team_code'], row['position']) for row in found}
            if len(keys) != len(found) or keys & seen:
                raise ValueError(f'Repeated fielding rows in {year} {name}')
            seen |= keys
            rows.extend(found)
            team_rows += len(found)
            target = next_page_target(page)
            if not found or not target:
                break
            current = active_page(page)
            page = site.request(form(page, target))
            if active_page(page) != current + 1:
                raise ValueError(f'{year} {name}: expected page {current + 1}, got {active_page(page)}')
        else:
            raise ValueError(f'Too many pages for {year} {name}')
        print(f'{year} {name}({code}): {team_rows} rows', flush=True)
    return rows


def save(connection, year, rows, allow_shrink=False):
    """Replace one season atomically. A crawl that returns far fewer rows than stored is treated as a failure."""
    with connection.cursor() as cursor:
        cursor.execute(SCHEMA)
    connection.commit()
    try:
        with connection.cursor() as cursor:
            cursor.execute('SELECT COUNT(*) FROM kbo_fielding_records WHERE year=%s', (year,))
            existing = cursor.fetchone()[0]
            if not rows and not existing:
                return 0
            if not allow_shrink and len(rows) < existing * 0.9:
                raise ValueError(f'{year}: collected {len(rows)} rows but {existing} are stored; database unchanged')
            cursor.execute('DELETE FROM kbo_fielding_records WHERE year=%s', (year,))
            cursor.executemany(f'INSERT INTO kbo_fielding_records ({",".join(COLUMNS)}) VALUES ({",".join(["%s"] * len(COLUMNS))})',
                               [tuple(row[column] for column in COLUMNS) for row in rows])
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    return len(rows)


def main():
    this_year = datetime.now(timezone(timedelta(hours=9))).year
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--year', type=int, help='Single season (default: current season)')
    parser.add_argument('--from-year', type=int)
    parser.add_argument('--to-year', type=int)
    parser.add_argument('--write', action='store_true', help='Save to MySQL (DB_HOST, DB_USER, DB_PASSWORD, DB_NAME)')
    parser.add_argument('--allow-shrink', action='store_true', help='Accept a season with far fewer rows than stored')
    args = parser.parse_args()
    if (args.from_year is None) != (args.to_year is None) or (args.year and args.from_year):
        parser.error('Use either --year or both --from-year and --to-year')
    years = range(args.from_year, args.to_year + 1) if args.from_year else [args.year or this_year]
    if min(years) < FIRST_YEAR or max(years) > this_year:
        parser.error(f'Seasons must be between {FIRST_YEAR} and {this_year}')
    connection = None
    if args.write:
        import pymysql
        connection = pymysql.connect(host=os.environ['DB_HOST'], port=int(os.getenv('DB_PORT', '3306')), user=os.environ['DB_USER'],
                                     password=os.environ['DB_PASSWORD'], database=os.environ['DB_NAME'], charset='utf8mb4', autocommit=False)
    site = Site()
    summary = {}
    try:
        for year in years:
            for attempt in range(3):
                try:
                    rows = crawl_season(site, year)
                    break
                except ValueError as error:
                    # Rows move between pages while the site updates live games; a later pass is consistent.
                    if attempt == 2 or 'Repeated fielding rows' not in str(error):
                        raise
                    print(f'{year}: {error}; retrying', flush=True)
                    time.sleep(60)
            summary[year] = {'rows': len(rows), 'players': len({row['player_id'] for row in rows}),
                             'saved': save(connection, year, rows, args.allow_shrink) if connection else None}
    finally:
        if connection:
            connection.close()
    print(json.dumps({'write': args.write, 'seasons': summary}, ensure_ascii=False))


if __name__ == '__main__':
    main()
