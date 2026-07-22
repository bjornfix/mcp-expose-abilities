<?php
/**
 * Standalone regression for neutral marker and write-policy extension seams.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function add_action( ...$args ): void { unset( $args ); }
function add_filter( ...$args ): void { unset( $args ); }
function apply_filters( string $name, $value, ...$args ) {
	if ( 'mcp_content_design_markup_markers' === $name ) {
		$content = (string) ( $args[0] ?? '' );
		if ( false !== strpos( $content, 'invalid-adapter-output' ) ) {
			return new WP_Error( 'invalid_adapter_output', 'Fixture invalid marker response.' );
		}
		if ( false !== strpos( $content, 'site-surface--' ) ) {
			$value[] = 'site-semantic-surface';
		}
		return $value;
	}
	if ( 'mcp_content_write_preflight' === $name && ! empty( $GLOBALS['site_write_policy_enabled'] ) ) {
		$GLOBALS['site_write_policy_calls']++;
		$context = is_array( $args[0] ?? null ) ? $args[0] : array();
		if ( 'page' !== (string) ( $context['post_type'] ?? '' ) || 'content/create-page' !== (string) ( $context['ability'] ?? '' ) ) {
			throw new RuntimeException( 'The neutral write context lost caller intent.' );
		}
		return new WP_Error( 'site_policy_rejected', 'Rejected by fixture site policy.' );
	}
	if ( 'mcp_expose_plugin_update_allowed_by_policy' === $name && array_key_exists( 'plugin_update_policy_result', $GLOBALS ) ) {
		return $GLOBALS['plugin_update_policy_result'];
	}
	return $value;
}
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?: ''; }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function WP_Filesystem(): bool { return true; }
function plugins_api() {}
function activate_plugin() {}
function wp_update_plugins() {}
function wp_generate_attachment_metadata() {}
function wp_create_user() {}

final class Plugin_Upgrader {}
final class WP_Ajax_Upgrader_Skin {}
final class WP_Error {
	public function __construct( private string $code, private string $message, public $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

require_once dirname( __DIR__ ) . '/mcp-expose-abilities.php';

$designed_content = '<!-- wp:group {"className":"site-surface--brand"} /-->';
$plain_content    = '<!-- wp:paragraph --><p>Flattened content.</p><!-- /wp:paragraph -->';

$markers = mcp_expose_detect_design_markup_markers( $designed_content );
if ( ! in_array( 'site-semantic-surface', $markers, true ) ) {
	throw new RuntimeException( 'A site Adapter cannot extend guarded design-marker detection.' );
}
$marker_result = mcp_expose_validate_content_design_markup_preserved( $designed_content, $plain_content, array() );
if ( ! $marker_result instanceof WP_Error || 'mcp_design_markup_loss_blocked' !== $marker_result->get_error_code() ) {
	throw new RuntimeException( 'Guarded mode did not reject site-supplied design marker removal.' );
}

$built_in_markers = mcp_expose_detect_design_markup_markers( '<!-- wp:columns --><div>invalid-adapter-output</div><!-- /wp:columns -->' );
if ( ! in_array( 'core-layout', $built_in_markers, true ) ) {
	throw new RuntimeException( 'Invalid Adapter output discarded built-in guarded design evidence.' );
}

$GLOBALS['plugin_update_policy_result'] = new WP_Error( 'invalid_policy_output', 'Fixture invalid policy response.' );
if ( mcp_expose_is_policy_allowed_plugin_update( 'fixture/fixture.php' ) ) {
	throw new RuntimeException( 'A truthy non-boolean update-policy response authorized plugin code mutation.' );
}

$GLOBALS['site_write_policy_enabled'] = false;
$GLOBALS['site_write_policy_calls']   = 0;
$without_adapter = mcp_expose_validate_content_write_policy( null, 'page', 'publish', $designed_content, array(), 'content/create-page' );
if ( true !== $without_adapter || 0 !== $GLOBALS['site_write_policy_calls'] ) {
	throw new RuntimeException( 'The public write Module is not neutral without a site Adapter.' );
}

$GLOBALS['site_write_policy_enabled'] = true;
$with_adapter = mcp_expose_validate_content_write_policy( null, 'page', 'publish', $designed_content, array(), 'content/create-page' );
if ( ! $with_adapter instanceof WP_Error || 1 !== $GLOBALS['site_write_policy_calls'] ) {
	throw new RuntimeException( 'The public write Module did not preserve a site Adapter rejection.' );
}

fwrite( STDOUT, "Neutral marker and content-write policy runtime passed.\n" );
