"""Compare every committed season value with its validated official source."""
import gzip, hashlib, json
from datetime import datetime
from decimal import Decimal
from collections import Counter
import requests
from collect_official_old_seasons import ROOT, atomic
from old_season_totals_schema import TABLES, columns
from backfill_kbo_early_official import connect

KEY = ('league_level','year','series_id','player_id','row_scope','filter_team_code')

def normalized(value):
    if isinstance(value, Decimal): return str(value.normalize())
    if isinstance(value, datetime): return value.strftime('%Y-%m-%d %H:%M:%S')
    return value

def run():
    report=json.loads((ROOT/'validation-report.json').read_text())
    expected={role:{} for role in TABLES}; sources={}; coverage=[]
    for relative in report['plans'].values():
        plan=json.loads((ROOT/relative).read_text()); role=plan['role']
        manifest=json.loads((ROOT/plan['manifest']).read_text())
        if manifest['row_scope']=='total':
            coverage.append({k:manifest.get(k) for k in ('year','role','series_id','coverage_status','headers')})
        for row in plan['rows']:
            key=tuple(row[k] for k in KEY)
            if key in expected[role]: raise ValueError('duplicate planned identity')
            expected[role][key]=row
            path=row['source_file']; sha=row['source_sha256']
            if path in sources and sources[path]!=sha: raise ValueError('source hash conflict')
            sources[path]=sha
    supplement=ROOT/'rate-supplement';receipt=supplement/'write-receipt.json'
    if receipt.exists():
        committed=set(json.loads(receipt.read_text()))
        for addition in json.loads((supplement/'plan.json').read_text()):
            key=f"{addition['year']}/{addition['series_id']}/{addition['player_id']}"
            if key not in committed: continue
            for row in expected['Hitter'].values():
                if (row['year'],row['series_id'],row['player_id'])!=(addition['year'],addition['series_id'],addition['player_id']): continue
                for field in ('r','sh','sf','tb','ibb','obp','slg','ops'): row[field]=addition[field]
                raw=json.loads(row['raw_record']);raw['_rate_supplement']=addition['sources'];row['raw_record']=json.dumps(raw,ensure_ascii=False)
            for source in addition['sources']:
                sources[source['source_file']]=source['source_sha256']
    for path,sha in sources.items():
        with gzip.open(ROOT/path,'rb') as f: page=f.read().decode('utf-8')
        if hashlib.sha256(page.encode()).hexdigest()!=sha: raise ValueError('cached official source changed: '+path)
    con=connect(); checked={}; details={}
    try:
        with con.cursor() as c:
            c.execute('SET SESSION max_statement_time=30')
            for role,table in TABLES.items():
                fields=list(columns(role))
                c.execute('SELECT '+','.join('`'+k+'`' for k in fields)+' FROM `'+table+'`')
                actual=c.fetchall(); seen=set()
                for values in actual:
                    row=dict(zip(fields,values)); key=tuple(row[k] for k in KEY)
                    if key in seen or key not in expected[role]: raise ValueError('unexpected/duplicate stored identity')
                    seen.add(key); planned=expected[role][key]
                    for field,definition in columns(role).items():
                        left=row[field]; right=planned[field]
                        if definition.startswith('DECIMAL') and right is not None: right=Decimal(right)
                        if normalized(left)!=normalized(right): raise ValueError(f'stored value differs: {role} {key} {field}')
                if seen!=set(expected[role]): raise ValueError('planned records missing from DB')
                checked[role]=len(actual)
            c.execute('SELECT @@innodb_buffer_pool_size,@@event_scheduler'); memory,event=c.fetchone()
            details={'innodb_buffer_pool_size':memory,'event_scheduler':event}
            c.execute('SELECT player_id,name,pos,is_kbodle FROM kbo_player_data WHERE player_id=20001')
            details['new_parent_20001']=list(c.fetchone())
    finally: con.close()
    health={}
    for endpoint in ('todayGames.php','teamRank.php'):
        r=requests.get('https://wesiper.xyz/api/'+endpoint,timeout=30); r.raise_for_status(); data=r.json()
        if isinstance(data,dict) and (data.get('error') or data.get('success') is False): raise ValueError('operating API error: '+endpoint)
        health[endpoint]={'http_status':r.status_code,'valid_json':True}
    summary={'compared_rows':checked,'source_pages_sha256_verified':len(sources),'mismatches':0,'coverage':coverage,'db_runtime':details,'api_health':health}
    atomic(ROOT/'final-verification.json',summary)
    print(json.dumps({k:v for k,v in summary.items() if k!='coverage'},ensure_ascii=False))

if __name__=='__main__': run()
