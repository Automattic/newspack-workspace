<?php
$f=json_decode(file_get_contents('/tmp/aa-cap-probe-20261110/private.json'),true);global $coauthors_plus;
wp_set_current_user($f['users']['admin']);
$f['page']=wp_insert_post(array('post_title'=>'AA CAP scoped page','post_content'=>'Untouched page','post_author'=>$f['users']['agent'],'post_type'=>'page','post_status'=>'draft'),true);$coauthors_plus->add_coauthors($f['page'],array('aa_cap_probe_agent'),false,'user_login');
$f['linked_guest']=$coauthors_plus->guest_authors->create_guest_author_from_user_id($f['users']['writer']);if(is_wp_error($f['linked_guest']))throw new Exception('Linked fixture failed');
$g=$coauthors_plus->guest_authors->get_guest_author_by('ID',$f['linked_guest']);$t=$coauthors_plus->get_author_term($g);$f['linked_guest_login']=$g->user_login;$f['old_writer_term']=$f['author_terms']['writer'];$f['author_terms']['writer']=(int)$t->term_id;
file_put_contents('/tmp/aa-cap-probe-20261110/private.json',wp_json_encode($f));
echo wp_json_encode(array('page'=>$f['page'],'linked_guest'=>$f['linked_guest'],'page_edit_cap'=>user_can($f['users']['agent'],'edit_post',$f['page'])));
