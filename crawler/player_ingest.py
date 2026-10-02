"""Validate missing parent players against official KBO profiles before ingestion."""
import html
import re
from datetime import datetime
from zoneinfo import ZoneInfo

import requests

KST = ZoneInfo('Asia/Seoul')
TEAMS = {'LG': 'LG', 'HH': '한화', 'LT': '롯데', 'HT': 'KIA', 'KT': 'KT',
         'NC': 'NC', 'SM': '상무', 'SS': '삼성', 'OB': '두산', 'SK': 'SSG', 'WO': '키움', 'UL': '울산'}
FIELDS = ('player_id', 'name', 'pos', 'team', 'birth', 'body', 'backNo', 'throw',
          'bat', 'draft', 'is_kbodle', 'is_foreign')


def validate_record_schema(connection):
    required = {
        'kbo_season_records': {'league_level', 'pitcher_id', 'pitcher_name', 'pos', 'rbi', 'r', 'is_gwrbi', 'run_out', 'order', 'is_gs', 'batting_index'},
        'kbo_season_pitch_records': {'league_level', 'pitched', 'order', 'er', 'r'},
        'kbo_schedule': {'league_level', 'away_inning_scores', 'home_inning_scores', 'is_allstar'},
    }
    with connection.cursor() as cursor:
        for table, fields in required.items():
            cursor.execute(f'SHOW COLUMNS FROM `{table}`')
            missing = fields - {row[0] for row in cursor.fetchall()}
            if missing:
                raise ValueError(f'{table} missing columns: {sorted(missing)}')


def text(fragment):
    return ' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', fragment)).split())


def parse_profile(page, player_id, expected_name=None, role=None):
    fields = {}
    for li in re.findall(r'<li\b[^>]*>(.*?)</li>', page, re.I | re.S):
        value = text(li)
        if ':' in value and len(value) < 180:
            label, content = value.split(':', 1)
            fields[label.strip()] = content.strip()
    name = fields.get('선수명', '')
    if not name or (expected_name and name != expected_name):
        raise ValueError(f'Official player identity mismatch: {player_id} {name!r} != {expected_name!r}')
    team_tag = re.search(r'<h4\b[^>]*\bid=[\'"]h4Team[\'"][^>]*>', page, re.I)
    team = re.search(r'regular/(\d{4})/emblem_(\w+)', team_tag[0] if team_tag else '')
    birth = re.fullmatch(r'(\d{4})년\s*(\d{1,2})월\s*(\d{1,2})일', fields.get('생년월일', ''))
    position = re.fullmatch(r'(투수|포수|내야수|외야수)?\s*\(((?:우|좌|양)(?:투|언|사))((?:우|좌|양)타)\)', fields.get('포지션', ''))
    if not team or team[2] not in TEAMS or not birth or not position:
        raise ValueError(f'Incomplete official profile: {player_id}')
    pos, throws, bat = position.groups()
    pos = pos or ('투수' if role == 'pitcher' else None)
    if not pos:
        raise ValueError(f'Unknown official position: {player_id}')
    active = int(team[1]) == datetime.now(KST).year
    foreign = '자유선발' in fields.get('지명순위', '') or '외국인선수' in fields.get('지명순위', '')
    birthday = datetime(*map(int, birth.groups())).strftime('%Y-%m-%d')
    return dict(zip(FIELDS, (int(player_id), name, pos, TEAMS[team[2]], birthday,
        fields.get('신장/체중') if fields.get('신장/체중') not in ('0cm/0kg', 'cm, kg') else None,
        fields.get('등번호', '').removeprefix('No.').strip() or None, throws, bat,
        fields.get('지명순위') or None, (4 if team[2] == 'UL' else 1 if foreign else 2) if active else 0,
        1 if foreign else None)))


def ensure_players(connection, batter_rows, pitcher_rows, *, dry_run=False):
    """Insert verified missing players in the caller's transaction; never overwrite."""
    wanted = {}
    for role, rows in (('batter', batter_rows), ('pitcher', pitcher_rows)):
        for row in rows:
            pid = int(row['player_id'])
            if pid <= 0:
                raise ValueError(f'Invalid player ID: {pid}')
            name = row.get('player_name')
            entry = wanted.setdefault(pid, {'names': set(), 'roles': set()})
            if name:
                entry['names'].add(name)
            entry['roles'].add(role)
    if not wanted:
        return []
    with connection.cursor() as cursor:
        cursor.execute('SELECT player_id FROM kbo_player_data WHERE player_id IN (' + ','.join(['%s'] * len(wanted)) + ')', list(wanted))
        existing = {int(row[0]) for row in cursor.fetchall()}
        verified = []
        for pid in sorted(wanted.keys() - existing):
            roles = wanted[pid]['roles']
            role = 'pitcher' if roles == {'pitcher'} else 'batter'
            errors = []
            for endpoint in ('PitcherDetail/Total', 'HitterDetail/Basic'):
                url = f'https://www.koreabaseball.com/Record/Player/{endpoint}.aspx?playerId={pid}'
                try:
                    response = requests.get(url, headers={'User-Agent': 'Mozilla/5.0'}, timeout=30)
                    response.raise_for_status()
                    response.encoding = 'utf-8'
                    # A stable player ID can legitimately have several names
                    # across seasons after a legal-name change.  The official
                    # profile's current name is authoritative for a new parent
                    # row; historical game rows keep their recorded names.
                    profile = parse_profile(response.text, pid, None, role)
                    verified.append(profile)
                    break
                except (requests.RequestException, ValueError) as error:
                    errors.append(str(error))
            else:
                raise ValueError(f'Cannot verify missing player {pid}: {errors}')
        if not dry_run:
            sql = 'INSERT INTO kbo_player_data (`' + '`,`'.join(FIELDS) + '`) VALUES (' + ','.join(['%s'] * len(FIELDS)) + ') ON DUPLICATE KEY UPDATE player_id=VALUES(player_id)'
            for profile in verified:
                cursor.execute(sql, [profile[field] for field in FIELDS])
        if verified:
            print(f'Verified missing players ({"dry-run" if dry_run else "registered"}): ' + ', '.join(f'{p["player_id"]}:{p["name"]}' for p in verified), flush=True)
        return verified
