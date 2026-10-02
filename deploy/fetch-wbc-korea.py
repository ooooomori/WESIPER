"""Read official KBO tournament roster tables without guessing missing years."""
from pathlib import Path
import urllib.request,json,re
from lxml import html
ROOT=Path(__file__).resolve().parent.parent
CACHE=ROOT/'.player-career'
def main():
 result=[]
 for year in [2006,2009,2013,2017,2023,2026]:
  page=f'Schedule{year}' if year==2013 else f'Main{year}'
  url=f'https://www.koreabaseball.com/Schedule/International/Wbc/{page}.aspx'
  path=CACHE/f'wbc-kbo-{page}.html'
  if not path.exists():
   try:
    with urllib.request.urlopen(url,timeout=45) as r:path.write_bytes(r.read())
   except Exception as e:print(year,str(e));continue
  tree=html.fromstring(path.read_bytes());text=' '.join(tree.itertext())
  print(year,'heads',tree.xpath('//h4//text()'),'tables',len(tree.xpath('//table')))
  for table in tree.xpath('//table'):
   headers=' '.join(table.xpath('.//th//text()'))
   if '선수명' in headers:
    for tr in table.xpath('.//tbody/tr'):
     for cell in tr.xpath('./td'):
      for name in re.findall(r'([가-힣 ]{2,5})\(',''.join(cell.itertext())):
       result.append(dict(year=year,name=name.strip().replace(' ',''),source=url))
    continue
   if '성명' not in headers:continue
   for tr in table.xpath('.//tbody/tr'):
    cells=[' '.join(' '.join(td.itertext()).split()) for td in tr.xpath('./td')]
    if len(cells)!=4 or cells[0] in ['감독','코치','합계']:continue
    result.append(dict(year=year,name=cells[1].replace(' ',''),position=cells[0],team=cells[2],source=url))
  print(year,[r['name'] for r in result if r['year']==year])
 (CACHE/'wbc-kbo-rosters.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
if __name__=='__main__':main()
