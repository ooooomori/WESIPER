"""Verify unbounded protected counts and persist actual committed year totals."""
import json
from pathlib import Path
from backfill_kbo_early_official import ROOT, atomic, connect, protected_snapshot

connection=connect()
connection._read_timeout=75
try:
    with connection.cursor() as cursor:
        cursor.execute('SET SESSION max_statement_time=60')
        cursor.execute('SELECT @@innodb_buffer_pool_size,@@event_scheduler')
        settings=cursor.fetchone()
        assert settings==(16*1024*1024,'ON')
    baseline=json.loads((ROOT/'protected-before.json').read_text())
    current=protected_snapshot(connection)
    # Include every current row outside the target range, with the actual
    # current maximum ID, instead of relying on the pre-write ID cutoff.
    matches={table:current[table]['counts']==baseline[table]['counts'] for table in baseline}
    assert all(matches.values()),'outside-range row counts changed'
    atomic(ROOT/'protected-after-all-current-rows.json',current)
    reports=json.loads((ROOT/'final-report.json').read_text())
    summary=[]
    for year in range(2001,2008):
        report=reports[str(year)]
        assert report['games']+len(report['unresolved_games'])==report['discovered_games']
        for name in ('null_batting_index','partial_pitcher','null_running','missing_player',
                     'missing_pitch_player','invalid_index_groups','duplicate_pitchers','duplicate_batters'):
            assert int(report[name])==0,(year,name)
        assert report['schedule_games']==report['games']
        failed={entry['game']['game_id'] for entry in json.loads((ROOT/str(year)/'dry-failures.json').read_text())}
        plans=[json.loads(path.read_text()) for path in (ROOT/str(year)/'plans').glob('*.json') if path.stem not in failed]
        assert len(plans)==report['games']
        assert sum(len(plan['batter_rows']) for plan in plans)==report['batter_rows']
        assert sum(len(plan['pitcher_rows']) for plan in plans)==report['pitcher_rows']
        assert sum(int(row['run_out']) for plan in plans for row in plan['batter_rows'])==int(report['run_out'])
        summary.append({'year':year,**{name:report[name] for name in
                        ('games','batter_rows','pitcher_rows','run_out','pitcher_missing_games','unresolved_games')}})
    override_path=Path(__file__).with_name('kbo_early_missing_pa_overrides.json')
    overrides=json.loads(override_path.read_text(encoding='utf-8')) if override_path.exists() else {}
    corrected=[]
    if overrides:
        with connection.cursor() as cursor:
            cursor.execute("SELECT game_id,team,player_name,inning,`order`,pa_result,batting_index,pitcher_id,pitcher_name FROM kbo_season_records FORCE INDEX(idx_league_game_team_batting_index) WHERE league_level=1 AND game_date>='2001-01-01' AND game_date<'2008-01-01' AND game_id IN ("+','.join(['%s']*len(overrides))+") AND pa_result='아웃'",list(overrides))
            corrected=cursor.fetchall()
        assert len(corrected)==len(overrides)
        for row in corrected:
            expected=overrides[row[0]]
            assert row[1:3]==(expected['team'],expected['name'])
            assert (int(row[3]),int(row[4]))==(expected['inning'],expected['order'])
            assert row[5]=='아웃' and row[6] is not None and row[7] is not None and row[8] is not None
    result={'phase':'verified_regular_season_write_complete','years':summary,
            'outside_range_all_current_row_counts_match':matches,
            'runtime_buffer_bytes':settings[0],'event_scheduler':settings[1],
            'all_requested_null_order_duplicate_checks_zero':True,
            'coverage':'officially discoverable regular-season games; special-series official schedule responses empty',
            'missing_pa_games':sum(len(report['unresolved_games']) for report in reports.values()),
            'user_authorized_missing_results':overrides,
            'user_authorized_out_rows_verified':len(corrected),
            'temporary_db_tables_created':False}
    atomic(ROOT/'verified-summary.json',result)
    print(json.dumps(result,ensure_ascii=False),flush=True)
finally:connection.close()
