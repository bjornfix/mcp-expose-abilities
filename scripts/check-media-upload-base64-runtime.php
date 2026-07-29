<?php
/**
 * Standalone behavior contract for the owned base64 media upload Ability.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['registered_abilities'] = array();
$GLOBALS['media_fixture'] = array(
	'can_edit'            => true,
	'uploads'             => 0,
	'detected'            => array( 'ext' => 'webp', 'type' => 'image/webp' ),
	'deleted_files'       => array(),
	'deleted_attachments' => array(),
	'insert'              => 101,
	'metadata'            => array( 'width' => 500, 'height' => 500 ),
);

function add_action( ...$args ): void { unset( $args ); }
function add_filter( ...$args ): void { unset( $args ); }
function apply_filters( string $name, $value, ...$args ) { unset( $name, $args ); return $value; }
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( string $text ): string { return $text; }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function sanitize_textarea_field( $value ): string { return trim( (string) $value ); }
function sanitize_file_name( $value ): string { return basename( (string) $value ); }
function sanitize_mime_type( $value ): string { return strtolower( trim( (string) $value ) ); }
function absint( $value ): int { return abs( (int) $value ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function current_user_can( string $capability, ...$args ): bool {
	if ( 'edit_post' === $capability ) {
		return (bool) $GLOBALS['media_fixture']['can_edit'];
	}
	return 'upload_files' === $capability;
}
function get_post( int $post_id ) { return $post_id > 0 ? (object) array( 'ID' => $post_id ) : null; }
function get_post_types( array $args = array(), string $output = 'names' ): array { unset( $args, $output ); return array(); }
function get_taxonomies( array $args = array(), string $output = 'names' ): array { unset( $args, $output ); return array(); }
function get_object_taxonomies( string $post_type, string $output = 'names' ): array { unset( $post_type, $output ); return array(); }
function get_option( string $name, $default = false ) { unset( $name ); return $default; }
function WP_Filesystem(): bool { return true; }
function plugins_api() {}
function activate_plugin() {}
function wp_update_plugins() {}
function wp_create_user() {}
function wp_register_ability( string $name, array $args ): void { $GLOBALS['registered_abilities'][ $name ] = $args; }
function wp_check_filetype( string $filename, ?array $mimes = null ): array {
	unset( $mimes );
	$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$types = array( 'svg' => 'image/svg+xml', 'webp' => 'image/webp' );
	return isset( $types[ $extension ] ) ? array( 'ext' => $extension, 'type' => $types[ $extension ] ) : array( 'ext' => false, 'type' => false );
}
function wp_check_filetype_and_ext( string $path, string $filename, array $mimes ): array { unset( $path, $filename, $mimes ); return $GLOBALS['media_fixture']['detected']; }
function wp_upload_bits( string $filename, $deprecated, string $bytes ): array {
	unset( $deprecated, $bytes );
	$GLOBALS['media_fixture']['uploads']++;
	return array( 'file' => '/tmp/' . $filename, 'url' => 'https://example.com/uploads/' . $filename, 'error' => '' );
}
function wp_delete_file( string $path ): void { $GLOBALS['media_fixture']['deleted_files'][] = $path; }
function wp_insert_attachment( array $attachment, string $path, int $post_id = 0, bool $wp_error = false ) { unset( $attachment, $path, $post_id, $wp_error ); return $GLOBALS['media_fixture']['insert']; }
function wp_generate_attachment_metadata( int $attachment_id, string $path ) { unset( $attachment_id, $path ); return $GLOBALS['media_fixture']['metadata']; }
function wp_update_attachment_metadata( int $attachment_id, array $metadata ): bool { unset( $attachment_id, $metadata ); return true; }
function wp_delete_attachment( int $attachment_id, bool $force_delete = false ): bool { unset( $force_delete ); $GLOBALS['media_fixture']['deleted_attachments'][] = $attachment_id; return true; }
function update_post_meta( int $post_id, string $key, $value ): bool { unset( $post_id, $key, $value ); return true; }
function wp_getimagesize( string $path ) { unset( $path ); return array( 500, 500 ); }

final class Plugin_Upgrader {}
final class WP_Ajax_Upgrader_Skin {}
final class WP_Error {
	public function __construct( private string $code, private string $message ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

require_once dirname( __DIR__ ) . '/mcp-expose-abilities.php';

mcp_register_content_abilities();
$ability = $GLOBALS['registered_abilities']['media/upload-base64'] ?? null;
if ( ! is_array( $ability ) || ! is_callable( $ability['execute_callback'] ?? null ) ) {
	throw new RuntimeException( 'The owned media/upload-base64 Ability is unavailable.' );
}
if ( 'mcp_expose_upload_base64_media' !== ( $ability['execute_callback'] ?? null ) ) {
	throw new RuntimeException( 'The Ability must use the directly tested Media Module callback.' );
}
$execute = $ability['execute_callback'];

$svg = $execute( array( 'filename' => 'unsafe.svg', 'mime_type' => 'image/svg+xml', 'base64' => base64_encode( '<svg/>' ) ) );
if ( false !== ( $svg['success'] ?? null ) || 0 !== $GLOBALS['media_fixture']['uploads'] ) {
	throw new RuntimeException( 'SVG reached the write seam without an SVG sanitizer.' );
}

$GLOBALS['media_fixture']['can_edit'] = false;
$denied = $execute( array( 'filename' => 'hero.webp', 'mime_type' => 'image/webp', 'base64' => base64_encode( 'bytes' ), 'post_id' => 44 ) );
if ( false !== ( $denied['success'] ?? null ) || 0 !== $GLOBALS['media_fixture']['uploads'] ) {
	throw new RuntimeException( 'A parent post bypassed exact edit authority.' );
}
$GLOBALS['media_fixture']['can_edit'] = true;

$GLOBALS['media_fixture']['detected'] = array( 'ext' => false, 'type' => false );
$mismatch = $execute( array( 'filename' => 'hero.webp', 'mime_type' => 'image/webp', 'base64' => base64_encode( 'not-a-webp' ) ) );
if ( false !== ( $mismatch['success'] ?? null ) || array( '/tmp/hero.webp' ) !== $GLOBALS['media_fixture']['deleted_files'] ) {
	throw new RuntimeException( 'A byte/type mismatch was not rejected and cleaned up.' );
}

$GLOBALS['media_fixture']['detected'] = array( 'ext' => 'webp', 'type' => 'image/webp' );
$GLOBALS['media_fixture']['insert'] = new WP_Error( 'insert_failed', 'Fixture insert failed.' );
$insert_failure = $execute( array( 'filename' => 'second.webp', 'mime_type' => 'image/webp', 'base64' => base64_encode( 'bytes' ) ) );
if ( false !== ( $insert_failure['success'] ?? null ) || ! in_array( '/tmp/second.webp', $GLOBALS['media_fixture']['deleted_files'], true ) ) {
	throw new RuntimeException( 'An attachment insert failure did not clean up its uploaded file.' );
}

$GLOBALS['media_fixture']['insert'] = 102;
$GLOBALS['media_fixture']['metadata'] = false;
$metadata_failure = $execute( array( 'filename' => 'third.webp', 'mime_type' => 'image/webp', 'base64' => base64_encode( 'bytes' ) ) );
if ( false !== ( $metadata_failure['success'] ?? null ) || array( 102 ) !== $GLOBALS['media_fixture']['deleted_attachments'] ) {
	throw new RuntimeException( 'An image metadata failure left an orphaned attachment.' );
}

fwrite( STDOUT, "Base64 media upload runtime passed.\n" );
