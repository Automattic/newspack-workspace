<?php
$f=json_decode(file_get_contents('/tmp/aa-cap-probe-20261110/private.json'),true);global $coauthors_plus;
$mode=getenv('AA_PROBE_MODE');
if('reset'===$mode){wp_set_current_user($f['users']['admin']);$coauthors_plus->add_coauthors($f['post'],array('aa_cap_probe_agent'),false,'user_login');}
elseif('grant-off'===$mode)delete_user_meta($f['users']['agent'],'_aa_cap_author_probe_'.$f['primary_uuid']);
elseif('grant-on'===$mode)update_user_meta($f['users']['agent'],'_aa_cap_author_probe_'.$f['primary_uuid'],'1');
elseif('publish'===$mode){wp_update_post(array('ID'=>$f['post'],'post_status'=>'publish'));$f['baseline']=get_post($f['post'],ARRAY_A);file_put_contents('/tmp/aa-cap-probe-20261110/private.json',wp_json_encode($f));}
elseif('scope'===$mode){$s=getenv('AA_PROBE_SCOPE');$choices=array('normal'=>array('post','/agent-access-probe/v1/*','/coauthors/v1/*'),'readonly'=>array('__read_only__','/agent-access-probe/v1/*'),'seo'=>array('__yoast_seo_all__','/agent-access-probe/v1/*'),'wrong'=>array('post'));Agent_Access_Scope::save($f['users']['agent'],$f['primary_uuid'],$choices[$s]);}
echo 'Fixture control complete.';
