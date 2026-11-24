<?php
/** Real WP dispatcher/admin-grant boundaries; auth context fixture is CLI synthetic. */
if(!defined('REST_REQUEST'))define('REST_REQUEST',true);
$f=json_decode(file_get_contents('/tmp/aa-users-20261119/private.json'),true);$GLOBALS['ud_count']=0;$agent=$f['users']['agent'];$uuid=$f['credentials']['secondary']['uuid'];$before=Agent_Access_Scope::get($agent,$uuid);
function ud_check($ok,$label){if(!$ok)throw new RuntimeException($label);++$GLOBALS['ud_count'];}
function ud_actor($id,$uuid=null,$cookie=false){unset($GLOBALS['_agent_access_pro_token_row'],$GLOBALS['wp_rest_application_password_uuid']);$_COOKIE=array();wp_set_current_user(0);wp_set_current_user($id);if($uuid)$GLOBALS['wp_rest_application_password_uuid']=$uuid;if($cookie)$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($id,time()+3600,'logged_in');}
$parse=new ReflectionMethod('Agent_Access_Admin','collect_scope_from_post');$parse->setAccessible(true);
$render=new ReflectionMethod('Agent_Access_Admin','render_scope_controls');$render->setAccessible(true);
try{
 ud_actor($f['users']['admin'],null,true);
 ud_check(Agent_Access_User_Directory::can_assign($agent),'admin cookie may assign');
 foreach(array('yes',array('1'),1) as $v){$_POST=array('scope_list_users'=>$v);ud_check(is_wp_error($parse->invoke(null,$agent)),'malformed list grant rejected');}
 foreach(array('scope_read_only'=>'__read_only__','scope_seo_only'=>Agent_Access_Yoast_SEO::SCOPE,'scope_full'=>'*') as $field=>$marker){$_POST=array($field=>'1','scope_list_users'=>'1');ud_check(array($marker,Agent_Access_User_Directory::SCOPE)===$parse->invoke(null,$agent),'read permission preserved across modes');}
 $_POST=array('scope_list_users'=>'1');ud_check(array('__read_only__',Agent_Access_User_Directory::SCOPE)===$parse->invoke(null,$agent),'only directory selection never permits content writes');
 $_POST=array('scope_types'=>array(Agent_Access_User_Directory::SCOPE));ud_check(is_wp_error($parse->invoke(null,$agent)),'cannot smuggle marker as content type');
 $_POST=array('scope_custom_routes'=>'/wp/v2/users');ud_check(is_wp_error($parse->invoke(null,$agent)),'core custom-route bypass still rejected');
 ud_check(!Agent_Access_User_Directory::can_assign($f['users']['admin']),'self grant denied');
 ud_actor($agent);$_POST=array('scope_list_users'=>'1');ud_check(is_wp_error($parse->invoke(null,$agent)),'agent cannot self-grant');
 ud_actor($f['users']['admin']);$_POST=array('scope_list_users'=>'1');ud_check(is_wp_error($parse->invoke(null,$agent)),'no-cookie CLI actor cannot grant');
 ud_actor($f['users']['admin'],null,true);ob_start();$render->invoke(new Agent_Access_Admin(new Agent_Access_API()),array('__read_only__',Agent_Access_User_Directory::SCOPE),$agent);$html=ob_get_clean();ud_check(strpos($html,'name="scope_list_users"')!==false,'admin directory control rendered');ud_check(strpos($html,'value="__list_users__"')===false,'marker not rendered as unknown content type');ud_check(strpos($html,'<tr>')===false,'grouped controls do not insert table rows inside checklist');
 Agent_Access_Scope::save($agent,$uuid,array('__read_only__',Agent_Access_User_Directory::SCOPE));
 ud_actor($f['users']['admin'],null,true);$_POST=array('scope_read_only'=>'1');ud_check(array('__read_only__',Agent_Access_User_Directory::SCOPE)===$parse->invoke(null,$agent),'older form preserves existing directory grant');$_POST=array('scope_read_only'=>'1','scope_list_users'=>'0');ud_check(array('__read_only__')===$parse->invoke(null,$agent),'explicit unchecked control revokes directory grant');
 ud_actor($agent);ob_start();$render->invoke(new Agent_Access_Admin(new Agent_Access_API()),array('__read_only__',Agent_Access_User_Directory::SCOPE),$agent);$own_html=ob_get_clean();ud_check(strpos($own_html,'name="scope_list_users"')===false,'own-profile form does not offer self-grant');
 ud_actor($agent,$uuid);$request=new WP_REST_Request('GET','/wp/v2/users');Agent_Access_User_Directory::begin_request(null,array(),$request);
 ud_check(current_user_can('list_users'),'verified UUID users-read transient grant');
 ud_check(!current_user_can('edit_users')&&!current_user_can('create_users')&&!current_user_can('delete_users')&&!current_user_can('promote_users'),'only list_users added');
 $nested=new WP_REST_Request('GET','/wp/v2/posts');Agent_Access_User_Directory::begin_request(null,array(),$nested);ud_check(!current_user_can('list_users'),'nested unrelated request does not inherit grant');Agent_Access_User_Directory::end_request(null,array(),$nested);ud_check(current_user_can('list_users'),'outer request grant restored');Agent_Access_User_Directory::end_request(null,array(),$request);ud_check(!current_user_can('list_users'),'grant removed after callback');
 foreach(array(array('POST','/wp/v2/users'),array('GET','/wp/v2/users/1/application-passwords'),array('GET','/wp/v2/users-extra'),array('OPTIONS','/wp/v2/users')) as $probe){$r=new WP_REST_Request($probe[0],$probe[1]);Agent_Access_User_Directory::begin_request(null,array(),$r);ud_check(!current_user_can('list_users'),'no grant on unrelated/mutation route');Agent_Access_User_Directory::end_request(null,array(),$r);}
 foreach(array(null,$f['credentials']['manual']['uuid']) as $bad_uuid){ud_actor($agent,$bad_uuid,true);Agent_Access_User_Directory::begin_request(null,array(),$request);ud_check(!current_user_can('list_users'),'cookie or unmanaged UUID cannot borrow grant');Agent_Access_User_Directory::end_request(null,array(),$request);}
 ud_actor($agent);$GLOBALS['_agent_access_pro_token_row']=(object)array('scope'=>'["__list_users__"]');Agent_Access_User_Directory::begin_request(null,array(),$request);ud_check(!current_user_can('list_users'),'Pro token does not gain native app-password privilege');Agent_Access_User_Directory::end_request(null,array(),$request);
 ud_actor($agent,$uuid);$response=rest_do_request(new WP_REST_Request('GET','/wp/v2/users'));ud_check(200===$response->get_status(),'native dispatcher directory read');ud_check(!current_user_can('list_users'),'dispatcher completes without ambient grant');
 ud_actor($agent);$GLOBALS['_agent_access_pro_token_row']=(object)array('scope'=>'["__list_users__"]');ud_check(is_wp_error(Agent_Access_Scope::enforce(null,rest_get_server(),new WP_REST_Request('POST','/wp/v2/categories'))),'read marker alone cannot grant taxonomy writes');
 $GLOBALS['_agent_access_pro_token_row']->scope='["post","__list_users__"]';ud_check(is_wp_error(Agent_Access_Scope::enforce(null,rest_get_server(),new WP_REST_Request('POST','/wp/v2/users'))),'directory marker adds no user write route');ud_check(null===Agent_Access_Scope::enforce(null,rest_get_server(),new WP_REST_Request('POST','/wp/v2/posts')),'selected content writes remain independent');
 ud_actor($f['users']['admin'],null,true);ud_check(current_user_can('list_users'),'existing administrator capability preserved');
 echo 'PASS '.$GLOBALS['ud_count'].' WordPress directory/admin/stack assertions';
}finally{Agent_Access_Scope::save($agent,$uuid,$before);ud_actor(0);}
