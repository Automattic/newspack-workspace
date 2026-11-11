import json,subprocess,urllib.request,urllib.error,base64,sys
from pathlib import Path
root=Path('/Users/bob/.openclaw/workspace/reports/agent-access-coauthors-20261110');version=sys.argv[1] if len(sys.argv)>1 else '4.2.2'
def remote(s):
 p=subprocess.run(['python3','/tmp/aa-qa-remote.py',s],capture_output=True,text=True,timeout=120)
 if p.returncode:raise RuntimeError('QA helper failed; no automatic mutation retry')
 return p.stdout
f=json.loads(remote('cat /tmp/aa-cap-probe-20261110/private.json'));c=json.load(open('/Users/bob/.openclaw/bobswpdevsite_credentials.json'));results=[]
def control(mode,extra=''):return remote('AA_PROBE_MODE='+mode+' '+extra+' wp eval-file /tmp/aa-cap-probe-20261110/control.php')
def inspect():return json.loads(remote('wp eval-file /tmp/aa-cap-probe-20261110/inspect.php'))
def call(label,method,path,payload=None,status=200,actor='agent',authors=None,raw=None,ua='AgentAccessAuthorProbe/1.0'):
 h={'User-Agent':ua}
 if actor:
  cred=f['credentials'][actor];h['Authorization']='Basic '+base64.b64encode((cred['username']+':'+cred['password']).encode()).decode()
 if payload is not None or raw is not None:h['Content-Type']='application/json'
 data=raw if raw is not None else json.dumps(payload).encode() if payload is not None else None
 req=urllib.request.Request(c['url'].rstrip('/')+'/wp-json'+path,headers=h,data=data,method=method)
 try:
  with urllib.request.urlopen(req,timeout=30) as r:code=r.status;d=json.load(r)
 except urllib.error.HTTPError as r:code=r.code;d=json.load(r)
 assert code==status,(label,code,d)
 s=inspect();assert s.get('cap_has_term_resolver')==(not version.startswith('3.')),version;assert s['own']['all_non_author_columns_unchanged'],(label,s);assert not s['agent_edit_others_posts'] and not s['agent_edit_other_post'],(label,s)
 assert s['other']['authors'] in [['aa_cap_probe_writer'],[f.get('linked_guest_login','aa_cap_probe_writer')]],(label,s)
 if authors is not None:assert s['own']['authors']==[f.get('linked_guest_login',x) if x=='aa_cap_probe_writer' else x for x in authors],(label,s)
 results.append({'case':label,'method':method,'status':code,'code':d.get('code') if isinstance(d,dict) else None,'state':s})
 (root/('author-prototype-'+version+'.json')).write_text(json.dumps({'dependency_version':version,'cases':results,'count':len(results),'real_https':True,'prototype_only':True},indent=2))
 print(version,label,code,flush=True)
p='/agent-access-probe/v1/authors/'+str(f['post']);a=f['author_terms'];agent=['aa_cap_probe_agent']
control('reset');control('scope','AA_PROBE_SCOPE=normal');control('grant-on')
call('Final legacy adapter reads own authors','GET',p,authors=agent)
call('Final legacy adapter preserves guest order','POST',p,{'authors':[a['guest'],a['agent']]},authors=['aa-cap-probe-guest','aa_cap_probe_agent'])
call('Final legacy adapter respects post-vs-page scope','POST','/agent-access-probe/v1/authors/'+str(f['page']),{'authors':[a['guest']]},403,authors=['aa-cap-probe-guest','aa_cap_probe_agent'])
assert results[-1]['code']=='probe_post_type_scope'
print('PASS',version,len(results),'final legacy adapter checks',flush=True)
