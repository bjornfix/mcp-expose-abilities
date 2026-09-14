<?php
/** Verify exact native option names through the registered read/write abilities. */
declare(strict_types=1);
define('ABSPATH', __DIR__.'/');
define('WP_PLUGIN_DIR', __DIR__.'/fixtures');
$GLOBALS['options_fixture']=['Example.Status'=>'ready','examplestatus'=>'other','Example.Settings'=>['keep'=>'yes','selected'=>'before'],'auto_updater.lock'=>123,'Example.api_key'=>'fixture-private'];
$GLOBALS['abilities']=[];$GLOBALS['can_manage']=true;
function add_action(...$args): void {}
function add_filter(...$args): void {}
function apply_filters($name,$value,...$args){return $value;}
function __($text,$domain=''){return $text;}
function esc_html__($text,$domain=''){return $text;}
function esc_html($text){return $text;}
function sanitize_text_field($value){return trim((string)$value);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
function current_user_can(...$args){return $GLOBALS['can_manage'];}
function is_wp_error($value){return $value instanceof WP_Error;}
function get_option($name,$default=false){return $GLOBALS['options_fixture'][$name]??$default;}
function update_option($name,$value){$changed=($GLOBALS['options_fixture'][$name]??null)!==$value;$GLOBALS['options_fixture'][$name]=$value;return $changed;}
function get_post_types(...$args){return [];}
function get_taxonomies(...$args){return [];}
function get_object_taxonomies(...$args){return [];}
function wp_register_ability($name,$args){$GLOBALS['abilities'][$name]=$args;}
function WP_Filesystem(){return true;}
function plugins_api(){}
function activate_plugin(){}
function wp_update_plugins(){}
function wp_generate_attachment_metadata(){}
function wp_create_user(){}
function get_current_screen(){return null;}
class Plugin_Upgrader{}
class WP_Ajax_Upgrader_Skin{}
class WP_Error{public function __construct(private string $code,private string $message,public $data=null){}public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}}
require dirname(__DIR__).'/mcp-expose-abilities.php';
mcp_register_content_abilities();
$get=$GLOBALS['abilities']['options/get']['execute_callback'];$set=$GLOBALS['abilities']['options/update']['execute_callback'];$failures=0;
$check=static function($ok,$label)use(&$failures){echo ($ok?'PASS ':'FAIL ').$label."\n";$failures+=!$ok;};
$r=$get(['name'=>'Example.Status']);$check(($r['name']??'')==='Example.Status'&&($r['value']??null)==='ready','Read preserves dots and letter case');
$r=$set(['name'=>'Example.Status','value'=>'changed','confirm_dangerous_action'=>'options/update']);$check(!empty($r['success'])&&get_option('Example.Status')==='changed'&&get_option('examplestatus')==='other','Write changes only the named native option');
$r=$set(['name'=>'Example.Settings','key'=>'selected','value'=>'after','confirm_dangerous_action'=>'options/update']);$check(!empty($r['success'])&&get_option('Example.Settings')===['keep'=>'yes','selected'=>'after'],'Array-key write preserves exact option and other keys');
foreach(['auto_updater.lock','Example.api_key','ACTIVE_PLUGINS'] as $name){$before=$GLOBALS['options_fixture'];$r=$get(['name'=>$name]);$w=$set(['name'=>$name,'value'=>'blocked','confirm_dangerous_action'=>'options/update']);$check(empty($r['success'])&&str_contains($r['message']??'','protected')&&empty($w['success'])&&$before===$GLOBALS['options_fixture'],'Protected option denied: '.$name);}
$before=$GLOBALS['options_fixture'];$r=$set(['name'=>'Example.Status','value'=>'blocked']);$check(empty($r['success'])&&$before===$GLOBALS['options_fixture'],'Write still requires explicit action confirmation');
$GLOBALS['can_manage']=false;$check(!$GLOBALS['abilities']['options/get']['permission_callback']()&&!$GLOBALS['abilities']['options/update']['permission_callback'](),'Both abilities retain manage_options permission');
exit($failures?1:0);
