<?php
/** wp eval-file tests/maintenance-2.4.5-wordpress.php on disposable QA.
 * Tests loaded production classes using a connection-scoped temporary table.
 * No permanent table, post, token or configuration mutations. */
$checks=0;
function aa_pr5_check($ok,$label){$GLOBALS['aa_pr5_checks']=($GLOBALS['aa_pr5_checks']??0)+1;if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
global $wpdb;
$original_prefix=$wpdb->prefix;$prior_suppression=$wpdb->suppress_errors(true);
$table='aa_pr5_'.wp_generate_password(10,false).'_agent_access_snapshots';
try {
 $wpdb->prefix=substr($table,0,-strlen('agent_access_snapshots'));
 $wpdb->query("CREATE TEMPORARY TABLE `$table` (id bigint PRIMARY KEY, post_id bigint, user_id bigint, before_state longtext, captured_at datetime)");
 aa_pr5_check(''===$wpdb->last_error,'temporary MySQL fixture created');
 aa_pr5_check(count(Agent_Access_Rollback::get_recent_snapshots())===0 && ''===$wpdb->last_error,'empty history returns no rows without SQL error');
 $wpdb->query("INSERT INTO `$table` VALUES (1,0,0,'{}','2026-01-01 00:00:00'),(2,0,0,'{}','2026-01-02 00:00:00')");
 $wpdb->prefix=substr($table,0,-strlen('agent_access_snapshots'));
 $patched=Agent_Access_Rollback::get_recent_snapshots();
 aa_pr5_check(count($patched)===2 && ''===$wpdb->last_error,'snapshot query retrieves stored snapshots without SQL error');
 aa_pr5_check((int)$patched[0]->id===2 && (int)$patched[1]->id===1,'newest snapshots first');
 aa_pr5_check(count(Agent_Access_Rollback::get_recent_snapshots(1))===1,'prepared row limit honored');
 aa_pr5_check(count(Agent_Access_Rollback::get_recent_snapshots(0))===0,'zero limit honored');
 aa_pr5_check(count(Agent_Access_Rollback::get_recent_snapshots(-1))===1,'negative limit normalized without SQL errors');
 aa_pr5_check(count(Agent_Access_Rollback::get_recent_snapshots('1 OR 1=1'))===1 && ''===$wpdb->last_error,'non-numeric limit suffix cannot alter query');
 $method=new ReflectionMethod('Agent_Access_Pro_Auth','verify_signature');
 $keys=sodium_crypto_sign_keypair();$public=base64_encode(sodium_crypto_sign_publickey($keys));$private=sodium_crypto_sign_secretkey($keys);
 $_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/wp-json/wp/v2/posts';$_SERVER['HTTP_X_AGENT_TIMESTAMP']=(string)time();
 $message="GET\n/wp-json/wp/v2/posts\n".$_SERVER['HTTP_X_AGENT_TIMESTAMP'];
 $signature=base64_encode(sodium_crypto_sign_detached($message,$private));$_SERVER['HTTP_X_AGENT_SIGNATURE']=$signature;
 aa_pr5_check(true===$method->invoke(null,$public),'valid Ed25519 request still accepted');
 $_SERVER['HTTP_X_AGENT_SIGNATURE']=base64_encode(str_repeat('x',64));aa_pr5_check(false===$method->invoke(null,$public),'wrong signature rejected');
 unset($_SERVER['HTTP_X_AGENT_SIGNATURE']);aa_pr5_check(false===$method->invoke(null,$public),'missing signature rejected');
 $_SERVER['HTTP_X_AGENT_SIGNATURE']=$signature;$_SERVER['HTTP_X_AGENT_TIMESTAMP']=(string)(time()-1000);aa_pr5_check(false===$method->invoke(null,$public),'expired signature rejected');
 echo 'PASS '.$GLOBALS['aa_pr5_checks']." real WordPress/MySQL and signature assertions\n";
} finally {
 $wpdb->prefix=$original_prefix;$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");$wpdb->suppress_errors($prior_suppression);
}
