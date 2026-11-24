<?php
$path='/tmp/aa-cap-probe-20261110/private.json';if(!is_file($path)){echo 'No private fixtures remain.';return;}
$f=json_decode(file_get_contents($path),true);global $wpdb,$coauthors_plus;
wp_delete_post($f['post'],true);wp_delete_post($f['other'],true);if(!empty($f['page']))wp_delete_post($f['page'],true);
wp_set_current_user($f['users']['admin']);if(!empty($f['linked_guest']))$coauthors_plus->guest_authors->delete($f['linked_guest']);$coauthors_plus->guest_authors->delete($f['guest']);
require_once ABSPATH.'wp-admin/includes/user.php';
foreach($f['users'] as $id){
 foreach(get_posts(array('author'=>$id,'post_type'=>'any','post_status'=>'any','numberposts'=>-1)) as $p)wp_delete_post($p->ID,true);
 foreach(array('agent_access_log','agent_access_snapshots') as $s){if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$s)))$wpdb->delete($wpdb->prefix.$s,array('user_id'=>$id),array('%d'));}
 WP_Application_Passwords::delete_all_application_passwords($id);WP_Session_Tokens::get_instance($id)->destroy_all();wp_delete_user($id);
}
foreach($f['terms'] as $term){if(term_exists($term,'author'))wp_delete_term($term,'author');}
foreach($f['uuids'] as $uuid){$h=substr(md5($uuid),0,16);for($i=0;$i<120;$i++)delete_transient('aa_rl_'.$h.'_m_'.(floor(time()/60)-$i));for($i=0;$i<3;$i++)delete_transient('aa_rl_'.$h.'_h_'.(floor(time()/3600)-$i));}
unlink($path);echo 'Removed synthetic agent/users, draft/revisions, guest, author terms, credentials, logs, snapshots and counters. CAP remains installed and active.';
