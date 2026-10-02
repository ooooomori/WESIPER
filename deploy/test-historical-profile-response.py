"""Exercise real PHP entry point and compare historical source rows, read-only."""
import argparse,json,subprocess
from pathlib import Path

def run(root):
    fixture=root/'deploy/run-profile-api-fixture.php'
    results=[]
    for pid in (20001,70122,70121,10082,78168,77829):
        command=['sudo','/opt/bitnami/php/bin/php',str(fixture),str(root),f'pid={pid}&part=year-records&type=all']
        response=subprocess.run(command,capture_output=True,text=True,timeout=180,check=True)
        if response.stderr:raise AssertionError(response.stderr)
        data=json.loads(response.stdout)
        if data.get('error'):raise AssertionError(data['error'])
        for role in ('batter','pitcher'):
            seasons=data[role]['rows'];years=[r['year'] for r in seasons]
            if len(years)!=len(set(years)):raise AssertionError('duplicate year')
            historical=[r for r in seasons if r['year']<=2000]
            for row in historical:
                if row['granularity']!='season' or row['teams']:raise AssertionError('fabricated game/team split')
            if seasons:
                for field in (('games','wins','so') if role=='pitcher' else ('games','h','hr','sb')):
                    values=[r['stats'].get(field) for r in seasons]
                    expected=None if None in values else sum(values)
                    if data[role]['career'].get(field)!=expected:raise AssertionError('API career count mismatch')
        results.append({'pid':pid,'batter_years':[r['year'] for r in data['batter']['rows']],'pitcher_years':[r['year'] for r in data['pitcher']['rows']]})
    (root/'api-verification.json').write_text(json.dumps({'passed':True,'fixtures':results},ensure_ascii=False),encoding='utf-8')
    print(json.dumps({'passed':True,'fixtures':len(results)},ensure_ascii=False))

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('root',type=Path);a=p.parse_args();run(a.root)
