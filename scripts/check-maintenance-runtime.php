<?php
/** Exercise maintenance through registered callbacks with hostile native result fields. */
declare( strict_types=1 );
$fixture = sys_get_temp_dir() . '/mcp-maintenance-' . bin2hex( random_bytes( 5 ) ) . '/';
mkdir( $fixture . 'wp-admin/includes', 0700, true );
foreach ( array( 'plugin.php', 'update.php', 'class-wp-upgrader.php', 'class-wp-site-health.php' ) as $file ) { file_put_contents( $fixture . 'wp-admin/includes/' . $file, "<?php\n" ); }
file_put_contents( $fixture . 'wp-admin/includes/misc.php', '<?php function wp_check_php_version() { return array( \'recommended_version\' => PHP_VERSION ); }' );
if ( getenv( 'MCP_EXPOSE_NATIVE_SITE_HEALTH_CLASS' ) ) {
	$native_health = file_get_contents( getenv( 'MCP_EXPOSE_NATIVE_SITE_HEALTH_CLASS' ) );
	if ( ! preg_match( '/public function get_test_php_version\(\).*?\n\t\}/s', $native_health, $native_method ) ) { throw new RuntimeException( 'Native PHP health method unavailable.' ); }
	file_put_contents( $fixture . 'wp-admin/includes/native-php-health.php', '<?php class FixtureNativePhpHealth { ' . $native_method[0] . ' }' );
	require $fixture . 'wp-admin/includes/native-php-health.php';
}
register_shutdown_function( static function () use ( $fixture ): void { foreach ( glob( $fixture . 'wp-admin/includes/*' ) as $file ) { unlink( $file ); } rmdir( $fixture . 'wp-admin/includes' ); rmdir( $fixture . 'wp-admin' ); rmdir( $fixture ); } );
define( 'ABSPATH', $fixture );
define( 'WP_PLUGIN_DIR', $fixture );
define( 'WPINC', 'wp-admin/includes' );
file_put_contents( $fixture . 'wp-admin/includes/version.php', '<?php $wp_version = \'7.0\';' );
if ( 'false' === ( $argv[1] ?? '' ) ) { define( 'WP_AUTO_UPDATE_CORE', false ); }
if ( 'true' === ( $argv[1] ?? '' ) ) { define( 'WP_AUTO_UPDATE_CORE', true ); }
if ( 'minor' === ( $argv[1] ?? '' ) ) { define( 'WP_AUTO_UPDATE_CORE', 'minor' ); }
$abilities = array(); $caps = array_fill_keys( array( 'manage_options', 'update_core', 'update_plugins', 'update_themes', 'view_site_health_checks' ), true );
$wp_version = '7.0'; $refreshes = 0; $cache_cleans = 0; $automatic_disabled = false; $health_fail = false; $health_bad_status = false;
$options = array( 'auto_update_plugins' => array( 'sample/sample.php' ), 'auto_update_themes' => array(), 'auto_update_core_major' => 'disabled', 'auto_update_core_minor' => 'enabled' );
$caches = array(
	'update_plugins' => (object) array( 'last_checked' => 1234, 'response' => array( 'sample/sample.php' => (object) array( 'new_version' => '2.0', 'plugin' => 'sample/sample.php', 'package' => 'https://SECRET', 'token' => 'SECRET' ) ) ),
	'update_themes' => (object) array( 'last_checked' => 1234, 'response' => array( 'sample' => array( 'new_version' => '2.0', 'theme' => 'sample', 'package' => 'https://SECRET' ) ) ),
	'update_core' => (object) array( 'last_checked' => 1234, 'updates' => array( (object) array( 'current' => '7.0.1', 'response' => 'upgrade', 'download' => 'https://SECRET' ) ) ),
);
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters( $name, $value, ...$args ) { return 'auto_update_theme' === $name ? true : $value; }
function current_user_can( $cap ) { return $GLOBALS['caps'][ $cap ] ?? false; }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][ $name ] = $args; }
function wp_normalize_path( $path ) { return $path; }
function __($text, ...$args) { return $text; }
function esc_html__( $text, ...$args ) { return $text; }
function esc_url( $value ) { return $value; }
function wp_get_update_php_url() { return 'https://example.org/php'; }
function get_post_types(...$args) { return array(); }
function get_taxonomies(...$args) { return array(); }
function get_object_taxonomies(...$args) { return array(); }
function get_option( $key, $default = false ) { return $default; }
function get_current_screen() { return null; }
function WP_Filesystem() { throw new RuntimeException( 'No filesystem actions permitted' ); }
function plugins_api() {}
function activate_plugin() { throw new RuntimeException( 'No activation permitted' ); }
function wp_update_plugins() { ++$GLOBALS['refreshes']; }
function wp_update_themes() { ++$GLOBALS['refreshes']; }
function wp_clean_update_cache() { ++$GLOBALS['cache_cleans']; }
function wp_version_check() { ++$GLOBALS['refreshes']; }
function wp_generate_attachment_metadata() {}
function wp_create_user() {}
function wp_is_auto_update_enabled_for_type( $type ) { return true; }
function get_plugins() { return array( 'sample/sample.php' => array( 'Version' => '1.0', 'Name' => 'SECRET' ) ); }
function wp_get_themes() { return array( 'sample' => new class { public function get( $key ) { return '1.0'; } } ); }
function get_site_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function get_site_transient( $key ) { return $GLOBALS['caches'][ $key ] ?? false; }
class Plugin_Upgrader {}
class WP_Ajax_Upgrader_Skin {}
class WP_Automatic_Updater { public function is_disabled() { return $GLOBALS['automatic_disabled']; } }
if ( getenv( 'MCP_EXPOSE_NATIVE_CORE_UPGRADER' ) ) {
	class WP_Upgrader {}
	require getenv( 'MCP_EXPOSE_NATIVE_CORE_UPGRADER' );
} else {
	class Core_Upgrader { public static function should_update_to_version( $version ) { return !defined( 'WP_AUTO_UPDATE_CORE' ) || false !== WP_AUTO_UPDATE_CORE; } }
}
if ( getenv( 'MCP_EXPOSE_NATIVE_AUTO_UPDATE_READER' ) ) {
	require getenv( 'MCP_EXPOSE_NATIVE_AUTO_UPDATE_READER' );
} else {
	function wp_is_auto_update_forced_for_item( $type, $update, $item ) { return apply_filters( 'auto_update_' . $type, $update, $item ); }
}
class WP_Site_Health {
	public static function get_instance() { return new self(); }
	public function __call( $name, $args ) {
		if ( $GLOBALS['health_fail'] && 'get_test_php_version' === $name ) { throw new RuntimeException( 'SECRET' ); }
		if ( 'get_test_php_version' === $name ) {
			wp_check_php_version();
			if ( class_exists( 'FixtureNativePhpHealth' ) ) { return ( new FixtureNativePhpHealth() )->get_test_php_version(); }
		}
		return array( 'status' => $GLOBALS['health_bad_status'] ? 'SECRET' : 'good', 'label' => 'SECRET', 'description' => 'SECRET', 'actions' => 'SECRET', 'debug' => array( 'SECRET' ) );
	}
}
class WP_Error {}
$root = getenv( 'MCP_EXPOSE_SOURCE_DIR' ) ?: dirname( __DIR__ );
require $root . '/mcp-expose-abilities.php';
mcp_register_content_abilities();
$checks = 0;
function check( $v, $message ) { ++$GLOBALS['checks']; if ( !$v ) { throw new RuntimeException( $message ); } }
function run( $name, $input = array() ) { $a = $GLOBALS['abilities'][ $name ]; check( $a['permission_callback'](), 'Permission preflight failed' ); $r = ($a['execute_callback'])( $input ); check( !str_contains( json_encode( $r ), 'SECRET' ), 'Secret disclosed' ); return $r; }
$r = run( 'system/get-maintenance-status' );
check( $r['success'] && !$refreshes, 'Default read refreshed providers or missed caches' );
check( null === $r['plugins']['items'][0]['offer_auto_update_forced'] && !$r['saved_plugin_theme_choices_included'] && 'options/get' === $r['saved_choices_reader'], 'Unforced offer conflated with saved choice' );
check( $r['themes']['items'][0]['offer_auto_update_forced'] && $r['themes']['auto_update_ui_enabled'], 'Native forced theme policy omitted' );
check( !$r['plugins']['items'][0]['mcp_update_allowed_by_policy'], 'Existing code-write guard bypassed' );
check( $r['core']['constant_change_supported'], 'Narrow configuration operation missing' );
if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) { check( WP_AUTO_UPDATE_CORE === $r['core']['auto_update_constant'], 'Constant enum lost' ); }
if ( getenv( 'MCP_EXPOSE_NATIVE_CORE_UPGRADER' ) ) {
	check( (!defined( 'WP_AUTO_UPDATE_CORE' ) || false !== WP_AUTO_UPDATE_CORE) === $r['core']['offers'][0]['offer_auto_update_allowed'], 'Native minor policy differs from report' );
	check( (defined( 'WP_AUTO_UPDATE_CORE' ) && true === WP_AUTO_UPDATE_CORE) === Core_Upgrader::should_update_to_version( '7.1' ), 'Native major policy differs from constant' );
}
run( 'system/get-maintenance-status', array( 'refresh' => true ) ); check( 3 === $refreshes && 1 === $cache_cleans, 'Refresh failed, used stale cache or repeated' );
$automatic_disabled = true;
$r = run( 'system/get-maintenance-status' );
check( $r['automatic_updater_disabled'] && !$r['core']['offers'][0]['offer_auto_update_allowed'], 'Global automatic-updater guard omitted' );
$caches = array();
$r = run( 'system/get-maintenance-status' ); check( !$r['success'] && null === $r['plugins']['items'][0]['update_available'] && !$r['core']['inventory_known'], 'Missing cache claimed no updates' );
$r = run( 'system/get-site-health' ); check( $r['success'] && 11 === count( $r['tests'] ) && !$r['debug_dump_returned'], 'Fixed native test coverage missing' );
$health_fail = true;
$r = run( 'system/get-site-health' ); check( !$r['complete'] && 'unavailable' === $r['tests'][3]['status'] && 'good' === $r['tests'][0]['status'], 'Partial native test failure destroyed other evidence' );
$health_bad_status = true;
$r = run( 'system/get-site-health' ); check( !$r['success'] && 'unavailable' === $r['tests'][0]['status'], 'Arbitrary native status escaped whitelist' );
foreach ( array( 'system/get-maintenance-status', 'system/get-site-health' ) as $name ) { check( false === $abilities[$name]['input_schema']['additionalProperties'], 'Arbitrary arguments accepted' ); }
$caps['manage_options'] = false;
check( !$abilities['system/get-maintenance-status']['permission_callback']() && !$abilities['system/get-site-health']['permission_callback'](), 'Administrator boundary removed' );
$caps['manage_options'] = true; $caps['update_core'] = false; $caps['view_site_health_checks'] = false;
check( !$abilities['system/get-maintenance-status']['permission_callback']() && !$abilities['system/get-site-health']['permission_callback'](), 'Native permissions removed' );
echo "PASS: {$checks} maintenance assertions; status redaction, native permissions, unknown inventory, saved/effective policy and partial health failures\n";
