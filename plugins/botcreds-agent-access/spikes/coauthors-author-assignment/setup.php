<?php
if(!class_exists('CoAuthors_Plus') || !class_exists('Agent_Access_API'))throw new Exception('Dependency missing');
global $coauthors_plus;
$f=array('users'=>array(),'credentials'=>array(),'terms'=>array(),'uuids'=>array());
foreach(array('agent'=>'agent','writer'=>'author','admin'=>'administrator') as $key=>$role){
 $id=wp_insert_user(array('user_login'=>'aa_cap_probe_'.$key,'user_email'=>'aa-cap-'.$key.'@example.invalid','display_name'=>'AA CAP '.ucfirst($key),'role'=>$role,'user_pass'=>wp_generate_password(40)));
 if(is_wp_error($id))throw new Exception('Fixture user failed');$f['users'][$key]=$id;
 $term=$coauthors_plus->update_author_term(get_userdata($id));if($term && !is_wp_error($term))$f['terms'][]=$term->term_id;
}
$m=(new Agent_Access_API())->create_password($f['users']['agent']);Agent_Access_Scope::save($f['users']['agent'],$m['uuid'],array('post','/agent-access-probe/v1/*','/coauthors/v1/*'));$f['uuids'][]=$m['uuid'];$f['primary_uuid']=$m['uuid'];update_user_meta($f['users']['agent'],'_aa_cap_author_probe_'.$m['uuid'],'1');$f['credentials']['agent']=array('username'=>'aa_cap_probe_agent','password'=>$m['password']);
$a=WP_Application_Passwords::create_new_application_password($f['users']['admin'],array('name'=>'CAP disposable admin test'));$f['uuids'][]=$a[1]['uuid'];$f['credentials']['admin']=array('username'=>'aa_cap_probe_admin','password'=>$a[0]);
wp_set_current_user($f['users']['admin']);
$g=$coauthors_plus->guest_authors->create(array('user_login'=>'aa-cap-probe-guest','display_name'=>'AA CAP Guest','first_name'=>'AA CAP','last_name'=>'Guest'));
if(is_wp_error($g))throw new Exception('Guest creation failed');$f['guest']=$g;
$o=$coauthors_plus->guest_authors->get_guest_author_by('ID',$g);$t=$coauthors_plus->get_author_term($o);if($t && !is_wp_error($t))$f['terms'][]=$t->term_id;
$f['post']=wp_insert_post(array('post_title'=>'AA CAP untouched title','post_content'=>'AA CAP untouched body','post_excerpt'=>'AA CAP untouched excerpt','post_author'=>$f['users']['agent'],'post_status'=>'draft'),true);
$coauthors_plus->add_coauthors($f['post'],array('aa_cap_probe_agent'));
$f['other']=wp_insert_post(array('post_title'=>'AA CAP other published','post_content'=>'Untouched other content','post_author'=>$f['users']['writer'],'post_status'=>'publish'),true);
$coauthors_plus->add_coauthors($f['other'],array('aa_cap_probe_writer'));
$f['baseline']=get_post($f['post'],ARRAY_A);
$f['author_terms']=array();foreach(array('agent'=>'aa_cap_probe_agent','writer'=>'aa_cap_probe_writer','guest'=>'aa-cap-probe-guest') as $key=>$login){$obj=$coauthors_plus->get_coauthor_by('user_login',$login);$term=$coauthors_plus->get_author_term($obj);$f['author_terms'][$key]=(int)$term->term_id;}
$extra=WP_Application_Passwords::create_new_application_password($f['users']['agent'],array('name'=>'BotCreds'));$f['credentials']['ungranted']=array('username'=>'aa_cap_probe_agent','password'=>$extra[0]);$f['uuids'][]=$extra[1]['uuid'];Agent_Access_Scope::save($f['users']['agent'],$extra[1]['uuid'],array('post','/agent-access-probe/v1/*'));
$manual=WP_Application_Passwords::create_new_application_password($f['users']['agent'],array('name'=>'Unmanaged prototype password'));$f['credentials']['manual']=array('username'=>'aa_cap_probe_agent','password'=>$manual[0]);$f['uuids'][]=$manual[1]['uuid'];
file_put_contents('/tmp/aa-cap-probe-20261110/private.json',wp_json_encode($f));chmod('/tmp/aa-cap-probe-20261110/private.json',0600);
echo wp_json_encode(array('users'=>$f['users'],'post'=>$f['post'],'guest'=>$f['guest'],'agent_edit_others_posts'=>user_can($f['users']['agent'],'edit_others_posts')));
