<?php
/** Exercise the registered provider bridge through native WordPress permissions/schema. */
if (!defined('ABSPATH') || !function_exists('wp_get_ability')) { throw new RuntimeException('WordPress required.'); }
if (class_exists('VarnishPurger',false)) { throw new RuntimeException('Run isolated with --skip-plugins; never replace a live provider.'); }
$admins=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));
wp_set_current_user((int)$admins[0]);
$a=wp_get_ability('cache/purge-proxy-urls');
$check=static function($ok,$why){if(!$ok){throw new RuntimeException($why);}};
$url=home_url('/native-proxy-fixture/');
$r=$a->execute(array('urls'=>array($url))); $check(!is_wp_error($r)&&!$r['success'],'Missing provider was accepted.');
if (!class_exists('VarnishPurger',false)) {
 class VarnishPurger {
  public static $urls=array();
  public static function purge_url($url){if(str_contains($url,'throw-fixture')){throw new RuntimeException('Private provider failure details');}self::$urls[]=$url;do_action('after_purge_url',$url,'http://private-backend.invalid/internal/',array('response'=>array('code'=>200),'body'=>'private-body-value','headers'=>array('private-header'=>'private-header-value')));}
 }
}
foreach(array('https://example.net/foreign/',$url.'?vhp-regex',$url.'#fragment',preg_replace('~://~','://user:secret@',$url,1))as$bad){
 $r=$a->execute(array('urls'=>array($url,$bad)));$check((is_wp_error($r)||!$r['success'])&&array()===VarnishPurger::$urls,'Invalid batch partially reached provider.');
}
$search=home_url('/').'?s=glass&e_search_props=widget-123';
$r=$a->execute(array('urls'=>array($search)));$check((is_wp_error($r)||!$r['success'])&&array()===VarnishPurger::$urls,'Search query accepted without explicit selection.');
foreach(array('?vhp-regex','?s=glass&vhp-regex','?s=glass&other=value','?s%5B%5D=glass','?s=glass&s=other','?s=glass&e_search_props=bad%2Fvalue')as$query){$r=$a->execute(array('urls'=>array($search,home_url('/').$query),'allow_search_queries'=>true));$check((is_wp_error($r)||!$r['success'])&&array()===VarnishPurger::$urls,'Broad or malformed query batch partially reached provider.');}
$r=$a->execute(array('urls'=>array($url,$url)));$check(!is_wp_error($r)&&$r['success']&&1===$r['submitted_count']&&$r['verification_required']&&array($url)===VarnishPurger::$urls,'Native provider receipt/deduplication failed.');
$r=$a->execute(array('urls'=>array($search),'allow_search_queries'=>true));$check(!is_wp_error($r)&&$r['success']&&1===$r['submitted_count']&&array($url,$search)===VarnishPurger::$urls,'Explicit exact native search query did not reach provider.');$check(200===$r['provider_attempts'][0]['http_status']&&$search===$r['provider_attempts'][0]['url']&&!str_contains(wp_json_encode($r),'private-')&&!has_action('after_purge_url'),'Provider telemetry leaked private transport data or left a hook.');
$r=$a->execute(array('urls'=>array(home_url('/throw-fixture/'))));$check(!$r['success']&&0===$r['submitted_count']&&!str_contains(wp_json_encode($r),'Private provider'),'Provider failure was hidden or exposed private detail.');
$before=VarnishPurger::$urls;wp_set_current_user(0);$r=$a->execute(array('urls'=>array($url)));$check(is_wp_error($r)&&$before===VarnishPurger::$urls,'Anonymous provider call accepted.');
echo "PASS: native proxy API delegation, same-origin batch validation, no partial foreign purge, deduplication, permission refusal and honest submission receipts.\n";
