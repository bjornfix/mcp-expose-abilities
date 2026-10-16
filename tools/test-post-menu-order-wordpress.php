<?php
/** Test the registered post writer against real WordPress storage and permissions. */
if (!defined('ABSPATH') || !function_exists('wp_get_ability')) { throw new RuntimeException('WordPress required.'); }
$admins=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));
if (!$admins) { throw new RuntimeException('Administrator fixture required.'); }
wp_set_current_user((int)$admins[0]);
$id=wp_insert_post(wp_slash(array('post_type'=>'post','post_status'=>'draft','post_title'=>'Native display order fixture','post_content'=>'<p>Original C:\\guides</p>','post_excerpt'=>'Original excerpt','post_date'=>'2020-01-02 03:04:05')),true);
if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}
$check=static function($ok,$why){if(!$ok){throw new RuntimeException($why);}};
$ability=wp_get_ability('content/update-post');
$read=static function()use($id){$p=get_post($id);return array($p->post_title,$p->post_content,$p->post_excerpt,$p->post_date,$p->post_date_gmt,$p->post_status,$p->post_name,$p->post_author);};
try {
 $before=$read();
 $reader=wp_get_ability('content/get-post'); $initial=$reader->execute(array('id'=>$id)); $check(!is_wp_error($initial)&&0===$initial['menu_order'],'Native order reader missing.');
 $r=$ability->execute(array('id'=>$id,'menu_order'=>7,'dry_run'=>true));
 $check(!is_wp_error($r)&&$r['success']&&0===(int)get_post($id)->menu_order&&$before===$read(),'Dry run wrote or failed.');
 foreach(array(7,0,-2)as$order){$r=$ability->execute(array('id'=>$id,'menu_order'=>$order));$check(!is_wp_error($r)&&$r['success']&&$order===(int)get_post($id)->menu_order&&$before===$read(),'Native order update changed publication/content fields.'); $reported=$reader->execute(array('id'=>$id)); $check(!is_wp_error($reported)&&$reported['menu_order']===$order,'Order reader did not report stored value.');}
 $deny=static function($caps,$cap,$uid,$args)use($id){return 'edit_post'===$cap&&(int)($args[0]??0)===$id?array('do_not_allow'):$caps;};
 add_filter('map_meta_cap',$deny,999,4);
 try{$r=$ability->execute(array('id'=>$id,'menu_order'=>99));$check((is_wp_error($r)||empty($r['success']))&&-2===(int)get_post($id)->menu_order,'Denied post order was written.');}finally{remove_filter('map_meta_cap',$deny,999);}
 $r=$ability->execute(array('id'=>$id,'menu_order'=>'invalid'));
 $check(is_wp_error($r)&&-2===(int)get_post($id)->menu_order,'Schema accepted a non-integer order.');
 echo "PASS: native display order, zero/negative values, dry-run, permission and schema refusal; original publication/content fields preserved.\n";
} finally {wp_delete_post($id,true);}
