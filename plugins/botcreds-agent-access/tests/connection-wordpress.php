<?php
/** Supplemental real-WP checks with synthetic authenticated-UUID/Pro context. */
if(!defined('REST_REQUEST')) define('REST_REQUEST',true);
$f=json_decode(file_get_contents('/tmp/aa-connection-20261120/private.json'),true);
$GLOBALS['aa_connection_checks']=0;
function aa_connection_check($ok,$label){if(!$ok)throw new RuntimeException($label);++$GLOBALS['aa_connection_checks'];}
wp_set_current_user($f['users']['agent']);
$GLOBALS['wp_rest_application_password_uuid']=$f['credentials']['secondary']['uuid'];
aa_connection_check(true===Agent_Access_Connection::permissions(),'verified managed credential');
$GLOBALS['_agent_access_pro_token_row']=(object)array('scope'=>'*');
$error=Agent_Access_Connection::permissions();
aa_connection_check(is_wp_error($error) && 403===$error->get_error_data()['status'],'Pro context must not claim native discovery');
unset($GLOBALS['_agent_access_pro_token_row']);
$GLOBALS['wp_rest_application_password_uuid']=wp_generate_uuid4();
aa_connection_check(is_wp_error(Agent_Access_Connection::permissions()),'unknown UUID rejected');
$GLOBALS['wp_rest_application_password_uuid']=$f['credentials']['secondary']['uuid'];
$api=new Agent_Access_API();$info=$api->get_connection_info('synthetic-not-a-real-password',$f['users']['agent']);
aa_connection_check($info['connection_info_url']===rest_url('agent-access/v1/connection'),'copied JSON includes canonical discovery URL');
wp_set_current_user(0);
aa_connection_check(401===Agent_Access_Connection::permissions()->get_error_data()['status'],'anonymous denied');
unset($GLOBALS['wp_rest_application_password_uuid']);
echo 'PASS '.$GLOBALS['aa_connection_checks']." supplemental discovery assertions (synthetic auth globals).\n";
