"""Extract linked people from the supplied family page without DB writes."""
from html.parser import HTMLParser
import json,re
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CACHE=ROOT/'.family-enrichment'


class Tables(HTMLParser):
    def __init__(self):
        super().__init__();self.stack=[];self.tables=[];self.link=None
    def handle_starttag(self,tag,attrs):
        attrs=dict(attrs)
        if tag=='table':self.stack.append({'rows':[],'cell':None})
        elif self.stack:
            current=self.stack[-1]
            if tag=='tr':current['row']=[]
            elif tag in ('td','th'):current['cell']={'text':'','links':[],'rowspan':int(attrs.get('rowspan',1)),'colspan':int(attrs.get('colspan',1))}
            elif tag=='a' and current['cell'] is not None:
                self.link={'href':attrs.get('href',''),'text':''};current['cell']['links'].append(self.link)
            elif tag=='br' and current['cell'] is not None:current['cell']['text']+='\n'
    def handle_data(self,data):
        if self.stack and self.stack[-1]['cell'] is not None:
            self.stack[-1]['cell']['text']+=data
            if self.link is not None:self.link['text']+=data
    def handle_endtag(self,tag):
        if tag=='a':self.link=None
        if not self.stack:return
        current=self.stack[-1]
        if tag in ('td','th') and current['cell'] is not None:
            cell=current['cell'];cell['text']=re.sub(r'[ \t]+',' ',cell['text']).strip()
            current.setdefault('row',[]).append(cell);current['cell']=None
        elif tag=='tr':current['rows'].append(current.get('row',[]))
        elif tag=='table':self.tables.append(self.stack.pop())


p=Tables();p.feed((CACHE/'namu.wiki.html').read_text(encoding='utf-8'))
out=[{'table':i,'rows':t['rows']} for i,t in enumerate(p.tables)]
(CACHE/'wiki-tables.json').write_text(json.dumps(out,ensure_ascii=False,indent=2),encoding='utf-8')
(CACHE/'wiki-table-review.txt').write_text('\n\n'.join('TABLE '+str(t['table'])+'\n'+'\n'.join(' | '.join(c['text']+' [LINKS: '+', '.join(a['text']+'='+a['href'] for a in c['links'])+']' for c in row) for row in t['rows']) for t in out),encoding='utf-8')
print('tables',len(out))
