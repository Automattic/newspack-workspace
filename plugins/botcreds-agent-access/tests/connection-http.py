"""Real HTTPS managed-credential discovery acceptance; outputs no credentials."""
import json,subprocess,pathlib,requests
REPORT=pathlib.Path('/Users/bob/.openclaw/workspace/reports/agent-access-connection-20261120')
def remote(command):
 r=subprocess.run(['python3','/tmp/aa-user-remote.py',command],capture_output=True,text=True,timeout=180)
 if r.returncode: raise RuntimeError('remote helper failed')
 return r.stdout
f=json.loads(remote('cat /tmp/aa-connection-20261120/private.json'));base='https://bobswpdevsite.wpcomstaging.com';path='/wp-json/agent-access/v1/connection';passed=[]
def check(ok,label):
 if not ok: raise AssertionError(label)
 passed.append(label);print('PASS',label,flush=True)
def req(method='GET',actor='primary',suffix='',**kwargs):
 auth=tuple(f['credentials'][actor][k] for k in ('username','password')) if actor else None
 return requests.request(method,base+path+suffix,auth=auth,timeout=35,allow_redirects=False,**kwargs)
r=req(actor=None);check(r.status_code==401,'anonymous request denied');check('no-store' in r.headers.get('Cache-Control',''),'anonymous error not cacheable')
for actor,mode in [('primary','read_only'),('secondary','yoast_seo_only')]:
 r=req(actor=actor);check(r.status_code==200,'managed '+actor+' authenticated');d=r.json()
 check(d['identity']['id']==f['users']['agent'] and d['scope']['mode']==mode,'own identity and credential-specific scope '+actor)
 check(d['plugin_version']=='2.4.4' and d['schema_version']==1,'version/schema '+actor)
 check(d['rate_limit']['tier']=='unlimited' and d['rate_limit']['per_hour']==0,'configured rate ceilings '+actor)
 check('private' in r.headers.get('Cache-Control','') and 'no-store' in r.headers.get('Cache-Control',''),'successful response private '+actor)
 check(not any(c['password'] in r.text or c['uuid'] in r.text for c in f['credentials'].values()) and 'user_email' not in r.text,'no password, UUID or email disclosure '+actor)
check(d['additional_permissions']['list_users'] is True,'explicit directory grant reported independently of SEO')
check(req('HEAD').status_code==200,'HEAD discovery supported')
r=req(suffix='?user_id='+str(f['users']['admin'])+'&uuid='+f['credentials']['secondary']['uuid']);check(r.json()['identity']['id']==f['users']['agent'] and r.json()['scope']['mode']=='read_only','selectors cannot inspect another identity or credential')
for actor in ['manual',None]:
 r=req(actor=actor,headers={'X-MCP-Client':'test','User-Agent':'wordpress.com-mcp/1.0'});check(r.status_code in (401,403),'spoofed audit headers do not grant discovery '+str(actor))
r=req(actor='manual');check(r.status_code==403 and r.json()['code']=='agent_connection_credential_required','authenticated unmanaged credential explicitly distinguished')
r=req(actor=None,headers={'Cookie':f['cookie'],'X-WP-Nonce':f['nonce']});check(r.status_code==403 and r.json()['code']=='agent_connection_credential_required','real authenticated administrator cookie is not a managed password')
for method in ['POST','PUT','PATCH','DELETE']:
 r=req(method,json={'scope':['*']});check(r.status_code==403 and r.json()['code']=='agent_scope_violation','read-only credential rejects discovery write '+method)
r=req('POST',actor='secondary',json={'title':'not permitted'});check(r.status_code==403 and r.json()['code']=='agent_scope_violation','SEO cannot write discovery route')
s=json.loads(remote('wp eval-file /tmp/aa-connection-20261120/fixture.php inspect'));check(all(s.values()),'scope metadata, stored user caps and role unchanged')
remote('wp eval-file /tmp/aa-connection-20261120/fixture.php pending')
r=req();check(r.status_code==200 and r.json()['approval_status']=='pending','pending credential can diagnose its approval status')
remote('wp eval-file /tmp/aa-connection-20261120/fixture.php full')
r=req(actor='secondary');check(r.status_code==200 and r.json()['scope']['mode']=='full' and not r.json()['additional_permissions']['list_users'],'changed scope visible immediately with unchanged password')
r=req('POST',actor='secondary',json={'scope':['*']});check(r.status_code==404 and r.json()['code']=='rest_no_route','wildcard credential cannot write read-only endpoint')
remote('wp eval-file /tmp/aa-connection-20261120/fixture.php revoke');check(req().status_code in (401,403),'revoked password cannot discover')
REPORT.joinpath('http-results.json').write_text(json.dumps({'passed':len(passed),'cases':passed},indent=2))
