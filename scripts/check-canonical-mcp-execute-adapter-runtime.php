<?php
/**
 * Prove that MCP Expose binds the generic execute Ability to the standalone
 * MCP Adapter before a later plugin can claim the same class name.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/wordpress-fixture/' );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );

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

function WP_Filesystem(): bool { return true; }
function plugins_api() { return null; }
function activate_plugin(): null { return null; }
function wp_update_plugins(): null { return null; }
function wp_generate_attachment_metadata(): array { return array(); }
function wp_create_user(): int { return 1; }

class Plugin_Upgrader {}
class WP_Ajax_Upgrader_Skin {}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function is_user_logged_in(): bool { return true; }
function current_user_can( string $capability ): bool { return 'mcp_expose_canonical_execute_adapter_unavailable' !== $capability; }

final class MCP_Expose_Execute_Target_Fixture {
	/** @var mixed */
	public $permission_input;
	/** @var mixed */
	public $execute_input;

	public function get_meta(): array { return array( 'mcp' => array( 'public' => true ) ); }
	public function check_permissions( $input ): bool { $this->permission_input = $input; return true; }
	public function execute( $input ): array { $this->execute_input = $input; return array( 'received' => $input ); }
}

$mcp_expose_execute_target = new MCP_Expose_Execute_Target_Fixture();

function wp_get_ability( string $name ) {
	global $mcp_expose_execute_target;
	return 'fixture/empty-object' === $name ? $mcp_expose_execute_target : null;
}

require dirname( __DIR__ ) . '/mcp-expose-abilities.php';

spl_autoload_register(
	static function ( string $class ): void {
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
