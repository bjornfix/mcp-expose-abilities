<?php
/** Verify the registered narrow maintenance writes with native-shaped collaborators. */
declare( strict_types=1 );
$fixture = sys_get_temp_dir() . '/mcp-maintenance-writes-' . bin2hex( random_bytes( 6 ) ) . '/';
mkdir( $fixture . 'wp-admin/includes', 0700, true ); mkdir( $fixture . 'wp-includes', 0700 );
foreach ( array( 'plugin.php', 'update.php', 'class-wp-upgrader.php', 'file.php' ) as $name ) { file_put_contents( $fixture . 'wp-admin/includes/' . $name, "<?php\n" ); }
register_shutdown_function( static function () use ( $fixture ): void {
	foreach ( glob( $fixture . 'wp-admin/includes/*' ) as $file ) { unlink( $file ); }
	foreach ( glob( $fixture . 'wp-includes/*' ) as $file ) { unlink( $file ); }
	foreach ( glob( $fixture . '*php' ) as $file ) { unlink( $file ); }
	foreach ( glob( $fixture . '.*php' ) as $file ) { unlink( $file ); }
	rmdir( $fixture . 'wp-admin/includes' ); rmdir( $fixture . 'wp-admin' ); rmdir( $fixture . 'wp-includes' ); rmdir( $fixture );
} );
define( 'ABSPATH', $fixture ); define( 'WP_PLUGIN_DIR', $fixture ); define( 'WPINC', 'wp-includes' );
define( 'WP_AUTO_UPDATE_CORE', '--already-enabled' === ( $argv[1] ?? '' ) );
$abilities = array(); $caps = array_fill_keys( array( 'manage_options', 'update_core', 'update_themes' ), true );
$file_mods = true; $core_updates = $theme_updates = $cache_invalidations = 0;
$core_throw = $theme_throw = $theme_switch = false;
$theme_version = '1.0'; $active_stylesheet = $active_template = 'sample';
$preferred = (object) array( 'current' => '7.0.1', 'response' => 'upgrade', 'packages' => (object) array( 'full' => 'https://downloads.wordpress.org/release/wordpress-7.0.1.zip' ) );
$theme_offer = array( 'new_version' => '2.0', 'package' => 'https://downloads.wordpress.org/theme/sample.2.0.zip' );
function write_core_version( $version ) { file_put_contents( ABSPATH . WPINC . '/version.php', '<?php $wp_version = ' . var_export( $version, true ) . ';' ); }
write_core_version( '7.0' );
$config_before = "<?php\n// Keep this comment.\ndefine('DB_PASSWORD', 'EXAMPLE_SECRET');\ndefine( 'WP_AUTO_UPDATE_CORE', " . ( WP_AUTO_UPDATE_CORE ? 'true' : 'false' ) . " );\n\$unknown = ['keep' => 'yes'];\n";
file_put_contents( ABSPATH . 'wp-config.php', $config_before );
function add_action(...$args) {} function add_filter(...$args) {}
function apply_filters( $name, $value, ...$args ) { return $value; }
function current_user_can( $cap, ...$args ) { return $GLOBALS['caps'][$cap] ?? false; }
function wp_is_file_mod_allowed( $context ) { return $GLOBALS['file_mods']; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][$name] = $args; }
function wp_normalize_path( $path ) { return $path; }
function __($text, ...$args) { return $text; } function esc_html__( $text, ...$args ) { return $text; }
function get_option( $name, $default = false ) { return $default; }
function get_post_types(...$args) { return array(); } function get_taxonomies(...$args) { return array(); } function get_object_taxonomies(...$args) { return array(); }
function plugins_api() {} function activate_plugin() {} function wp_update_plugins() {} function wp_generate_attachment_metadata() {} function wp_create_user() {} function get_current_screen() { return null; }
function get_preferred_from_update_core() { return $GLOBALS['preferred']; }
function get_site_transient( $name ) { return (object) array( 'response' => array( 'sample' => $GLOBALS['theme_offer'] ) ); }
class FixtureTheme { public function get( $name ) { return $GLOBALS['theme_version']; } }
function wp_get_themes() { return array( 'sample' => new FixtureTheme() ); }
function wp_get_theme( $stylesheet ) { return new FixtureTheme(); }
function wp_clean_themes_cache() {} function get_stylesheet() { return $GLOBALS['active_stylesheet']; } function get_template() { return $GLOBALS['active_template']; }
function wp_generate_uuid4() { return 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'; }
function wp_opcache_invalidate(...$args) { ++$GLOBALS['cache_invalidations']; }
function WP_Filesystem() { return true; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error { public function __construct( public $code, public $message ) {} public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
class Plugin_Upgrader {} class WP_Ajax_Upgrader_Skin {}
class WP_Upgrader { public static $locked = false, $deny = false;
	public static function create_lock( ...$args ) { if ( self::$deny ) { return false; } return self::$locked = true; }
	public static function release_lock( ...$args ) { self::$locked = false; }
}
class Core_Upgrader { public function __construct( $skin ) {} public function upgrade( $offer, $args ) {
	if ( true !== $args['pre_check_md5'] || true !== $args['attempt_rollback'] ) { throw new RuntimeException( 'Native integrity or rollback disabled' ); }
	++$GLOBALS['core_updates']; write_core_version( $offer->current );
	if ( $GLOBALS['core_throw'] ) { throw new RuntimeException( 'EXAMPLE_SECRET' ); } return $offer->current;
} }
class Theme_Upgrader { public function __construct( $skin ) {} public function upgrade( $stylesheet ) {
	++$GLOBALS['theme_updates']; $GLOBALS['theme_version'] = '2.0';
	if ( $GLOBALS['theme_switch'] ) { $GLOBALS['active_stylesheet'] = 'other'; }
	if ( $GLOBALS['theme_throw'] ) { throw new RuntimeException( 'EXAMPLE_SECRET' ); } return true;
} }
class FixtureFilesystem { public $method = 'direct', $writable = true, $put_fail = false, $move_fail = false, $move_throw = false, $drift = false, $puts = 0;
	public function exists( $p ) { return file_exists( $p ); }
	public function get_contents( $p ) { if ( $this->drift && WP_Upgrader::$locked && str_ends_with( $p, 'wp-config.php' ) ) { $this->drift = false; file_put_contents( $p, "\n// Concurrent edit", FILE_APPEND ); } return file_get_contents( $p ); }
	public function is_writable( $p ) { return $this->writable; }
	public function owner( $p ) { return 'fixture-owner'; }
	public function group( $p ) { return 'fixture-group'; }
	public function getchmod( $p ) { return substr( sprintf( '%o', fileperms( $p ) ), -3 ); }
	public function put_contents( $p, $content, $mode ) { ++$this->puts; if ( $this->put_fail ) { return false; } return false !== file_put_contents( $p, $content ); }
	public function move( $from, $to, $overwrite ) { if ( $this->move_fail ) { return false; } $r = rename( $from, $to ); if ( $this->move_throw ) { throw new RuntimeException( 'EXAMPLE_SECRET' ); } return $r; }
	public function delete( $p ) { return unlink( $p ); }
}
$wp_filesystem = new FixtureFilesystem();
$root = getenv( 'MCP_EXPOSE_SOURCE_DIR' ) ?: dirname( __DIR__ );
require $root . '/mcp-expose-abilities.php'; mcp_register_content_abilities();
$names = array( 'system/set-core-auto-update-policy', 'system/update-wordpress-core', 'themes/update' );
if ( '--schemas' === ( $argv[1] ?? '' ) ) { echo json_encode( array_map( static fn($name) => array( 'name' => $name, 'input_schema' => $GLOBALS['abilities'][$name]['input_schema'], 'annotations' => $GLOBALS['abilities'][$name]['meta']['annotations'] ), $names ), JSON_PRETTY_PRINT ); exit; }
$checks = 0;
function check( $v, $message ) { ++$GLOBALS['checks']; if ( !$v ) { throw new RuntimeException( $message ); } }
function run( $name, $input ) { $a = $GLOBALS['abilities'][$name]; check( $a['permission_callback'](), 'Permission fixture failed' ); $r = ($a['execute_callback'])( $input ); check( !str_contains( json_encode( $r ), 'EXAMPLE_SECRET' ), 'Private content or native error leaked' ); return $r; }
foreach ( $names as $name ) { check( false === $abilities[$name]['input_schema']['additionalProperties'], 'Broad write parameters accepted' ); check( false === $abilities[$name]['meta']['annotations']['idempotent'], 'Transport may retry an uncertain write' ); }
$core_input = array( 'target_version' => '7.0.1', 'expected_current_version' => '7.0', 'confirm_dangerous_action' => 'system/update-wordpress-core' );
$r = run( 'system/update-wordpress-core', array_diff_key( $core_input, array( 'confirm_dangerous_action' => true ) ) ); check( !$r['success'] && !$core_updates, 'Unconfirmed core update ran' );
$r = run( 'system/update-wordpress-core', array_merge( $core_input, array( 'expected_current_version' => '6.9' ) ) ); check( !$r['success'] && !$core_updates, 'Stale core version ran' );
$preferred->packages->full = 'https://example.com/wordpress-7.0.1.zip';
$r = run( 'system/update-wordpress-core', $core_input ); check( !$r['success'] && !$core_updates, 'Non-official core package ran' );
$preferred->packages->full = 'https://downloads.wordpress.org/release/wordpress-7.0.1.zip';
$r = run( 'system/update-wordpress-core', $core_input ); check( $r['success'] && $r['updated'] && 1 === $core_updates && '7.0.1' === $r['current_version'], 'Native core readback failed' );
$r = run( 'system/update-wordpress-core', $core_input ); check( $r['success'] && !$r['updated'] && 1 === $core_updates, 'Core repeat reinstalled' );
write_core_version( '7.0' ); $core_throw = true;
$r = run( 'system/update-wordpress-core', $core_input ); check( !$r['success'] && $r['software_changed'] && 'read_back' === $r['write_outcome'] && '7.0.1' === $r['current_version'], 'Core exception hid saved version' ); $core_throw = false;
$theme_input = array( 'stylesheet' => 'sample', 'target_version' => '2.0', 'expected_current_version' => '1.0', 'confirm_dangerous_action' => 'themes/update' );
$r = run( 'themes/update', array_merge( $theme_input, array( 'stylesheet' => '../other' ) ) ); check( !$r['success'] && !$theme_updates, 'Theme traversal accepted' );
$theme_offer['package'] = 'https://user@downloads.wordpress.org/theme/sample.2.0.zip';
$r = run( 'themes/update', $theme_input ); check( !$r['success'] && !$theme_updates, 'Credential-bearing theme URL accepted' );
$theme_offer['package'] = 'https://downloads.wordpress.org/theme/sample.3.0.zip';
$r = run( 'themes/update', $theme_input ); check( !$r['success'] && !$theme_updates, 'Different theme version package accepted' );
$theme_offer['package'] = 'https://downloads.wordpress.org/theme/sample.2.0.zip';
$r = run( 'themes/update', $theme_input ); check( $r['success'] && $r['active_theme_preserved'] && 1 === $theme_updates, 'Native theme update or preservation failed' );
$r = run( 'themes/update', $theme_input ); check( $r['success'] && !$r['updated'] && 1 === $theme_updates, 'Theme repeat reinstalled' );
$theme_version = '1.0'; $theme_throw = true;
$r = run( 'themes/update', $theme_input ); check( !$r['success'] && $r['software_changed'] && '2.0' === $r['current_version'] && 'read_back' === $r['write_outcome'], 'Theme exception hid saved version' ); $theme_throw = false;
$theme_version = '1.0'; $theme_switch = true;
$r = run( 'themes/update', $theme_input ); check( !$r['success'] && !$r['active_theme_preserved'] && $r['updated'], 'Unexpected theme switch claimed success' ); $theme_switch = false;
$patch = mcp_expose_patch_core_auto_update_constant( $config_before );
check( is_array( $patch ) && str_replace( "'WP_AUTO_UPDATE_CORE', false", "'WP_AUTO_UPDATE_CORE', true", $config_before ) === $patch['content'], 'Config changed outside one literal' );
foreach ( array( "<?php // define('WP_AUTO_UPDATE_CORE', false);\n", "<?php define('WP_AUTO_UPDATE_CORE', false); define('WP_AUTO_UPDATE_CORE', true);", "<?php define('WP_AUTO_UPDATE_CORE', getenv('EXAMPLE_SECRET'));", "<?php if (true) { define('WP_AUTO_UPDATE_CORE', false); }", "<?php if (true) define('WP_AUTO_UPDATE_CORE', false);" ) as $unsupported ) { check( null === mcp_expose_patch_core_auto_update_constant( $unsupported ), 'Ambiguous or dynamic config patched' ); }
$policy = array( 'enabled' => true );
$r = run( 'system/set-core-auto-update-policy', $policy ); check( $r['success'] && $r['preview'] && !$r['raw_config_returned'] && !$wp_filesystem->puts, 'Config preview wrote or disclosed content' );
$policy['expected_config_hash'] = $r['config_hash']; $policy['confirm_dangerous_action'] = 'system/set-core-auto-update-policy';
if ( WP_AUTO_UPDATE_CORE ) { $r = run( 'system/set-core-auto-update-policy', $policy ); check( $r['success'] && $r['unchanged'] && !$wp_filesystem->puts, 'Enabled policy was rewritten' ); }
else {
	$r = run( 'system/set-core-auto-update-policy', array_merge( $policy, array( 'expected_config_hash' => str_repeat( '0', 64 ) ) ) ); check( !$r['success'] && !$wp_filesystem->puts, 'Stale config hash wrote' );
	$wp_filesystem->drift = true; $r = run( 'system/set-core-auto-update-policy', $policy ); check( !$r['success'] && 'stale_config' === $r['code'] && !$wp_filesystem->puts, 'Concurrent config edit overwritten' );
	file_put_contents( ABSPATH . 'wp-config.php', $config_before );
	$wp_filesystem->move_fail = true; $r = run( 'system/set-core-auto-update-policy', $policy ); check( !$r['success'] && false === $r['applied'] && $config_before === file_get_contents( ABSPATH . 'wp-config.php' ), 'Failed config move hid unchanged state' ); $wp_filesystem->move_fail = false;
	$r = run( 'system/set-core-auto-update-policy', $policy ); check( $r['success'] && $r['applied'] && $r['effective_policy_requires_fresh_request'] && $patch['content'] === file_get_contents( ABSPATH . 'wp-config.php' ), 'Narrow config write failed or changed unrelated bytes' );
	file_put_contents( ABSPATH . 'wp-config.php', $config_before ); $wp_filesystem->move_throw = true;
	$r = run( 'system/set-core-auto-update-policy', $policy ); check( !$r['success'] && true === $r['applied'] && 'read_back' === $r['write_outcome'], 'Config exception hid persisted change' );
}
check( !glob( ABSPATH . '.mcp-core-policy-*.php' ) && !WP_Upgrader::$locked, 'Temporary config or lock remained' );
$file_mods = false; foreach ( $names as $name ) { check( !$abilities[$name]['permission_callback'](), 'File modification policy bypassed' ); }
$file_mods = true; $caps['manage_options'] = false; foreach ( $names as $name ) { check( !$abilities[$name]['permission_callback'](), 'Administrator boundary removed' ); }
$caps['manage_options'] = true; $caps['update_core'] = $caps['update_themes'] = false; foreach ( $names as $name ) { check( !$abilities[$name]['permission_callback'](), 'Native update capability removed' ); }
echo "PASS: {$checks} narrow maintenance-write assertions; no generic execution, config disclosure, role changes or backup function\n";
