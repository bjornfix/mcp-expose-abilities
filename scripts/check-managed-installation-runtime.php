<?php
/** Verify signed-member scope, read permissions and redaction through the registered reader. */
declare( strict_types=1 );
$fixture = sys_get_temp_dir() . '/mcp-managed-proof-' . bin2hex( random_bytes( 6 ) ) . '/';
mkdir( $fixture . 'plugins/sample', 0700, true ); mkdir( $fixture . 'wp-admin/includes', 0700, true );
file_put_contents( $fixture . 'wp-admin/includes/plugin.php', "<?php\n" );
register_shutdown_function( static function () use ( $fixture ): void {
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fixture, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) { if ( $item->isDir() && !$item->isLink() ) { rmdir( $item->getPathname() ); } else { unlink( $item->getPathname() ); } } rmdir( $fixture );
} );
define( 'ABSPATH', $fixture ); define( 'WP_PLUGIN_DIR', $fixture . 'plugins' );
$abilities = array(); $admin = $can_update = true;
$plugin_body = "<?php\n// EXAMPLE_SECRET is fixture data and must never be returned.\n";
$asset_body = 'Fixture asset';
file_put_contents( WP_PLUGIN_DIR . '/sample/sample.php', $plugin_body ); file_put_contents( WP_PLUGIN_DIR . '/sample/asset.txt', $asset_body );
file_put_contents( $fixture . 'outside.txt', 'EXAMPLE_SECRET' );
$installed = array( 'sample/sample.php' => array( 'Version' => '1.0' ) );
$members = array( array( 'path' => 'sample/sample.php', 'size' => strlen( $plugin_body ), 'sha256' => hash( 'sha256', $plugin_body ) ), array( 'path' => 'sample/asset.txt', 'size' => strlen( $asset_body ), 'sha256' => hash( 'sha256', $asset_body ) ) );
$entry = array( 'slug' => 'sample', 'file' => 'sample/sample.php', 'version' => '1.0', 'sha256' => str_repeat( 'a', 64 ), 'releaseIdentity' => array( 'mainFile' => 'sample/sample.php', 'source' => array( 'commit' => str_repeat( 'b', 40 ) ), 'members' => $members ) );
$managed = array( 'sample/sample.php' => $entry );
function add_action(...$args) {} function add_filter(...$args) {} function apply_filters( $name, $value, ...$args ) { return $value; }
function current_user_can( $cap, ...$args ) { return 'manage_options' === $cap ? $GLOBALS['admin'] : $GLOBALS['can_update']; }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][$name] = $args; }
function __($text, ...$args) { return $text; } function esc_html__($text, ...$args) { return $text; }
function get_option( $name, $default = false ) { return $default; }
function get_post_types(...$args) { return array(); } function get_taxonomies(...$args) { return array(); } function get_object_taxonomies(...$args) { return array(); }
function WP_Filesystem() { return true; } function plugins_api() {} function activate_plugin() {} function wp_update_plugins() {} function wp_generate_attachment_metadata() {} function wp_create_user() {} function get_current_screen() { return null; }
function get_plugins() { return $GLOBALS['installed']; } function wp_filesize( $path ) { return filesize( $path ); }
class Plugin_Upgrader {} class WP_Ajax_Upgrader_Skin {} class WP_Error {}
if ( '--no-updater' !== ( $argv[1] ?? '' ) ) { function devenia_mcp_updater_manifest_plugins() { return $GLOBALS['managed']; } }
$root = getenv( 'MCP_EXPOSE_SOURCE_DIR' ) ?: dirname( __DIR__ ); require $root . '/mcp-expose-abilities.php'; mcp_register_content_abilities();
$a = $abilities['plugins/verify-managed-installation']; $checks = 0;
function check( $v, $message ) { ++$GLOBALS['checks']; if (!$v) { throw new RuntimeException( $message ); } }
function run( $input = array( 'plugin' => 'sample/sample.php' ) ) { $r = ($GLOBALS['a']['execute_callback'])( $input ); check( !str_contains( json_encode( $r ), 'EXAMPLE_SECRET' ), 'Reader returned file contents' ); return $r; }
if ( '--no-updater' === ( $argv[1] ?? '' ) ) { $r = run(); check( !$r['success'] && 'native_signed_updater_unavailable' === $r['code'], 'Missing trust source accepted' ); echo "PASS: unavailable native trust source fails closed\n"; exit; }
check( $a['permission_callback']() && $a['meta']['annotations']['readonly'] && false === $a['input_schema']['additionalProperties'], 'Reader schema or permissions widened' );
$r = run(); check( $r['success'] && 2 === $r['matched_files'] && !$r['file_contents_returned'] && !$r['additional_files_checked'], 'Signed installed members did not match' );
file_put_contents( WP_PLUGIN_DIR . '/sample/asset.txt', 'Changed asset' ); clearstatcache();
$r = run(); check( !$r['success'] && 1 === $r['matched_files'] && 'mismatch' === $r['files'][1]['status'], 'Changed installed file passed' );
unlink( WP_PLUGIN_DIR . '/sample/asset.txt' ); clearstatcache();
$r = run(); check( !$r['success'] && null === $r['files'][1]['actual_sha256'], 'Missing file passed' );
symlink( $fixture . 'outside.txt', WP_PLUGIN_DIR . '/sample/asset.txt' ); clearstatcache();
$r = run(); check( !$r['success'] && null === $r['files'][1]['actual_sha256'] && 'missing_or_outside_plugin' === $r['files'][1]['status'], 'External symlink was hashed' );
unlink( WP_PLUGIN_DIR . '/sample/asset.txt' ); file_put_contents( WP_PLUGIN_DIR . '/sample/asset.txt', $asset_body ); clearstatcache();
$managed['sample/sample.php']['releaseIdentity']['members'][1]['path'] = 'sample/../outside.txt';
$r = run(); check( !$r['success'] && 'invalid_signed_member_path' === $r['code'], 'Manifest traversal accepted' );
$managed['sample/sample.php'] = $entry; $installed['sample/sample.php']['Version'] = '0.9';
$r = run(); check( !$r['success'] && 2 === $r['matched_files'], 'Version mismatch passed' );
$installed['sample/sample.php']['Version'] = '1.0';
$r = run( array( 'plugin' => '../wp-config.php' ) ); check( !$r['success'] && 'invalid_plugin_identifier' === $r['code'], 'Caller path accepted' );
$managed = array(); $r = run(); check( !$r['success'] && 'installed_signed_identity_unavailable' === $r['code'], 'Unmanaged source accepted' );
$admin = false; check( !$a['permission_callback']() && 'permission_denied' === run()['code'], 'Administrator boundary removed' );
$admin = true; $can_update = false; check( !$a['permission_callback']() && 'permission_denied' === run()['code'], 'Native update capability removed' );
echo "PASS: {$checks} signed installation assertions; no caller file access, private contents or out-of-root reads\n";
