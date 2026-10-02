import re,html
import kbo_futures_crawl as base
def attrs(text):return {m[1]:html.unescape(m[3]) for m in re.finditer(r'([\w:$-]+)\s*=\s*([\"\'])(.*?)\2',text,re.S)}
def selects(page):
 out={}
 for raw,body in re.findall(r'<select\b([^>]*)>(.*?)</select>',page,re.I|re.S):
  a=attrs(raw);options=[]
  for rawopt,text in re.findall(r'<option\b([^>]*)>(.*?)</option>',body,re.I|re.S):
   o=attrs(rawopt);options.append({'value':o.get('value',''),'text':base.clean(text),'selected':bool(re.search(r'\bselected\b',rawopt))})
  out[a['name']]={'attrs':a,'options':options,'value':next((o['value'] for o in options if o['selected']),options[0]['value'] if options else '')}
 return out
def form(page,target,values=None):
 data={}
 for raw in re.findall(r'<input\b([^>]*)>',page,re.I|re.S):
  a=attrs(raw)
  if a.get('name') and a.get('type','').lower()=='hidden':data[a['name']]=a.get('value','')
 data.update({k:v['value'] for k,v in selects(page).items()});data.update(values or {});data.update(__EVENTTARGET=target,__EVENTARGUMENT='');return data
def pager(page):
 out=[]
 for raw,body in re.findall(r'<a\b([^>]*)>(.*?)</a>',page,re.I|re.S):
  a=attrs(raw);m=re.search(r"__doPostBack\('([^']*)','([^']*)'\)",a.get('href',''))
  if m and 'ucPager' in m[1]:out.append({'target':m[1],'text':base.clean(body),'active':'on' in a.get('class','').split()})
 return out
def record_table(page):
 tables=[t for t in base.parse_tables(page) if t['rows'] and '선수명' in base.row_values(t['rows'][0])]
 if len(tables)!=1:raise ValueError('expected one official player statistics table')
 return tables[0]
