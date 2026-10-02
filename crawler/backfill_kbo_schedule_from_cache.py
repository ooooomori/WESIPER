"""Backfill kbo_schedule from cached Naver record responses without HTTP calls."""
import argparse
import json
import re
from collections import Counter
from pathlib import Path

import pymysql

from kbo_candle_crawl import DB_CONFIG
from update_kbo_scoreboard import parse_naver_scoreboard


CACHE_ROOT = Path('/home/bitnami/wesiper')
GAME_FILE = re.compile(r'\d{8}[A-Z]{4}\d(?:\d{4})?\.json')


def load_rows(cache_root):
    paths = sorted(
        path for path in cache_root.glob('backfill-game-details-20[0-9][0-9]/*.json')
        if GAME_FILE.fullmatch(path.name)
    )
    rows = {}
    skipped = []
    for path in paths:
        try:
            payload = json.loads(path.read_text(encoding='utf-8'))
            row = parse_naver_scoreboard(payload, path.stem)
        except Exception as exc:
            raise ValueError(f'{path.stem}: {exc}') from exc
        if row is None:
            skipped.append(path.stem)
            continue
        previous = rows.get(row[0])
        if previous is not None and previous != row:
            raise ValueError(f'Conflicting cached responses for {row[0]}')
        rows[row[0]] = row
    if len(rows) + len(skipped) != len(paths):
        raise ValueError(
            f'Duplicate game codes: files={len(paths)} rows={len(rows)} skipped={len(skipped)}'
        )
    return [rows[code] for code in sorted(rows)], skipped


def get_existing(cursor):
    cursor.execute('SELECT game_code, tv FROM kbo_schedule')
    return {row['game_code']: row['tv'] for row in cursor.fetchall()}


def ensure_nullable_tv(cursor):
    cursor.execute("SHOW COLUMNS FROM kbo_schedule LIKE 'tv'")
    column = cursor.fetchone()
    if not column:
        raise ValueError('kbo_schedule.tv is missing')
    if column['Null'] != 'YES':
        cursor.execute('ALTER TABLE kbo_schedule MODIFY COLUMN tv VARCHAR(255) NULL DEFAULT NULL')


def verify(cursor, planned, original_tv, legacy_codes):
    cursor.execute('SELECT * FROM kbo_schedule')
    actual = {row['game_code']: row for row in cursor.fetchall()}
    failures = []
    inserted = set(planned) - set(original_tv)
    for code, expected in planned.items():
        row = actual.get(code)
        if row is None:
            failures.append(f'{code}: missing row')
            continue
        expected_values = {
            'game_date': expected[1], 'away_team': expected[2],
            'home_team': expected[3], 'away_score': expected[4],
            'home_score': expected[5], 'stadium': expected[6],
        }
        for name, value in expected_values.items():
            actual_value = str(row[name]) if name == 'game_date' else row[name]
            if actual_value != value:
                failures.append(f'{code}: {name}={actual_value!r} expected {value!r}')
        for name, source_index in (('away_inning_scores', 7), ('home_inning_scores', 8)):
            if json.loads(row[name]) != json.loads(expected[source_index]):
                failures.append(f'{code}: {name} mismatch')
        expected_tv = None if code in inserted else original_tv[code]
        if row['tv'] != expected_tv:
            failures.append(f'{code}: tv={row["tv"]!r} expected {expected_tv!r}')
    if failures:
        raise ValueError('Post-write verification failed: ' + '; '.join(failures[:20]))
    legacy_remaining = sorted(legacy_codes & set(actual))
    if legacy_remaining:
        raise ValueError(f'Legacy pseudo-date schedule rows remain: {legacy_remaining[:20]}')
    cursor.execute("SHOW COLUMNS FROM kbo_schedule LIKE 'tv'")
    if cursor.fetchone()['Null'] != 'YES':
        raise ValueError('kbo_schedule.tv still does not allow NULL')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cache-root', type=Path, default=CACHE_ROOT)
    parser.add_argument('--write', action='store_true')
    args = parser.parse_args()

    rows, skipped = load_rows(args.cache_root)
    planned = {row[0]: row for row in rows}
    legacy_codes = {code[:13] for code in planned if len(code) == 17}
    connection = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
    try:
        with connection.cursor() as cursor:
            existing_tv = get_existing(cursor)
        inserts = set(planned) - set(existing_tv)
        updates = set(planned) & set(existing_tv)
        legacy_replacements = legacy_codes & set(existing_tv)
        actual_years = Counter(row[1][:4] for row in rows)
        print(
            f'검증 완료: caches={len(rows)} skipped={len(skipped)} '
            f'updates={len(updates)} inserts={len(inserts)} '
            f'legacy_replacements={len(legacy_replacements)} '
            f'years={dict(sorted(actual_years.items()))}', flush=True,
        )
        if skipped:
            print(f'점수판 데이터 누락 경기: {",".join(skipped)}', flush=True)
        if not args.write:
            print('DRY-RUN: DB를 변경하지 않았습니다.', flush=True)
            return

        with connection.cursor() as cursor:
            ensure_nullable_tv(cursor)
            if legacy_replacements:
                cursor.executemany(
                    'DELETE FROM kbo_schedule WHERE game_code=%s',
                    [(code,) for code in sorted(legacy_replacements)],
                )
            cursor.executemany(
                '''INSERT INTO kbo_schedule
                   (game_code, game_date, away_team, home_team, away_score, home_score,
                    stadium, away_inning_scores, home_inning_scores, tv)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,NULL)
                   ON DUPLICATE KEY UPDATE
                    game_date=VALUES(game_date), away_team=VALUES(away_team),
                    home_team=VALUES(home_team), away_score=VALUES(away_score),
                    home_score=VALUES(home_score), stadium=VALUES(stadium),
                    away_inning_scores=VALUES(away_inning_scores),
                    home_inning_scores=VALUES(home_inning_scores)''',
                rows,
            )
        connection.commit()

        with connection.cursor() as cursor:
            verify(cursor, planned, existing_tv, legacy_codes)
        print(
            f'운영 반영 완료: updated={len(updates)} inserted={len(inserts)} '
            f'inserted_tv_null={len(inserts)}', flush=True,
        )
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


if __name__ == '__main__':
    main()
