"""Real HTTPS directory grant tests on disposable WordPress QA; no secrets output."""
import json,subprocess,pathlib,requests,os
REPORT=pathlib.Path(os.environ['AA_USERS_REPORT_DIR']);REMOTE=os.environ['AA_USERS_REMOTE_HELPER']
def remote(s):
 r=subprocess.run(['python3',REMOTE,s],capture_output=True,text=True,timeout=180)
 if r.returncode:raise RuntimeError('remote QA helper failed')
 return r.stdout
f=json.loads(remote('cat /tmp/aa-users-20261119/private.json'));base='https://bobswpdevsite.wpcomstaging.com';results=[]
def check(ok,label):
 if not ok:raise AssertionError(label)
 results.append(label);print('PASS',label,flush=True)
def req(method,path,payload=None,actor='primary',**kw):
 auth=tuple(f['credentials'][actor][k] for k in ('username','password')) if actor else None
 return requests.request(method,base+'/wp-json'+path,json=payload,auth=auth,timeout=35,allow_redirects=False,**kw)
admin=requests.Session();admin.headers['Cookie']=f['cookie']
def save(extra):
 data={'action':'agent_access_admin_update','nonce':f['nonce'],'user_id':f['users']['agent'],'scope_read_only':'1','scope_list_users':'0','rate_limit':'unlimited','content_policy':'standard'};data.update(extra)
 return admin.post(base+'/wp-admin/admin-ajax.php',data=data,timeout=35)
def state():return json.loads(remote('wp eval-file /tmp/aa-users-20261119/fixture.php inspect'))
u='/wp/v2/users';target=u+'/'+str(f['users']['hidden']);own=u+'/'+str(f['users']['agent'])
check(req('GET',u+'?context=edit').status_code==403,'edit-context directory denied by default')
bad=save({'nonce':'invalid','scope_list_users':'1'});check(bad.status_code==403,'actual admin AJAX invalid nonce denied')
check(save({'scope_list_users':'yes'}).json()['success'] is False,'malformed grant denied')
check(save({'scope_list_users[]':'1','scope_list_users':None}).json()['success'] is False,'array grant denied')
check(state()['scope']==['__read_only__'],'invalid saves did not mutate scope')
check(save({'scope_list_users':'1'}).json()['success'] is True,'real admin cookie save enables list grant')
check(state()['scope']==['__read_only__','__list_users__'],'saved grant and read-only round trip')
r=req('GET',u+'?context=view&per_page=1');check(r.status_code==200 and len(r.json())==1 and int(r.headers['X-WP-Total'])>=3 and int(r.headers['X-WP-TotalPages'])>=3,'native directory pagination and full totals')
r=req('GET',u+'?context=view&search='+f['credentials']['primary']['username']);check(r.status_code==200 and len(r.json())==1 and int(r.headers['X-WP-Total'])==1,'search and matching count')
r=req('GET',target+'?context=view');check(r.status_code==200 and r.json()['id']==f['users']['hidden'] and 'email' not in r.json() and 'roles' not in r.json(),'unpublished subscriber profile without private fields');check(req('GET',target+'?context=edit').status_code==403,'private profile edit context remains denied')
check(req('HEAD',u+'?context=edit').status_code==200,'HEAD directory request')
r=req('GET',u+'/me?context=edit');check(r.status_code==200 and r.json()['capabilities'].get('list_users') is True and not r.json()['capabilities'].get('edit_users',False),'native self capability discovery advertises list but not user editing')
for actor in ('secondary','manual',None):check(req('GET',u+'?context=edit',actor=actor).status_code in (401,403),'no grant for '+str(actor))
remote('wp eval-file /tmp/aa-users-20261119/fixture.php scope-secondary');check(req('GET',u+'?context=edit',actor='secondary').status_code==403,'full write access is not directory permission')
for method,path,payload in [('POST',u,{'username':'aa_directory_should_not_exist','email':'no-create@example.invalid','password':'not-a-real-credential'}),('POST',target,{'name':'DO NOT CHANGE'}),('PUT',target,{'roles':['administrator']}),('PATCH',target,{'password':'DO NOT CHANGE'}),('DELETE',target+'?force=true&reassign='+str(f['users']['admin']),None),('POST',own,{'name':'DO NOT CHANGE'}),('POST','/wp/v2/posts/'+str(f['post']),{'content':'DO NOT CHANGE'})]:
 r=req(method,path,payload);check(r.status_code==403 and r.json()['code']=='agent_scope_violation',method+' '+path+' blocked by read-only scope')
for method,path,payload in [('POST',target,{'name':'DO NOT CHANGE'}),('POST',u,{'username':'aa_directory_should_not_exist','email':'no-create@example.invalid','password':'not-a-real-credential'}),('DELETE',target+'?force=true&reassign='+str(f['users']['admin']),None)]:check(req(method,path,payload,actor='secondary').status_code==403,'native user-write capability denial despite wildcard '+method)
# SEO-only can keep its independent read grant.
check(save({'scope_read_only':None,'scope_seo_only':'1','scope_list_users':'1'}).json()['success'] is True,'SEO-only plus list grant saved')
check(req('GET',u+'?context=view').status_code==200,'directory works alongside SEO-only')
check(req('POST',target,{'name':'DO NOT CHANGE'}).status_code==403,'SEO-only still denies user writes')
# Full write plus directory must not create new native user-management authority.
check(save({'scope_read_only':None,'scope_full':'1','scope_list_users':'1'}).json()['success'] is True,'full plus explicit directory grant saved')
for method,path,payload in [('POST',target,{'name':'DO NOT CHANGE'}),('POST',u,{'username':'aa_directory_should_not_exist','email':'no-create@example.invalid','password':'not-a-real-credential'}),('DELETE',target+'?force=true&reassign='+str(f['users']['admin']),None)]:
 r=req(method,path,payload);check(r.status_code==403 and r.json()['code']!='agent_scope_violation','directory grant adds no native user-write cap '+method)
check(save({'scope_list_users':'0'}).json()['success'] is True,'actual admin revoke save')
check(req('GET',u+'?context=edit').status_code==403,'revocation immediate with unchanged password')
check(save({'scope_list_users':'1'}).json()['success'] is True,'regrant using unchanged password')
check(req('GET',u+'?context=view',headers={'User-Agent':'wordpress.com-mcp/1.0','X-MCP-Client':'test'}).status_code==200,'audit headers do not change native credential authorization')
s=state();check(all(s[k] for k in ('rows_unchanged','caps_unchanged','role_unchanged')) and not any(s[k] for k in ('list_users_outside_rest','manage_options','edit_users','create_users','delete_users')),'user records, stored caps and Agent role unchanged; no ambient grant')
remote('wp eval-file /tmp/aa-users-20261119/fixture.php revoke');check(req('GET',u+'?context=edit').status_code in (401,403),'revoked application password loses directory access')
(REPORT/'http-results.json').write_text(json.dumps({'passed':len(results),'cases':results,'boundary':'real HTTPS; actual admin-cookie AJAX grant save; isolated QA users and passwords'},indent=2))
