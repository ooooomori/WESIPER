"""Save public source HTML privately and inspect tables/forms for the import."""
import argparse
import json
import urllib.request
from pathlib import Path
from urllib.parse import quote
from lxml import html

ROOT = Path(__file__).resolve().parent.parent
CACHE = ROOT / '.player-history'
SOURCES = {
    'roster': 'https://m.namu.moe/w/울산 웨일즈/선수단',
    'wiki_pitcher': 'https://m.namu.moe/w/KBO 리그/역대 타이틀홀더/투수',
    'wiki_hitter': 'https://m.namu.moe/w/KBO 리그/역대 타이틀홀더/타자',
    'kbo_pitcher': 'https://www.koreabaseball.com/Record/History/Player/Pitcher.aspx',
    'kbo_hitter': 'https://www.koreabaseball.com/Record/History/Player/Hitter.aspx',
    'kbo_search': 'https://www.koreabaseball.com/Player/Search.aspx',
}

def fetch(url):
    request = urllib.request.Request(quote(url,safe=':/?=&%'),headers={'User-Agent':'Mozilla/5.0'})
    with urllib.request.urlopen(request,timeout=40) as response:
        return response.read()

def main():
    CACHE.mkdir(exist_ok=True)
    for key, url in SOURCES.items():
        path = CACHE / (key + '.html')
        if not path.exists(): path.write_bytes(fetch(url))
        doc = html.fromstring(path.read_bytes())
        print(key, len(path.read_bytes()))
        print('selects', [(x.get('name'), [(o.get('value'),o.text_content().strip()) for o in x.xpath('.//option')]) for x in doc.xpath('//select')])
        tables = doc.xpath('//table')
        print('tables',len(tables))
        for index, table in enumerate(tables[:4]):
            print(index, [' | '.join(x.text_content().strip() for x in row.xpath('./th|./td')) for row in table.xpath('.//tr')[:4]])

if __name__ == '__main__': main()
