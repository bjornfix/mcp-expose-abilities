<?php
/** Isolated native hook regression. No HTTP server or site mutation is used. */
declare(strict_types=1);

namespace Elementor\MCP\Composer\Admin { abstract class RestController {} }
namespace {
	$fixture = getenv('MCP_EXPOSE_NATIVE_FIXTURE_DIR');
	$hooks = getenv('MCP_EXPOSE_NATIVE_HOOKS_DIR');
	if (!$fixture || !$hooks) { throw new RuntimeException('Provide the unchanged native MCP/Elementor fixture and WordPress hook directories.'); }
	define('ABSPATH', __DIR__ . '/');
	define('WP_PLUGIN_DIR', $fixture . '/wp-content/plugins');
	define('WP_MCP_VERSION', '0.7.0');
	require $hooks . '/plugin.php';
	$case = $argv[1] ?? 'disabled';
	$elementor_option = 'enabled' === $case ? true : ('disabled' === $case || 'reverse_load' === $case ? false : null);
	$original_option = $elementor_option;
	$logged_in = true;
	$capabilities = array('manage_options' => true, 'fixture_target' => true);
	$abilities = $categories = array();
	function __return_false() { return false; }
	function get_option($name, $default = false) { return 'elementor_mcp_enabled' === $name ? $GLOBALS['elementor_option'] ?? $default : $default; }
	function update_option(...$args) { throw new RuntimeException('The compatibility fix changed a setting.'); }
	function add_option(...$args) { throw new RuntimeException('The compatibility fix created a setting.'); }
	function delete_option(...$args) { throw new RuntimeException('The compatibility fix deleted a setting.'); }
	function register_rest_route(...$args) { throw new RuntimeException('The isolated regression must not create a REST route.'); }
	function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
	function WP_Filesystem() { return true; }
	function plugins_api() {}
	function activate_plugin() {}
	function wp_update_plugins() {}
	function wp_generate_attachment_metadata() {}
	function wp_create_user() {}
	function get_current_screen() { return null; }
	function __($text, $domain = '') { return $text; }
	function is_wp_error($v) { return $v instanceof WP_Error; }
	function is_user_logged_in() { return $GLOBALS['logged_in']; }
	function current_user_can($cap) { return $GLOBALS['capabilities'][$cap] ?? false; }
	function wp_register_ability_category($name, $args) { $GLOBALS['categories'][$name] = $args; }
	function wp_register_ability($name, $args) { $GLOBALS['abilities'][$name] = new WP_Ability($name, apply_filters('wp_register_ability_args', $args, $name)); }
	function wp_get_ability($name) { return $GLOBALS['abilities'][$name] ?? null; }
	function wp_get_abilities() { return $GLOBALS['abilities']; }
	class Plugin_Upgrader {}
	class WP_Ajax_Upgrader_Skin {}
	class WP_Error { public function __construct(public $code, public $message) {} public function get_error_message() { return $this->message; } }
	class WP_Ability {
		public function __construct(private $name, private $args) {}
		public function get_name() { return $this->name; }
		public function get_meta() { return $this->args['meta'] ?? array(); }
		public function get_input_schema() { return $this->args['input_schema'] ?? null; }
		public function check_permissions($input) { return ($this->args['permission_callback'])($input); }
		public function execute($input) { return $this->check_permissions($input) ? array('received' => $input) : new WP_Error('forbidden', 'Target denied.'); }
	}
	require WP_PLUGIN_DIR . '/mcp-adapter/vendor/autoload.php';
	$load_elementor = static function () {
		require WP_PLUGIN_DIR . '/elementor/vendor/elementor/elementor-mcp-composer/src/Admin/McpSettingsController.php';
		require WP_PLUGIN_DIR . '/elementor/vendor/elementor/elementor-mcp-composer/src/Mcp/Server_Bootstrap.php';
		new \Elementor\MCP\Composer\Mcp\Server_Bootstrap();
	};
	if ('reverse_load' !== $case && 'no_elementor' !== $case) { $load_elementor(); }
	if ('early_policy' === $case) { add_filter('mcp_adapter_create_default_server', static fn($value) => false, 5); }
	if ('peer_policy' === $case) { add_filter('mcp_adapter_create_default_server', static fn($value) => false, 10); }
	if ('late_policy' === $case) { add_filter('mcp_adapter_create_default_server', static fn($value) => false, 30); }
	if ('no_elementor' === $case) { add_filter('mcp_adapter_create_default_server', '__return_false', 10); }
	require getenv('MCP_EXPOSE_RUNTIME_CORE_FILE') ?: dirname(__DIR__) . '/mcp-expose-abilities.php';
	if ('reverse_load' === $case) { $load_elementor(); }
	$expected = !in_array($case, array('early_policy', 'peer_policy', 'late_policy', 'no_elementor'), true);
	$enabled = apply_filters('mcp_adapter_create_default_server', true);
	if ($expected !== $enabled) { throw new RuntimeException('Native default-server policy failed for ' . $case); }
	if ($original_option !== $elementor_option) { throw new RuntimeException('Elementor MCP option changed.'); }
	if ('manage_options' !== apply_filters('mcp_adapter_default_transport_permission_user_capability', 'read')
		|| 'manage_options' !== apply_filters('mcp_adapter_execute_ability_capability', 'read')) { throw new RuntimeException('MCP administrator requirement changed.'); }
	if ($expected) {
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$adapter->register_default_category();
		$adapter->register_default_abilities();
		foreach (array('mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info', 'mcp-adapter/execute-ability') as $name) {
			if (!isset($abilities[$name])) { throw new RuntimeException('Native generic MCP ability missing: ' . $name); }
		}
		$abilities['fixture/reader'] = new WP_Ability('fixture/reader', array('input_schema' => array('type' => 'object'), 'meta' => array('mcp' => array('public' => true)), 'permission_callback' => static fn($input) => current_user_can('fixture_target')));
		$input = array('ability_name' => 'fixture/reader', 'parameters' => array());
		$executor = '\\WP\\MCP\\Abilities\\ExecuteAbilityAbility';
		if (true !== $executor::check_permission($input)) { throw new RuntimeException('Authorized native execution failed.'); }
		$logged_in = false;
		if (!is_wp_error($executor::check_permission($input))) { throw new RuntimeException('Anonymous execution allowed.'); }
		$logged_in = true;
		$capabilities['manage_options'] = false;
		if (!is_wp_error($executor::check_permission($input))) { throw new RuntimeException('Non-administrator execution allowed.'); }
		$capabilities['manage_options'] = true;
		$capabilities['fixture_target'] = false;
		if (true === $executor::check_permission($input)) { throw new RuntimeException('Target permissions bypassed.'); }
	}
	echo 'PASS: ' . $case . ', native hook order, independent settings, administrator and target permissions, no route exposure' . PHP_EOL;
}
