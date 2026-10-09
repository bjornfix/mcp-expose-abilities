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
function WP_Filesystem(){return $GLOBALS["fs_available"];}
function plugins_api(){}
function activate_plugin(){}
function wp_update_plugins(){}
function wp_generate_attachment_metadata(){}
function wp_create_user(){}
function get_current_screen(){return null;}
class Plugin_Upgrader{}
class WP_Ajax_Upgrader_Skin{}
class WP_Error{public function __construct(private string $code,private string $message,public $data=null){}public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}}

define('WPMU_PLUGIN_DIR',sys_get_temp_dir().'/mcp-mu-reader-'.getmypid());
$GLOBALS['fs_available']=true;
class MuReadFilesystem {
 public $fail_read=false;
 public function dirlist($path,$hidden,$recursive){$r=[];foreach(scandir($path) as $n){if($n==='.'||$n==='..')continue;$r[$n]=['type'=>is_dir($path.'/'.$n)?'d':'f'];}return $r;}
 public function size($path){return filesize($path);}
 public function get_contents($path){return $this->fail_read?false:file_get_contents($path);}
}
$GLOBALS['wp_filesystem']=new MuReadFilesystem();
require dirname(__DIR__).'/mcp-expose-abilities.php';
mcp_register_content_abilities();
$ability=$GLOBALS['abilities']['plugins/read-mu-plugins'];$read=$ability['execute_callback'];$failures=0;
$check=static function($ok,$label)use(&$failures){echo ($ok?'PASS ':'FAIL ').$label."\n";$failures+=!$ok;};
$r=$read([]);$check($r['success']&&!$r['directory_exists']&&$r['total']===0,'Missing MU directory is explicit');
mkdir(WPMU_PLUGIN_DIR);mkdir(WPMU_PLUGIN_DIR.'/nested');
$code='<?php add_filter("auto_update_plugin", "__return_false");';
file_put_contents(WPMU_PLUGIN_DIR.'/loader.php',$code);file_put_contents(WPMU_PLUGIN_DIR.'/nested/filter.php','<?php throw new Exception("Do not execute source");');file_put_contents(WPMU_PLUGIN_DIR.'/icon.svg','not PHP');
$r=$read(['per_page'=>1]);$check($r['success']&&$r['total']===2&&$r['pages']===2&&$r['files'][0]['content']===$code&&$r['files'][0]['sha256']===hash('sha256',$code),'Read source and identity without executing it');
$r=$read(['per_page'=>1,'page'=>2]);$check($r['success']&&$r['files'][0]['file']==='nested/filter.php','Pagination includes nested loader code');
$r=$read(['max_bytes'=>1]);$check(!$r['success']&&count($r['errors'])===2,'Oversized files are failed reads, not empty success');
$GLOBALS['wp_filesystem']->fail_read=true;$r=$read([]);$check(!$r['success']&&count($r['errors'])===2,'Read failure stays explicit');$GLOBALS['wp_filesystem']->fail_read=false;
$outside=sys_get_temp_dir().'/mcp-mu-outside-'.getmypid().'.php';file_put_contents($outside,'private');symlink($outside,WPMU_PLUGIN_DIR.'/escape.php');$r=$read([]);$check(!$r['success']&&count($r['files'])===2&&!str_contains(json_encode($r),'private'),'Symlink outside MU scope is not read');unlink(WPMU_PLUGIN_DIR.'/escape.php');unlink($outside);
$GLOBALS['fs_available']=false;$r=$read([]);$check(!$r['success']&&$r['directory_exists'],'Unavailable file reader never reports empty success');
$GLOBALS['can_manage']=false;$check(!$ability['permission_callback'](),'Administrator permission remains required');$check($ability['meta']['annotations']['readonly']&&!$ability['meta']['annotations']['destructive'],'Read-only contract');
unlink(WPMU_PLUGIN_DIR.'/loader.php');unlink(WPMU_PLUGIN_DIR.'/icon.svg');unlink(WPMU_PLUGIN_DIR.'/nested/filter.php');rmdir(WPMU_PLUGIN_DIR.'/nested');rmdir(WPMU_PLUGIN_DIR);
exit($failures?1:0);
