"""Cache official historical postseason HTML by year; no UI or other sources."""
import gzip
import re
import requests
from backfill_kbo_early_official import ROOT, HEADERS, clean

for series, game_id in ((3,'20011006HHOB0'), (7,'20011020OBSS0')):
    path = ROOT/'2001'/f's{series}-{game_id}-official-box-html.html.gz'
    if not path.exists():
        response = requests.get('https://www.koreabaseball.com/Futures/Schedule/BoxScore.aspx',
                                params={'leagueId':1,'seriesId':series,'seasonId':2001,'gameId':game_id},
                                headers=HEADERS,timeout=30)
        response.raise_for_status()
        response.encoding='utf-8'
        with gzip.open(path,'wt',encoding='utf-8') as stream:
            stream.write(response.text)
        print(game_id, 'final_url', response.url, flush=True)
    with gzip.open(path,'rt',encoding='utf-8') as stream:
        page=stream.read()
    tables=re.findall(r'<table\b[^>]*>(.*?)</table>',page,re.S)
    print(game_id, 'html_chars',len(page),'tables',len(tables),
          'table_text_samples',[clean(table)[:130] for table in tables[:3]],flush=True)
