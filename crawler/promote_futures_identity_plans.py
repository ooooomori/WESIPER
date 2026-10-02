"""Promote checked identity corrections only for games not yet committed."""
import json
from backfill_futures_history import ROOT,atomic
from write_futures_history import validate
report=json.loads((ROOT/'changed-identity-audit.json').read_text())
if report['failures']:raise ValueError('identity audit failures require resolution')
for entry in report['changed_games']:
 folder=ROOT/str(entry['year']);gid=entry['game_id'];proposal=json.loads((folder/'identity-corrections'/(gid+'.json')).read_text());path=folder/'plans'/(gid+'.json')
 receipt=folder/'write-receipt.json';committed=set(json.loads(receipt.read_text())['committed_game_ids']) if receipt.exists() else set()
 if gid in committed:raise ValueError(f'{gid}: committed identity correction needs separate verified reconciliation')
 current=json.loads(path.read_text())
 if current!=proposal['old_plan'] and current!=proposal['new_plan']:raise ValueError(f'{gid}: cached plan changed since identity audit')
 validate(proposal['new_plan']);atomic(path,proposal['new_plan'])
print('checked identity corrections promoted',len(report['changed_games']),flush=True)
