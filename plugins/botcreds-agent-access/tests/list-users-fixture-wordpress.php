<?php
/** Disposable hosted QA fixture; never shipped. Pass setup/inspect/cleanup. */
$dir='/tmp/aa-users-20261119';$file=$dir.'/private.json';$mode=$args[0]??'inspect';
if('setup'===$mode){
 $f=array('users'=>array(),'credentials'=>array());
 foreach(array('admin'=>'administrator','agent'=>'agent','hidden'=>'subscriber') as $key=>$role){$id=wp_insert_user(array('user_login'=>'aa_directory_'.$key.'_'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(32),'user_email'=>'aa-directory-'.$key.'-'.wp_generate_password(8,false).'@example.invalid','role'=>$role));if(is_wp_error($id))throw new RuntimeException('fixture insert failed');$f['users'][$key]=$id;}
 $agent=$f['users']['agent'];
 foreach(array('primary'=>'BotCreds','secondary'=>'BotCreds','manual'=>'Directory manual test') as $key=>$name){$c=WP_Application_Passwords::create_new_application_password($agent,array('name'=>$name));if(is_wp_error($c))throw new RuntimeException('password fixture failed');$f['credentials'][$key]=array('username'=>get_userdata($agent)->user_login,'password'=>$c[0],'uuid'=>$c[1]['uuid']);Agent_Access_Scope::save($agent,$c[1]['uuid'],array('__read_only__'));Agent_Access_Rate_Limiter::save($agent,$c[1]['uuid'],'unlimited');}
 wp_set_current_user($f['users']['admin']);$f['cookie']=LOGGED_IN_COOKIE.'='.wp_generate_auth_cookie($f['users']['admin'],time()+7200,'logged_in');$_COOKIE[LOGGED_IN_COOKIE]=substr($f['cookie'],strlen(LOGGED_IN_COOKIE)+1);$f['nonce']=wp_create_nonce('agent_access_admin_nonce');
 $f['post']=wp_insert_post(array('post_title'=>'Directory QA fixture','post_content'=>'Do not change','post_status'=>'draft','post_author'=>$agent));
 global $wpdb;$f['original_user_rows']=$wpdb->get_results('SELECT * FROM '.$wpdb->users.' WHERE ID IN ('.implode(',',array_map('intval',$f['users'])).')',ARRAY_A);$f['original_caps']=get_user_meta($agent,$wpdb->get_blog_prefix().'capabilities',true);$f['role_caps']=get_role('agent')->capabilities;
 file_put_contents($file,wp_json_encode($f));chmod($file,0600);echo 'fixture ready';return;
}
$f=json_decode(file_get_contents($file),true);$agent=$f['users']['agent'];
if('refresh-admin'===$mode){wp_set_current_user($f['users']['admin']);$_COOKIE[LOGGED_IN_COOKIE]=substr($f['cookie'],strlen(LOGGED_IN_COOKIE)+1);$f['nonce']=wp_create_nonce('agent_access_admin_nonce');file_put_contents($file,wp_json_encode($f));echo 'admin nonce refreshed';return;}
if('inspect'===$mode){global $wpdb;$rows=$wpdb->get_results('SELECT * FROM '.$wpdb->users.' WHERE ID IN ('.implode(',',array_map('intval',$f['users'])).')',ARRAY_A);echo wp_json_encode(array('scope'=>Agent_Access_Scope::get($agent,$f['credentials']['primary']['uuid']),'rows_unchanged'=>$rows===$f['original_user_rows'],'caps_unchanged'=>get_user_meta($agent,$wpdb->get_blog_prefix().'capabilities',true)===$f['original_caps'],'role_unchanged'=>get_role('agent')->capabilities===$f['role_caps'],'list_users_outside_rest'=>user_can($agent,'list_users'),'manage_options'=>user_can($agent,'manage_options'),'edit_users'=>user_can($agent,'edit_users'),'create_users'=>user_can($agent,'create_users'),'delete_users'=>user_can($agent,'delete_users')));return;}
if('cleanup'===$mode){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_post($f['post'],true);foreach($f['users'] as $id)wp_delete_user($id);global $wpdb;$wpdb->query('DELETE FROM '.$wpdb->prefix.'agent_access_log WHERE user_id IN ('.implode(',',array_map('intval',$f['users'])).')');unlink($file);echo 'fixture removed';return;}
if('reset'===$mode){foreach(array('primary','secondary') as $key)Agent_Access_Scope::save($agent,$f['credentials'][$key]['uuid'],array('__read_only__'));return;}
if('scope-secondary'===$mode){Agent_Access_Scope::save($agent,$f['credentials']['secondary']['uuid'],array('*'));return;}
if('revoke'===$mode){WP_Application_Passwords::delete_application_password($agent,$f['credentials']['primary']['uuid']);return;}
throw new RuntimeException('unknown fixture operation');
