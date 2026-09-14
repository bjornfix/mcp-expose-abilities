<?php
/** Native WordPress proof for exact option names; removes only its own fixtures. */
if (!defined('ABSPATH') || !function_exists('wp_get_ability')) { throw new RuntimeException('WordPress abilities are required.'); }
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user((int)($admins[0]??0));
$prefix='mcp_option_name_test_'.str_replace('-','',wp_generate_uuid4());
$exact=$prefix.'.Status';$alias=sanitize_key($exact);$array_name=$prefix.'.Settings';$created=[];
$check=static function($ok,$label){if(!$ok){throw new RuntimeException($label);}echo 'PASS '.$label."\n";};
set_error_handler(static function($severity,$message){throw new RuntimeException($message);});
try {
 foreach([$exact=>'ready',$alias=>'other',$array_name=>['keep'=>'yes','selected'=>'before']] as $name=>$value){if(!add_option($name,$value,'',false)){throw new RuntimeException('Fixture could not be created.');}$created[]=$name;}
 $get=wp_get_ability('options/get');$set=wp_get_ability('options/update');
 $r=$get->execute(['name'=>$exact]);$check(!is_wp_error($r)&&($r['name']??'')===$exact&&($r['value']??null)==='ready','Exact native option read');
 $r=$set->execute(['name'=>$exact,'value'=>'changed','confirm_dangerous_action'=>'options/update']);$check(!is_wp_error($r)&&!empty($r['success'])&&get_option($exact)==='changed'&&get_option($alias)==='other','Exact native option write leaves the other name unchanged');
 $r=$set->execute(['name'=>$array_name,'key'=>'selected','value'=>'after','confirm_dangerous_action'=>'options/update']);$check(!is_wp_error($r)&&!empty($r['success'])&&get_option($array_name)===['keep'=>'yes','selected'=>'after'],'Native array update preserves other keys');
 $r=$get->execute(['name'=>'auto_updater.lock']);$check(!is_wp_error($r)&&empty($r['success'])&&str_contains($r['message']??'','protected'),'Protected option read is denied');
} finally {restore_error_handler();foreach($created as $name){delete_option($name);}}
