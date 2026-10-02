"""Cache public KBO awards and the two spreadsheet URLs supplied by the user."""
import csv
import io
import json
import re
import urllib.request
import urllib.error
from pathlib import Path
from lxml import html

ROOT=Path(__file__).resolve().parent.parent
CACHE=ROOT/'.player-career'
CACHE.mkdir(exist_ok=True)

def fetch(url,path):
 if path.exists():return path.read_bytes()
 req=urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'})
 with urllib.request.urlopen(req,timeout=45) as response:data=response.read()
 path.write_bytes(data)
 return data

def main():
 for page in ['PlayerPrize','GoldenGlove','DefensePrize','SeriesPrize']:
  data=fetch('https://www.koreabaseball.com/Player/Awards/'+page+'.aspx',CACHE/(page+'.html'))
  tree=html.fromstring(data)
  tables=[[[' '.join(c.itertext()).strip() for c in r.xpath('./th|./td')] for r in t.xpath('.//tr')] for t in tree.xpath('//table')]
  print(page,json.dumps(tables,ensure_ascii=False)[:1800])
 for sid in ['1kz0EGnWSeUljsjTD9WxLfDm4dg4_5lLOcYjIxu4squQ','1ZfyRUmJiGebATvBDiQFV0KrEc-vnkaZYwEyHGsn7Dsk']:
  try:
   data=fetch('https://docs.google.com/spreadsheets/d/'+sid+'/export?format=csv&gid=0',CACHE/(sid+'.csv'))
   rows=list(csv.reader(io.StringIO(data.decode('utf-8-sig'))))
   print('SHEET',sid,'ROWS',len(rows),'SAMPLE',json.dumps(rows[:8],ensure_ascii=False)[:2500])
   view=fetch('https://docs.google.com/spreadsheets/d/'+sid+'/htmlview',CACHE/(sid+'.html'))
   tree=html.fromstring(view)
   tabs=re.findall(r'items.push\(\{name: "([^"]+)",.*?gid: "(\d+)"',view.decode('utf-8'))
   print('TABS',sid,tabs)
   for tab,gid in tabs:
    if gid=='0':continue
    extra=fetch('https://docs.google.com/spreadsheets/d/'+sid+'/export?format=csv&gid='+gid,CACHE/(sid+'-'+gid+'.csv'))
    erows=list(csv.reader(io.StringIO(extra.decode('utf-8-sig'))))
    print('EXTRA',tab,len(erows),'HEADER',erows[0])
  except urllib.error.HTTPError as error:print('SHEET',sid,'HTTP',error.code)

if __name__=='__main__':main()
