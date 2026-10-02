"""Compare old and migrated player APIs using a private local PHP server.

Run on the deployment host; fixtures come from player-api-fixtures.php.
No game picks, scores, player flags, or answers are written by this test.
"""
import argparse
import json
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


def request(base, endpoint, body=None, expected=200):
    encoded = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(base + '/api/' + endpoint, data=encoded,
                                 headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            status, raw = response.status, response.read()
    except urllib.error.HTTPError as error:
        status, raw = error.code, error.read()
    if status != expected:
        raise AssertionError(f'{endpoint}: HTTP {status}, expected {expected}: {raw[:300]!r}')
    try:
        return json.loads(raw)
    except ValueError as error:
        raise AssertionError(f'{endpoint}: invalid JSON: {raw[:300]!r}') from error


def unique_players(data, id_key='SporkId'):
    return {str(player[id_key]): player for player in data}


def run(base, fixture, baseline=None, career_migration=False):
    snapshots = {}
    comparisons = [
        ('today', 'kbodle/get_today_kbodle.php', None),
        ('previous', 'kbodle/get_prev_kbodle.php', None),
        ('search', 'kbodle/get_player_list.php', {'keyword': ''}),
        ('renamed_search', 'kbodle/get_player_list.php', {'keyword': '장지수'}),
        ('generated', 'kbodle/get_custom_kbodle.php', {'p_no': fixture['generated']['player_id']}),
        ('excluded', 'kbodle/get_custom_kbodle.php', {'p_no': fixture['excluded']['player_id']}),
        ('roster', 'kbodle/get_roster.php', None),
        ('candle_active', 'kbocandle/get_player_list.php', {'name': '최정'}),
        ('candle_retired', 'kbocandle/get_player_list.php', {'name': '이승엽'}),
    ]
    if fixture.get('pick'):
        pick = fixture['pick']
        comparisons.append(('bingo_pick', 'kbobingo/pick_data_most.php',
                            {'index': pick['grid_index'], 'row': pick['row_no'], 'col': pick['col_no']}))
    if fixture.get('cached_player'):
        comparisons.append(('bingo_search', 'kbobingo/search.php', {'keyword': fixture['cached_player']['name']}))
    if fixture.get('prediction'):
        query = urllib.parse.urlencode(fixture['prediction'])
        comparisons.append(('prediction', 'kbocandle/prediction_rankings.php?' + query, None))
    for name, endpoint, body in comparisons:
        current = request(base, endpoint, body)
        snapshots[name] = current
        if name in ('search', 'renamed_search'):
            assert current.get('success') is True
            assert all(int(player['SporkId']) in fixture['active_ids'] for player in current['list'])
        if name in ('generated', 'excluded', 'today'):
            assert current.get('success') is True, (name, current)
        if name.startswith('candle'):
            assert isinstance(current, list), current
            if name == 'candle_retired':
                assert current and all(player['Team'] == '은퇴' for player in current)
        if baseline is not None:
            previous = baseline[name]
            if name in ('search', 'renamed_search'):
                actual_players = unique_players(current['list'])
                prior_players = unique_players(previous['list'])
                # Later explicit status 3/4 changes remove these players from KBO games.
                prior_players = {pid: player for pid, player in prior_players.items() if int(pid) in fixture['active_ids']}
                # Filling missing birth dates deliberately corrects fallback age 20.
                for pid, age in (fixture.get('kbodle_age_changes') or {}).items():
                    if pid in prior_players:
                        prior_players[pid] = {**prior_players[pid], 'Age': age}
                if actual_players != prior_players:
                    differences = [(pid, prior_players.get(pid), actual_players.get(pid)) for pid in actual_players.keys() | prior_players.keys() if actual_players.get(pid) != prior_players.get(pid)]
                    raise AssertionError((name, differences[:10]))
            elif name == 'roster':
                # The user requested longer values when position columns differ.
                for team in ['KIA', 'SSG', 'NC', '키움', '두산', '삼성', '한화', '롯데', 'LG', 'KT']:
                    assert [sorted(set(names)) for names in current[team]] == [sorted(set(names)) for names in fixture['roster'][team]], team
            elif name == 'bingo_search' and career_migration:
                # The new source intentionally corrects historical award flags.
                import copy
                normalized = [copy.deepcopy(current), copy.deepcopy(previous)]
                for result in normalized:
                    for player in result['list']:
                        for stats in player.get('Season', {}).values():
                            stats.pop('gg', None)
                            stats.pop('as', None)
                assert normalized[0] == normalized[1], name
            else:
                assert current == previous, name
        print(f'PASS {name}', flush=True)
    if baseline is not None:
        if fixture.get('public_board'):
            board = request(base, 'kbobingo/get_user_board.php', fixture['public_board'])
            assert board['code'] == 200 and len(board['players']) == 9, board
            assert all(player is None or {'no', 'name', 'img'} <= player.keys() for player in board['players'])
            print('PASS public bingo board', flush=True)
        today_copy = request(base, 'kbodle/get_today_kbodle%20copy.php')
        assert today_copy == snapshots['today']
        retired = request(base, 'kbodle/get_custom_kbodle.php', {'p_no': fixture['retired']['player_id']})
        assert retired['success'] is False
        request(base, 'playerProfile.php?pid=' + str(fixture['excluded']['player_id']))
        profile = request(base, 'playerProfile.php?pid=' + str(fixture['retired']['player_id']))
        assert profile['player']['Team'] == '은퇴'
        request(base, 'playerProfile.php?pid=invalid', expected=400)
        request(base, 'playerProfile.php?pid=9999999999', expected=404)
        for _ in range(20):
            random_player = request(base, 'kbodle/get_random_player.php', {'answer_id': fixture['today']['PlayerID']})
            assert random_player['success'] is True
            pid = int(random_player['player']['SporkId'])
            assert pid in fixture['active_ids'] and pid != int(fixture['today']['PlayerID'])
        print('PASS legacy URL, retired filters, profiles and 20 random selections', flush=True)
    return snapshots


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--phase', choices=['baseline', 'staged', 'live'], required=True)
    parser.add_argument('--base', default='https://wesiper.xyz')
    parser.add_argument('--career-migration', action='store_true')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    fixture = json.loads(subprocess.check_output(['sudo', '/opt/bitnami/php/bin/php', str(root / 'deploy/player-api-fixtures.php')]))
    baseline_path = root / 'deploy/player-api-baseline.json'
    if args.phase == 'baseline':
        baseline_path.write_text(json.dumps(run(args.base, fixture), ensure_ascii=False), encoding='utf-8')
        return
    baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
    if args.phase == 'live':
        run(args.base, fixture, baseline, args.career_migration)
        return
    php_files = list((root / 'backend').rglob('*.php')) + list((root / 'deploy').glob('*.php'))
    for path in php_files:
        result = subprocess.run(['/opt/bitnami/php/bin/php', '-l', str(path)], capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
    print(f'PASS PHP syntax ({len(php_files)} files)', flush=True)
    with (root / 'stage-api.log').open('w') as log:
        server = subprocess.Popen(['sudo', '-u', 'daemon', '/opt/bitnami/php/bin/php', '-d', 'display_errors=0',
                                   '-S', '127.0.0.1:18089', '-t', str(root / 'backend')], stdout=log, stderr=log)
        try:
            for _ in range(30):
                if server.poll() is not None:
                    raise RuntimeError('Private PHP server failed to start.')
                try:
                    request('http://127.0.0.1:18089', 'playerProfile.php?pid=invalid', expected=400)
                    break
                except OSError:
                    time.sleep(0.1)
            run('http://127.0.0.1:18089', fixture, baseline, args.career_migration)
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()


if __name__ == '__main__':
    main()
