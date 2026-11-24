<?php
/** Disposable real-WordPress authorization checks; private fixture is external. */
$f=json_decode(file_get_contents(getenv('AA_AUTHORS_FIXTURE')),true);
$GLOBALS['count']=0;
function aa_authors_assert($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;}
function aa_authors_actor($id,$cookie=true){unset($GLOBALS['_agent_access_pro_token_row'],$GLOBALS['wp_rest_application_password_uuid']);$_COOKIE=array();wp_set_current_user(0);wp_set_current_user($id);if($cookie)$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($id,time()+3600,'logged_in');}
$parse=new ReflectionMethod('Agent_Access_Admin','collect_scope_from_post');$parse->setAccessible(true);
$render=new ReflectionMethod('Agent_Access_Admin','render_scope_controls');$render->setAccessible(true);
$aid=$f['users']['agent'];$admin=$f['users']['admin'];
aa_authors_actor($admin);
aa_authors_assert(Agent_Access_Authors::can_assign($aid),'separate human admin can assign');
aa_authors_assert(!Agent_Access_Authors::can_assign($admin),'self grant denied');
foreach(array('1','yes',array('1')) as $value){$_POST=array('scope_assign_authors'=>$value,'scope_types'=>array('post'));$result=$parse->invoke(null,$aid);aa_authors_assert('1'===$value?!is_wp_error($result):is_wp_error($result),'strict grant value');}
$_POST=array('scope_assign_authors'=>'1','scope_full'=>'1');aa_authors_assert(array('*',Agent_Access_Authors::SCOPE)===$parse->invoke(null,$aid),'full retains separate explicit author grant');
foreach(array('__read_only__'=>'scope_read_only',Agent_Access_Yoast_SEO::SCOPE=>'scope_seo_only') as $marker=>$key){$_POST=array($key=>'1','scope_assign_authors'=>'1','scope_full'=>'1');aa_authors_assert(array($marker)===$parse->invoke(null,$aid),'restrictive mode overrides byline grant');}
$_POST=array('scope_types'=>array('post',Agent_Access_Authors::SCOPE));aa_authors_assert(is_wp_error($parse->invoke(null,$aid)),'marker cannot be smuggled as post type');
ob_start();$render->invoke(new Agent_Access_Admin(new Agent_Access_API()),array('post',Agent_Access_Authors::SCOPE),$aid);$html=ob_get_clean();aa_authors_assert(strpos($html,'name="scope_assign_authors"')!==false,'admin control rendered');aa_authors_assert(strpos($html,'value="__assign_authors__"')===false,'special marker not rendered as unknown post type');
foreach(array('read_only','seo_only') as $mode){ob_start();$render->invoke(new Agent_Access_Admin(new Agent_Access_API()),array('read_only'===$mode?'__read_only__':Agent_Access_Yoast_SEO::SCOPE),$aid);$html=ob_get_clean();aa_authors_assert((bool)preg_match('/class="agent-access-scope-authors"[^>]*disabled/',$html),'restrictive control disabled');}
$GLOBALS['_agent_access_pro_token_row']=(object)array('scope'=>'["*"]');aa_authors_assert(!Agent_Access_Authors::can_assign($aid),'Pro cannot grant');unset($GLOBALS['_agent_access_pro_token_row']);
$GLOBALS['wp_rest_application_password_uuid']='unverified';aa_authors_assert(!Agent_Access_Authors::can_assign($aid),'app password cannot grant');unset($GLOBALS['wp_rest_application_password_uuid']);
aa_authors_actor($admin,false);aa_authors_assert(!Agent_Access_Authors::can_assign($aid),'no cookie cannot grant');
aa_authors_actor($aid);$_POST=array('scope_assign_authors'=>'1');aa_authors_assert(is_wp_error($parse->invoke(null,$admin)),'agent cannot forge admin grant');
ob_start();$render->invoke(new Agent_Access_Admin(new Agent_Access_API()),array('post'),$admin);$html=ob_get_clean();aa_authors_assert(strpos($html,'name="scope_assign_authors"')===false,'agent form omits privileged switch');
$old=Agent_Access_Scope::get($aid,$f['primary_uuid']);Agent_Access_Scope::save($aid,$f['primary_uuid'],array('*',Agent_Access_Authors::SCOPE));aa_authors_assert(array('*',Agent_Access_Authors::SCOPE)===Agent_Access_Scope::get($aid,$f['primary_uuid']),'wildcard survives persisted roundtrip');Agent_Access_Scope::save($aid,$f['primary_uuid'],$old);
$agent=get_userdata($aid);
try {foreach(array('manage_options','promote_users','edit_users') as $cap)$agent->add_cap($cap);aa_authors_actor($aid);aa_authors_assert(current_user_can('manage_options'),'privileged agent fixture meaningful');aa_authors_assert(!Agent_Access_Authors::can_assign($f['users']['writer']),'privileged Agent role cannot grant to others');} finally {foreach(array('manage_options','promote_users','edit_users') as $cap)$agent->remove_cap($cap);}
echo "PASS ".$GLOBALS['count']." author admin authorization/render checks\n";
