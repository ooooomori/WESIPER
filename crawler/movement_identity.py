"""Preserve reviewed movement IDs using immutable official source keys."""
import json
from pathlib import Path
OVERRIDES=json.loads(Path(__file__).with_name('movement_identity_overrides.json').read_text(encoding='utf-8'))
def resolved_movement_id(row):
    correction=OVERRIDES.get(row['source_key'])
    if correction is None:return row['player_id']
    for field,value in correction['identity'].items():
        if str(row[field])!=str(value):raise ValueError('Movement identity guard changed: '+field)
    if row['player_id'] not in (None,correction['player_id']):raise ValueError('Conflicting movement identity')
    return correction['player_id']
