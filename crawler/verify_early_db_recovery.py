"""Bounded recovery verification; no ingest or data-row output."""
import json
from backfill_kbo_early_official import ROOT, atomic, connect, protected_snapshot

connection = connect()
connection._read_timeout = 45
report = {'automatic_backfill': False}
try:
    with connection.cursor() as cursor:
        cursor.execute('SET SESSION max_statement_time=30')
        cursor.execute('SELECT @@innodb_buffer_pool_size, @@event_scheduler')
        report['runtime_buffer_bytes'], report['event_scheduler'] = cursor.fetchone()
        cursor.execute("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kbobingo_grid'")
        row = cursor.fetchone()
        report['bingo_engine'] = row[0] if row else None
        cursor.execute('CHECK TABLE kbobingo_grid QUICK')
        report['bingo_check'] = [list(row) for row in cursor.fetchall()]
        print(json.dumps({'bingo_engine': report['bingo_engine'], 'bingo_check': report['bingo_check']}), flush=True)
    before = json.loads((ROOT / 'protected-before.json').read_text())
    after = protected_snapshot(connection, before)
    report['protected_counts_match'] = after == before
    atomic(ROOT / 'protected-after-recovery.json', after)
    atomic(ROOT / 'db-recovery-verification.json', report)
    print(json.dumps(report), flush=True)
finally:
    connection.close()
