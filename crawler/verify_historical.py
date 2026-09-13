"""Read-only verification of completed historical backfill."""
import json
from collections import Counter
from pathlib import Path
from backfill_seasons import connect, UNPLAYED_GAMES

root = Path('/home/bitnami/wesiper/backfill-2014-2017')
empty = json.loads((root / 'empty-records.json').read_text(encoding='utf-8'))
conn = connect()
try:
    with conn.cursor() as cur:
        for year in range(2014, 2018):
            imported = json.loads((root / f'imported-{year}.json').read_text(encoding='utf-8'))
            schedule = json.loads((root / f'schedule-{year}.json').read_text(encoding='utf-8'))
            cur.execute('SELECT game_id, COUNT(*) FROM kbo_season_records WHERE game_date >= %s AND game_date < %s GROUP BY game_id', (f'{year}-01-01',f'{year+1}-01-01'))
            actual = dict(cur.fetchall())
            assert imported == actual, f'{year}: checkpoint / DB mismatch'
            excluded = {gid for gid, day in empty.items() if day.startswith(str(year))} | (UNPLAYED_GAMES & set(schedule))
            assert set(schedule) == set(imported) | excluded
            cur.execute('SELECT COUNT(DISTINCT game_date) FROM kbo_season_records WHERE game_date >= %s AND game_date < %s', (f'{year}-01-01',f'{year+1}-01-01'))
            days = cur.fetchone()[0]
            cur.execute('SELECT COUNT(*) FROM kbo_league_records WHERE year=%s', (year,))
            league_days = cur.fetchone()[0]
            assert days == league_days, f'{year}: league date-count mismatch'
            cur.execute('SELECT COUNT(*) FROM kbo_season_records s LEFT JOIN kbo_league_records l ON l.year=%s AND l.game_date=s.game_date WHERE s.game_date >= %s AND s.game_date < %s AND l.game_date IS NULL', (year,f'{year}-01-01',f'{year+1}-01-01'))
            assert cur.fetchone()[0] == 0
            print(json.dumps({'year':year,'games':len(actual),'rows':sum(actual.values()),'excluded':len(excluded),'league_days':league_days}, ensure_ascii=False))
        print('VERIFIED')
finally:
    conn.close()
