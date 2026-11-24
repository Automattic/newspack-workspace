import json,subprocess,urllib.request,urllib.error,base64
from pathlib import Path
f=json.loads(subprocess.check_output(['python3','/tmp/aa-qa-remote.py','cat /tmp/aa-cap-probe-20261110/private.json'],text=True));c=json.load(open('/Users/bob/.openclaw/bobswpdevsite_credentials.json'));cred=f['credentials']['agent'];out=[]
for path,payload,status in [('/agent-access-probe/v1/authors/'+str(f['post']),{'authors':[f['author_terms']['guest']]},503),('/wp/v2/posts/'+str(f['other']),{'content':'Untouched other content'},403)]:
 h={'Authorization':'Basic '+base64.b64encode((cred['username']+':'+cred['password']).encode()).decode(),'Content-Type':'application/json','User-Agent':'AgentAccessAuthorProbe/1.0'}
 q=urllib.request.Request(c['url'].rstrip('/')+'/wp-json'+path,headers=h,data=json.dumps(payload).encode(),method='POST')
 try:
  with urllib.request.urlopen(q,timeout=30) as r:code=r.status;d=json.load(r)
 except urllib.error.HTTPError as r:code=r.code;d=json.load(r)
 assert code==status,(path,code,d);out.append({'path':path,'status':code,'code':d.get('code')})
Path('/Users/bob/.openclaw/workspace/reports/agent-access-coauthors-20261110/prototype-dependency-disabled.json').write_text(json.dumps(out,indent=2));print('Dependency disabled: author probe503; unrelated core edit403.')
