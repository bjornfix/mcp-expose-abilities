<?php
/**
 * Prove that plugin update execution loads the WordPress Screen API outside wp-admin.
 */

declare( strict_types=1 );

$fixture_root = sys_get_temp_dir() . '/mcp-expose-update-runtime-' . bin2hex( random_bytes( 8 ) ) . '/';
$screen_dir   = $fixture_root . 'wp-admin/includes';
if ( ! mkdir( $screen_dir, 0700, true ) && ! is_dir( $screen_dir ) ) {
	throw new RuntimeException( 'Could not create the WordPress runtime fixture.' );
}

$screen_file = $screen_dir . '/screen.php';
$screen_code = <<<'PHP'
<?php
function get_current_screen() {
	return null;
}
PHP;
if ( false === file_put_contents( $screen_file, $screen_code ) ) {
	throw new RuntimeException( 'Could not create the WordPress Screen API fixture.' );
}

register_shutdown_function(
	static function () use ( $screen_file, $screen_dir, $fixture_root ): void {
		@unlink( $screen_file );
		@rmdir( $screen_dir );
		@rmdir( dirname( $screen_dir ) );
		@rmdir( $fixture_root );
	}
);

define( 'ABSPATH', $fixture_root );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );

function add_filter(): bool { return true; }
function add_action(): bool { return true; }
function apply_filters( string $hook, $value ) { return $value; }
function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
function WP_Filesystem(): bool { return true; }
function plugins_api() { return null; }
function activate_plugin(): null { return null; }
function wp_update_plugins(): null { return null; }
function wp_generate_attachment_metadata(): array { return array(); }
function wp_create_user(): int { return 1; }

class Plugin_Upgrader {}
class WP_Ajax_Upgrader_Skin {}
class WP_Error {}

require dirname( __DIR__ ) . '/mcp-expose-abilities.php';

if ( ! function_exists( 'get_current_screen' ) ) {
	throw new RuntimeException( 'The WordPress Screen API was not loaded for an out-of-admin plugin update request.' );
}

if ( null !== get_current_screen() ) {
	throw new RuntimeException( 'The Screen API fixture returned an unexpected screen.' );
}

fwrite( STDOUT, "Plugin update admin runtime checks passed.\n" );
