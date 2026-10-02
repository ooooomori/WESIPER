from concurrent.futures import ThreadPoolExecutor
from backfill_futures_history import ROOT,cached
import re
def read(n):
 page=cached(f'https://www.koreabaseball.com/Schedule/Allstar/News.aspx?bdSe={n}',ROOT/'2018'/'allstar-identity'/f'news-{n}.html.gz')
 text=re.sub('<[^>]+>',' ',page);text=re.sub(r'\s+',' ',text)
 if '퓨처스' in text and ('입단' in text or '김태형(L)' in text or '김태형(엘' in text):
  for word in ('입단','김태형(L)','김태형(엘'):
   i=text.find(word)
   if i>=0:print(n,text[max(0,i-180):i+280],flush=True)
with ThreadPoolExecutor(max_workers=4) as pool:list(pool.map(read,range(7070,7097)))
