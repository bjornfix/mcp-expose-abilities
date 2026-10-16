<?php
/**
 * Prove that MCP Expose binds the generic execute Ability to the standalone
 * MCP Adapter before a later plugin can claim the same class name.
 */

declare( strict_types=1 );

define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

define( 'ABSPATH', __DIR__ . '/wordpress-fixture/' );
define( 'WP_PLUGIN_DIR', getenv( 'MCP_EXPOSE_RUNTIME_PLUGIN_DIR' ) ?: dirname( __DIR__, 2 ) );

/** @var array<string,array<int,array{callback:callable,priority:int}>> $mcp_expose_runtime_filters */
$mcp_expose_runtime_filters = array();

function add_filter( string $hook, callable $callback, int $priority = 10 ): bool {
	global $mcp_expose_runtime_filters;
	$mcp_expose_runtime_filters[ $hook ][] = array( 'callback' => $callback, 'priority' => $priority );
	return true;
}

function add_action( string $hook, callable $callback, int $priority = 10 ): bool {
	return add_filter( $hook, $callback, $priority );
}

function apply_filters( string $hook, $value ) {
	global $mcp_expose_runtime_filters;
	$callbacks = $mcp_expose_runtime_filters[ $hook ] ?? array();
	usort( $callbacks, static fn( array $left, array $right ): int => $left['priority'] <=> $right['priority'] );
	foreach ( $callbacks as $registered ) {
		$value = $registered['callback']( $value );
	}
	return $value;
}

function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path );
}

function __( string $text, string $domain = '' ): string { return $text; }

function WP_Filesystem(): bool { return true; }
function plugins_api() { return null; }
function activate_plugin(): null { return null; }
function wp_update_plugins(): null { return null; }
function wp_generate_attachment_metadata(): array { return array(); }
function wp_create_user(): int { return 1; }
function get_current_screen() { return null; }

class Plugin_Upgrader {}
class WP_Ajax_Upgrader_Skin {}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
	public function get_error_data() { return $this->data; }
	public function get_error_message(): string { return $this->message; }
}

function wp_has_ability( string $name ): bool { return wp_get_ability( $name ) instanceof WP_Ability; }
function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function is_user_logged_in(): bool { return true; }
function get_current_user_id(): int { return 1; }
function current_user_can( string $capability ): bool { return 'mcp_expose_canonical_execute_adapter_unavailable' !== $capability; }

class WP_Ability {}
final class MCP_Expose_Execute_Target_Fixture extends WP_Ability {
	/** @var mixed */
	public $permission_input;
	/** @var mixed */
	public $execute_input;

	public function get_meta(): array { return array( 'mcp' => array( 'public' => true ) ); }
	public function get_input_schema(): array { return array( 'type' => 'object' ); }
	public function get_name(): string { return 'fixture/empty-object'; }
	public function check_permissions( $input ): bool { $this->permission_input = $input; return true; }
	public function execute( $input ): array { $this->execute_input = $input; return array( 'received' => $input ); }
}

$mcp_expose_execute_target = new MCP_Expose_Execute_Target_Fixture();

function wp_get_ability( string $name ) {
	global $mcp_expose_execute_target;
	return 'fixture/empty-object' === $name ? $mcp_expose_execute_target : null;
}

if ( is_readable( WP_PLUGIN_DIR . '/mcp-adapter/vendor/autoload.php' ) ) {
	require WP_PLUGIN_DIR . '/mcp-adapter/vendor/autoload.php';
}
require dirname( __DIR__ ) . '/mcp-expose-abilities.php';

spl_autoload_register(
	static function ( string $class ): void {
		if ( in_array( $class, array( 'WP\\MCP\\Transport\\Infrastructure\\HttpRequestHandler', 'WP\\MCP\\Transport\\Infrastructure\\HttpRequestContext', 'WP\\MCP\\Transport\\Infrastructure\\RequestRouter' ), true ) ) {
			$name = substr( $class, strrpos( $class, '\\' ) + 1 );
			eval( 'namespace WP\\MCP\\Transport\\Infrastructure; final class ' . $name . ' {}' );
			return;
		}

		if ( 'WP\\MCP\\Transport\\Infrastructure\\HttpSessionValidator' === $class ) {
			eval( 'namespace WP\\MCP\\Transport\\Infrastructure; final class HttpSessionValidator {}' );
			return;
		}

		if ( 'WP\\MCP\\Transport\\Infrastructure\\SessionManager' === $class ) {
			eval( 'namespace WP\\MCP\\Transport\\Infrastructure; final class SessionManager {}' );
			return;
		}

		if ( 'WP\\MCP\\Abilities\\ExecuteAbilityAbility' !== $class ) {
			return;
		}

		eval(
			'namespace WP\\MCP\\Abilities; final class ExecuteAbilityAbility {' .
			'public static function check_permission($input=array()) {' .
			'$ability=\\wp_get_ability($input["ability_name"] ?? "");' .
			'$parameters=empty($input["parameters"]) ? null : $input["parameters"];' .
			'return $ability->check_permissions($parameters);}' .
			'public static function execute($input=array()): array {' .
			'$ability=\\wp_get_ability($input["ability_name"] ?? "");' .
			'$parameters=empty($input["parameters"]) ? null : $input["parameters"];' .
			'return array("success"=>true,"data"=>$ability->execute($parameters));}' .
			'}'
		);
	},
	true,
	true
);

apply_filters( 'plugins_loaded', null );

$class = 'WP\\MCP\\Abilities\\ExecuteAbilityAbility';
$input = array( 'ability_name' => 'fixture/empty-object', 'parameters' => array() );

$class::check_permission( $input );
$class::execute( $input );

$reflection = new ReflectionClass( $class );
$expected   = wp_normalize_path( WP_PLUGIN_DIR . '/mcp-adapter/includes/Abilities/ExecuteAbilityAbility.php' );
$actual     = wp_normalize_path( (string) $reflection->getFileName() );

if ( $expected !== $actual ) {
	throw new RuntimeException( 'A later bundled MCP Adapter claimed the generic execute Ability: ' . $actual );
}

if ( array() !== $mcp_expose_execute_target->permission_input || array() !== $mcp_expose_execute_target->execute_input ) {
	throw new RuntimeException( 'The generic execute Ability changed an explicit empty parameter object before the target Ability.' );
}

if ( 'manage_options' !== apply_filters( 'mcp_adapter_execute_ability_capability', 'read' ) ) {
	throw new RuntimeException( 'The canonical execute Adapter did not retain the configured MCP capability.' );
}

fwrite( STDOUT, "Canonical MCP execute Adapter runtime checks passed.\n" );

$session_class    = 'WP\\MCP\\Transport\\Infrastructure\\SessionManager';
$session          = new ReflectionClass( $session_class );
$session_expected = wp_normalize_path( WP_PLUGIN_DIR . '/mcp-adapter/includes/Transport/Infrastructure/SessionManager.php' );
if ( $session_expected !== wp_normalize_path( (string) $session->getFileName() ) ) {
	throw new RuntimeException( 'A later bundled adapter claimed SessionManager.' );
}
$adapter_header = file_get_contents( WP_PLUGIN_DIR . '/mcp-adapter/mcp-adapter.php' );
if ( ! preg_match( '/Version:\s*([^\r\n]+)/', $adapter_header, $adapter_version ) ) {
	throw new RuntimeException( 'The adapter package version could not be verified.' );
}
if ( version_compare( trim( $adapter_version[1] ), '0.7.1', '>=' ) ) {
	if ( 4096 !== $session->getConstant( 'MAX_CLIENT_PARAMS_BYTES' ) ) {
		throw new RuntimeException( 'The loaded SessionManager does not have the 4 KiB security limit.' );
	}
	if ( true !== $session_class::validate_client_params( array( 'name' => 'small-client' ) ) ) {
		throw new RuntimeException( 'Small initialization parameters were rejected.' );
	}
	if ( ! is_wp_error( $session_class::validate_client_params( array( 'name' => str_repeat( 'x', 8192 ) ) ) ) ) {
		throw new RuntimeException( 'Oversized initialization parameters were accepted.' );
	}
	fwrite( STDOUT, "Canonical session storage security checks passed.\n" );
}
fwrite( STDOUT, "Canonical SessionManager binding checks passed.\n" );

$validator_class = 'WP\\MCP\\Transport\\Infrastructure\\HttpSessionValidator';
$validator = new ReflectionClass( $validator_class );
if ( wp_normalize_path( WP_PLUGIN_DIR . '/mcp-adapter/includes/Transport/Infrastructure/HttpSessionValidator.php' ) !== wp_normalize_path( (string) $validator->getFileName() ) ) {
	throw new RuntimeException( 'A later bundled adapter claimed HttpSessionValidator.' );
}
if ( version_compare( trim( $adapter_version[1] ), '0.7.1', '>=' ) ) {
	$logger = new class implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
		public function log( string $message, array $context = array(), string $type = 'error' ): void {}
	};
	$error = $validator_class::create_session_with_error_handler( array( 'name' => str_repeat( 'x', 8192 ) ), $logger, 9171 );
	if ( -32602 !== ( $error['error']['code'] ?? null ) || 9171 !== ( $error['id'] ?? null ) ) {
		throw new RuntimeException( 'Oversized initialization did not retain Invalid params and the request ID.' );
	}
}
fwrite( STDOUT, "Canonical HTTP session validation checks passed.\n" );

foreach ( array( 'HttpRequestHandler', 'HttpRequestContext', 'RequestRouter' ) as $name ) {
	$class = 'WP\\MCP\\Transport\\Infrastructure\\' . $name;
	$reflection = new ReflectionClass( $class );
	if ( wp_normalize_path( WP_PLUGIN_DIR . '/mcp-adapter/includes/Transport/Infrastructure/' . $name . '.php' ) !== wp_normalize_path( (string) $reflection->getFileName() ) ) {
		throw new RuntimeException( 'A later bundled adapter claimed ' . $name );
	}
}
fwrite( STDOUT, "Canonical adapter transport graph checks passed.\n" );
