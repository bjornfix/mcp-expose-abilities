<?php
/**
 * Standalone behavior contract for one-time Application Password provisioning.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['registered_abilities'] = array();
$GLOBALS['editable_user_ids']    = array( 194, 195 );
$GLOBALS['global_caps']          = array( 'edit_users' );
$GLOBALS['fixture_users']        = array(
	194 => (object) array( 'ID' => 194, 'allcaps' => array( 'read' => true ) ),
	195 => (object) array( 'ID' => 195, 'allcaps' => array( 'read' => true, 'assign_terms' => true ) ),
);

function add_action( ...$args ): void { unset( $args ); }
function add_filter( ...$args ): void { unset( $args ); }
function apply_filters( string $name, $value, ...$args ) { unset( $name, $args ); return $value; }
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( string $text ): string { return $text; }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function current_user_can( string $capability, ...$args ): bool {
	if ( 'edit_user' === $capability ) {
		return in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['editable_user_ids'], true );
	}
	return in_array( $capability, $GLOBALS['global_caps'], true );
}
function get_user_by( string $field, $value ) {
	return 'id' === $field ? ( $GLOBALS['fixture_users'][ (int) $value ] ?? false ) : false;
}
function wp_is_application_passwords_available_for_user( $user ): bool { return 194 === (int) ( $user->ID ?? 0 ); }
function wp_is_uuid( $value ): bool { return 1 === preg_match( '/^[a-f0-9-]{36}$/i', (string) $value ); }
function wp_register_ability( string $name, array $args ): void { $GLOBALS['registered_abilities'][ $name ] = $args; }
function get_post_types( array $args = array(), string $output = 'names' ): array { unset( $args, $output ); return array(); }
function get_taxonomies( array $args = array(), string $output = 'names' ): array { unset( $args, $output ); return array(); }
function get_object_taxonomies( string $post_type, string $output = 'names' ): array { unset( $post_type, $output ); return array(); }
function get_option( string $name, $default = false ) { unset( $name ); return $default; }
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
final class WP_Application_Passwords {
	public static array $calls = array();
	public static array $deletions = array();
	public static string $mode = 'success';
	public static $delete_result = true;
	public static function create_new_application_password( int $user_id, array $args ) {
		self::$calls[] = array( $user_id, $args );
		if ( 'malformed' === self::$mode ) {
			return array( 'orphaned plaintext', array( 'uuid' => '33333333-3333-4333-8333-333333333333' ) );
		}
		return array(
			'fixture plaintext application password',
			array(
				'uuid'      => '11111111-1111-4111-8111-111111111111',
				'app_id'    => '22222222-2222-4222-8222-222222222222',
				'name'      => $args['name'],
				'password'  => '$generic$fixture-stored-verifier',
				'created'   => 1770000000,
				'last_used' => null,
				'last_ip'   => null,
			),
		);
	}
	public static function delete_application_password( int $user_id, string $uuid ) {
		self::$deletions[] = array( $user_id, $uuid );
		return self::$delete_result;
	}
}

require_once dirname( __DIR__ ) . '/mcp-expose-abilities.php';

mcp_register_content_abilities();

$ability = $GLOBALS['registered_abilities']['users/create-restricted-application-password'] ?? null;
if ( ! is_array( $ability ) ) {
	throw new RuntimeException( 'Application Password provisioning ability was not registered.' );
}

$permission = $ability['permission_callback'];
if ( true !== $permission( array( 'user_id' => 194 ) ) || false !== $permission( array( 'user_id' => 195 ) ) ) {
	throw new RuntimeException( 'Provisioning did not enforce exact-target edit authority and restricted target capabilities.' );
}
$GLOBALS['global_caps'] = array();
if ( false !== $permission( array( 'user_id' => 194 ) ) ) {
	throw new RuntimeException( 'A self-editable low-privilege principal could mint another credential.' );
}
$GLOBALS['global_caps'] = array( 'edit_users' );

$execute = $ability['execute_callback'];
$recipient_keypair  = sodium_crypto_box_keypair();
$recipient_public   = sodium_crypto_box_publickey( $recipient_keypair );
$recipient_encoded  = base64_encode( $recipient_public );
$unconfirmed = $execute(
	array(
		'user_id'             => 194,
		'name'                => 'Devenia Slop Reviewer',
		'app_id'              => '22222222-2222-4222-8222-222222222222',
		'recipient_public_key' => $recipient_encoded,
	)
);
if ( false !== ( $unconfirmed['success'] ?? null ) || array_key_exists( 'password', $unconfirmed ) || array() !== WP_Application_Passwords::$calls ) {
	throw new RuntimeException( 'Provisioning created or exposed a credential without exact confirmation.' );
}

$result  = $execute(
	array(
		'user_id'             => 194,
		'name'                => 'Devenia Slop Reviewer',
		'app_id'              => '22222222-2222-4222-8222-222222222222',
		'recipient_public_key' => $recipient_encoded,
		'confirm'             => 'create_restricted_application_password',
	)
);

if ( array(
	'success',
	'user_id',
	'uuid',
	'app_id',
	'name',
	'password_ciphertext',
	'password_hash',
	'created',
) !== array_keys( $result ) ) {
	throw new RuntimeException( 'Provisioning response did not preserve the closed one-time credential contract.' );
}
$ciphertext = base64_decode( (string) $result['password_ciphertext'], true );
$plaintext  = is_string( $ciphertext ) ? sodium_crypto_box_seal_open( $ciphertext, $recipient_keypair ) : false;
if ( 'fixture plaintext application password' !== $plaintext || '$generic$fixture-stored-verifier' !== $result['password_hash'] || array_key_exists( 'password', $result ) ) {
	throw new RuntimeException( 'Provisioning exposed plaintext or lost the sealed secret or exact WordPress verifier.' );
}
if ( array( array( 194, array( 'name' => 'Devenia Slop Reviewer', 'app_id' => '22222222-2222-4222-8222-222222222222' ) ) ) !== WP_Application_Passwords::$calls ) {
	throw new RuntimeException( 'Provisioning changed the exact WordPress core call.' );
}

WP_Application_Passwords::$mode = 'malformed';
$malformed = $execute(
	array(
		'user_id'             => 194,
		'name'                => 'Malformed fixture',
		'recipient_public_key' => $recipient_encoded,
		'confirm'             => 'create_restricted_application_password',
	)
);
if ( false !== ( $malformed['success'] ?? null ) || array_key_exists( 'password', $malformed ) ) {
	throw new RuntimeException( 'Malformed core output exposed an unusable credential.' );
}
if ( array( array( 194, '33333333-3333-4333-8333-333333333333' ) ) !== WP_Application_Passwords::$deletions ) {
	throw new RuntimeException( 'Malformed core output left an orphaned Application Password.' );
}
WP_Application_Passwords::$mode = 'success';

WP_Application_Passwords::$mode          = 'malformed';
WP_Application_Passwords::$delete_result = false;
$orphaned = $execute(
	array(
		'user_id'             => 194,
		'name'                => 'Rollback failure fixture',
		'recipient_public_key' => $recipient_encoded,
		'confirm'             => 'create_restricted_application_password',
	)
);
if ( false !== ( $orphaned['success'] ?? null ) || true !== ( $orphaned['cleanup_required'] ?? null ) || '33333333-3333-4333-8333-333333333333' !== ( $orphaned['uuid'] ?? '' ) || array_key_exists( 'password', $orphaned ) ) {
	throw new RuntimeException( 'Rollback failure hid the exact orphaned credential identity or exposed its secret.' );
}
WP_Application_Passwords::$mode          = 'success';
WP_Application_Passwords::$delete_result = true;

WP_Application_Passwords::$mode          = 'malformed';
WP_Application_Passwords::$delete_result = new WP_Error( 'delete_failed', 'Fixture cleanup failed.' );
$cleanup_error = $execute(
	array(
		'user_id'             => 194,
		'name'                => 'Cleanup error fixture',
		'recipient_public_key' => $recipient_encoded,
		'confirm'             => 'create_restricted_application_password',
	)
);
if ( true !== ( $cleanup_error['cleanup_required'] ?? null ) || '33333333-3333-4333-8333-333333333333' !== ( $cleanup_error['uuid'] ?? '' ) ) {
	throw new RuntimeException( 'WP_Error cleanup failure was reported as a successful rollback.' );
}
WP_Application_Passwords::$mode          = 'success';
WP_Application_Passwords::$delete_result = true;

$calls_before_privileged_target = count( WP_Application_Passwords::$calls );
$privileged_target = $execute(
	array(
		'user_id'             => 195,
		'name'                => 'Privileged target fixture',
		'recipient_public_key' => $recipient_encoded,
		'confirm'             => 'create_restricted_application_password',
	)
);
if ( false !== ( $privileged_target['success'] ?? null ) || $calls_before_privileged_target !== count( WP_Application_Passwords::$calls ) ) {
	throw new RuntimeException( 'Provisioning minted a credential for a target with generic write authority.' );
}

$missing = $execute( array( 'user_id' => 999, 'name' => 'Missing user' ) );
if ( true !== array_key_exists( 'success', $missing ) || false !== $missing['success'] || array_key_exists( 'password', $missing ) ) {
	throw new RuntimeException( 'A failed provisioning attempt exposed a credential field.' );
}

fwrite( STDOUT, "Application Password provisioning ability runtime passed.\n" );
