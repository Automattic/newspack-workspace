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

records=[]
def req(actor,path,payload):
 cred=f['credentials'][actor];h={'Authorization':'Basic '+base64.b64encode((cred['username']+':'+cred['password']).encode()).decode(),'Content-Type':'application/json','User-Agent':'AgentAccessAuthorProbe/1.0'}
 q=urllib.request.Request(c['url'].rstrip('/')+'/wp-json'+path,headers=h,data=json.dumps(payload).encode(),method='POST')
 with urllib.request.urlopen(q,timeout=30) as r:d=json.load(r);return r.status
other='/coauthors/v1/authors/'+str(f['other']);core='/wp/v2/posts/'+str(f['other'])
assert not inspect()['agent_edit_other_post']
try:
 assert req('admin',other,{'new_authors':'aa_cap_probe_writer,aa_cap_probe_agent'})==200
 s=inspect();assert s['agent_edit_other_post'] and not s['agent_edit_others_posts']
 assert req('agent',core,{'content':'Untouched other content'})==200
 records.append({'existing_cap_coauthor_effect':'adding agent as coauthor grants this post edit without edit_others_posts','unchanged_content_probe_status':200,'edit_others_posts':False})
finally:
 assert req('admin',other,{'new_authors':'aa_cap_probe_writer'})==200
assert not inspect()['agent_edit_other_post']
(root/'cap-existing-ownership-effect.json').write_text(json.dumps({'records':records,'restored_original_byline':True},indent=2))
print('Existing CAP behavior verified: co-author gains post editing without broad role cap; original byline restored.')
