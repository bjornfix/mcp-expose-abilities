<?php
/** Exercise the existing health Interface with localized, hostile and partial native results. */
declare( strict_types=1 );
$fixture = sys_get_temp_dir() . '/mcp-health-' . bin2hex( random_bytes( 6 ) ) . '/';
mkdir( $fixture . 'wp-admin/includes', 0700, true );
foreach ( array( 'plugin.php', 'update.php', 'class-wp-upgrader.php', 'misc.php', 'file.php', 'class-wp-site-health.php' ) as $file ) { file_put_contents( $fixture . 'wp-admin/includes/' . $file, "<?php\n" ); }
register_shutdown_function( static function () use ( $fixture ): void { foreach ( glob( $fixture . 'wp-admin/includes/*' ) as $file ) { unlink( $file ); } rmdir( $fixture . 'wp-admin/includes' ); rmdir( $fixture . 'wp-admin' ); rmdir( $fixture ); } );
define( 'ABSPATH', $fixture ); define( 'WP_PLUGIN_DIR', $fixture );
$abilities = array(); $admin = $can_health = true; $filters = array(); $calls = array(); $mode = '';
$labels = array(
 'wordpress_version' => 'Your version of WordPress (%s) is up to date', 'plugin_version' => 'You should remove inactive plugins', 'theme_version' => 'You should remove inactive themes',
 'php_version' => 'Your site is running a recommended version of PHP (%s)', 'php_extensions' => 'Required and recommended modules are installed', 'sql_server' => 'SQL server is up to date',
 'https_status' => 'Your website is using an active HTTPS connection', 'scheduled_events' => 'Scheduled events are running', 'background_updates' => 'Background updates are working',
 'loopback_requests' => 'Your site can perform loopback requests', 'rest_availability' => 'The REST API is available', 'php_default_timezone' => 'PHP default timezone is valid',
 'php_sessions' => 'No PHP sessions detected', 'ssl_support' => 'Your site can communicate securely with other services', 'http_requests' => 'HTTP requests seem to be working as expected',
 'is_in_debug_mode' => 'Your site is not set to output debug information', 'file_uploads' => 'Files can be uploaded', 'plugin_theme_auto_updates' => 'Plugin and theme auto-updates appear to be configured correctly',
 'update_temp_backup_writable' => 'Plugin and theme temporary backup directory is writable', 'available_updates_disk_space' => 'Disk space available to safely perform updates',
 'autoloaded_options' => 'Autoloaded options are acceptable', 'insecure_registration' => 'Open Registration with privileged default role', 'search_engine_visibility' => 'Search engine indexing is enabled.',
 'opcode_cache' => 'Opcode cache is enabled', 'dotorg_communication' => 'Can communicate with WordPress.org', 'page_cache' => 'Page cache is detected and the server response time is good',
 'persistent_object_cache' => 'A persistent object cache is being used', 'authorization_header' => 'The Authorization header is working as expected',
);
function add_action(...$args) {} function add_filter( $name, $callback, $priority=10, $accepted=1 ) { $GLOBALS['filters'][$name][$priority][] = $callback; }
function remove_filter( $name, $callback, $priority=10 ) { foreach ( $GLOBALS['filters'][$name][$priority] ?? array() as $i=>$cb ) { if ( $cb === $callback ) { unset( $GLOBALS['filters'][$name][$priority][$i] ); } } }
function apply_filters( $name, $value, ...$args ) { $f=$GLOBALS['filters'][$name]??array(); ksort($f); foreach($f as $list) { foreach($list as $cb) {$value=$cb($value,...$args);} } return $value; }
function __( $text, $domain='default' ) { $translated='fixture-nb::'.$text; if ('cache_reordered'===$GLOBALS['mode'] && str_starts_with($text,'Median server response time was')) { $translated='Terskel %2$s ms, faktisk median %1$s ms.'; } return apply_filters('gettext', $translated, $text, $domain); } function esc_html__($text,...$args) {return __($text,...$args);}
function wp_strip_all_tags($text) {return strip_tags($text);}
function current_user_can($cap,...$args) {return 'manage_options'===$cap?$GLOBALS['admin']:$GLOBALS['can_health'];}
function wp_register_ability($name,$args) {$GLOBALS['abilities'][$name]=$args;}
function get_option($name,$default=false) {return $default;}
function get_post_types(...$a) {return array();} function get_taxonomies(...$a) {return array();} function get_object_taxonomies(...$a) {return array();}
function get_filesystem_method() { return 'no_direct_fs'===$GLOBALS['mode'] ? 'ftpext' : 'direct'; }
function WP_Filesystem() {} function plugins_api() {} function activate_plugin() {} function wp_update_plugins() {} function wp_generate_attachment_metadata() {} function wp_create_user() {} function get_current_screen() {return null;}
class Plugin_Upgrader {} class WP_Ajax_Upgrader_Skin {} class WP_Error {}
class WP_Site_Health {
 public static function get_instance() {return new self();}
 public static function get_tests() {
  $tests=array(); foreach($GLOBALS['labels'] as $name=>$label) {$tests['is_in_debug_mode'===$name?'debug_enabled':$name]=array('test'=>static function(){throw new RuntimeException('SECRET registry callback must never run');});}
  $tests['private_SECRET_callback']=array('test'=>static function(){throw new RuntimeException('SECRET');});
  if ('unregistered'===$GLOBALS['mode']) {unset($tests['page_cache']);}
  return array('direct'=>$tests,'async'=>array());
 }
 public function __call($method,$args) {
  $name=substr($method,9);$GLOBALS['calls'][]=$name;
  if (!isset($GLOBALS['labels'][$name]) || 'authorization_header'===$name) {throw new RuntimeException('SECRET unexpected dispatch');}
  if ('throw'===$GLOBALS['mode'] && 'php_sessions'===$name) {echo 'SECRET output';throw new RuntimeException('SECRET native exception');}
  $status=in_array($name,array('plugin_version','theme_version'),true)?'recommended':'good';
  $source=$GLOBALS['labels'][$name]; $label=sprintf(__($source),'SECRET-substitution','SECRET-substitution');
  $description='SECRET /var/private/SECRET credential=SECRET';
  if ('page_cache'===$name && str_starts_with($GLOBALS['mode'],'cache_')) {
   $value='cache_hostile'===$GLOBALS['mode']?'SECRET':('cache_overflow'===$GLOBALS['mode']?'999999999999':'1 234');
   $description.='<li>'.sprintf(__('Median server response time was %1$s milliseconds. It should be less than the recommended %2$s milliseconds threshold.'),$value,'600').'</li><li>'.__('No client caching response headers were detected.').'</li><li>'.__('A page cache plugin was not detected.').'</li>';
  }
  if ('critical'===$GLOBALS['mode'] && 'available_updates_disk_space'===$name) {$status='critical';$description=sprintf(__('Available disk space is critically low, less than %s available. Proceed with caution, updates may fail.'),'SECRET');}
  if ('unknown_label'===$GLOBALS['mode'] && 'plugin_version'===$name) {$label='SECRET unknown native label';}
  if ('invalid'===$GLOBALS['mode'] && 'php_sessions'===$name) {$status='SECRET';}
  echo 'SECRET unexpected native echo';
  return array('status'=>$status,'label'=>$label,'description'=>$description,'actions'=>'SECRET','debug'=>array('SECRET'),'test'=>'SECRET');
 }
}
$root=getenv('MCP_EXPOSE_SOURCE_DIR')?:dirname(__DIR__); require $root.'/mcp-expose-abilities.php';mcp_register_content_abilities();
$a=$abilities['system/get-site-health'];$checks=0;
function check($ok,$message) {++$GLOBALS['checks'];if(!$ok) {throw new RuntimeException($message);}}
function run() { $before=ob_get_level();ob_start();$r=($GLOBALS['a']['execute_callback'])(array());$echo=ob_get_clean();check(''===$echo && $before===ob_get_level(),'Native output/buffer escaped');check(!str_contains(json_encode($r),'SECRET'),'Private values escaped');check(empty(array_filter($GLOBALS['filters']['gettext'][PHP_INT_MAX]??array())),'Native observer leaked');return $r;}
function row($r,$name) {return array_column($r['tests'],null,'test')[$name];}
$r=run();check($r['success']&&$r['execution_complete']&&$r['diagnostics_complete']&&!$r['complete'],'Executed coverage or partial completeness is false');
check(28===count($r['tests'])&&27===$r['counts']['executed']&&25===$r['counts']['passed']&&2===$r['counts']['recommended']&&1===$r['counts']['not_run'],'Expected fixed native coverage missing');
check('You should remove inactive plugins'===row($r,'plugin_version')['label']&&'you_should_remove_inactive_plugins'===row($r,'plugin_version')['reason_code'],'Inactive-plugin localized cause lost');
check('You should remove inactive themes'===row($r,'theme_version')['label'],'Inactive-theme localized cause lost');
check(str_contains(row($r,'php_version')['label'],'[value omitted]'),'Native substitutions retained');
check('not_run'===row($r,'authorization_header')['outcome']&&!in_array('authorization_header',$calls,true),'MCP credentials confused with native browser probe');
check(1===$r['additional_registered_tests_not_run'],'Unlisted registry work claimed completed');
$mode='critical';$r=run();check('failed'===row($r,'available_updates_disk_space')['outcome']&&'disk_space_critical'===row($r,'available_updates_disk_space')['reason_code'],'Critical low disk cause was masked by generic native title');
$mode='throw';$r=run();check(!$r['success']&&'execution_error'===row($r,'php_sessions')['outcome']&&'passed'===row($r,'php_version')['outcome'],'Partial failure erased other results or claimed PASS');
$mode='invalid';$r=run();check(!$r['success']&&'unavailable'===row($r,'php_sessions')['status'],'Invalid native status accepted');
$mode='unknown_label';$r=run();check(!$r['success']&&!$r['diagnostics_complete']&&'unrecognized_native_diagnostic'===row($r,'plugin_version')['reason_code'],'Unrecognized diagnostic invented a cause');
$mode='unregistered';$r=run();check('not_registered_for_this_site'===row($r,'page_cache')['reason_code']&&!row($r,'page_cache')['executed'],'Disabled native test executed or claimed passed');
$mode='cache_numeric';$_SERVER['PHP_AUTH_USER']='SECRET';$_SERVER['PHP_AUTH_PW']='SECRET';$r=run();$metric=row($r,'page_cache')['measurement'];
check(1234===$metric['median_response_time_ms']&&600===$metric['recommended_threshold_ms'],'Native rounded millisecond projection incorrect');
check(false===$metric['native_client_cache_headers_detected']&&false===$metric['native_page_cache_plugin_detected'],'Native cache detection changed');
check($metric['native_loopback_basic_auth_available'],'Native Basic-auth context omitted');unset($_SERVER['PHP_AUTH_USER'],$_SERVER['PHP_AUTH_PW']);
$mode='cache_reordered';$r=run();check(1234===row($r,'page_cache')['measurement']['median_response_time_ms']&&600===row($r,'page_cache')['measurement']['recommended_threshold_ms'],'Localized reordered placeholders reversed timing');
$mode='cache_hostile';$r=run();check(null===row($r,'page_cache')['measurement']['median_response_time_ms'],'Untrusted numeric native substitution leaked');
$mode='cache_overflow';$r=run();check(null===row($r,'page_cache')['measurement']['median_response_time_ms'],'Unbounded native measurement returned');
$mode='no_direct_fs';$r=run();check('requires_direct_native_filesystem'===row($r,'update_temp_backup_writable')['reason_code']&&!row($r,'update_temp_backup_writable')['executed'],'Non-direct filesystem login attempted');
check(false===$a['input_schema']['additionalProperties']&&empty((array)$a['input_schema']['properties']),'Caller callback/path input introduced');
$admin=false;check(!$a['permission_callback'](),'Administrator permission removed');$admin=true;$can_health=false;check(!$a['permission_callback'](),'Native health permission removed');
echo "PASS: {$checks} health coverage, localized causes, critical/advisory/error/not-run outcomes, no callback execution, bounded page-cache metrics, no private native text or output\n";
