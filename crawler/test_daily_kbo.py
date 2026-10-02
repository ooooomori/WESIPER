import json
import os
import tempfile
import time
import unittest
from datetime import datetime
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from zoneinfo import ZoneInfo

for key in ('DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'):
    os.environ.setdefault(key, 'test')
from kbo_futures_crawl import PlayerResolver
from player_ingest import ensure_players, parse_profile


def fixture(year):
    return f'''<h4 id="h4Team" class="regular/{year}/emblem_SS"></h4>
    <li>선수명: 새선수</li><li>생년월일: 2001년 02월 03일</li>
    <li>포지션: 투수(우투좌타)</li><li>등번호: No.51</li>
    <li>신장/체중: 185cm/80kg</li><li>지명순위: 2026 삼성 1차</li>'''


class Connection:
    def __init__(self): self.commands = []
    def cursor(self): return self
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def execute(self, sql, args): self.commands.append((sql, args))
    def fetchall(self): return []


class DailyKboTest(unittest.TestCase):
    def test_profile_preserves_full_hand_notation(self):
        year = datetime.now(ZoneInfo('Asia/Seoul')).year
        for throws, bat in [('우투', '우타'), ('좌투', '좌타'), ('우언', '우타'), ('우사', '우타'), ('우투', '양타')]:
            with self.subTest(throws=throws, bat=bat):
                page = fixture(year).replace('우투좌타', throws + bat)
                profile = parse_profile(page, 50126, '새선수')
                self.assertEqual(profile['throw'], throws)
                self.assertEqual(profile['bat'], bat)

    def test_missing_parent_is_verified_before_insert_and_dry_run_never_inserts(self):
        year = datetime.now(ZoneInfo('Asia/Seoul')).year
        response = SimpleNamespace(text=fixture(year), raise_for_status=lambda: None)
        rows = [{'player_id': 50126, 'player_name': '새선수'}]
        with patch('player_ingest.requests.get', return_value=response):
            for dry in (True, False):
                connection = Connection()
                result = ensure_players(connection, [], rows, dry_run=dry)
                self.assertEqual(result[0]['team'], '삼성')
                self.assertEqual(sum(sql.startswith('INSERT') for sql, _ in connection.commands), 0 if dry else 1)
        with self.assertRaises(ValueError): parse_profile(fixture(year), 50126, '다른선수')

    def test_current_daily_cache_expires_but_historical_cache_survives(self):
        year = datetime.now(ZoneInfo('Asia/Seoul')).year
        with tempfile.TemporaryDirectory() as directory:
            for season, age, should_fetch in [(year, 7200, True), (year, 30, False), (year-1, 7200, False)]:
                resolver = PlayerResolver.__new__(PlayerResolver)
                resolver.daily_cache = Path(directory)
                resolver.daily_memory = {}
                response = SimpleNamespace(text=f'<h6>{season} 일자별 성적</h6>', raise_for_status=lambda: None)
                resolver.session = SimpleNamespace(get=lambda *a, **kw: response)
                resolver.parse_daily_appearances = lambda page: {('09.30', '삼성')}
                path = resolver.daily_cache / f'pitcher-50126-{season}.json'
                path.write_text(json.dumps({'appearances': [['09.01', 'LG']]}))
                os.utime(path, (time.time()-age, time.time()-age))
                result = resolver.daily_appearances(50126, season, 'pitcher')
                self.assertEqual(result, {('09.30', '삼성')} if should_fetch else {('09.01', 'LG')})


if __name__ == '__main__': unittest.main()
