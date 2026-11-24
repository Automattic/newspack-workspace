<?php
$f=json_decode(file_get_contents('/tmp/aa-cap-probe-20261110/private.json'),true);$all=array();global $coauthors_plus,$post;
foreach(array('own'=>$f['post'],'other'=>$f['other']) as $key=>$id){
 $p=get_post($id,ARRAY_A);$a=$p;$b='own'===$key?$f['baseline']:null;$unchanged=true;
 if($b){unset($a['post_author'],$b['post_author']);$unchanged=$a===$b;}
 $authors=array_map(function($a){return $a->user_login;},get_coauthors($id));
 $post=get_post($id);setup_postdata($post);ob_start();coauthors_posts_links(', ',' and ','','',true);$byline=wp_strip_all_tags(ob_get_clean());
 $all[$key]=array('id'=>$id,'post_author'=>(int)$p['post_author'],'authors'=>$authors,'author_types'=>array_map(function($a){return isset($a->type)?$a->type:'wpuser';},get_coauthors($id)),'byline'=>$byline,'all_non_author_columns_unchanged'=>$unchanged);
}
$all['agent_edit_others_posts']=user_can($f['users']['agent'],'edit_others_posts');$all['agent_edit_other_post']=user_can($f['users']['agent'],'edit_post',$f['other']);
$all['cap_has_term_resolver']=method_exists($coauthors_plus,'get_coauthor_by_term');echo wp_json_encode($all);
