"""Import cached official movements and narrowly update verified parent fields."""
import argparse,gzip,hashlib,json
from collections import Counter
from collect_player_movements import ROOT,atomic
from backfill_kbo_early_official import connect
from player_identity_corrections import validate_rename_id
from movement_identity import resolved_movement_id

TABLE='kbo_player_movements'
DDL=f'''CREATE TABLE IF NOT EXISTS {TABLE} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 source_key CHAR(64) NOT NULL,
 year SMALLINT UNSIGNED NOT NULL,
 event_date DATE NULL,
 event_type VARCHAR(64) NOT NULL,
 team VARCHAR(32) NOT NULL,
 player_id INT NULL,
 player_name VARCHAR(64) NOT NULL,
 player_text VARCHAR(128) NOT NULL,
 note TEXT NULL,
 old_back_no VARCHAR(8) NULL,
 new_back_no VARCHAR(8) NULL,
 source_url VARCHAR(255) NOT NULL,
 source_file VARCHAR(255) NOT NULL,
 source_page INT NOT NULL,
 source_row INT NOT NULL,
 source_sha256 CHAR(64) NOT NULL,
 raw_json LONGTEXT NOT NULL,
 contract_years SMALLINT UNSIGNED NULL,
 contract_term VARCHAR(64) NULL,
 contract_total_amount BIGINT UNSIGNED NULL,
 contract_registered_amount BIGINT UNSIGNED NULL,
 contract_currency CHAR(3) NULL,
 contract_details TEXT NULL,
 contract_source_url TEXT NULL,
 contract_verified_at DATETIME NULL,
 collected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),UNIQUE KEY uq_movement_source(source_key),
 KEY idx_movement_date(event_date),KEY idx_movement_player(player_id,event_date),
 KEY idx_movement_type(event_type,event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'''
FIELDS=['source_key','year','event_date','event_type','team','player_id','player_name','player_text','note','old_back_no','new_back_no','source_url','source_file','source_page','source_row','source_sha256','raw_json']
def values(row):
 r=dict(row);r['player_id']=resolved_movement_id(r);r['source_url']='https://www.koreabaseball.com/Player/Trade.aspx';r['raw_json']=json.dumps(r['raw'],ensure_ascii=False,separators=(',',':'));return tuple(r[k] for k in FIELDS)
def snapshot(con):
 with con.cursor() as c:c.execute('SELECT * FROM kbo_player_data ORDER BY player_id');return {'columns':[d[0] for d in c.description],'rows':[list(r) for r in c.fetchall()]}
def run(write=False):
 plan=json.loads((ROOT/'resolved-plan.json').read_text());numbers=json.loads((ROOT/'number-plan.json').read_text());renames=json.loads((ROOT/'rename-plan.json').read_text());assert not plan['failures'];assert not numbers['profile_failures'];assert not renames['failures'];rows=plan['rows'];assert len({r['source_key'] for r in rows})==len(rows);assert all(r['event_type']!='개명' and 2017<=r['year']<=2026 for r in rows)
 for item in renames['updates']:validate_rename_id(item)
 for row in rows:
  with gzip.open(ROOT/row['source_file'],'rb') as f:raw=f.read()
  assert hashlib.sha256(raw).hexdigest()==row['source_sha256']
 con=connect();before=snapshot(con);backup=ROOT/'parent-full-before.json.gz'
 if not backup.exists():
  with gzip.open(backup,'wt',encoding='utf-8') as f:json.dump(before,f,ensure_ascii=False,default=str)
 if not write:
  print(json.dumps({'dry_run':True,'movement_rows':len(rows),'number_updates':len(numbers['updates']),'rename_updates':len(renames['updates'])}));return
 with con.cursor() as c:c.execute(DDL)
 con.commit();receipt_path=ROOT/'write-receipt.json';receipt=json.loads(receipt_path.read_text()) if receipt_path.exists() else {'years':{},'parents':False}
 for year in range(2017,2027):
  batch=[r for r in rows if r['year']==year]
  if str(year) in receipt['years']:continue
  try:
   with con.cursor() as c:
    for row in batch:
     c.execute(f'SELECT '+','.join('`'+f+'`' for f in FIELDS)+f' FROM {TABLE} WHERE source_key=%s',(row['source_key'],));existing=c.fetchone();wanted=values(row)
     if existing:
      existing=list(existing);existing[FIELDS.index('event_date')]=str(existing[FIELDS.index('event_date')]);assert tuple(existing)==wanted,'existing movement differs; reconciliation required'
     else:c.execute(f'INSERT INTO {TABLE} ('+','.join('`'+f+'`' for f in FIELDS)+') VALUES ('+','.join(['%s']*len(FIELDS))+')',wanted)
   con.commit();receipt['years'][str(year)]=len(batch);atomic(receipt_path,receipt);print('committed',year,len(batch),flush=True)
  except Exception:con.rollback();raise
 parent_sha=hashlib.sha256(json.dumps([numbers['updates'],renames['updates']],ensure_ascii=False,sort_keys=True).encode()).hexdigest()
 if not receipt['parents'] or receipt.get('parent_plan_sha256')!=parent_sha:
  try:
   with con.cursor() as c:
    for item in numbers['updates']:
     c.execute('SELECT backNo FROM kbo_player_data WHERE player_id=%s',(item['player_id'],));current=c.fetchone();assert current is not None
     if current[0]==item['after']:continue
     c.execute('UPDATE kbo_player_data SET backNo=%s WHERE player_id=%s AND backNo <=> %s',(item['after'],item['player_id'],item['before']));assert c.rowcount==1,f"number changed concurrently: {item['player_id']}"
    for item in renames['updates']:
     c.execute('SELECT name,oldname FROM kbo_player_data WHERE player_id=%s',(item['player_id'],));current=c.fetchone()
     if current==(item['name'],item['oldname']):continue
     c.execute('UPDATE kbo_player_data SET name=%s,oldname=%s WHERE player_id=%s AND name <=> %s AND oldname <=> %s',(item['name'],item['oldname'],item['player_id'],item['before_name'],item['before_oldname']));assert c.rowcount==1,f"name changed concurrently: {item['player_id']}"
   con.commit();receipt['parents']=True;receipt['parent_plan_sha256']=parent_sha;atomic(receipt_path,receipt)
  except Exception:con.rollback();raise
 with gzip.open(backup,'rt',encoding='utf-8') as f:before=json.load(f)
 after=snapshot(con);assert before['columns']==after['columns'];columns=before['columns'];pididx=columns.index('player_id');beforemap={r[pididx]:r for r in before['rows']};aftermap={r[pididx]:r for r in after['rows']};assert beforemap.keys()==aftermap.keys()
 expected={pid:list(row) for pid,row in beforemap.items()}
 for item in numbers['updates']:expected[item['player_id']][columns.index('backNo')]=item['after']
 for item in renames['updates']:
  expected[item['player_id']][columns.index('name')]=item['name'];expected[item['player_id']][columns.index('oldname')]=item['oldname']
 external_changes=Counter()
 for pid,wanted in expected.items():
  for index,(value,actual) in enumerate(zip(wanted,aftermap[pid])):
   if value==actual:continue
   field=columns[index]
   assert field not in ('name','oldname','backNo'),f'requested parent value differs: {pid} {field}'
   external_changes[field]+=1
 with con.cursor() as c:
  c.execute(f'SELECT '+','.join('`'+f+'`' for f in FIELDS)+f" FROM {TABLE} WHERE source_url='https://www.koreabaseball.com/Player/Trade.aspx' ORDER BY source_key");stored={r[0]:list(r) for r in c.fetchall()};assert len(stored)==len(rows)
  for row in rows:
   actual=stored[row['source_key']];actual[FIELDS.index('event_date')]=str(actual[FIELDS.index('event_date')]);assert tuple(actual)==values(row)
  c.execute(f"SELECT COUNT(*) FROM {TABLE} WHERE event_type='개명'");assert c.fetchone()[0]==0
 result={'passed':True,'annual':plan['annual'],'movement_rows':len(rows),'number_change_rows':sum(r['event_type']=='등번호 변경' for r in rows),'number_updates':len(numbers['updates']),'rename_updates':len(renames['updates']),'parent_rows':len(aftermap),'requested_parent_values_verified':True,'external_non_target_column_changes':dict(external_changes),'note':'Importer UPDATE statements set only backNo, name and oldname. Concurrent non-target changes are preserved, never rolled back.','unresolved_number_identities':numbers['unresolved_number_identities'],'unresolved_renames':renames['unresolved']}
 atomic(ROOT/'final-verification.json',result);print(json.dumps({k:v for k,v in result.items() if not k.startswith('unresolved')},ensure_ascii=False),flush=True);con.close()
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--write',action='store_true');run(parser.parse_args().write)
