<?php
/** Isolated real-HTTP connection discovery fixtures. Never shipped. */
$dir = '/tmp/aa-connection-20261120';
$file = $dir . '/private.json';
$mode = $args[0] ?? 'setup';
if ( 'setup' === $mode ) {
 $f = array( 'users' => array(), 'credentials' => array() );
 foreach ( array( 'agent' => 'agent', 'admin' => 'administrator' ) as $key => $role ) {
  $id = wp_insert_user( array( 'user_login' => 'aa_connection_' . $key . '_' . wp_generate_password(8,false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(12,false) . '@example.invalid', 'role' => $role ) );
  if ( is_wp_error($id) ) throw new RuntimeException('fixture creation failed');
  $f['users'][$key] = $id;
 }
 $id = $f['users']['agent'];
 foreach ( array( 'primary' => 'BotCreds', 'secondary' => 'BotCreds', 'manual' => 'Manual discovery test' ) as $key => $name ) {
  $c = WP_Application_Passwords::create_new_application_password($id,array('name'=>$name));
  if (is_wp_error($c)) throw new RuntimeException('credential creation failed');
  $f['credentials'][$key] = array('username'=>get_userdata($id)->user_login,'password'=>$c[0],'uuid'=>$c[1]['uuid']);
  Agent_Access_Scope::save($id,$c[1]['uuid'], $key === 'secondary' ? array('__yoast_seo_all__','__list_users__') : array('__read_only__'));
  Agent_Access_Rate_Limiter::save($id,$c[1]['uuid'],'unlimited');
 }
 wp_set_current_user($f['users']['admin']);
 $cookie = wp_generate_auth_cookie($f['users']['admin'],time()+3600,'logged_in');
 $_COOKIE[LOGGED_IN_COOKIE]=$cookie;
 $f['cookie']=LOGGED_IN_COOKIE.'='.$cookie;
 $f['nonce']=wp_create_nonce('wp_rest');
 global $wpdb;
 $f['caps']=get_user_meta($id,$wpdb->get_blog_prefix().'capabilities',true);
 $f['role_caps']=get_role('agent')->capabilities;
 $f['scope_snapshot']=array();
 foreach($f['credentials'] as $key=>$c) $f['scope_snapshot'][$key]=get_user_meta($id,Agent_Access_Scope::META_PREFIX.$c['uuid'],true);
 file_put_contents($file,wp_json_encode($f));chmod($file,0600);echo 'fixture ready';return;
}
$f=json_decode(file_get_contents($file),true);$id=$f['users']['agent'];
if('inspect'===$mode){
 global $wpdb;$same=true;
 foreach($f['credentials'] as $key=>$c) $same=$same && get_user_meta($id,Agent_Access_Scope::META_PREFIX.$c['uuid'],true)===$f['scope_snapshot'][$key];
 echo wp_json_encode(array('scopes_unchanged'=>$same,'caps_unchanged'=>get_user_meta($id,$wpdb->get_blog_prefix().'capabilities',true)===$f['caps'],'role_unchanged'=>get_role('agent')->capabilities===$f['role_caps']));return;
}
if('pending'===$mode){Agent_Access_Approval_Queue::set_pending($id,$f['credentials']['primary']['uuid']);echo 'pending';return;}
if('full'===$mode){Agent_Access_Scope::save($id,$f['credentials']['secondary']['uuid'],array('*'));echo 'full';return;}
if('revoke'===$mode){WP_Application_Passwords::delete_application_password($id,$f['credentials']['primary']['uuid']);echo 'revoked';return;}
if('cleanup'===$mode){require_once ABSPATH.'wp-admin/includes/user.php';foreach($f['users'] as $uid)wp_delete_user($uid);global $wpdb;$wpdb->query('DELETE FROM '.$wpdb->prefix.'agent_access_log WHERE user_id IN ('.implode(',',array_map('intval',$f['users'])).')');unlink($file);echo 'fixtures removed';return;}
throw new RuntimeException('unknown mode');
