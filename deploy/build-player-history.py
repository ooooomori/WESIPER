"""Extract requested factual tables, keeping tied winners as separate records."""
import json
import re
from collections import Counter
from pathlib import Path
from lxml import html

ROOT = Path(__file__).resolve().parent.parent
CACHE = ROOT / '.player-history'

def text(cell):
    copy = html.fromstring(html.tostring(cell))
    for marker in copy.xpath('.//a[contains(@href,"#fn")]'):
        marker.drop_tree()
    return re.sub(r'\[\d+\]','', ' '.join(copy.itertext())).strip()

def expanded(table):
    grid, spans = [], {}
    for row in table.xpath('./tr|./tbody/tr|./thead/tr'):
        cells, col = [], 0
        for cell in row.xpath('./td|./th'):
            while col in spans:
                value, left = spans[col]
                cells.append(value)
                if left == 1: del spans[col]
                else: spans[col] = (value,left-1)
                col += 1
            value = text(cell)
            for _ in range(int(cell.get('colspan',1))):
                cells.append(value)
                if int(cell.get('rowspan',1)) > 1: spans[col] = (value,int(cell.get('rowspan'))-1)
                col += 1
        while col in spans:
            value,left = spans[col];cells.append(value)
            if left == 1: del spans[col]
            else: spans[col] = (value,left-1)
            col += 1
        grid.append(cells)
    return grid

def build_titles():
    types = {'wiki_pitcher':['다승','평균자책점','탈삼진','세이브','홀드','승률','세이브포인트'],
             'wiki_hitter':['타율','안타','홈런','타점','득점','도루','출루율','장타율','승리타점']}
    records = []
    for key, categories in types.items():
        doc = html.fromstring((CACHE/(key+'.html')).read_bytes())
        tables = [expanded(t) for t in doc.xpath('//table')]
        annual = [rows for rows in tables if rows and '연도' in rows[0][0]]
        # The eighth pitcher table is explicitly unofficial save points (2004+).
        for category, rows in zip(categories,annual):
            for row in rows[1:]:
                if '연도' in row[0]: continue
                if len(set(row)) == 1 and not re.fullmatch(r'\d{4}',row[0]): continue
                if len(row)!=5 or not re.fullmatch(r'\d{4}',row[0]): raise ValueError((category,row))
                year = int(row[0])
                match = re.match(r'\s*(\d+(?:\.\d+)?)',row[3])
                if not match: raise ValueError((category,row))
                records.append({'year':year,'type':category,'name':row[1],'team':row[2], 'record':match[1], 'source':'https://namu.wiki/w/KBO 리그/역대 타이틀홀더/'+('투수' if key.endswith('pitcher') else '타자')})
    (CACHE/'titles-extracted.json').write_text(json.dumps(records,ensure_ascii=False,indent=2),encoding='utf-8')
    print('Title records',len(records),dict(Counter(x['type'] for x in records)))

def build_roster():
    doc = html.fromstring((CACHE/'roster.html').read_bytes())
    tables = doc.xpath('//table')
    roster=[]
    for table,position in zip(tables[2:6],['투수','포수','내야수','외야수']):
        for row in expanded(table)[1:]:
            if len(row)!=8: raise ValueError(row)
            number,name,draft,joined,birth,hands,body,note=row
            m=re.fullmatch(r'(\d+)년 (\d+)월 (\d+)일',birth)
            if not m: raise ValueError(birth)
            roster.append({'name':name,'birth':'%04d-%02d-%02d'%tuple(map(int,m.groups())),'backNo':number,'pos':position,'hands':hands,'body':body,'draft_source':draft,'note':note,'military':False})
    for row in expanded(tables[6])[1:]:
        name,draft,joined,position,hands,birth,body,service,enlisted,discharged=row
        m=re.fullmatch(r'(\d+)년 (\d+)월 (\d+)일',birth)
        roster.append({'name':name,'birth':'%04d-%02d-%02d'%tuple(map(int,m.groups())),'backNo':None,'pos':position,'hands':hands,'body':body,'draft_source':draft,'military':True})
    (CACHE/'roster-extracted.json').write_text(json.dumps(roster,ensure_ascii=False,indent=2),encoding='utf-8')
    print('Roster',len(roster),dict(Counter(x['pos'] for x in roster)))

if __name__=='__main__':
    import sys
    sys.stdout.reconfigure(encoding='utf-8')
    build_titles();build_roster()
