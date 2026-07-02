<?php
/**
 * Plugin Name: MCP Expose Abilities
 * Plugin URI: https://devenia.com
 * Description: Core WordPress abilities for MCP. Content, menus, users, media, widgets, plugins, options, and system management. Add-on plugins available for Elementor, GeneratePress, Cloudflare, and filesystem operations.
 * Version: 3.0.68
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Expose_Abilities
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the minimum WordPress capability required to reach MCP transport and
 * generic execute-ability entrypoints.
 *
 * @return string
 */
function mcp_expose_get_mcp_transport_capability(): string {
	$capability = apply_filters( 'mcp_expose_mcp_transport_capability', 'manage_options' );

	if ( ! is_string( $capability ) || '' === trim( $capability ) ) {
		return 'manage_options';
	}

	return trim( $capability );
}

/**
 * Filter MCP adapter transport and execute-ability capabilities.
 *
 * @return string
 */
function mcp_expose_filter_mcp_transport_capability(): string {
	return mcp_expose_get_mcp_transport_capability();
}

add_filter( 'mcp_adapter_default_transport_permission_user_capability', 'mcp_expose_filter_mcp_transport_capability', 20 );
add_filter( 'mcp_adapter_execute_ability_capability', 'mcp_expose_filter_mcp_transport_capability', 20 );

/**
 * Watch MCP HTTP requests from below the adapter layer.
 *
 * Adapter observability only helps once a request reaches the adapter router
 * and the configured observability handler. A shutdown watcher gives us a
 * second signal for long-running or fatal MCP REST requests without logging
 * request bodies, ability arguments, authentication headers, or session data.
 *
 * @return void
 */
function mcp_expose_watch_mcp_http_request(): void {
	if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
		return;
	}

	$uri = mcp_expose_get_server_text_value( 'REQUEST_URI' );
	if ( ! mcp_expose_is_mcp_http_request_uri( $uri ) ) {
		return;
	}

	$method       = mcp_expose_get_server_text_value( 'REQUEST_METHOD' );
	$method       = '' !== $method ? strtoupper( $method ) : 'UNKNOWN';
	$route        = mcp_expose_normalize_mcp_http_route( $uri );
	$start        = microtime( true );
	$start_memory = memory_get_usage( true );

	register_shutdown_function(
		static function () use ( $start, $start_memory, $method, $route ): void {
			$duration_ms = ( microtime( true ) - $start ) * 1000;
			$error       = error_get_last();
			$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
			$is_fatal    = is_array( $error ) && isset( $error['type'] ) && in_array( (int) $error['type'], $fatal_types, true );
			$threshold   = (float) apply_filters( 'mcp_expose_mcp_http_shutdown_threshold_ms', 10000.0 );

			if ( ! $is_fatal && $duration_ms < $threshold ) {
				return;
			}

			$status = $is_fatal ? 'fatal' : 'slow_shutdown';
			$code   = $is_fatal ? 'php_fatal_shutdown' : 'slow_shutdown';

			mcp_expose_maybe_record_ability_timing(
				'mcp.http/' . $method . ' ' . $route,
				$duration_ms,
				array(
					'success' => ! $is_fatal,
					'code'    => $code,
					'message' => $is_fatal && isset( $error['message'] ) ? (string) $error['message'] : '',
					'context' => array(
						'event'     => 'mcp.http.shutdown',
						'method'    => $method,
						'status'    => $status,
						'transport' => 'http',
						'server_id' => $route,
					),
				),
				memory_get_usage( true ) - $start_memory,
				memory_get_peak_usage( true )
			);
		}
	);
}
add_action( 'init', 'mcp_expose_watch_mcp_http_request', 0 );

/**
 * Get a sanitized scalar value from the server environment.
 *
 * @param string $key Server key.
 * @return string
 */
function mcp_expose_get_server_text_value( string $key ): string {
	if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
}

/**
 * Determine whether a request URI targets the MCP REST route.
 *
 * @param string $uri Request URI.
 * @return bool
 */
function mcp_expose_is_mcp_http_request_uri( string $uri ): bool {
	if ( '' === $uri ) {
		return false;
	}

	return false !== strpos( $uri, '/wp-json/mcp/' ) || false !== strpos( $uri, 'rest_route=/mcp/' );
}

/**
 * Normalize an MCP REST route for compact diagnostics.
 *
 * @param string $uri Request URI.
 * @return string
 */
function mcp_expose_normalize_mcp_http_route( string $uri ): string {
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

	if ( '' === $path && false !== strpos( $uri, 'rest_route=' ) ) {
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		if ( isset( $query['rest_route'] ) && is_scalar( $query['rest_route'] ) ) {
			$path = (string) $query['rest_route'];
		}
	}

	$path = preg_replace( '#^/wp-json/#', '/', $path );
	$path = preg_replace( '#/+#', '/', (string) $path );
	$path = trim( (string) $path, '/' );

	return '' !== $path ? sanitize_text_field( $path ) : 'mcp';
}

/**
 * Install an MCP Adapter observability handler for the default server.
 *
 * The existing ability timing wrapper only sees calls that reach a WordPress
 * ability callback. Transport-level failures, slow discovery calls, and adapter
 * routing issues otherwise disappear when Cloudflare times out before a normal
 * ability response is returned.
 *
 * @param array $config Default MCP adapter server config.
 * @return array
 */
function mcp_expose_enable_adapter_observability( array $config ): array {
	if ( ! interface_exists( '\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface' ) ) {
		return $config;
	}

	if ( ! class_exists( 'MCP_Expose_Adapter_Observability_Handler', false ) ) {
		class MCP_Expose_Adapter_Observability_Handler implements \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface {
			/**
			 * Record an MCP adapter event.
			 *
			 * @param string     $event       Event name.
			 * @param array      $tags        Event tags.
			 * @param float|null $duration_ms Duration in milliseconds.
			 * @return void
			 */
			public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
				if ( 'mcp.request' !== $event || null === $duration_ms ) {
					return;
				}

				$method = isset( $tags['method'] ) && is_scalar( $tags['method'] ) ? (string) $tags['method'] : 'unknown';
				$params = isset( $tags['params'] ) && is_array( $tags['params'] ) ? $tags['params'] : array();
				$tool   = isset( $params['name'] ) && is_scalar( $params['name'] ) ? (string) $params['name'] : '';

				$details = array(
					'success' => ! isset( $tags['status'] ) || 'error' !== (string) $tags['status'],
					'code'    => isset( $tags['error_code'] ) ? (string) $tags['error_code'] : '',
					'message' => '',
					'context' => array(
						'event'      => $event,
						'method'     => $method,
						'tool'       => $tool,
						'status'     => isset( $tags['status'] ) && is_scalar( $tags['status'] ) ? (string) $tags['status'] : '',
						'transport'  => isset( $tags['transport'] ) && is_scalar( $tags['transport'] ) ? (string) $tags['transport'] : '',
						'server_id'  => isset( $tags['server_id'] ) && is_scalar( $tags['server_id'] ) ? (string) $tags['server_id'] : '',
						'request_id' => isset( $tags['request_id'] ) && is_scalar( $tags['request_id'] ) ? (string) $tags['request_id'] : '',
					),
				);

				$operation = '' !== $tool ? "{$method}:{$tool}" : $method;
				mcp_expose_maybe_record_ability_timing(
					'mcp.request/' . $operation,
					$duration_ms,
					$details,
					0,
					memory_get_peak_usage( true )
				);
			}
		}
	}

	$config['observability_handler'] = 'MCP_Expose_Adapter_Observability_Handler';
	return $config;
}
add_filter( 'mcp_adapter_default_server_config', 'mcp_expose_enable_adapter_observability', 20 );

/**
 * Return input schema fragment for high-risk ability confirmation.
 *
 * @param string $ability_name Ability name.
 * @return array
 */
function mcp_expose_dangerous_action_confirmation_schema( string $ability_name ): array {
	return array(
		'type'        => 'string',
		'description' => sprintf(
			/* translators: %s: Ability name. */
			__( 'Required for this high-risk action. Must exactly equal "%s".', 'mcp-expose-abilities' ),
			$ability_name
		),
	);
}

/**
 * Require an explicit per-ability confirmation token for high-risk writes.
 *
 * @param array  $input        Ability input.
 * @param string $ability_name Ability name.
 * @return true|WP_Error
 */
function mcp_expose_confirm_dangerous_action( array $input, string $ability_name ) {
	$confirmation = isset( $input['confirm_dangerous_action'] ) ? (string) $input['confirm_dangerous_action'] : '';
	if ( $ability_name === $confirmation ) {
		return true;
	}

	return new WP_Error(
		'mcp_dangerous_action_confirmation_required',
		sprintf(
			/* translators: 1: Ability name, 2: Confirmation parameter name, 3: Confirmation value. */
			__( 'High-risk ability "%1$s" requires explicit confirmation. Set %2$s to "%3$s" after verifying the target and rollback path.', 'mcp-expose-abilities' ),
			$ability_name,
			'confirm_dangerous_action',
			$ability_name
		)
	);
}

/**
 * Convert a dangerous-action guard result to a standard ability response.
 *
 * @param true|WP_Error $result Guard result.
 * @param string        $ability_name Ability name.
 * @return array|null
 */
function mcp_expose_dangerous_action_error_response( $result, string $ability_name ): ?array {
	if ( ! is_wp_error( $result ) ) {
		return null;
	}

	return array(
		'success' => false,
		'message' => $result->get_error_message(),
		'ability' => $ability_name,
		'code'    => $result->get_error_code(),
	);
}

/**
 * Check whether MCP plugin code writes are explicitly enabled on this site.
 *
 * Plugin install/update/delete abilities can execute attacker-controlled PHP if
 * an MCP admin credential is stolen, so they require a server-side opt-in in
 * addition to per-call confirmation.
 *
 * @param string              $ability_name Ability name currently being checked.
 * @param array<string,mixed> $input        Ability input.
 * @return bool
 */
function mcp_expose_plugin_code_writes_enabled( string $ability_name = '', array $input = array() ): bool {
	$enabled = defined( 'MCP_EXPOSE_ENABLE_PLUGIN_CODE_WRITES' ) && true === MCP_EXPOSE_ENABLE_PLUGIN_CODE_WRITES;

	/**
	 * Filter whether MCP plugin code write abilities are enabled.
	 *
	 * This should only be enabled from trusted server-side configuration, not
	 * from user-controlled request data.
	 *
	 * @param bool $enabled Whether plugin code writes are enabled.
	 */
	$enabled = (bool) apply_filters( 'mcp_expose_enable_plugin_code_writes', $enabled );

	/**
	 * Filter whether a specific MCP plugin code write ability is enabled.
	 *
	 * This gives trusted server-side gate plugins structured context without
	 * requiring request-body parsing or stack inspection.
	 *
	 * @param bool                $enabled      Whether plugin code writes are enabled.
	 * @param string              $ability_name Ability name currently being checked.
	 * @param array<string,mixed> $input        Ability input.
	 */
	return (bool) apply_filters( 'mcp_expose_enable_plugin_code_write_ability', $enabled, $ability_name, $input );
}

/**
 * Require server-side opt-in for plugin install/update/delete abilities.
 *
 * @param string              $ability_name Ability name.
 * @param array<string,mixed> $input        Ability input.
 * @return true|WP_Error
 */
function mcp_expose_require_plugin_code_write_enabled( string $ability_name, array $input = array() ) {
	if ( mcp_expose_plugin_code_writes_enabled( $ability_name, $input ) ) {
		return true;
	}

	return new WP_Error(
		'mcp_plugin_code_writes_disabled',
		sprintf(
			/* translators: 1: Ability name, 2: Constant name. */
			__( 'High-risk plugin code write ability "%1$s" is disabled by default. Define %2$s as true in trusted server-side configuration to enable it temporarily.', 'mcp-expose-abilities' ),
			$ability_name,
			'MCP_EXPOSE_ENABLE_PLUGIN_CODE_WRITES'
		)
	);
}

/**
 * Check whether a plugin update is explicitly gated by the Devenia MCP updater manifest.
 *
 * This keeps generic plugin code writes disabled while allowing WordPress-native
 * updates for Devenia-managed packages that have already passed the updater's
 * manifest/package/hash/Plugin Check gates.
 *
 * @param string $plugin_file Plugin file path.
 * @return bool
 */
function mcp_expose_is_devenia_manifest_plugin_update( string $plugin_file ): bool {
	if ( ! function_exists( 'devenia_mcp_updater_manifest_plugins' ) ) {
		return false;
	}

	$manifest_plugins = devenia_mcp_updater_manifest_plugins( true );
	if ( ! isset( $manifest_plugins[ $plugin_file ] ) || ! is_array( $manifest_plugins[ $plugin_file ] ) ) {
		return false;
	}

	$manifest_entry = $manifest_plugins[ $plugin_file ];
	if ( empty( $manifest_entry['autoUpdate'] ) ) {
		return false;
	}

	$manifest_package = isset( $manifest_entry['package'] ) ? (string) $manifest_entry['package'] : '';
	$manifest_version = isset( $manifest_entry['version'] ) ? (string) $manifest_entry['version'] : '';
	if ( '' === $manifest_package || '' === $manifest_version ) {
		return false;
	}

	wp_clean_plugins_cache( true );
	wp_update_plugins();

	$updates = get_site_transient( 'update_plugins' );
	if ( ! is_object( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) || ! isset( $updates->response[ $plugin_file ] ) ) {
		return false;
	}

	$update_item    = $updates->response[ $plugin_file ];
	$update_package = isset( $update_item->package ) ? (string) $update_item->package : '';
	$update_version = isset( $update_item->new_version ) ? (string) $update_item->new_version : '';

	return hash_equals( $manifest_package, $update_package ) && hash_equals( $manifest_version, $update_version );
}

/**
 * Require either global plugin-code-write opt-in or a manifest-gated update.
 *
 * @param string $ability_name Ability name.
 * @param string $plugin_file  Plugin file path.
 * @return true|WP_Error
 */
function mcp_expose_require_plugin_update_allowed( string $ability_name, string $plugin_file ) {
	if ( mcp_expose_plugin_code_writes_enabled( $ability_name ) || mcp_expose_is_devenia_manifest_plugin_update( $plugin_file ) ) {
		return true;
	}

	return mcp_expose_require_plugin_code_write_enabled( $ability_name );
}

/**
 * Convert plugin-code-write guard result to ability output.
 *
 * @param true|WP_Error $result Guard result.
 * @param string        $ability_name Ability name.
 * @return array|null
 */
function mcp_expose_plugin_code_write_error_response( $result, string $ability_name ): ?array {
	if ( ! is_wp_error( $result ) ) {
		return null;
	}

	return array(
		'success' => false,
		'message' => $result->get_error_message(),
		'ability' => $ability_name,
		'code'    => $result->get_error_code(),
	);
}

/**
 * Return valid template slugs for a post type.
 *
 * @param string $post_type Post type slug.
 * @return string[]
 */
function mcp_expose_get_valid_template_slugs( string $post_type ): array {
	$templates = wp_get_theme()->get_page_templates( null, $post_type );
	$slugs     = array_keys( $templates );
	$slugs[]   = '';
	$slugs[]   = 'default';

	return array_values( array_unique( $slugs ) );
}

/**
 * Check whether a template slug is valid for a post type.
 *
 * @param string $template_slug Template slug.
 * @param string $post_type     Post type slug.
 * @return bool
 */
function mcp_expose_is_valid_template_slug( string $template_slug, string $post_type ): bool {
	return in_array( $template_slug, mcp_expose_get_valid_template_slugs( $post_type ), true );
}

/**
 * Clear a stale invalid assigned page template to unblock content updates.
 *
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type slug.
 * @return bool True when invalid template meta was removed.
 */
function mcp_expose_normalize_assigned_template( int $post_id, string $post_type ): bool {
	$template_slug = (string) get_post_meta( $post_id, '_wp_page_template', true );

	if ( '' === $template_slug || 'default' === $template_slug ) {
		return false;
	}

	if ( mcp_expose_is_valid_template_slug( $template_slug, $post_type ) ) {
		return false;
	}

	delete_post_meta( $post_id, '_wp_page_template' );

	return true;
}

/**
 * Trigger post-save integrations after updating Yoast SEO post meta.
 *
 * @param int      $post_id   Post ID.
 * @param string[] $meta_keys Changed meta keys.
 * @return bool True when a refresh was triggered.
 */
function mcp_expose_maybe_refresh_yoast_indexable_for_meta_keys( int $post_id, array $meta_keys ): bool {
	$has_yoast_meta = false;
	foreach ( $meta_keys as $meta_key ) {
		if ( 0 === strpos( (string) $meta_key, '_yoast_wpseo_' ) ) {
			$has_yoast_meta = true;
			break;
		}
	}

	if ( ! $has_yoast_meta ) {
		return false;
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}

	mcp_expose_normalize_assigned_template( $post_id, (string) $post->post_type );
	clean_post_cache( $post_id );

	$result = wp_update_post(
		array(
			'ID' => $post_id,
		),
		true
	);

	return ! is_wp_error( $result );
}

/**
 * Idempotently assign a featured image.
 *
 * @param int $post_id           Post ID.
 * @param int $featured_image_id Attachment ID.
 * @return true|WP_Error
 */
function mcp_expose_set_featured_image( int $post_id, int $featured_image_id ) {
	$attachment = get_post( $featured_image_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $featured_image_id ) ) {
		return new WP_Error( 'mcp_invalid_featured_image', __( 'Invalid featured image attachment ID', 'mcp-expose-abilities' ) );
	}

	if ( (int) get_post_thumbnail_id( $post_id ) === $featured_image_id ) {
		return true;
	}

	if ( set_post_thumbnail( $post_id, $featured_image_id ) ) {
		return true;
	}

	return new WP_Error( 'mcp_set_featured_image_failed', __( 'Failed to set featured image', 'mcp-expose-abilities' ) );
}

/**
 * Get translated sibling post IDs through the shared Elementor/WPML sibling provider when available.
 *
 * Generic content updates must target only the requested post. WPML can sync
 * custom fields and content to translated siblings during wp_update_post() or
 * set_post_thumbnail(), so content abilities snapshot siblings first and restore
 * them after the requested write.
 *
 * @param int $post_id Post ID.
 * @return int[]
 */
function mcp_expose_get_translation_sibling_post_ids( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}

	$sibling_ids = array();

	if ( function_exists( 'mcp_abilities_elementor_translation_sibling_filter_name' ) ) {
		$sibling_ids = apply_filters( mcp_abilities_elementor_translation_sibling_filter_name(), $sibling_ids, $post_id, $post );
	}

	$sibling_ids = apply_filters( 'mcp_expose_translation_sibling_post_ids', $sibling_ids, $post_id, $post );

	if ( ! is_array( $sibling_ids ) ) {
		return array();
	}

	return array_values(
		array_unique(
			array_filter(
				array_map( 'intval', $sibling_ids ),
				static function ( int $sibling_id ) use ( $post_id ): bool {
					return $sibling_id > 0 && $sibling_id !== $post_id;
				}
			)
		)
	);
}

/**
 * Meta keys that must be preserved on translated siblings during generic content updates.
 *
 * @return string[]
 */
function mcp_expose_get_translation_sibling_guard_meta_keys(): array {
	return array(
		'_elementor_data',
		'_elementor_page_settings',
		'_thumbnail_id',
	);
}

/**
 * Capture translated sibling post fields and critical meta.
 *
 * @param int $post_id Post ID being edited.
 * @return array
 */
function mcp_expose_capture_translation_sibling_state( int $post_id ): array {
	$sibling_ids = mcp_expose_get_translation_sibling_post_ids( $post_id );
	$snapshot    = array(
		'sibling_ids' => $sibling_ids,
		'posts'       => array(),
		'meta'        => array(),
	);

	foreach ( $sibling_ids as $sibling_id ) {
		$post = get_post( $sibling_id );
		if ( ! $post ) {
			continue;
		}

		$snapshot['posts'][ $sibling_id ] = array(
			'post_title'   => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_name'    => (string) $post->post_name,
			'post_status'  => (string) $post->post_status,
			'post_parent'  => (int) $post->post_parent,
			'menu_order'   => (int) $post->menu_order,
		);

		$snapshot['meta'][ $sibling_id ] = array();
		foreach ( mcp_expose_get_translation_sibling_guard_meta_keys() as $meta_key ) {
			$snapshot['meta'][ $sibling_id ][ $meta_key ] = get_post_meta( $sibling_id, $meta_key, false );
		}
	}

	return $snapshot;
}

/**
 * Restore a meta key directly without firing metadata sync hooks.
 *
 * @param int    $post_id Post ID.
 * @param string $meta_key Meta key.
 * @param array  $values Meta values as returned by get_post_meta( ..., false ).
 * @return void
 */
function mcp_expose_restore_post_meta_rows_direct( int $post_id, string $meta_key, array $values ): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional hook-bypassing restore after multilingual sync hooks mutate translated siblings.
	$wpdb->delete(
		$wpdb->postmeta,
		array(
			'post_id'  => $post_id,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Direct restore of one known meta key for one translated sibling.
			'meta_key' => $meta_key,
		),
		array( '%d', '%s' )
	);

	foreach ( $values as $value ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional hook-bypassing restore after multilingual sync hooks mutate translated siblings.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Direct restore of one known meta key for one translated sibling.
				'meta_key'   => $meta_key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Direct restore of captured meta value for one translated sibling.
				'meta_value' => maybe_serialize( $value ),
			),
			array( '%d', '%s', '%s' )
		);
	}
}

/**
 * Restore translated sibling state if a multilingual sync hook changed it.
 *
 * @param array $snapshot Snapshot from mcp_expose_capture_translation_sibling_state().
 * @return array
 */
function mcp_expose_restore_translation_sibling_state( array $snapshot ): array {
	global $wpdb;

	$restored_posts = array();
	$restored_meta  = array();

	foreach ( (array) ( $snapshot['posts'] ?? array() ) as $post_id => $fields ) {
		$post_id = (int) $post_id;
		$current = get_post( $post_id );
		if ( ! $current || ! is_array( $fields ) ) {
			continue;
		}

		$changed = false;
		foreach ( $fields as $field => $value ) {
			if ( isset( $current->{$field} ) && $current->{$field} !== $value ) {
				$changed = true;
				break;
			}
		}

		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional hook-bypassing restore after multilingual sync hooks mutate translated siblings.
			$wpdb->update(
				$wpdb->posts,
				$fields,
				array( 'ID' => $post_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' ),
				array( '%d' )
			);
			$restored_posts[] = $post_id;
		}
	}

	foreach ( (array) ( $snapshot['meta'] ?? array() ) as $post_id => $meta_by_key ) {
		$post_id = (int) $post_id;
		if ( ! is_array( $meta_by_key ) ) {
			continue;
		}

		foreach ( $meta_by_key as $meta_key => $values ) {
			$current_values = get_post_meta( $post_id, (string) $meta_key, false );
			if ( $current_values !== $values ) {
				mcp_expose_restore_post_meta_rows_direct( $post_id, (string) $meta_key, is_array( $values ) ? $values : array() );
				$restored_meta[] = $post_id . ':' . $meta_key;
			}
		}
	}

	foreach ( array_unique( array_map( 'intval', (array) ( $snapshot['sibling_ids'] ?? array() ) ) ) as $post_id ) {
		if ( $post_id > 0 ) {
			clean_post_cache( $post_id );
			wp_cache_delete( $post_id, 'post_meta' );
		}
	}

	return array(
		'protected_post_ids' => array_values( array_map( 'intval', (array) ( $snapshot['sibling_ids'] ?? array() ) ) ),
		'protected_count'    => count( (array) ( $snapshot['sibling_ids'] ?? array() ) ),
		'restored_post_ids'  => array_values( array_unique( $restored_posts ) ),
		'restored_meta_keys' => $restored_meta,
	);
}

/**
 * Schedule a final translated sibling restore after late multilingual sync hooks.
 *
 * @param array $snapshot Snapshot from mcp_expose_capture_translation_sibling_state().
 * @return bool
 */
function mcp_expose_schedule_translation_sibling_state_restore( array $snapshot ): bool {
	if ( empty( $snapshot['sibling_ids'] ) ) {
		return false;
	}

	register_shutdown_function(
		static function () use ( $snapshot ): void {
			mcp_expose_restore_translation_sibling_state( $snapshot );
		}
	);

	return true;
}

/**
 * Return post meta keys that must not be changed through generic meta abilities.
 *
 * These keys are owned by Elementor and require dedicated Elementor abilities
 * that validate/encode the builder document format before writing.
 *
 * @return string[]
 */
function mcp_expose_get_protected_post_meta_keys(): array {
	$protected = array(
		'_elementor_controls_usage',
		'_elementor_css',
		'_elementor_data',
		'_elementor_edit_mode',
		'_elementor_page_settings',
		'_elementor_popup_display_settings',
		'_elementor_pro_version',
		'_elementor_template_sub_type',
		'_elementor_template_type',
		'_elementor_version',
	);

	/**
	 * Filter post meta keys blocked from generic content/meta abilities.
	 *
	 * Builder-specific add-ons can add keys here while keeping the generic
	 * post meta write policy in one place.
	 *
	 * @param string[] $protected Protected post meta keys.
	 */
	$filtered = apply_filters( 'mcp_expose_protected_post_meta_keys', $protected );
	if ( ! is_array( $filtered ) ) {
		$filtered = $protected;
	}

	return array_values( array_unique( array_map( 'strval', $filtered ) ) );
}

/**
 * Validate generic post meta write intent before any direct write runs.
 *
 * This is the single policy interface for generic post meta writes. When
 * $post_id is null it validates keys only, which lets create-post fail before
 * wp_insert_post() can create a temporary post. When $post_id is provided it
 * also validates per-key WordPress meta capabilities.
 *
 * @param array    $meta    Meta key/value map.
 * @param int|null $post_id Post ID when capability checks should run.
 * @param string   $cap     Meta capability to check.
 * @return true|WP_Error
 */
function mcp_expose_validate_post_meta_write_policy( array $meta, ?int $post_id = null, string $cap = 'edit_post_meta' ) {
	$protected_keys = array_fill_keys( mcp_expose_get_protected_post_meta_keys(), true );

	foreach ( array_keys( $meta ) as $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return new WP_Error( 'mcp_empty_meta_key', __( 'Meta keys cannot be empty.', 'mcp-expose-abilities' ) );
		}

		if ( isset( $protected_keys[ $key ] ) ) {
			return new WP_Error(
				'mcp_protected_elementor_meta_key',
				sprintf(
					/* translators: %s: Meta key. */
					__( 'Protected Elementor meta key "%s" cannot be changed through generic content/meta abilities. Use the dedicated elementor/* abilities instead.', 'mcp-expose-abilities' ),
					$key
				)
			);
		}

		if ( null !== $post_id && ! current_user_can( $cap, $post_id, $key ) ) {
			return new WP_Error(
				'mcp_post_meta_permission_denied',
				sprintf(
					/* translators: %s: Meta key. */
					__( 'Permission denied for post meta key "%s".', 'mcp-expose-abilities' ),
					$key
				)
			);
		}
	}

	return true;
}

/**
 * Detect whether content carries builder/design markup that should not disappear
 * during a broad content replacement.
 *
 * @param string $content Post content.
 * @return string[]
 */
function mcp_expose_detect_design_markup_markers( string $content ): array {
	$markers = array();

	if ( false !== strpos( $content, '<!-- wp:generateblocks/' ) || false !== strpos( $content, 'gb-container-' ) || false !== strpos( $content, 'gb-grid-wrapper-' ) || false !== strpos( $content, 'gb-headline-' ) || false !== strpos( $content, 'gb-button-' ) ) {
		$markers[] = 'generateblocks';
	}

	if ( preg_match( '/\bdv-page-\d+[-_a-z0-9]*\b/i', $content ) ) {
		$markers[] = 'devenia-design-classes';
	}

	return array_values( array_unique( $markers ) );
}

/**
 * Guard broad content writes from accidentally flattening designed pages.
 *
 * @param string $old_content Existing post content.
 * @param string $new_content Proposed post content.
 * @param array  $input       Ability input.
 * @return true|WP_Error
 */
function mcp_expose_validate_content_design_markup_preserved( string $old_content, string $new_content, array $input ) {
	if ( ! empty( $input['allow_design_markup_loss'] ) ) {
		return true;
	}

	$old_markers = mcp_expose_detect_design_markup_markers( $old_content );
	if ( empty( $old_markers ) ) {
		return true;
	}

	$new_markers = mcp_expose_detect_design_markup_markers( $new_content );
	$lost        = array_values( array_diff( $old_markers, $new_markers ) );
	if ( empty( $lost ) ) {
		return true;
	}

	return new WP_Error(
		'mcp_design_markup_loss_blocked',
		sprintf(
			/* translators: %s: comma-separated marker names. */
			__( 'Content update blocked because it would remove existing design markup (%s). Use a targeted patch or pass allow_design_markup_loss=true only when intentionally replacing the page design.', 'mcp-expose-abilities' ),
			implode( ', ', $lost )
		)
	);
}

/**
 * Whether an existing post is managed as a Devenia translation.
 */
function mcp_expose_is_devenia_translation_post( int $post_id ): bool {
	if ( $post_id <= 0 ) {
		return false;
	}

	return '' !== (string) get_post_meta( $post_id, '_devenia_translation_source_id', true )
		|| '' !== (string) get_post_meta( $post_id, '_devenia_translation_language', true );
}

/**
 * Whether proposed meta identifies a translated post.
 *
 * @param mixed $meta_input Ability meta input.
 */
function mcp_expose_meta_input_marks_devenia_translation( $meta_input ): bool {
	if ( ! is_array( $meta_input ) ) {
		return false;
	}

	return array_key_exists( '_devenia_translation_source_id', $meta_input )
		|| array_key_exists( '_devenia_translation_language', $meta_input );
}

/**
 * Guard Devenia source posts from being published or kept published without the
 * approved editorial source-design contract.
 *
 * @param WP_Post|null $post          Existing post, when this is an update.
 * @param string       $post_type     Target post type.
 * @param string       $target_status Target post status after the write.
 * @param string       $content       Proposed post content.
 * @param array        $input         Ability input.
 * @param string       $ability       Calling ability.
 * @return true|WP_Error
 */
function mcp_expose_validate_devenia_editorial_source_post_gate( ?WP_Post $post, string $post_type, string $target_status, string $content, array $input, string $ability ) {
	if ( 'post' !== $post_type || 'publish' !== $target_status ) {
		return true;
	}

	if ( ! class_exists( 'Devenia_AI_Translations' ) ) {
		return true;
	}

	if ( $post instanceof WP_Post && mcp_expose_is_devenia_translation_post( (int) $post->ID ) ) {
		return true;
	}

	if ( mcp_expose_meta_input_marks_devenia_translation( $input['meta_input'] ?? null ) ) {
		return true;
	}

	$validation = apply_filters(
		'devenia_editorial_source_post_validation',
		null,
		$post,
		$content,
		array(
			'caller'        => 'mcp-expose-abilities',
			'ability'       => $ability,
			'post_type'     => $post_type,
			'target_status' => $target_status,
		)
	);

	if ( ! is_array( $validation ) || empty( $validation['available'] ) ) {
		return new WP_Error(
			'devenia_editorial_source_gate_unavailable',
			__( 'Devenia source-post editorial validation is unavailable. Activate the block-editor validation adapter before publishing source posts.', 'mcp-expose-abilities' )
		);
	}

	if ( ! empty( $validation['passed'] ) ) {
		return true;
	}

	$codes = array();
	foreach ( $validation['issue_codes'] ?? array() as $code ) {
		$codes[] = sanitize_key( (string) $code );
	}
	$codes = array_values( array_filter( array_unique( $codes ) ) );

	return new WP_Error(
		'devenia_editorial_source_gate_failed',
		sprintf(
			/* translators: %s: comma-separated validation issue codes. */
			__( 'Published Devenia source posts must pass the editorial source-design gate before they can be saved or inherited into translations. Failed checks: %s', 'mcp-expose-abilities' ),
			$codes ? implode( ', ', $codes ) : __( 'unknown', 'mcp-expose-abilities' )
		),
		$validation
	);
}

/**
 * Acquire a short-lived per-post write lock for read-modify-write abilities.
 *
 * This prevents concurrent patch calls from reading the same old content and
 * then overwriting each other with stale saves.
 *
 * @param int    $post_id   Post ID being modified.
 * @param string $operation Ability/operation name.
 * @param int    $ttl       Lock lifetime in seconds.
 * @return string|WP_Error Lock token or error.
 */
function mcp_expose_acquire_post_write_lock( int $post_id, string $operation, int $ttl = 30 ) {
	$option_name = 'mcp_expose_post_write_lock_' . $post_id;
	$now         = time();
	$existing    = get_option( $option_name );

	if ( is_array( $existing ) && isset( $existing['expires_at'] ) && (int) $existing['expires_at'] <= $now ) {
		delete_option( $option_name );
		$existing = false;
	}

	if ( is_array( $existing ) ) {
		$existing_operation = isset( $existing['operation'] ) ? (string) $existing['operation'] : 'another write operation';
		return new WP_Error(
			'mcp_post_write_lock_active',
			sprintf(
				/* translators: 1: Post ID, 2: Operation name. */
				__( 'Post %1$d is already being modified by %2$s. Retry after the current write completes; do not run concurrent patch calls against the same post.', 'mcp-expose-abilities' ),
				$post_id,
				$existing_operation
			)
		);
	}

	$token = wp_generate_uuid4();
	$lock  = array(
		'token'      => $token,
		'operation'  => $operation,
		'created_at' => $now,
		'expires_at' => $now + max( 5, $ttl ),
	);

	if ( add_option( $option_name, $lock, '', 'no' ) ) {
		return $token;
	}

	return new WP_Error(
		'mcp_post_write_lock_active',
		sprintf(
			/* translators: %d: Post ID. */
			__( 'Post %d is already being modified. Retry after the current write completes; do not run concurrent patch calls against the same post.', 'mcp-expose-abilities' ),
			$post_id
		)
	);
}

/**
 * Release a per-post write lock created by mcp_expose_acquire_post_write_lock().
 *
 * @param int    $post_id Post ID.
 * @param string $token   Lock token.
 */
function mcp_expose_release_post_write_lock( int $post_id, string $token ): void {
	$option_name = 'mcp_expose_post_write_lock_' . $post_id;
	$existing    = get_option( $option_name );

	if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) {
		delete_option( $option_name );
	}
}

// ============================================================================
// PLUGIN STRUCTURE INDEX
// Use this map to quickly find abilities and functions.
// ============================================================================
//
// CONSTANTS (After include guards, ~Line 138)
//   'mcp-expose-abilities'                      - Plugin text domain
//   MCP_VERSION                          - Plugin version
//   MCP_SCHEMA_PAGINATION                - Pagination schema
//   MCP_SCHEMA_ORDER                     - Orderby/order schema
//   MCP_SCHEMA_STATUS                    - Status filter schema
//   MCP_SCHEMA_POST_OUTPUT               - Post output fields schema
//   MCP_SCHEMA_POST_TYPE                 - Post type parameter schema
//   MCP_SCHEMA_AUTHOR                    - Author ID schema
//   MCP_SCHEMA_SEARCH                    - Search parameter schema
//   MCP_SCHEMA_SUCCESS_MESSAGE           - Success response schema
//   MCP_SCHEMA_PLUGIN_FILE               - Plugin file parameter schema
//   MCP_SCHEMA_USER_ID                   - User ID schema
//   MCP_SCHEMA_MENU_ID                   - Menu ID schema
//   MCP_SCHEMA_MEDIA_ID                  - Media ID schema
//   MCP_SCHEMA_TITLE                     - Title parameter schema
//   MCP_SCHEMA_CONTENT                   - Content parameter schema
//
// HELPER CLASSES & FUNCTIONS (~Line 482)
//   class MCP_Helper                     - Centralized helpers:
//     - validate_required()              - Validate required params
//     - get_cached()                     - Get/cache expensive operations
//     - format_post()                    - Format post for output
//     - format_user()                    - Format user for output
//     - format_media()                   - Format media for output
//     - check_capability()               - Check with error response
//     - success()                        - Create success response
//     - error()                          - Create error response
//   mcp_expose_install_plugin_zip()      Line 286   - Install plugin from zip
//   mcp_expose_all_abilities()           Line 433   - Filter: Add MCP metadata
//   mcp_expose_parse_pagination()        Line 469   - Parse per_page/page params
//   mcp_get_optimized_query_args()       Line 667   - Get optimized WP_Query args
//
// ABILITIES BY CATEGORY
//
// CONTENT (Posts, Pages, Revisions, Search)
//   content/list-posts                  Line 720   - List posts with filters
//   content/get-post                    Line 878   - Get single post by ID/slug
//   content/create-post                 Line 987   - Create new post
//   content/update-post                 Line 1117  - Update existing post
//   content/delete-post                 Line 1255  - Delete/trash post
//   content/restore-post                - Restore a post, page, or custom post type from trash
//   content/list-pages                  Line 1328  - List pages
//   content/get-page                    Line 1442  - Get single page
//   content/create-page                 Line 1545  - Create new page
//   content/update-page                 Line 1661  - Update existing page
//   content/delete-page                 Line 1797  - Delete/trash page
//   content/list-revisions              Line 1864  - List post revisions
//   content/get-revision                Line 1944  - Get single revision
//   content/patch-page                  Line 2014  - Quick page edit
//   content/list-categories             Line 2140  - List categories
//   content/create-category             Line 2209  - Create category
//   content/update-category             Line 2280  - Update category
//   content/list-tags                   Line 2308  - List tags
//   content/create-tag                  Line 2374  - Create tag
//   content/update-tag                  - Update tag
//   content/list-media                  Line 2466  - List media attachments
//   content/list-users                  Line 2566  - List users
//   content/patch-post                  Line 2655  - Quick post edit
//   content/search                      Line 2794  - Search posts/pages
//
// META
//   meta/get-post-meta                  - Read explicit post meta fields
//   meta/update-post-meta               - Update post meta fields
//   meta/delete-post-meta               - Delete post meta fields
//   content/update-discussion-status    - Open/close comments and pings
//
// PLUGINS
//   plugins/upload                      Line 2880  - Install from zip file
//   plugins/upload-base64               Line 2951  - Install from base64
//   plugins/list                        Line 3040  - List all plugins
//   plugins/delete                      Line 3114  - Delete plugin
//   plugins/activate                    Line 3186  - Activate plugin
//   plugins/deactivate                  Line 3255  - Deactivate plugin
//
// MENUS
//   menus/list                          Line 3326  - List all menus
//   menus/get-items                     Line 3391  - List menu items
//
// WIDGETS
//   widgets/list-sidebars               Line 3488  - List registered sidebars
//
// ============================================================================

// ============================================================================
// Centralized WordPress Admin Include Guards
// Prevents redundant require_once calls throughout the file.
// ============================================================================
if ( ! function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
if ( ! function_exists( 'plugins_api' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
}
if ( ! class_exists( 'Plugin_Upgrader', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}
if ( ! class_exists( 'WP_Ajax_Upgrader_Skin', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
}
if ( ! function_exists( 'activate_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! function_exists( 'wp_update_plugins' ) ) {
	require_once ABSPATH . 'wp-includes/update.php';
}
if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
}
if ( ! function_exists( 'wp_create_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
// ============================================================================

// ============================================================================
// PLUGIN CONSTANTS
// ============================================================================
define('MCP_TEXT_DOMAIN', 'mcp-expose-abilities');
define('MCP_VERSION', '3.0.60');

// ============================================================================
// REUSABLE SCHEMA DEFINITIONS
// These reduce schema duplication across abilities.
// ============================================================================

// Common pagination parameters.
define('MCP_SCHEMA_PAGINATION', array(
	'per_page' => array(
		'description' => 'Number of items per page',
		'type'        => 'integer',
		'minimum'     => 1,
		'maximum'     => 100,
		'default'     => 20,
	),
	'page' => array(
		'description' => 'Page number',
		'type'        => 'integer',
		'minimum'     => 1,
		'default'     => 1,
	),
));

// Common post order parameters.
define('MCP_SCHEMA_ORDER', array(
	'orderby' => array(
		'description' => 'Sort field',
		'type'        => 'string',
		'enum'        => array('date', 'title', 'name', 'ID', 'modified', 'menu_order'),
		'default'     => 'date',
	),
	'order' => array(
		'description' => 'Sort order',
		'type'        => 'string',
		'enum'        => array('ASC', 'DESC'),
		'default'     => 'DESC',
	),
));

// Common status filter.
define('MCP_SCHEMA_STATUS', array(
	'status' => array(
		'description' => 'Post status filter',
		'type'        => 'string',
		'enum'        => array('publish', 'draft', 'pending', 'private', 'trash', 'any'),
		'default'     => 'publish',
	),
));

// Common output fields for post-like objects.
define('MCP_SCHEMA_POST_OUTPUT', array(
	'id'       => array('type' => 'integer'),
	'title'    => array('type' => 'string'),
	'slug'     => array('type' => 'string'),
	'status'   => array('type' => 'string'),
	'date'     => array('type' => 'string', 'format' => 'date-time'),
	'modified' => array('type' => 'string', 'format' => 'date-time'),
	'link'     => array('type' => 'string', 'format' => 'uri'),
));

// Post type parameter.
define('MCP_SCHEMA_POST_TYPE', array(
	'post_type' => array(
		'description' => 'Post type to query',
		'type'        => 'string',
		'default'     => 'post',
	),
));

// Author ID parameter.
define('MCP_SCHEMA_AUTHOR', array(
	'author_id' => array(
		'description' => 'Filter by author ID',
		'type'        => 'integer',
	),
));

// Search parameter.
define('MCP_SCHEMA_SEARCH', array(
	'search' => array(
		'description' => 'Search keyword',
		'type'        => 'string',
	),
));

// Success output with message.
define('MCP_SCHEMA_SUCCESS_MESSAGE', array(
	'success' => array('type' => 'boolean'),
	'message' => array('type' => 'string'),
));

// Plugin file parameter.
define('MCP_SCHEMA_PLUGIN_FILE', array(
	'plugin' => array(
		'description' => 'Plugin file (directory/main-file.php)',
		'type'        => 'string',
	),
));

// User ID parameter.
define('MCP_SCHEMA_USER_ID', array(
	'id' => array(
		'description' => 'User ID',
		'type'        => 'integer',
	),
));

// Menu ID parameter.
define('MCP_SCHEMA_MENU_ID', array(
	'menu_id' => array(
		'description' => 'Menu ID',
		'type'        => 'integer',
	),
));

// Media ID parameter.
define('MCP_SCHEMA_MEDIA_ID', array(
	'id' => array(
		'description' => 'Media/Attachment ID',
		'type'        => 'integer',
	),
));

// Title parameter.
define('MCP_SCHEMA_TITLE', array(
	'title' => array(
		'description' => 'Title',
		'type'        => 'string',
	),
));

// Content parameter.
define('MCP_SCHEMA_CONTENT', array(
	'content' => array(
		'description' => 'Content',
		'type'        => 'string',
	),
));

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

if ( ! defined( 'MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES' ) ) {
	define( 'MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES', 64 * 1024 * 1024 );
}

if ( ! defined( 'MCP_EXPOSE_MAX_MEDIA_DOWNLOAD_BYTES' ) ) {
	define( 'MCP_EXPOSE_MAX_MEDIA_DOWNLOAD_BYTES', 25 * 1024 * 1024 );
}

if ( ! defined( 'MCP_EXPOSE_MAX_MEDIA_BASE64_BYTES' ) ) {
	define( 'MCP_EXPOSE_MAX_MEDIA_BASE64_BYTES', 25 * 1024 * 1024 );
}

/**
 * Normalize a base64 string that may include a data URI prefix.
 *
 * @param string $base64 Base64 content, optionally as a data URI.
 * @return string Normalized base64 content.
 */
function mcp_expose_normalize_base64( string $base64 ): string {
	$base64 = trim( $base64 );

	if ( str_contains( $base64, ',' ) && preg_match( '/^data:[^;]+;base64,/', $base64 ) ) {
		$parts  = explode( ',', $base64, 2 );
		$base64 = $parts[1] ?? '';
	}

	return preg_replace( '/\s+/', '', $base64 ) ?? '';
}

/**
 * Check whether an IP is private/reserved.
 *
 * @param string $ip IP address.
 * @return bool
 */
function mcp_expose_is_private_ip( string $ip ): bool {
	return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/**
 * Validate that a local file is a readable ZIP archive before install.
 *
 * @param string $zip_path Path to the local zip file.
 * @return true|WP_Error
 */
function mcp_expose_validate_local_plugin_zip( string $zip_path ) {
	if ( '' === $zip_path || ! is_file( $zip_path ) || ! is_readable( $zip_path ) ) {
		return new WP_Error( 'mcp_invalid_plugin_zip', 'Plugin zip file is missing or unreadable.' );
	}

	$size = filesize( $zip_path );
	if ( false === $size || 0 === (int) $size ) {
		return new WP_Error( 'mcp_invalid_plugin_zip', 'Plugin zip file is empty.' );
	}

	WP_Filesystem();
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		return new WP_Error( 'mcp_invalid_plugin_zip', 'Unable to initialize filesystem access for ZIP validation.' );
	}

	$contents = $wp_filesystem->get_contents( $zip_path );
	if ( false === $contents || '' === $contents ) {
		return new WP_Error( 'mcp_invalid_plugin_zip', 'Unable to read plugin zip file for validation.' );
	}

	$signature = substr( $contents, 0, 4 );

	if ( false === $signature || strlen( $signature ) < 4 ) {
		return new WP_Error( 'mcp_invalid_plugin_zip', 'Plugin zip file is too short to be a valid ZIP archive.' );
	}

	$valid_signatures = array( "PK\x03\x04", "PK\x05\x06", "PK\x07\x08" );
	if ( ! in_array( $signature, $valid_signatures, true ) ) {
		return new WP_Error( 'mcp_invalid_plugin_zip', 'Plugin file is not a valid ZIP archive.' );
	}

	if ( class_exists( 'ZipArchive' ) ) {
		$zip         = new ZipArchive();
		$open_result = $zip->open( $zip_path, ZipArchive::CHECKCONS );
		if ( true !== $open_result ) {
			return new WP_Error( 'mcp_invalid_plugin_zip', 'Plugin file is not a valid ZIP archive.' );
		}
		$zip->close();
	}

	return true;
}

/**
 * Resolve the single top-level directory from a plugin ZIP.
 *
 * @param string $zip_path Path to the local zip file.
 * @return string Empty string when the ZIP has no single safe root directory.
 */
function mcp_expose_get_plugin_zip_root_directory( string $zip_path ): string {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return '';
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path, ZipArchive::CHECKCONS ) ) {
		return '';
	}

	$root = '';
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );
		$name = trim( wp_normalize_path( $name ), '/' );
		if ( '' === $name || str_contains( $name, '../' ) ) {
			continue;
		}

		$parts = explode( '/', $name );
		if ( empty( $parts[0] ) ) {
			continue;
		}

		if ( '' === $root ) {
			$root = $parts[0];
			continue;
		}

		if ( $root !== $parts[0] ) {
			$zip->close();
			return '';
		}
	}

	$zip->close();

	if ( '' === $root || sanitize_file_name( $root ) !== $root ) {
		return '';
	}

	return $root;
}

/**
 * Check whether a directory tree contains no files.
 *
 * @param string $directory Directory path.
 * @return bool
 */
function mcp_expose_is_empty_directory_tree( string $directory ): bool {
	if ( ! is_dir( $directory ) ) {
		return false;
	}

	$items = scandir( $directory );
	if ( false === $items ) {
		return false;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = trailingslashit( $directory ) . $item;
		if ( is_dir( $path ) ) {
			if ( ! mcp_expose_is_empty_directory_tree( $path ) ) {
				return false;
			}
			continue;
		}

		return false;
	}

	return true;
}

/**
 * Delete an empty directory tree.
 *
 * @param string $directory Directory path.
 * @return bool
 */
function mcp_expose_delete_empty_directory_tree( string $directory ): bool {
	if ( ! is_dir( $directory ) ) {
		return false;
	}

	$items = scandir( $directory );
	if ( false === $items ) {
		return false;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = trailingslashit( $directory ) . $item;
		if ( ! is_dir( $path ) || ! mcp_expose_delete_empty_directory_tree( $path ) ) {
			return false;
		}
	}

	WP_Filesystem();
	global $wp_filesystem;

	if ( ! $wp_filesystem ) {
		return false;
	}

	return (bool) $wp_filesystem->rmdir( $directory );
}

/**
 * Remove an empty stale target directory before an overwrite install.
 *
 * @param string $zip_path Path to the local zip file.
 * @return true|WP_Error
 */
function mcp_expose_prepare_plugin_overwrite_target( string $zip_path ) {
	$root = mcp_expose_get_plugin_zip_root_directory( $zip_path );
	if ( '' === $root ) {
		return true;
	}

	$target = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $root );
	if ( ! is_dir( $target ) ) {
		return true;
	}

	if ( ! mcp_expose_is_empty_directory_tree( $target ) ) {
		return true;
	}

	if ( ! mcp_expose_delete_empty_directory_tree( $target ) ) {
		return new WP_Error(
			'mcp_empty_plugin_target_cleanup_failed',
			sprintf(
				/* translators: %s: Plugin target directory. */
				__( 'Plugin upload could not remove empty stale target directory: %s', 'mcp-expose-abilities' ),
				$target
			)
		);
	}

	return true;
}

/**
 * Return a normalized ability-facing nav menu item model.
 *
 * @param int|WP_Post $item Menu item ID or post object.
 * @return array|null
 */
function mcp_expose_get_nav_menu_item_model( $item ): ?array {
	$post = is_numeric( $item ) ? get_post( (int) $item ) : $item;
	if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
		return null;
	}

	$menu_item = wp_setup_nav_menu_item( $post );
	if ( ! $menu_item ) {
		return null;
	}

	return array(
		'id'          => (int) $menu_item->ID,
		'title'       => (string) $menu_item->title,
		'post_title'  => (string) $post->post_title,
		'url'         => (string) $menu_item->url,
		'target'      => (string) $menu_item->target,
		'attr_title'  => (string) $menu_item->attr_title,
		'description' => (string) $menu_item->description,
		'classes'     => is_array( $menu_item->classes ) ? array_values( array_filter( $menu_item->classes ) ) : array(),
		'xfn'         => (string) $menu_item->xfn,
		'parent'      => (int) $menu_item->menu_item_parent,
		'order'       => (int) $menu_item->menu_order,
		'object'      => (string) $menu_item->object,
		'object_id'   => (int) $menu_item->object_id,
		'type'        => (string) $menu_item->type,
	);
}

/**
 * Resolve a menu item object/type pair for a write payload.
 *
 * @param string $object    Object type.
 * @param int    $object_id Object ID.
 * @return array|WP_Error
 */
function mcp_expose_resolve_nav_menu_item_target( string $object, int $object_id ) {
	$object = sanitize_key( $object );
	if ( '' === $object ) {
		$object = 'custom';
	}

	if ( 'custom' === $object ) {
		return array(
			'object'    => 'custom',
			'object_id' => 0,
			'type'      => 'custom',
		);
	}

	if ( ! $object_id ) {
		return new WP_Error( 'mcp_menu_object_id_required', __( 'Object ID is required for non-custom menu items', 'mcp-expose-abilities' ) );
	}

	if ( 'category' === $object ) {
		$term = get_term( $object_id, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'mcp_menu_category_not_found', __( 'Category not found', 'mcp-expose-abilities' ) );
		}

		return array(
			'object'    => 'category',
			'object_id' => $object_id,
			'type'      => 'taxonomy',
		);
	}

	$post_type = get_post_type( $object_id );
	if ( ! $post_type ) {
		return new WP_Error( 'mcp_menu_post_object_not_found', __( 'Post object not found', 'mcp-expose-abilities' ) );
	}

	if ( $post_type !== $object ) {
		return new WP_Error( 'mcp_menu_object_type_mismatch', __( 'Object type does not match the object ID', 'mcp-expose-abilities' ) );
	}

	return array(
		'object'    => $object,
		'object_id' => $object_id,
		'type'      => 'post_type',
	);
}

/**
 * Build a complete WordPress nav menu item write payload.
 *
 * @param array      $input    Ability input.
 * @param array|null $existing Existing normalized item model.
 * @return array|WP_Error
 */
function mcp_expose_build_nav_menu_item_payload( array $input, ?array $existing = null ) {
	$title = array_key_exists( 'title', $input )
		? sanitize_text_field( (string) $input['title'] )
		: sanitize_text_field( (string) ( $existing['post_title'] ?? $existing['title'] ?? '' ) );

	if ( '' === $title ) {
		return new WP_Error( 'mcp_menu_title_required', __( 'Title is required', 'mcp-expose-abilities' ) );
	}

	$object = array_key_exists( 'object', $input )
		? sanitize_key( (string) $input['object'] )
		: (string) ( $existing['object'] ?? 'custom' );
	$object_id = array_key_exists( 'object_id', $input )
		? absint( $input['object_id'] )
		: (int) ( $existing['object_id'] ?? 0 );

	$target = mcp_expose_resolve_nav_menu_item_target( $object, $object_id );
	if ( is_wp_error( $target ) ) {
		return $target;
	}

	$url = array_key_exists( 'url', $input )
		? esc_url_raw( (string) $input['url'] )
		: (string) ( $existing['url'] ?? '' );

	if ( 'custom' !== $target['object'] ) {
		$url = '';
	}

	$classes = array_key_exists( 'classes', $input )
		? sanitize_text_field( (string) $input['classes'] )
		: implode( ' ', (array) ( $existing['classes'] ?? array() ) );

	return array(
		'menu-item-title'     => $title,
		'menu-item-url'       => $url,
		'menu-item-object'    => $target['object'],
		'menu-item-object-id' => (int) $target['object_id'],
		'menu-item-type'      => $target['type'],
		'menu-item-parent-id' => array_key_exists( 'parent', $input ) ? absint( $input['parent'] ) : (int) ( $existing['parent'] ?? 0 ),
		'menu-item-position'  => array_key_exists( 'position', $input ) ? absint( $input['position'] ) : (int) ( $existing['order'] ?? 0 ),
		'menu-item-target'    => array_key_exists( 'target', $input ) ? sanitize_key( (string) $input['target'] ) : (string) ( $existing['target'] ?? '' ),
		'menu-item-classes'   => $classes,
		'menu-item-status'    => 'publish',
	);
}

/**
 * Write a nav menu item and verify the normalized readback.
 *
 * @param int   $menu_id Menu ID.
 * @param int   $item_id Existing item ID, or 0 to create.
 * @param array $payload Complete WordPress nav menu item payload.
 * @return array|WP_Error
 */
function mcp_expose_write_nav_menu_item( int $menu_id, int $item_id, array $payload ) {
	$result = wp_update_nav_menu_item( $menu_id, $item_id, $payload );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$persisted_title = sanitize_text_field( (string) $payload['menu-item-title'] );
	if ( '' !== $persisted_title ) {
		$title_result = wp_update_post(
			array(
				'ID'         => (int) $result,
				'post_title' => $persisted_title,
			),
			true
		);
		if ( is_wp_error( $title_result ) ) {
			return $title_result;
		}
		clean_post_cache( (int) $result );
	}

	$model = mcp_expose_get_nav_menu_item_model( (int) $result );
	if ( ! $model ) {
		return new WP_Error( 'mcp_menu_item_readback_failed', __( 'Menu item was written but could not be read back', 'mcp-expose-abilities' ) );
	}

	foreach ( array( 'object', 'object_id', 'type', 'parent' ) as $field ) {
		$payload_key = array(
			'object'    => 'menu-item-object',
			'object_id' => 'menu-item-object-id',
			'type'      => 'menu-item-type',
			'parent'    => 'menu-item-parent-id',
		)[ $field ];

		if ( (string) $model[ $field ] !== (string) $payload[ $payload_key ] ) {
			return new WP_Error( 'mcp_menu_item_readback_mismatch', __( 'Menu item readback did not match the requested object, type, or parent', 'mcp-expose-abilities' ) );
		}
	}

	return $model;
}

/**
 * Find an existing menu item by object identity or custom URL.
 *
 * @param int   $menu_id Menu ID.
 * @param array $input   Ability input.
 * @return array|null
 */
function mcp_expose_find_nav_menu_item_for_upsert( int $menu_id, array $input ): ?array {
	$items = wp_get_nav_menu_items( $menu_id );
	if ( ! $items ) {
		return null;
	}

	$object    = isset( $input['object'] ) ? sanitize_key( (string) $input['object'] ) : 'custom';
	$object_id = isset( $input['object_id'] ) ? absint( $input['object_id'] ) : 0;
	$url       = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';

	foreach ( $items as $item ) {
		$model = mcp_expose_get_nav_menu_item_model( $item );
		if ( ! $model ) {
			continue;
		}

		if ( 'custom' !== $object && $model['object'] === $object && (int) $model['object_id'] === $object_id ) {
			return $model;
		}

		if ( 'custom' === $object && '' !== $url && $model['type'] === 'custom' && $model['url'] === $url ) {
			return $model;
		}
	}

	return null;
}

/**
 * Validate remote URL for download-based abilities.
 *
 * @param string $url Candidate URL.
 * @return true|WP_Error
 */
function mcp_expose_validate_remote_download_url( string $url ) {
	$url = trim( $url );
	if ( '' === $url ) {
		return new WP_Error( 'mcp_invalid_url', 'URL is required.' );
	}

	if ( ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'mcp_invalid_url', 'Invalid download URL.' );
	}

	$parts  = wp_parse_url( $url );
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return new WP_Error( 'mcp_invalid_url_scheme', 'Only http/https URLs are allowed.' );
	}

	if ( '' === $host ) {
		return new WP_Error( 'mcp_invalid_url_host', 'URL host is required.' );
	}

	$blocked_hosts = array( 'localhost', '0.0.0.0', '127.0.0.1', '::1' );
	if ( in_array( $host, $blocked_hosts, true ) || str_ends_with( $host, '.local' ) ) {
		return new WP_Error( 'mcp_blocked_host', 'Local/internal hosts are not allowed.' );
	}

	if ( filter_var( $host, FILTER_VALIDATE_IP ) && mcp_expose_is_private_ip( $host ) ) {
		return new WP_Error( 'mcp_blocked_ip', 'Private or reserved IP addresses are not allowed.' );
	}

	$resolved_ips = gethostbynamel( $host );
	if ( is_array( $resolved_ips ) ) {
		foreach ( $resolved_ips as $resolved_ip ) {
			if ( mcp_expose_is_private_ip( (string) $resolved_ip ) ) {
				return new WP_Error( 'mcp_blocked_dns', 'Resolved host points to private or reserved address space.' );
			}
		}
	}

	return true;
}

/**
 * Validate remote file size (when Content-Length is available).
 *
 * @param string $url       Remote URL.
 * @param int    $max_bytes Max accepted size.
 * @return true|WP_Error
 */
function mcp_expose_validate_remote_download_size( string $url, int $max_bytes ) {
	$response = wp_remote_head(
		$url,
		array(
			'timeout'     => 15,
			'redirection' => 5,
		)
	);

	if ( is_wp_error( $response ) ) {
		// Fall back to post-download size checks.
		return true;
	}

	$length = wp_remote_retrieve_header( $response, 'content-length' );
	if ( '' === $length || null === $length ) {
		return true;
	}

	$length_int = (int) $length;
	if ( $length_int > 0 && $length_int > $max_bytes ) {
		return new WP_Error(
			'mcp_download_too_large',
			sprintf( 'Remote file exceeds limit (%d bytes > %d bytes).', $length_int, $max_bytes )
		);
	}

	return true;
}

/**
 * Install a plugin from a local zip file path.
 *
 * @param string $zip_path Path to the plugin zip file.
 * @param array  $input    Ability input for activate/overwrite flags.
 *
 * @return array Result payload.
 */
function mcp_expose_install_plugin_zip( string $zip_path, array $input ): array {
	if ( empty( $zip_path ) || ! file_exists( $zip_path ) ) {
		return array( 'success' => false, 'message' => esc_html__( 'Plugin zip file not found', 'mcp-expose-abilities' ) );
	}

	$zip_check = mcp_expose_validate_local_plugin_zip( $zip_path );
	if ( is_wp_error( $zip_check ) ) {
		return array( 'success' => false, 'message' => esc_html( $zip_check->get_error_message() ) );
	}

	$activate  = ! array_key_exists( 'activate', $input ) || (bool) $input['activate'];
	$overwrite = ! array_key_exists( 'overwrite', $input ) || (bool) $input['overwrite'];

	$before_plugins = get_plugins();
	$before_active  = array();
	foreach ( array_keys( $before_plugins ) as $file ) {
		$before_active[ $file ] = is_plugin_active( $file );
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$args     = array(
		'clear_update_cache' => true,
	);

	if ( $overwrite ) {
		$args['overwrite_package'] = true;

		$target_check = mcp_expose_prepare_plugin_overwrite_target( $zip_path );
		if ( is_wp_error( $target_check ) ) {
			return array(
				'success' => false,
				'message' => esc_html( $target_check->get_error_message() ),
			);
		}
	}

	$result = $upgrader->install( $zip_path, $args );
	if ( true !== $result ) {
		return array(
			'success' => false,
			'message' => esc_html( mcp_expose_get_upgrader_error_message( $skin, $result, __( 'Plugin installation failed.', 'mcp-expose-abilities' ) ) ),
		);
	}

	wp_clean_plugins_cache( true );

	$plugin_file = $upgrader->plugin_info();
	if ( empty( $plugin_file ) ) {
		$after_plugins = get_plugins();
		foreach ( $after_plugins as $file => $data ) {
			if ( ! isset( $before_plugins[ $file ] ) || $before_plugins[ $file ] !== $data ) {
				$plugin_file = $file;
				break;
			}
		}
	}

	if ( empty( $plugin_file ) ) {
		return array( 'success' => false, 'message' => esc_html__( 'Plugin installed but main file not found', 'mcp-expose-abilities' ) );
	}

	$was_active = isset( $before_active[ $plugin_file ] ) && $before_active[ $plugin_file ];
	$activated = false;
	if ( $activate || $was_active ) {
		$activate_result = activate_plugin( $plugin_file );
		if ( is_wp_error( $activate_result ) ) {
			return array(
				'success'   => true,
				/* translators: %s: Error message from WordPress */
				'message'   => esc_html__( 'Plugin installed but activation failed: ', 'mcp-expose-abilities' ) . esc_html( $activate_result->get_error_message() ),
				'plugin'    => $plugin_file,
				'activated' => false,
			);
		}
		$activated = true;
	}

	$all_plugins = get_plugins();
	$version     = isset( $all_plugins[ $plugin_file ]['Version'] ) ? (string) $all_plugins[ $plugin_file ]['Version'] : '';

	return array(
		'success'   => true,
		'message'   => $activated
			? esc_html__( 'Plugin installed successfully and activated', 'mcp-expose-abilities' )
			: esc_html__( 'Plugin installed successfully', 'mcp-expose-abilities' ),
		'plugin'    => $plugin_file,
		'version'   => $version,
		'activated' => $activated,
	);
}

/**
 * Find an installed plugin file by WordPress.org slug.
 *
 * @param string $slug Plugin slug.
 * @return string Plugin file or empty string when not found.
 */
function mcp_expose_find_plugin_file_by_slug( string $slug ): string {
	$slug = sanitize_key( $slug );
	if ( '' === $slug ) {
		return '';
	}

	$all_plugins = get_plugins();
	foreach ( $all_plugins as $file => $data ) {
		$directory   = wp_normalize_path( dirname( $file ) );
		$base_name   = sanitize_key( wp_basename( $file, '.php' ) );
		$text_domain = sanitize_key( (string) ( $data['TextDomain'] ?? '' ) );

		if ( $directory === $slug || ( '.' === $directory && $base_name === $slug ) || $text_domain === $slug ) {
			return $file;
		}
	}

	return '';
}

/**
 * Resolve the best available upgrader error message.
 *
 * @param WP_Ajax_Upgrader_Skin $skin Upgrader skin.
 * @param mixed                 $result Upgrader result.
 * @param string                $fallback Default message.
 * @return string
 */
function mcp_expose_get_upgrader_error_message( WP_Ajax_Upgrader_Skin $skin, $result, string $fallback ): string {
	global $wp_filesystem;

	if ( is_wp_error( $result ) ) {
		return $result->get_error_message();
	}

	if ( is_wp_error( $skin->result ) ) {
		return $skin->result->get_error_message();
	}

	$errors = $skin->get_errors();
	if ( $errors instanceof WP_Error && $errors->has_errors() ) {
		return $errors->get_error_message();
	}

	if ( null === $result && $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
		return $wp_filesystem->errors->get_error_message();
	}

	return $fallback;
}

/**
 * Fetch WordPress.org plugin information for a slug.
 *
 * @param string $slug Plugin slug.
 * @return object|WP_Error
 */
function mcp_expose_get_plugin_directory_info( string $slug ) {
	$slug = sanitize_key( $slug );
	if ( '' === $slug ) {
		return new WP_Error( 'mcp_invalid_plugin_slug', __( 'Plugin slug is required.', 'mcp-expose-abilities' ) );
	}

	return plugins_api(
		'plugin_information',
		array(
			'slug'   => $slug,
			'fields' => array(
				'sections'            => false,
				'tags'                => false,
				'versions'            => false,
				'banners'             => false,
				'reviews'             => false,
				'ratings'             => false,
				'downloaded'          => true,
				'active_installs'     => true,
				'short_description'   => true,
				'last_updated'        => true,
				'added'               => true,
				'homepage'            => true,
				'icons'               => true,
				'language_packs'      => false,
				'donate_link'         => false,
				'contributors'        => false,
				'compatibility'       => false,
				'tested'              => true,
				'requires'            => true,
				'requires_php'        => true,
			),
		)
	);
}

/**
 * Install a plugin from the official WordPress.org directory.
 *
 * @param string $slug  Plugin slug.
 * @param array  $input Ability input.
 * @return array Result payload.
 */
function mcp_expose_install_directory_plugin( string $slug, array $input ): array {
	$slug = sanitize_key( $slug );
	if ( '' === $slug ) {
		return array( 'success' => false, 'message' => esc_html__( 'Plugin slug is required', 'mcp-expose-abilities' ) );
	}

	$api = mcp_expose_get_plugin_directory_info( $slug );
	if ( is_wp_error( $api ) ) {
		return array( 'success' => false, 'message' => esc_html( $api->get_error_message() ) );
	}

	$existing_plugin = mcp_expose_find_plugin_file_by_slug( $slug );
	$activate        = ! empty( $input['activate'] );
	$overwrite       = ! empty( $input['overwrite'] );

	if ( ! empty( $existing_plugin ) && ! $overwrite ) {
		if ( $activate && ! is_plugin_active( $existing_plugin ) ) {
			$activate_result = activate_plugin( $existing_plugin );
			if ( is_wp_error( $activate_result ) ) {
				return array(
					'success'   => false,
					'message'   => esc_html__( 'Plugin is already installed, but activation failed: ', 'mcp-expose-abilities' ) . esc_html( $activate_result->get_error_message() ),
					'plugin'    => $existing_plugin,
					'slug'      => $slug,
					'activated' => false,
					'installed' => false,
				);
			}

			return array(
				'success'   => true,
				'message'   => esc_html__( 'Plugin is already installed and has been activated', 'mcp-expose-abilities' ),
				'plugin'    => $existing_plugin,
				'slug'      => $slug,
				'activated' => true,
				'installed' => false,
			);
		}

		return array(
			'success'   => true,
			'message'   => esc_html__( 'Plugin is already installed', 'mcp-expose-abilities' ),
			'plugin'    => $existing_plugin,
			'slug'      => $slug,
			'activated' => is_plugin_active( $existing_plugin ),
			'installed' => false,
		);
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$args     = array();

	if ( $overwrite ) {
		$args['overwrite_package'] = true;
	}

	$result = $upgrader->install( $api->download_link, $args );
	if ( true !== $result ) {
		return array(
			'success' => false,
			'message' => esc_html( mcp_expose_get_upgrader_error_message( $skin, $result, __( 'Plugin installation failed.', 'mcp-expose-abilities' ) ) ),
			'slug'    => $slug,
		);
	}

	$plugin_file = $upgrader->plugin_info();
	if ( empty( $plugin_file ) ) {
		wp_clean_plugins_cache( true );
		$plugin_file = mcp_expose_find_plugin_file_by_slug( $slug );
	}

	if ( empty( $plugin_file ) ) {
		return array(
			'success' => false,
			'message' => esc_html__( 'Plugin installed but the installed plugin file could not be determined.', 'mcp-expose-abilities' ),
			'slug'    => $slug,
		);
	}

	$activated = false;
	if ( $activate ) {
		$activate_result = activate_plugin( $plugin_file );
		if ( is_wp_error( $activate_result ) ) {
			return array(
				'success'   => true,
				'message'   => esc_html__( 'Plugin installed but activation failed: ', 'mcp-expose-abilities' ) . esc_html( $activate_result->get_error_message() ),
				'plugin'    => $plugin_file,
				'slug'      => $slug,
				'activated' => false,
				'installed' => true,
			);
		}
		$activated = true;
	}

	$all_plugins = get_plugins();
	$version     = isset( $all_plugins[ $plugin_file ]['Version'] ) ? (string) $all_plugins[ $plugin_file ]['Version'] : '';

	return array(
		'success'   => true,
		'message'   => $activated
			? esc_html__( 'Plugin installed from the WordPress.org directory and activated', 'mcp-expose-abilities' )
			: esc_html__( 'Plugin installed from the WordPress.org directory', 'mcp-expose-abilities' ),
		'plugin'    => $plugin_file,
		'slug'      => $slug,
		'version'   => $version,
		'activated' => $activated,
		'installed' => true,
	);
}

/**
 * List available plugin updates.
 *
 * @return array<int, array<string, mixed>>
 */
function mcp_expose_get_plugin_updates(): array {
	wp_clean_plugins_cache( true );
	wp_update_plugins();

	$updates     = get_site_transient( 'update_plugins' );
	$all_plugins = get_plugins();
	if ( ! is_object( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) ) {
		return array();
	}

	$items = array();
	foreach ( $updates->response as $plugin_file => $update ) {
		$current_version = isset( $all_plugins[ $plugin_file ]['Version'] ) ? (string) $all_plugins[ $plugin_file ]['Version'] : '';
		$items[]         = array(
			'plugin'          => $plugin_file,
			'slug'            => sanitize_key( (string) ( $update->slug ?? '' ) ),
			'name'            => isset( $all_plugins[ $plugin_file ]['Name'] ) ? (string) $all_plugins[ $plugin_file ]['Name'] : $plugin_file,
			'current_version' => $current_version,
			'new_version'     => isset( $update->new_version ) ? (string) $update->new_version : '',
			'package'         => isset( $update->package ) ? (string) $update->package : '',
			'url'             => isset( $update->url ) ? (string) $update->url : '',
			'active'          => is_plugin_active( $plugin_file ),
		);
	}

	return $items;
}

/**
 * Update a single installed plugin.
 *
 * @param string $plugin_file Plugin file path.
 * @return array Result payload.
 */
function mcp_expose_update_plugin( string $plugin_file ): array {
	$all_plugins = get_plugins();
	if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
		return array( 'success' => false, 'message' => esc_html__( 'Plugin not found: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ) );
	}
	$was_active = is_plugin_active( $plugin_file );

	$update_item = null;
	foreach ( mcp_expose_get_plugin_updates() as $item ) {
		if ( $item['plugin'] === $plugin_file ) {
			$update_item = $item;
			break;
		}
	}

	if ( empty( $update_item ) ) {
		return array(
			'success'         => true,
			'message'         => esc_html__( 'Plugin is already up to date', 'mcp-expose-abilities' ),
			'plugin'          => $plugin_file,
			'current_version' => (string) $all_plugins[ $plugin_file ]['Version'],
			'updated'         => false,
		);
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->upgrade( $plugin_file );

	if ( true !== $result ) {
		return array(
			'success' => false,
			'message' => esc_html( mcp_expose_get_upgrader_error_message( $skin, $result, __( 'Plugin update failed.', 'mcp-expose-abilities' ) ) ),
			'plugin'  => $plugin_file,
		);
	}

	wp_clean_plugins_cache( true );
	if ( $was_active && ! is_plugin_active( $plugin_file ) ) {
		$activate_result = activate_plugin( $plugin_file );
		if ( is_wp_error( $activate_result ) ) {
			return array(
				'success'          => false,
				'message'          => esc_html__( 'Plugin updated, but reactivation failed: ', 'mcp-expose-abilities' ) . esc_html( $activate_result->get_error_message() ),
				'plugin'           => $plugin_file,
				'previous_version' => (string) $update_item['current_version'],
				'current_version'  => '',
				'updated'          => true,
				'active_before'    => true,
				'active_after'     => false,
				'reactivated'      => false,
			);
		}
	}

	wp_clean_plugins_cache( true );
	$all_plugins = get_plugins();
	$new_version = isset( $all_plugins[ $plugin_file ]['Version'] ) ? (string) $all_plugins[ $plugin_file ]['Version'] : '';
	$active_after = is_plugin_active( $plugin_file );

	return array(
		'success'         => true,
		'message'         => esc_html__( 'Plugin updated successfully', 'mcp-expose-abilities' ),
		'plugin'          => $plugin_file,
		'previous_version' => (string) $update_item['current_version'],
		'current_version' => $new_version,
		'updated'         => true,
		'active_before'   => $was_active,
		'active_after'    => $active_after,
		'reactivated'     => $was_active && $active_after,
	);
}

/**
 * Switch active plugins by activating one plugin and deactivating others.
 *
 * @param string   $activate_plugin Plugin file to activate.
 * @param string[] $deactivate_plugins Plugin files to deactivate.
 * @return array Result payload.
 */
function mcp_expose_switch_plugins( string $activate_plugin, array $deactivate_plugins ): array {
	$all_plugins = get_plugins();
	if ( empty( $activate_plugin ) ) {
		return array( 'success' => false, 'message' => esc_html__( 'activate_plugin is required', 'mcp-expose-abilities' ) );
	}

	if ( ! isset( $all_plugins[ $activate_plugin ] ) ) {
		return array( 'success' => false, 'message' => esc_html__( 'Plugin not found: ', 'mcp-expose-abilities' ) . esc_html( $activate_plugin ) );
	}

	$deactivate_plugins = array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $deactivate_plugins )
			)
		)
	);

	if ( in_array( $activate_plugin, $deactivate_plugins, true ) ) {
		return array( 'success' => false, 'message' => esc_html__( 'activate_plugin cannot also appear in deactivate_plugins', 'mcp-expose-abilities' ) );
	}

	foreach ( $deactivate_plugins as $plugin_file ) {
		if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
			return array( 'success' => false, 'message' => esc_html__( 'Plugin not found: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ) );
		}
	}

	$was_active = array();
	foreach ( $deactivate_plugins as $plugin_file ) {
		$was_active[ $plugin_file ] = is_plugin_active( $plugin_file );
	}

	foreach ( $deactivate_plugins as $plugin_file ) {
		if ( $was_active[ $plugin_file ] ) {
			deactivate_plugins( $plugin_file );
		}
	}

	$activate_result = null;
	if ( ! is_plugin_active( $activate_plugin ) ) {
		$activate_result = activate_plugin( $activate_plugin );
		if ( is_wp_error( $activate_result ) ) {
			foreach ( $deactivate_plugins as $plugin_file ) {
				if ( ! empty( $was_active[ $plugin_file ] ) ) {
					$rollback_result = activate_plugin( $plugin_file );
					if ( is_wp_error( $rollback_result ) ) {
						break;
					}
				}
			}

			return array(
				'success' => false,
				'message' => esc_html__( 'Switch failed while activating target plugin: ', 'mcp-expose-abilities' ) . esc_html( $activate_result->get_error_message() ),
			);
		}
	}

	return array(
		'success'             => true,
		'message'             => esc_html__( 'Plugin switch completed successfully', 'mcp-expose-abilities' ),
		'activated_plugin'    => $activate_plugin,
		'deactivated_plugins' => $deactivate_plugins,
	);
}

/**
 * Add MCP exposure metadata to all registered abilities.
 *
 * @param array  $args         The arguments used to instantiate the ability.
 * @param string $ability_name The name of the ability being registered.
 *
 * @return array Modified ability arguments with MCP exposure enabled.
 */
function mcp_expose_all_abilities( array $args, string $ability_name ): array {
	if ( ! isset( $args['meta'] ) ) {
		$args['meta'] = array();
	}
	if ( ! isset( $args['meta']['mcp'] ) ) {
		$args['meta']['mcp'] = array();
	}
	if ( ! isset( $args['meta']['mcp']['public'] ) ) {
		$args['meta']['mcp']['public'] = true;
	}
	if ( ! isset( $args['meta']['mcp']['type'] ) ) {
		$args['meta']['mcp']['type'] = 'tool';
	}
	if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
		$args['execute_callback'] = mcp_expose_wrap_ability_timing_callback( $ability_name, $args['execute_callback'] );
	}
	return $args;
}
add_filter( 'wp_register_ability_args', 'mcp_expose_all_abilities', 10, 2 );

/**
 * Wrap an ability callback with lightweight timing capture.
 *
 * @param string   $ability_name Ability name.
 * @param callable $callback     Original execute callback.
 * @return callable
 */
function mcp_expose_wrap_ability_timing_callback( string $ability_name, callable $callback ): callable {
	return function ( $input = array() ) use ( $ability_name, $callback ) {
		$start_time   = microtime( true );
		$start_memory = memory_get_usage( true );
		$result       = null;
		$thrown       = null;

		try {
			$result = call_user_func( $callback, $input );
			return $result;
		} catch ( Throwable $exception ) {
			$thrown = $exception;
			throw $exception;
		} finally {
			$duration_ms = ( microtime( true ) - $start_time ) * 1000;
			mcp_expose_maybe_record_ability_timing(
				$ability_name,
				$duration_ms,
				mcp_expose_ability_timing_result_details( $result, $thrown ),
				memory_get_usage( true ) - $start_memory,
				memory_get_peak_usage( true )
			);
		}
	};
}

/**
 * Derive timing status details from an ability result.
 *
 * @param mixed          $result Ability result.
 * @param Throwable|null $thrown Thrown exception, if any.
 * @return array{success:bool,code:string,message:string}
 */
function mcp_expose_ability_timing_result_details( $result, ?Throwable $thrown ): array {
	if ( $thrown instanceof Throwable ) {
		return array(
			'success' => false,
			'code'    => 'exception',
			'message' => $thrown->getMessage(),
		);
	}

	if ( is_wp_error( $result ) ) {
		return array(
			'success' => false,
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
		);
	}

	if ( is_array( $result ) && array_key_exists( 'success', $result ) && true !== $result['success'] ) {
		$message = isset( $result['message'] ) && is_scalar( $result['message'] ) ? (string) $result['message'] : '';

		return array(
			'success' => false,
			'code'    => 'ability_failure',
			'message' => $message,
		);
	}

	return array(
		'success' => true,
		'code'    => '',
		'message' => '',
	);
}

/**
 * Store slow or failed ability timings in a bounded option.
 *
 * @param string $ability_name Ability name.
 * @param float  $duration_ms  Duration in milliseconds.
 * @param array  $details      Result details.
 * @param int    $memory_delta Memory delta in bytes.
 * @param int    $memory_peak  Peak memory in bytes.
 * @return void
 */
function mcp_expose_maybe_record_ability_timing( string $ability_name, float $duration_ms, array $details, int $memory_delta, int $memory_peak ): void {
	if ( 'system/ability-timings' === $ability_name ) {
		return;
	}

	$threshold_ms = (float) apply_filters( 'mcp_expose_ability_timing_threshold_ms', 1000.0 );
	$success      = isset( $details['success'] ) ? (bool) $details['success'] : true;

	if ( $success && $duration_ms < $threshold_ms ) {
		return;
	}

	$entries = get_option( 'mcp_expose_ability_timings', array() );
	if ( ! is_array( $entries ) ) {
		$entries = array();
	}

	$entry = array(
		'timestamp'       => gmdate( 'c' ),
		'ability'         => sanitize_text_field( $ability_name ),
		'duration_ms'     => round( $duration_ms, 2 ),
		'success'         => $success,
		'code'            => isset( $details['code'] ) ? sanitize_key( (string) $details['code'] ) : '',
		'message'         => isset( $details['message'] ) ? wp_strip_all_tags( (string) $details['message'] ) : '',
		'memory_delta_mb' => round( $memory_delta / 1048576, 2 ),
		'memory_peak_mb'  => round( $memory_peak / 1048576, 2 ),
	);

	if ( isset( $details['context'] ) && is_array( $details['context'] ) ) {
		$entry['context'] = mcp_expose_sanitize_timing_context( $details['context'] );
	}

	$entries[] = $entry;

	$entries = array_slice( $entries, -50 );
	update_option( 'mcp_expose_ability_timings', $entries, false );
}

/**
 * Sanitize a compact diagnostic context for timing entries.
 *
 * @param array $context Raw context.
 * @return array
 */
function mcp_expose_sanitize_timing_context( array $context ): array {
	$allowed = array( 'event', 'method', 'tool', 'status', 'transport', 'server_id', 'request_id' );
	$result  = array();

	foreach ( $allowed as $key ) {
		if ( ! isset( $context[ $key ] ) || ! is_scalar( $context[ $key ] ) ) {
			continue;
		}
		$value = sanitize_text_field( (string) $context[ $key ] );
		if ( '' !== $value ) {
			$result[ $key ] = $value;
		}
	}

	return $result;
}

/**
 * Return recent stored ability timings.
 *
 * @param array $input Ability input.
 * @return array
 */
function mcp_expose_get_ability_timings( array $input ): array {
	$limit           = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
	$limit           = max( 1, min( 50, $limit ) );
	$min_duration_ms = isset( $input['min_duration_ms'] ) ? max( 0.0, (float) $input['min_duration_ms'] ) : 0.0;
	$ability_filter  = isset( $input['ability'] ) && is_string( $input['ability'] ) ? trim( $input['ability'] ) : '';
	$entries         = get_option( 'mcp_expose_ability_timings', array() );

	if ( ! is_array( $entries ) ) {
		$entries = array();
	}

	$entries = array_values(
		array_filter(
			$entries,
			function ( $entry ) use ( $ability_filter, $min_duration_ms ): bool {
				if ( ! is_array( $entry ) ) {
					return false;
				}
				if ( '' !== $ability_filter && ( $entry['ability'] ?? '' ) !== $ability_filter ) {
					return false;
				}
				if ( isset( $entry['duration_ms'] ) && (float) $entry['duration_ms'] < $min_duration_ms ) {
					return false;
				}
				return true;
			}
		)
	);

	$entries = array_slice( $entries, -$limit );

	return array(
		'success'      => true,
		'threshold_ms' => (float) apply_filters( 'mcp_expose_ability_timing_threshold_ms', 1000.0 ),
		'count'        => count( $entries ),
		'entries'      => array_values( array_reverse( $entries ) ),
	);
}

/**
 * Normalize pagination parameters for list abilities.
 *
 * @param array $input            Input array.
 * @param int   $default_per_page Default per-page value.
 * @param int   $max_per_page     Maximum per-page value.
 * @return array{per_page:int,page:int}
 */
function mcp_expose_parse_pagination( array $input, int $default_per_page, int $max_per_page ): array {
	$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : $default_per_page;
	$per_page = max( 1, min( $max_per_page, $per_page ) );

	$page = isset( $input['page'] ) ? (int) $input['page'] : 1;
	$page = max( 1, $page );

	return array(
		'per_page' => $per_page,
		'page'     => $page,
	);
}

/**
 * Normalize orderby aliases to WordPress-supported values.
 *
 * @param string|null $orderby Raw orderby input.
 * @param string      $default Default value when input is empty/invalid.
 * @return string
 */
function mcp_expose_normalize_orderby( ?string $orderby, string $default = 'date' ): string {
	$value = is_string( $orderby ) ? trim( $orderby ) : '';
	if ( '' === $value ) {
		return $default;
	}

	$map = array(
		'date'       => 'date',
		'modified'   => 'modified',
		'title'      => 'title',
		'id'         => 'ID',
		'ID'         => 'ID',
		'name'       => 'name',
		'slug'       => 'name',
		'post_name'  => 'name',
		'menu_order' => 'menu_order',
	);

	return $map[ $value ] ?? $map[ strtolower( $value ) ] ?? $default;
}

/**
 * Normalize sort order to ASC or DESC.
 *
 * @param string|null $order Raw order input.
 * @param string      $default Default sort order.
 * @return string
 */
function mcp_expose_normalize_order( ?string $order, string $default = 'DESC' ): string {
	$value = strtoupper( trim( (string) $order ) );
	return in_array( $value, array( 'ASC', 'DESC' ), true ) ? $value : strtoupper( $default );
}

/**
 * Get the list of protected option names.
 *
 * @return array<string>
 */
function mcp_expose_protected_option_names(): array {
	$protected = array(
		'active_plugins',           // Can disable security plugins.
		'siteurl',                  // Can break site access.
		'home',                     // Can break site access.
		'template',                 // Can break theme bootstrap.
		'stylesheet',               // Can break theme bootstrap.
		'users_can_register',       // Security: user registration.
		'default_role',             // Security: new user privileges.
		'admin_email',              // Security: site recovery email.
		'cron',                     // Can inject malicious scheduled tasks.
		'auto_updater.lock',        // Can block security updates.
		'rewrite_rules',            // Can break permalinks.
		'recently_activated',       // Plugin state tracking.
		'uninstall_plugins',        // Plugin cleanup callbacks.
		'wp_user_roles',            // Security: role definitions.
		'mcp_gmail_config',         // Stores service account keys.
		'cloudflare_api_key',       // External API secret.
		'sib_api_key_v3',           // External API secret.
		'wordfence_apiKey',         // Security plugin API key.
		'mailserver_pass',          // SMTP password.
		'mailserver_login',         // SMTP login.
	);

	/**
	 * Filter protected option names for MCP option abilities.
	 *
	 * @param array<string> $protected Protected option names.
	 */
	return apply_filters( 'mcp_expose_protected_options', $protected );
}

/**
 * Determine whether an option name is sensitive and should be blocked.
 *
 * @param string $option_name Option name.
 * @return bool
 */
function mcp_expose_is_sensitive_option_name( string $option_name ): bool {
	$option_name = sanitize_key( $option_name );

	if ( in_array( $option_name, mcp_expose_protected_option_names(), true ) ) {
		return true;
	}

	$sensitive_patterns = array(
		'/(?:^|_)(api_?key|token|secret|private_?key|password|client_?secret)(?:$|_)/i',
		'/(?:^|_)auth(?:$|_)/i',
	);

	foreach ( $sensitive_patterns as $pattern ) {
		if ( preg_match( $pattern, $option_name ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Read the last N non-empty lines from a file.
 *
 * @param string $file_path File path.
 * @param int    $num_lines Number of lines to return.
 * @return array<int,string>
 */
function mcp_expose_read_tail_lines( string $file_path, int $num_lines ): array {
	if ( $num_lines < 1 ) {
		return array();
	}

	WP_Filesystem();
	global $wp_filesystem;

	if ( ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'get_contents_array' ) ) {
		return array();
	}

	$lines = $wp_filesystem->get_contents_array( $file_path );
	if ( ! is_array( $lines ) ) {
		return array();
	}

	$filtered = array_values(
		array_filter(
			$lines,
			static function ( $line ): bool {
				return is_string( $line ) && trim( $line ) !== '';
			}
		)
	);

	if ( count( $filtered ) > $num_lines ) {
		$filtered = array_slice( $filtered, -$num_lines );
	}

	return $filtered;
}

// ============================================================================
// CENTRALIZED HELPER CLASS
// Provides validation, caching, and response helpers.
// ============================================================================

/**
 * Helper class for MCP abilities.
 * Provides centralized methods for validation, caching, and response formatting.
 */
class MCP_Helper {

	/**
	 * Validate required parameters.
	 *
	 * @param array       $input      Input data.
	 * @param array       $required   Required parameter names.
	 * @param string|null $error_code Error code to return.
	 * @return array|null WP_Error or null if valid.
	 */
	public static function validate_required( array $input, array $required, ?string $error_code = null ): ?array {
		$missing = array();
		foreach ( $required as $param ) {
			if ( empty( $input[ $param ] ) ) {
				$missing[] = $param;
			}
		}
		if ( ! empty( $missing ) ) {
			$code = $error_code ?? 'missing_params';
			return array(
				'success' => false,
				/* translators: %s: Missing parameter names */
				'message' => esc_html( sprintf( __( 'Missing required parameter(s): %s', 'mcp-expose-abilities' ), implode( ', ', $missing ) ) ),
			);
		}
		return null;
	}

	/**
	 * Get cached result or compute and cache.
	 *
	 * @param string   $cache_key  Cache key.
	 * @param callable $callback   Function to call if cache miss.
	 * @param string   $cache_group Cache group.
	 * @param int      $expires    Expiration in seconds.
	 * @return mixed Cached or computed result.
	 */
	public static function get_cached( string $cache_key, callable $callback, string $cache_group = 'mcp', int $expires = 300 ) {
		$result = wp_cache_get( $cache_key, $cache_group );
		if ( false === $result ) {
			$result = $callback();
			if ( ! is_wp_error( $result ) ) {
				wp_cache_set( $cache_key, $result, $cache_group, $expires );
			}
		}
		return $result;
	}

	/**
	 * Invalidate cache by group.
	 *
	 * @param string $pattern Cache key pattern (supports % wildcards).
	 * @param string $group   Cache group.
	 */
	public static function invalidate_cache( string $pattern, string $group = 'mcp' ): void {
		// WordPress cache doesn't support pattern deletion.
		// For now, just note this limitation.
		// In production, consider using Redis with SCAN command.
	}

	/**
	 * Format post for output.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $extra Extra fields to include.
	 * @return array Formatted post data.
	 */
	public static function format_post( WP_Post $post, array $extra = array() ): array {
		$data = array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'link'     => get_permalink( $post->ID ),
		);
		return array_merge( $data, $extra );
	}

	/**
	 * Format user for output.
	 *
	 * @param WP_User $user User object.
	 * @param array   $extra Extra fields to include.
	 * @return array Formatted user data.
	 */
	public static function format_user( WP_User $user, array $extra = array() ): array {
		$data = array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'roles'        => $user->roles,
		);
		return array_merge( $data, $extra );
	}

	/**
	 * Format media item for output.
	 *
	 * @param WP_Post $attachment Attachment post object.
	 * @return array Formatted media data.
	 */
	public static function format_media( WP_Post $attachment ): array {
		return array(
			'id'        => $attachment->ID,
			'title'     => $attachment->post_title,
			'filename'  => basename( get_attached_file( $attachment->ID ) ),
			'mime_type' => $attachment->post_mime_type,
			'url'       => wp_get_attachment_url( $attachment->ID ),
			'date'      => $attachment->post_date,
			'alt_text'  => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Check capability with fallback.
	 *
	 * @param string $cap   Capability to check.
	 * @param mixed  $args  Additional arguments for cap check.
	 * @param string $error_message Error message if denied.
	 * @return array|null WP_Error if denied, null if allowed.
	 */
	public static function check_capability( string $cap, $args = null, string $error_message = 'Permission denied' ): ?array {
		if ( ! current_user_can( $cap, $args ) ) {
			return array(
				'success' => false,
				'message' => esc_html( $error_message ),
			);
		}
		return null;
	}

	/**
	 * Create success response.
	 *
	 * @param string $message Success message.
	 * @param array  $extra   Extra data to include.
	 * @return array Response array.
	 */
	public static function success( string $message, array $extra = array() ): array {
		return array_merge(
			array( 'success' => true, 'message' => esc_html( $message ) ),
			$extra
		);
	}

	/**
	 * Create error response.
	 *
	 * @param string|WP_Error $error Error message or WP_Error object.
	 * @param string          $prefix Optional prefix.
	 * @return array Response array.
	 */
	public static function error( $error, string $prefix = '' ): array {
		$message = $error instanceof WP_Error
			? $error->get_error_message()
			: $error;
		return array(
			'success' => false,
			'message' => esc_html( $prefix . $message ),
		);
	}
}

/**
 * Get optimized WP_Query arguments.
 *
 * @param array $args Base arguments (post_type, post_status, etc.).
 * @param array $pagination Pagination params (per_page, page).
 * @param array $options Additional options (orderby, order, search, etc.).
 * @return array Full WP_Query arguments with performance optimizations.
 */
function mcp_get_optimized_query_args( array $args, array $pagination = array(), array $options = array() ): array {
	$defaults = array(
		'posts_per_page'         => $pagination['per_page'] ?? 20,
		'paged'                  => $pagination['page'] ?? 1,
		// Performance optimizations.
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'update_post_meta_cache' => false,
	);

	return array_merge( $defaults, $args, $options );
}

// ============================================================================
// REGISTER ABILITY CATEGORIES
// ============================================================================

/**
 * Register content management abilities.
 */
function mcp_register_content_abilities(): void {
	// =========================================================================
	// POSTS - List
	// =========================================================================
	wp_register_ability(
		'content/list-posts',
		array(
			'label'               => 'List Posts',
			'description'         => 'List posts. Params: status, per_page, page, orderby, order, search, category_id, author_id, post_type (all optional). Accepts case-insensitive order and common orderby aliases like id and slug.',
			'category'            => 'site',
				'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'      => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' ),
						'default'     => 'publish',
						'description' => 'Filter by post status.',
					),
					'post_type'   => array(
						'type'        => 'string',
						'default'     => 'post',
						'description' => 'Post type to list (default: post).',
					),
					'per_page'    => array(
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of posts to return.',
					),
					'include_totals' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Include total counts (disables no_found_rows optimization).',
					),
					'page'        => array(
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
						'description' => 'Page number for pagination.',
					),
					'orderby'     => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'modified', 'title', 'ID', 'id', 'name', 'slug', 'post_name' ),
						'default'     => 'date',
						'description' => 'Field to order by. Aliases: id -> ID, slug/post_name -> name.',
					),
					'order'       => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC', 'asc', 'desc' ),
						'default'     => 'DESC',
						'description' => 'Sort order (case-insensitive).',
					),
					'search'      => array(
						'type'        => 'string',
						'description' => 'Search term to filter posts.',
					),
					'category_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by category ID.',
					),
					'author_id'   => array(
						'type'        => 'integer',
						'description' => 'Filter by author ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'posts'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'title'    => array( 'type' => 'string' ),
								'slug'     => array( 'type' => 'string' ),
								'status'   => array( 'type' => 'string' ),
								'date'     => array( 'type' => 'string' ),
								'modified' => array( 'type' => 'string' ),
								'excerpt'  => array( 'type' => 'string' ),
								'link'     => array( 'type' => 'string' ),
							),
						),
					),
					'returned'    => array( 'type' => 'integer' ),
					'has_more'    => array( 'type' => 'boolean' ),
					'total'       => array( 'type' => array( 'integer', 'null' ) ),
					'total_pages' => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$pagination = mcp_expose_parse_pagination( $input, 10, 100 );
				$include_totals = ! empty( $input['include_totals'] );
				$post_type = sanitize_key( $input['post_type'] ?? 'post' );
				if ( ! post_type_exists( $post_type ) ) {
					/* translators: %s: Post type name */
					return array( 'success' => false, 'message' => esc_html__( 'Invalid post_type: ', 'mcp-expose-abilities' ) . esc_html( $post_type ) );
				}

				$args = array(
					'post_type'              => $post_type,
					'post_status'            => $input['status'] ?? 'publish',
					'posts_per_page'         => $pagination['per_page'],
					'paged'                  => $pagination['page'],
					'orderby'                => mcp_expose_normalize_orderby( $input['orderby'] ?? null, 'date' ),
					'order'                  => mcp_expose_normalize_order( $input['order'] ?? null, 'DESC' ),
					// Performance optimizations.
					'no_found_rows'          => ! $include_totals,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				);

				if ( 'any' === $args['post_status'] ) {
					$args['post_status'] = array( 'publish', 'draft', 'pending', 'private', 'future' );
				}

				if ( ! empty( $input['search'] ) ) {
					$args['s'] = $input['search'];
				}
				if ( ! empty( $input['category_id'] ) ) {
					$args['cat'] = $input['category_id'];
				}
				if ( ! empty( $input['author_id'] ) ) {
					$args['author'] = $input['author_id'];
				}

				$query = new WP_Query( $args );
				$posts = array();

				foreach ( $query->posts as $post ) {
					$posts[] = array(
						'id'       => $post->ID,
						'title'    => $post->post_title,
						'slug'     => $post->post_name,
						'status'   => $post->post_status,
						'date'     => $post->post_date,
						'modified' => $post->post_modified,
						'excerpt'  => wp_trim_words( $post->post_excerpt ?: $post->post_content, 30 ),
						'link'     => get_permalink( $post->ID ),
					);
				}

				$returned = count( $posts );
				$total = $include_totals ? (int) $query->found_posts : null;
				$total_pages = $include_totals ? (int) $query->max_num_pages : null;
				$has_more = $include_totals
					? $pagination['page'] < (int) $query->max_num_pages
					: $returned === $pagination['per_page'];

				return array(
					'posts'       => $posts,
					'returned'    => $returned,
					'has_more'    => $has_more,
					'total'       => $total,
					'total_pages' => $total_pages,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Get Single
	// =========================================================================
	wp_register_ability(
		'content/get-post',
		array(
			'label'               => 'Get Post',
			'description'         => 'Get single post. Params: id or slug (one required). Optional post_type helps slug lookups and custom post types.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Post ID to retrieve.',
					),
					'slug' => array(
						'type'        => 'string',
						'description' => 'Post slug to retrieve (used if ID not provided).',
					),
					'post_type' => array(
						'type'        => 'string',
						'default'     => 'post',
						'description' => 'Post type used for slug lookups (default: post).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'id'             => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'slug'           => array( 'type' => 'string' ),
					'status'         => array( 'type' => 'string' ),
					'content'        => array( 'type' => 'string' ),
					'excerpt'        => array( 'type' => 'string' ),
					'date'           => array( 'type' => 'string' ),
					'modified'       => array( 'type' => 'string' ),
					'author_id'      => array( 'type' => 'integer' ),
					'author_name'    => array( 'type' => 'string' ),
					'categories'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'tags'           => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'featured_image' => array( 'type' => 'string' ),
					'featured_image_id' => array( 'type' => array( 'integer', 'null' ) ),
					'link'           => array( 'type' => 'string' ),
					'message'        => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$post  = null;

				if ( ! empty( $input['id'] ) ) {
					$post = get_post( $input['id'] );
				} elseif ( ! empty( $input['slug'] ) ) {
					$post_type = sanitize_key( $input['post_type'] ?? 'post' );
					if ( ! post_type_exists( $post_type ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'Invalid post_type: ', 'mcp-expose-abilities' ) . esc_html( $post_type ) );
					}
					$posts = get_posts( array(
						'name'        => $input['slug'],
						'post_type'   => $post_type,
						'post_status' => 'any',
						'numberposts' => 1,
					) );
					$post = $posts[0] ?? null;
				}

				if ( ! $post ) {
					$response = array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
					if ( ! empty( $input['id'] ) ) {
						$response['requested_id'] = (int) $input['id'];
					}
					if ( ! empty( $input['slug'] ) ) {
						$response['requested_slug'] = (string) $input['slug'];
						$response['post_type']      = sanitize_key( $input['post_type'] ?? 'post' );
					}
					return $response;
				}

				if ( ! current_user_can( 'read_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied', 'mcp-expose-abilities' ) );
				}

				$categories   = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
				$tags         = wp_get_post_tags( $post->ID );
				$author       = get_user_by( 'id', $post->post_author );
				$thumbnail    = get_the_post_thumbnail_url( $post->ID, 'full' );
				$thumbnail_id = get_post_thumbnail_id( $post->ID );

				return array(
					'success'        => true,
					'id'             => $post->ID,
					'title'          => $post->post_title,
					'slug'           => $post->post_name,
					'status'         => $post->post_status,
					'content'        => $post->post_content,
					'excerpt'        => $post->post_excerpt,
					'date'           => $post->post_date,
					'modified'       => $post->post_modified,
					'author_id'      => (int) $post->post_author,
					'author_name'    => $author ? $author->display_name : '',
					'categories'     => array_map( function ( $cat ) {
						return array( 'id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug );
					}, $categories ),
					'tags'           => array_map( function ( $tag ) {
						return array( 'id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug );
					}, $tags ),
					'featured_image' => $thumbnail ?: '',
					'featured_image_id' => $thumbnail_id ? (int) $thumbnail_id : null,
					'link'           => get_permalink( $post->ID ),
					'message'        => 'Post retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Get Next Existing Post
	// =========================================================================
	wp_register_ability(
		'content/get-next-post',
		array(
			'label'               => 'Get Next Post',
			'description'         => 'Find the next existing post after a given ID. Useful when IDs have gaps. Optional filters: status, post_type, category_id, author_id.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'after_id' ),
				'properties'           => array(
					'after_id'    => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return the first post with an ID greater than this value.',
					),
					'status'      => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ),
						'default'     => 'publish',
						'description' => 'Filter by post status.',
					),
					'post_type'   => array(
						'type'        => 'string',
						'default'     => 'post',
						'description' => 'Post type to search (default: post).',
					),
					'category_id' => array(
						'type'        => 'integer',
						'description' => 'Optional category filter.',
					),
					'author_id'   => array(
						'type'        => 'integer',
						'description' => 'Optional author filter.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'found'    => array( 'type' => 'boolean' ),
					'id'       => array( 'type' => array( 'integer', 'null' ) ),
					'title'    => array( 'type' => array( 'string', 'null' ) ),
					'slug'     => array( 'type' => array( 'string', 'null' ) ),
					'status'   => array( 'type' => array( 'string', 'null' ) ),
					'date'     => array( 'type' => array( 'string', 'null' ) ),
					'modified' => array( 'type' => array( 'string', 'null' ) ),
					'link'     => array( 'type' => array( 'string', 'null' ) ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! array_key_exists( 'after_id', $input ) ) {
					return MCP_Helper::error( __( 'after_id is required', 'mcp-expose-abilities' ) );
				}

				$post_type = sanitize_key( $input['post_type'] ?? 'post' );
				if ( ! post_type_exists( $post_type ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Invalid post_type: ', 'mcp-expose-abilities' ) . esc_html( $post_type ) );
				}

				$args = array(
					'post_type'              => $post_type,
					'post_status'            => $input['status'] ?? 'publish',
					'posts_per_page'         => 1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'suppress_filters'       => false,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				);

				if ( 'any' === $args['post_status'] ) {
					$args['post_status'] = array( 'publish', 'draft', 'pending', 'private', 'future' );
				}

				if ( ! empty( $input['category_id'] ) ) {
					$args['cat'] = (int) $input['category_id'];
				}

				if ( ! empty( $input['author_id'] ) ) {
					$args['author'] = (int) $input['author_id'];
				}

				add_filter(
					'posts_where',
					$filter = static function ( string $where ) use ( $input ): string {
						global $wpdb;
						return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", (int) $input['after_id'] );
					}
				);

				$posts = get_posts( $args );
				remove_filter( 'posts_where', $filter );

				if ( empty( $posts ) ) {
					return array(
						'success'  => true,
						'found'    => false,
						'id'       => null,
						'title'    => null,
						'slug'     => null,
						'status'   => null,
						'date'     => null,
						'modified' => null,
						'link'     => null,
						'message'  => esc_html__( 'No next post found', 'mcp-expose-abilities' ),
					);
				}

				$post = $posts[0];

				return array_merge(
					array(
						'success' => true,
						'found'   => true,
						'message' => esc_html__( 'Next post found', 'mcp-expose-abilities' ),
					),
					MCP_Helper::format_post( $post )
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Create
	// =========================================================================
	// Check for Toolset custom post types
	$custom_types = get_post_types( array( '_builtin' => false ), 'objects' );
	$type_choices = array( 'post', 'page' );
	$type_enum    = array( 'post', 'page' );
	foreach ( $custom_types as $type ) {
		$type_choices[] = $type->name;
		$type_enum[]    = $type->name;
	}

	$all_taxonomies     = get_taxonomies( array( '_builtin' => false ), 'objects' );
	$taxonomy_choices   = array();
	$taxonomy_enum      = array();
	$builtin_taxonomies = array( 'category', 'post_tag' );
	foreach ( $all_taxonomies as $tax ) {
		$taxonomy_choices[] = $tax->name;
		$taxonomy_enum[]    = $tax->name;
	}
	foreach ( $builtin_taxonomies as $builtin ) {
		$taxonomy_choices[] = $builtin;
		$taxonomy_enum[]    = $builtin;
	}

	// Collect all taxonomies for this post type
	$post_type_taxonomies = array();
	foreach ( $type_choices as $pt ) {
		$taxonomies = get_object_taxonomies( $pt, 'objects' );
		$post_type_taxonomies[ $pt ] = array_keys( $taxonomies );
	}

	$ability = array(
		'label'               => 'Create Post',
		'description'         => 'Create post. For custom post types, use post_type param. Use tax_input for custom taxonomies. Supports featured_image_id.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'title' ),
			'properties'           => array(
				'title'        => array(
					'type'        => 'string',
					'description' => 'Post title.',
				),
				'content'      => array(
					'type'        => 'string',
					'description' => 'Post content (supports Gutenberg blocks).',
				),
				'excerpt'      => array(
					'type'        => 'string',
					'description' => 'Post excerpt.',
				),
				'status'       => array(
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'default'     => 'draft',
					'description' => 'Post status.',
				),
				'post_type'    => array(
					'type'        => 'string',
					'default'     => 'post',
					'description' => 'Post type. Options: ' . implode( ', ', $type_choices ),
				),
				'slug'         => array(
					'type'        => 'string',
					'description' => 'Post slug (auto-generated from title if not provided).',
				),
				'category_ids' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Array of category IDs (for built-in category taxonomy).',
				),
				'tag_ids'      => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Array of tag IDs (for built-in post_tag taxonomy).',
				),
				'tax_input'    => array(
					'type'        => 'object',
					'description' => 'Taxonomy terms. Format: {"taxonomy_name": ["term_slug"]}. For custom taxonomies.',
					'properties'  => array(),
				),
				'meta_input'   => array(
					'type'        => 'object',
					'description' => 'Post meta fields. Format: {"meta_key": "value"}.',
				),
				'date'         => array(
					'type'        => 'string',
					'description' => 'Post date (Y-m-d H:i:s format). For scheduled posts.',
				),
				'author_id'    => array(
					'type'        => 'integer',
					'description' => 'Author user ID. Defaults to current user.',
				),
				'featured_image_id' => array(
					'type'        => 'integer',
					'description' => 'Attachment ID to set as featured image. Use 0 to leave empty.',
				),
				'dry_run'      => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Validate the requested create without writing the post.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
				'link'    => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
				'dry_run' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => function ( $input = array() ): array {
			$input = is_array( $input ) ? $input : array();

			if ( empty( $input['title'] ) ) {
				return array( 'success' => false, 'message' => esc_html__( 'Title is required', 'mcp-expose-abilities' ) );
			}

			$post_type = ! empty( $input['post_type'] ) ? $input['post_type'] : 'post';

			// Validate post type exists
			if ( ! post_type_exists( $post_type ) ) {
				/* translators: %s: Post type slug. */
				return array( 'success' => false, 'message' => sprintf( esc_html__( 'Post type "%s" does not exist', 'mcp-expose-abilities' ), $post_type ) );
			}

			// Check capability for custom post type
			$cap = 'publish_posts';
			if ( 'post' === $post_type ) {
				$cap = 'publish_posts';
			} elseif ( 'page' === $post_type ) {
				$cap = 'publish_pages';
			} else {
				// Check if post type has custom capability type
				$pto                = get_post_type_object( $post_type );
				$cap                = ! empty( $pto->cap->publish_posts ) ? $pto->cap->publish_posts : 'publish_posts';
			}

			if ( ! current_user_can( $cap ) ) {
				return array( 'success' => false, 'message' => esc_html__( 'Permission denied to create this post type', 'mcp-expose-abilities' ) );
			}

			if ( ! empty( $input['author_id'] ) ) {
				$author_id = intval( $input['author_id'] );
				if ( $author_id !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to set a different author.', 'mcp-expose-abilities' ) );
				}
			}

				$post_data = array(
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => $input['content'] ?? '',
					'post_excerpt' => $input['excerpt'] ?? '',
					'post_status'  => $input['status'] ?? 'draft',
					'post_type'    => $post_type,
				);

			if ( ! empty( $input['slug'] ) ) {
				$post_data['post_name'] = sanitize_title( $input['slug'] );
			}
			if ( ! empty( $input['date'] ) ) {
				$post_data['post_date'] = $input['date'];
			}
				if ( ! empty( $input['author_id'] ) ) {
					$post_data['post_author'] = intval( $input['author_id'] );
				}
				$meta_input = null;
			if ( isset( $input['meta_input'] ) ) {
				if ( ! is_array( $input['meta_input'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'meta_input must be an object.', 'mcp-expose-abilities' ) );
				}
				$meta_input = $input['meta_input'];
				$meta_guard = mcp_expose_validate_post_meta_write_policy( $meta_input );
				if ( is_wp_error( $meta_guard ) ) {
					return array( 'success' => false, 'message' => esc_html( $meta_guard->get_error_message() ) );
					}
				}

				$source_design_gate = mcp_expose_validate_devenia_editorial_source_post_gate(
					null,
					$post_type,
					(string) $post_data['post_status'],
					(string) $post_data['post_content'],
					$input,
					'content/create-post'
				);
				if ( is_wp_error( $source_design_gate ) ) {
					return array( 'success' => false, 'message' => esc_html( $source_design_gate->get_error_message() ) );
				}

				if ( ! empty( $input['dry_run'] ) ) {
					return array(
						'success'       => true,
						'dry_run'       => true,
						'would_create'  => true,
						'target_status' => (string) $post_data['post_status'],
						'message'       => esc_html__( 'Dry run passed. Post was not created.', 'mcp-expose-abilities' ),
					);
				}

				$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				return array( 'success' => false, 'message' => esc_html( $post_id->get_error_message() ) );
			}

			if ( null !== $meta_input ) {
				$meta_permission = mcp_expose_validate_post_meta_write_policy( $meta_input, (int) $post_id );
				if ( is_wp_error( $meta_permission ) ) {
					wp_delete_post( (int) $post_id, true );
					return array( 'success' => false, 'message' => esc_html( $meta_permission->get_error_message() ) );
				}

				foreach ( $meta_input as $key => $value ) {
					update_post_meta( (int) $post_id, (string) $key, $value );
				}
			}

			// Set categories (for built-in category taxonomy)
			if ( ! empty( $input['category_ids'] ) ) {
				wp_set_post_categories( $post_id, array_map( 'intval', $input['category_ids'] ) );
			}
			// Set tags (for built-in post_tag taxonomy)
			if ( ! empty( $input['tag_ids'] ) ) {
				wp_set_post_tags( $post_id, array_map( 'intval', $input['tag_ids'] ) );
			}

			// Set custom taxonomy terms (for Toolset taxonomies)
			if ( ! empty( $input['tax_input'] ) && is_array( $input['tax_input'] ) ) {
				foreach ( $input['tax_input'] as $taxonomy => $terms ) {
					if ( ! empty( $terms ) && is_array( $terms ) ) {
						$taxonomy = sanitize_key( $taxonomy );
						if ( taxonomy_exists( $taxonomy ) ) {
							$term_ids = array();
							foreach ( $terms as $term ) {
								if ( is_numeric( $term ) ) {
									$term_ids[] = intval( $term );
								} else {
									$t = get_term_by( 'slug', $term, $taxonomy );
									if ( $t ) {
										$term_ids[] = $t->term_id;
									}
								}
							}
							if ( ! empty( $term_ids ) ) {
								wp_set_post_terms( $post_id, $term_ids, $taxonomy, false );
							}
						}
					}
				}
			}

			if ( array_key_exists( 'featured_image_id', $input ) ) {
				$featured_image_id = (int) $input['featured_image_id'];

				if ( $featured_image_id > 0 ) {
					$thumbnail_result = mcp_expose_set_featured_image( $post_id, $featured_image_id );
					if ( is_wp_error( $thumbnail_result ) ) {
						wp_delete_post( $post_id, true );
						return array( 'success' => false, 'message' => esc_html( $thumbnail_result->get_error_message() ) );
					}
				}
			}

			return array(
				'success' => true,
				'id'      => $post_id,
				'link'    => get_permalink( $post_id ),
				'message' => esc_html__( 'Post created successfully', 'mcp-expose-abilities' ),
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'publish_posts' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
		),
	);

	// Fix tax_input schema properties dynamically
	if ( ! empty( $taxonomy_choices ) ) {
		$tax_input_props = array();
		foreach ( array_slice( $taxonomy_choices, 0, 20 ) as $tax_name ) {
			$tax_input_props[ $tax_name ] = array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			);
		}
		$ability['input_schema']['properties']['tax_input']['properties'] = $tax_input_props;
	}

	wp_register_ability( 'content/create-post', $ability );

	// =========================================================================
	// POSTS - Update
	// =========================================================================
	wp_register_ability(
		'content/update-post',
		array(
			'label'               => 'Update Post',
			'description'         => 'Update post. Params: id (required), title, content, excerpt, status, slug, date, category_ids, tag_ids, author_id, meta_input, featured_image_id.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'Post ID to update.',
					),
					'title'        => array(
						'type'        => 'string',
						'description' => 'New post title.',
					),
					'content'      => array(
						'type'        => 'string',
						'description' => 'New post content.',
					),
					'allow_design_markup_loss' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Allow replacing content even when existing GenerateBlocks/design markup would be removed. Defaults to false.',
					),
					'excerpt'      => array(
						'type'        => 'string',
						'description' => 'New post excerpt.',
					),
					'status'       => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'description' => 'New post status.',
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => 'New post slug.',
					),
					'date'         => array(
						'type'        => 'string',
						'description' => 'New local post date in Y-m-d H:i:s format.',
					),
					'category_ids' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'New category IDs (replaces existing).',
					),
					'tag_ids'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'New tag IDs (replaces existing).',
					),
					'author_id'    => array(
						'type'        => 'integer',
						'description' => 'New author user ID.',
					),
					'meta_input'   => array(
						'type'        => 'object',
						'description' => 'Post meta fields to update. Format: {"meta_key": "value"}.',
					),
					'featured_image_id' => array(
						'type'        => 'integer',
						'description' => 'Attachment ID to set as featured image. Use 0 to remove the current featured image.',
					),
					'dry_run'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Validate the requested update without writing changes.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'           => array( 'type' => 'boolean' ),
					'id'                => array( 'type' => 'integer' ),
					'link'              => array( 'type' => 'string' ),
					'message'           => array( 'type' => 'string' ),
					'translation_guard' => array( 'type' => 'object' ),
					'dry_run'           => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post ID is required', 'mcp-expose-abilities' ) );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to edit this post.', 'mcp-expose-abilities' ) );
				}

				mcp_expose_normalize_assigned_template( (int) $post->ID, $post->post_type );

				$post_data = array( 'ID' => $input['id'] );

				if ( isset( $input['title'] ) ) {
					$post_data['post_title'] = sanitize_text_field( $input['title'] );
				}
					if ( isset( $input['content'] ) ) {
						$design_guard = mcp_expose_validate_content_design_markup_preserved( (string) $post->post_content, (string) $input['content'], $input );
						if ( is_wp_error( $design_guard ) ) {
							return array( 'success' => false, 'message' => esc_html( $design_guard->get_error_message() ) );
						}
						$post_data['post_content'] = $input['content'];
					}
				if ( isset( $input['excerpt'] ) ) {
					$post_data['post_excerpt'] = $input['excerpt'];
				}
				if ( isset( $input['status'] ) ) {
					$post_data['post_status'] = $input['status'];
				}
				if ( isset( $input['slug'] ) ) {
					$post_data['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( isset( $input['date'] ) ) {
					$date = (string) $input['date'];
					$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date );
					if ( false === $datetime || $datetime->format( 'Y-m-d H:i:s' ) !== $date ) {
						return array( 'success' => false, 'message' => esc_html__( 'Invalid date. Expected Y-m-d H:i:s.', 'mcp-expose-abilities' ) );
					}

					$post_data['post_date']     = $date;
					$post_data['post_date_gmt'] = get_gmt_from_date( $date );
					$post_data['edit_date']     = true;
				}
				if ( isset( $input['author_id'] ) ) {
					$author_id = intval( $input['author_id'] );
					if ( $author_id !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'Permission denied to change the author.', 'mcp-expose-abilities' ) );
					}
					$post_data['post_author'] = $author_id;
				}
					if ( isset( $input['meta_input'] ) ) {
						if ( ! is_array( $input['meta_input'] ) ) {
							return array( 'success' => false, 'message' => esc_html__( 'meta_input must be an object.', 'mcp-expose-abilities' ) );
						}
						$meta_permission = mcp_expose_validate_post_meta_write_policy( $input['meta_input'], (int) $post->ID );
					if ( is_wp_error( $meta_permission ) ) {
						return array( 'success' => false, 'message' => esc_html( $meta_permission->get_error_message() ) );
						}
						$post_data['meta_input'] = $input['meta_input'];
					}

					$source_design_gate = mcp_expose_validate_devenia_editorial_source_post_gate(
						$post,
						(string) $post->post_type,
						(string) ( $post_data['post_status'] ?? $post->post_status ),
						(string) ( $post_data['post_content'] ?? $post->post_content ),
						$input,
						'content/update-post'
					);
					if ( is_wp_error( $source_design_gate ) ) {
						return array( 'success' => false, 'message' => esc_html( $source_design_gate->get_error_message() ) );
					}

					if ( ! empty( $input['dry_run'] ) ) {
						return array(
							'success'       => true,
							'dry_run'       => true,
							'would_update'  => true,
							'id'            => (int) $input['id'],
							'link'          => get_permalink( (int) $input['id'] ),
							'target_status' => (string) ( $post_data['post_status'] ?? $post->post_status ),
							'message'       => esc_html__( 'Dry run passed. Post was not updated.', 'mcp-expose-abilities' ),
						);
					}

					$translation_guard_snapshot = mcp_expose_capture_translation_sibling_state( (int) $input['id'] );
					$result = wp_update_post( $post_data, true );

				if ( is_wp_error( $result ) ) {
					mcp_expose_restore_translation_sibling_state( $translation_guard_snapshot );
					return array( 'success' => false, 'message' => esc_html( $result->get_error_message() ) );
				}

				if ( isset( $input['category_ids'] ) ) {
					wp_set_post_categories( $input['id'], array_map( 'intval', $input['category_ids'] ) );
				}
				if ( isset( $input['tag_ids'] ) ) {
					wp_set_post_tags( $input['id'], array_map( 'intval', $input['tag_ids'] ) );
				}
				if ( array_key_exists( 'featured_image_id', $input ) ) {
					$featured_image_id = (int) $input['featured_image_id'];
					if ( $featured_image_id > 0 ) {
						$thumbnail_result = mcp_expose_set_featured_image( (int) $input['id'], $featured_image_id );
						if ( is_wp_error( $thumbnail_result ) ) {
							mcp_expose_restore_translation_sibling_state( $translation_guard_snapshot );
							return array( 'success' => false, 'message' => esc_html( $thumbnail_result->get_error_message() ) );
						}
					} else {
						delete_post_thumbnail( $input['id'] );
					}
				}
				$translation_guard = mcp_expose_restore_translation_sibling_state( $translation_guard_snapshot );
				$translation_guard['shutdown_restore_scheduled'] = mcp_expose_schedule_translation_sibling_state_restore( $translation_guard_snapshot );

				return array(
					'success'           => true,
					'id'                => $input['id'],
					'link'              => get_permalink( $input['id'] ),
					'message'           => esc_html__( 'Post updated successfully', 'mcp-expose-abilities' ),
					'translation_guard' => $translation_guard,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POST META - Get
	// =========================================================================
	wp_register_ability(
		'meta/get-post-meta',
		array(
			'label'               => 'Get Post Meta',
			'description'         => 'Read explicit post meta fields. Params: post_id (required), keys (required array of meta keys).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'keys' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Post ID whose meta should be read.',
					),
					'keys'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'minItems'    => 1,
						'description' => 'Explicit meta keys to read.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
					'meta'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['post_id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post ID is required', 'mcp-expose-abilities' ) );
				}
				if ( empty( $input['keys'] ) || ! is_array( $input['keys'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'keys must be a non-empty array.', 'mcp-expose-abilities' ) );
				}

				$post_id = (int) $input['post_id'];
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to read this post meta.', 'mcp-expose-abilities' ) );
				}

				$meta = array();
				$keys = array_values( array_unique( array_map( 'strval', $input['keys'] ) ) );
				foreach ( $keys as $key ) {
					$key          = sanitize_key( $key );
					$meta[ $key ] = get_post_meta( $post_id, $key, false );
				}

				return array(
					'success' => true,
					'post_id' => $post_id,
					'meta'    => $meta,
					'message' => esc_html__( 'Post meta retrieved successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POST META - Update
	// =========================================================================
	wp_register_ability(
		'meta/update-post-meta',
		array(
			'label'               => 'Update Post Meta',
			'description'         => 'Update post meta fields. Params: post_id (required), meta (required object of key/value pairs).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'meta' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Post ID whose meta should be updated.',
					),
					'meta'    => array(
						'type'        => 'object',
						'description' => 'Meta fields to update. Format: {"meta_key": "value"}.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
					'updated' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'yoast_indexable_refresh_triggered' => array( 'type' => 'boolean' ),
					'message'                           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['post_id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post ID is required', 'mcp-expose-abilities' ) );
				}
				if ( ! isset( $input['meta'] ) || ! is_array( $input['meta'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'meta must be an object.', 'mcp-expose-abilities' ) );
				}

				$post_id = (int) $input['post_id'];
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to edit this post.', 'mcp-expose-abilities' ) );
				}

				$meta_permission = mcp_expose_validate_post_meta_write_policy( $input['meta'], $post_id );
				if ( is_wp_error( $meta_permission ) ) {
					return array( 'success' => false, 'message' => esc_html( $meta_permission->get_error_message() ) );
				}

				$updated = array();
				foreach ( $input['meta'] as $key => $value ) {
					$key = (string) $key;
					update_post_meta( $post_id, $key, $value );
					$updated[] = $key;
				}
				$yoast_indexable_refresh_triggered = mcp_expose_maybe_refresh_yoast_indexable_for_meta_keys( $post_id, $updated );

				return array(
					'success'                           => true,
					'post_id'                           => $post_id,
					'updated'                           => $updated,
					'yoast_indexable_refresh_triggered' => $yoast_indexable_refresh_triggered,
					'message'                           => esc_html__( 'Post meta updated successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POST META - Delete
	// =========================================================================
	wp_register_ability(
		'meta/delete-post-meta',
		array(
			'label'               => 'Delete Post Meta',
			'description'         => 'Delete post meta fields. Params: post_id (required), meta (required object). Use null as a value to delete all values for a key.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'meta' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Post ID whose meta should be deleted.',
					),
					'meta'    => array(
						'type'        => 'object',
						'description' => 'Meta fields to delete. Format: {"meta_key": "value"} deletes a specific value; {"meta_key": null} deletes all values for that key.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
					'deleted' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'yoast_indexable_refresh_triggered' => array( 'type' => 'boolean' ),
					'message'                           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['post_id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post ID is required', 'mcp-expose-abilities' ) );
				}
				if ( ! isset( $input['meta'] ) || ! is_array( $input['meta'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'meta must be an object.', 'mcp-expose-abilities' ) );
				}

				$post_id = (int) $input['post_id'];
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to edit this post.', 'mcp-expose-abilities' ) );
				}

				$meta_permission = mcp_expose_validate_post_meta_write_policy( $input['meta'], $post_id, 'delete_post_meta' );
				if ( is_wp_error( $meta_permission ) ) {
					return array( 'success' => false, 'message' => esc_html( $meta_permission->get_error_message() ) );
				}

				$deleted = array();
				foreach ( $input['meta'] as $key => $value ) {
					$key = (string) $key;
					if ( null === $value ) {
						delete_post_meta( $post_id, $key );
					} else {
						delete_post_meta( $post_id, $key, $value );
					}
					$deleted[] = $key;
				}
				$yoast_indexable_refresh_triggered = mcp_expose_maybe_refresh_yoast_indexable_for_meta_keys( $post_id, $deleted );

				return array(
					'success'                           => true,
					'post_id'                           => $post_id,
					'deleted'                           => $deleted,
					'yoast_indexable_refresh_triggered' => $yoast_indexable_refresh_triggered,
					'message'                           => esc_html__( 'Post meta deleted successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Delete
	// =========================================================================
	wp_register_ability(
		'content/delete-post',
		array(
			'label'               => 'Delete Post',
			'description'         => 'Delete post. Params: id (required), force (optional, true=permanent).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Post ID to delete.',
					),
					'force'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, permanently deletes. If false, moves to trash.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post ID is required', 'mcp-expose-abilities' ) );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'delete_post', $post->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to delete this post.', 'mcp-expose-abilities' ) );
				}

				$force  = ! empty( $input['force'] );
				$result = wp_delete_post( $input['id'], $force );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => esc_html__( 'Failed to delete post', 'mcp-expose-abilities' ) );
				}

				return array(
					'success' => true,
					'message' => $force ? esc_html__( 'Post permanently deleted', 'mcp-expose-abilities' ) : esc_html__( 'Post moved to trash', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Restore from Trash
	// =========================================================================
	wp_register_ability(
		'content/restore-post',
		array(
			'label'               => 'Restore Post',
			'description'         => 'Restore a post, page, or custom post type from trash. Params: id (required), status (optional; defaults to previous status or draft).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'Post ID to restore from trash.',
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'description' => 'Optional post status to apply after restoring. Defaults to WordPress previous status when available.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'integer' ),
					'post_type'   => array( 'type' => 'string' ),
					'post_status' => array( 'type' => 'string' ),
					'link'        => array( 'type' => 'string' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post ID is required', 'mcp-expose-abilities' ) );
				}

				$post_id = (int) $input['id'];
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
				}

				if ( 'trash' !== $post->post_status ) {
					return array(
						'success'     => true,
						'id'          => $post_id,
						'post_type'   => (string) $post->post_type,
						'post_status' => (string) $post->post_status,
						'link'        => get_permalink( $post_id ),
						'message'     => esc_html__( 'Post is not in trash', 'mcp-expose-abilities' ),
					);
				}

				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to restore this post.', 'mcp-expose-abilities' ) );
				}

				$lock_token = mcp_expose_acquire_post_write_lock( $post_id, 'content/restore-post' );
				if ( is_wp_error( $lock_token ) ) {
					return array( 'success' => false, 'message' => esc_html( $lock_token->get_error_message() ) );
				}

				try {
					mcp_expose_normalize_assigned_template( $post_id, (string) $post->post_type );

					$restored = wp_untrash_post( $post_id );
					if ( ! $restored ) {
						return array( 'success' => false, 'message' => esc_html__( 'Post restore failed', 'mcp-expose-abilities' ) );
					}

					if ( isset( $input['status'] ) ) {
						$status_result = wp_update_post(
							array(
								'ID'          => $post_id,
								'post_status' => (string) $input['status'],
							),
							true
						);

						if ( is_wp_error( $status_result ) ) {
							return array( 'success' => false, 'message' => esc_html( $status_result->get_error_message() ) );
						}
					}
				} finally {
					mcp_expose_release_post_write_lock( $post_id, $lock_token );
				}

				$restored_post = get_post( $post_id );
				if ( ! $restored_post ) {
					return array( 'success' => false, 'message' => esc_html__( 'Restored post could not be read', 'mcp-expose-abilities' ) );
				}

				return array(
					'success'     => true,
					'id'          => $post_id,
					'post_type'   => (string) $restored_post->post_type,
					'post_status' => (string) $restored_post->post_status,
					'link'        => get_permalink( $post_id ),
					'message'     => esc_html__( 'Post restored successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function ( $input = array() ): bool {
				$input = is_array( $input ) ? $input : array();
				if ( empty( $input['id'] ) ) {
					return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
				}

				return current_user_can( 'edit_post', (int) $input['id'] );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - List
	// =========================================================================
	wp_register_ability(
		'content/list-pages',
		array(
			'label'               => 'List Pages',
			'description'         => 'List pages. Params: status, per_page, page, orderby, order, search, parent_id (all optional).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'   => array(
						'type'    => 'string',
						'enum'    => array( 'publish', 'draft', 'pending', 'private', 'any' ),
						'default' => 'publish',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'include_totals' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Include total counts (disables no_found_rows optimization).',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'parent'   => array(
						'type'        => 'integer',
						'description' => 'Filter by parent page ID. Use 0 for top-level pages.',
					),
					'search'   => array(
						'type'        => 'string',
						'description' => 'Optional keyword search across page title and content.',
					),
					'orderby'  => array(
						'type'    => 'string',
						'enum'    => array( 'title', 'date', 'modified', 'menu_order', 'ID' ),
						'default' => 'menu_order',
					),
					'order'    => array(
						'type'    => 'string',
						'enum'    => array( 'ASC', 'DESC' ),
						'default' => 'ASC',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'pages'       => array( 'type' => 'array' ),
					'returned'    => array( 'type' => 'integer' ),
					'has_more'    => array( 'type' => 'boolean' ),
					'total'       => array( 'type' => array( 'integer', 'null' ) ),
					'total_pages' => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$pagination = mcp_expose_parse_pagination( $input, 20, 100 );
				$include_totals = ! empty( $input['include_totals'] );
				$args = array(
					'post_type'              => 'page',
					'post_status'            => $input['status'] ?? 'publish',
					'posts_per_page'         => $pagination['per_page'],
					'paged'                  => $pagination['page'],
					'orderby'                => $input['orderby'] ?? 'menu_order',
					'order'                  => $input['order'] ?? 'ASC',
					// Performance optimizations.
					'no_found_rows'          => ! $include_totals,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				);

				if ( 'any' === $args['post_status'] ) {
					$args['post_status'] = array( 'publish', 'draft', 'pending', 'private' );
				}

				if ( isset( $input['parent'] ) ) {
					$args['post_parent'] = $input['parent'];
				}

				if ( ! empty( $input['search'] ) ) {
					$args['s'] = sanitize_text_field( (string) $input['search'] );
				}

				$query = new WP_Query( $args );
				$pages = array();

				foreach ( $query->posts as $page ) {
					$pages[] = array(
						'id'         => $page->ID,
						'title'      => $page->post_title,
						'slug'       => $page->post_name,
						'status'     => $page->post_status,
						'parent_id'  => $page->post_parent,
						'menu_order' => $page->menu_order,
						'date'       => $page->post_date,
						'modified'   => $page->post_modified,
						'link'       => get_permalink( $page->ID ),
					);
				}

				$returned = count( $pages );
				$total = $include_totals ? (int) $query->found_posts : null;
				$total_pages = $include_totals ? (int) $query->max_num_pages : null;
				$has_more = $include_totals
					? $pagination['page'] < (int) $query->max_num_pages
					: $returned === $pagination['per_page'];

				return array(
					'pages'       => $pages,
					'returned'    => $returned,
					'has_more'    => $has_more,
					'total'       => $total,
					'total_pages' => $total_pages,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Get
	// =========================================================================
	wp_register_ability(
		'content/get-page',
		array(
			'label'               => 'Get Page',
			'description'         => 'Get single page. Params: id or slug (one required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Page ID to retrieve.',
					),
					'slug' => array(
						'type'        => 'string',
						'description' => 'Page slug to retrieve (used if ID not provided).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'id'             => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'slug'           => array( 'type' => 'string' ),
					'status'         => array( 'type' => 'string' ),
					'content'        => array( 'type' => 'string' ),
					'excerpt'        => array( 'type' => 'string' ),
					'parent_id'      => array( 'type' => 'integer' ),
					'menu_order'     => array( 'type' => 'integer' ),
					'template'       => array( 'type' => 'string' ),
					'date'           => array( 'type' => 'string' ),
					'modified'       => array( 'type' => 'string' ),
					'author_id'      => array( 'type' => 'integer' ),
					'author_name'    => array( 'type' => 'string' ),
					'featured_image' => array( 'type' => 'string' ),
					'featured_image_id' => array( 'type' => array( 'integer', 'null' ) ),
					'link'           => array( 'type' => 'string' ),
					'message'        => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$page  = null;

				if ( ! empty( $input['id'] ) ) {
					$page = get_post( $input['id'] );
					if ( $page && 'page' !== $page->post_type ) {
						$page = null;
					}
				} elseif ( ! empty( $input['slug'] ) ) {
					$page = get_page_by_path( $input['slug'] );
				}

				if ( ! $page ) {
					return array( 'success' => false, 'message' => esc_html__( 'Page not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'read_post', $page->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied', 'mcp-expose-abilities' ) );
				}

				$author       = get_user_by( 'id', $page->post_author );
				$thumbnail    = get_the_post_thumbnail_url( $page->ID, 'full' );
				$thumbnail_id = get_post_thumbnail_id( $page->ID );
				$template     = get_page_template_slug( $page->ID );

				return array(
					'success'        => true,
					'id'             => $page->ID,
					'title'          => $page->post_title,
					'slug'           => $page->post_name,
					'status'         => $page->post_status,
					'content'        => $page->post_content,
					'excerpt'        => $page->post_excerpt,
					'parent_id'      => (int) $page->post_parent,
					'menu_order'     => (int) $page->menu_order,
					'template'       => $template ?: 'default',
					'date'           => $page->post_date,
					'modified'       => $page->post_modified,
					'author_id'      => (int) $page->post_author,
					'author_name'    => $author ? $author->display_name : '',
					'featured_image' => $thumbnail ?: '',
					'featured_image_id' => $thumbnail_id ? (int) $thumbnail_id : null,
					'link'           => get_permalink( $page->ID ),
					'message'        => 'Page retrieved successfully',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Create
	// =========================================================================
	wp_register_ability(
		'content/create-page',
		array(
			'label'               => 'Create Page',
			'description'         => 'Create page. Params: title (required), content, excerpt, status, slug, parent_id, menu_order, template, featured_image_id.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'title' ),
				'properties'           => array(
					'title'      => array(
						'type'        => 'string',
						'description' => 'Page title.',
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'Page content (supports Gutenberg blocks).',
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => 'Page excerpt.',
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
						'default'     => 'draft',
						'description' => 'Page status.',
					),
					'slug'       => array(
						'type'        => 'string',
						'description' => 'Page slug (auto-generated from title if not provided).',
					),
					'parent'     => array(
						'type'        => 'integer',
						'description' => 'Parent page ID. Use 0 for top-level page.',
					),
					'menu_order' => array(
						'type'        => 'integer',
						'description' => 'Menu order for page sorting.',
					),
					'template'   => array(
						'type'        => 'string',
						'description' => 'Page template slug.',
					),
					'featured_image_id' => array(
						'type'        => 'integer',
						'description' => 'Attachment ID to set as featured image. Use 0 to leave empty.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'link'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
					'translation_guard' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['title'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Title is required', 'mcp-expose-abilities' ) );
				}

				$page_data = array(
					'post_type'    => 'page',
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => $input['content'] ?? '',
					'post_excerpt' => $input['excerpt'] ?? '',
					'post_status'  => $input['status'] ?? 'draft',
				);

				if ( ! empty( $input['slug'] ) ) {
					$page_data['post_name'] = sanitize_title( $input['slug'] );
				}

				if ( isset( $input['parent'] ) ) {
					$page_data['post_parent'] = (int) $input['parent'];
				}

				if ( isset( $input['menu_order'] ) ) {
					$page_data['menu_order'] = (int) $input['menu_order'];
				}

				$page_id = wp_insert_post( $page_data, true );

				if ( is_wp_error( $page_id ) ) {
					return array( 'success' => false, 'message' => esc_html( $page_id->get_error_message() ) );
				}

				if ( isset( $input['template'] ) ) {
					$template_slug = (string) $input['template'];

					if ( '' !== $template_slug && 'default' !== $template_slug ) {
						if ( ! mcp_expose_is_valid_template_slug( $template_slug, 'page' ) ) {
							wp_delete_post( $page_id, true );
							return array( 'success' => false, 'message' => esc_html__( 'Invalid page template.', 'mcp-expose-abilities' ) );
						}

						update_post_meta( $page_id, '_wp_page_template', $template_slug );
					}
				}
				if ( array_key_exists( 'featured_image_id', $input ) ) {
					$featured_image_id = (int) $input['featured_image_id'];

					if ( $featured_image_id > 0 ) {
						$thumbnail_result = mcp_expose_set_featured_image( $page_id, $featured_image_id );
						if ( is_wp_error( $thumbnail_result ) ) {
							wp_delete_post( $page_id, true );
							return array( 'success' => false, 'message' => esc_html( $thumbnail_result->get_error_message() ) );
						}
					}
				}

				return array(
					'success' => true,
					'id'      => $page_id,
					'link'    => get_permalink( $page_id ),
					'message' => esc_html__( 'Page created successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'publish_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// CONTENT - Update Discussion Status
	// =========================================================================
	wp_register_ability(
		'content/update-discussion-status',
		array(
			'label'               => 'Update Discussion Status',
			'description'         => 'Open or close comments and pings for one or more posts/pages. Params: ids (required), comment_status, ping_status.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'ids' ),
				'properties'           => array(
					'ids'            => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'minItems'    => 1,
						'description' => 'Post/page IDs to update.',
					),
					'comment_status' => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'Comment status to apply. Omit to leave unchanged.',
					),
					'ping_status'    => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'Ping/trackback status to apply. Omit to leave unchanged.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'updated' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'skipped' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$ids   = isset( $input['ids'] ) && is_array( $input['ids'] ) ? array_values( array_unique( array_map( 'absint', $input['ids'] ) ) ) : array();
				$ids   = array_values( array_filter( $ids ) );

				if ( empty( $ids ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'At least one post ID is required.', 'mcp-expose-abilities' ) );
				}
				if ( ! array_key_exists( 'comment_status', $input ) && ! array_key_exists( 'ping_status', $input ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'comment_status or ping_status is required.', 'mcp-expose-abilities' ) );
				}

				$allowed_statuses = array( 'open', 'closed' );
				$post_data_base   = array();

				if ( array_key_exists( 'comment_status', $input ) ) {
					$comment_status = (string) $input['comment_status'];
					if ( ! in_array( $comment_status, $allowed_statuses, true ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'Invalid comment_status.', 'mcp-expose-abilities' ) );
					}
					$post_data_base['comment_status'] = $comment_status;
				}
				if ( array_key_exists( 'ping_status', $input ) ) {
					$ping_status = (string) $input['ping_status'];
					if ( ! in_array( $ping_status, $allowed_statuses, true ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'Invalid ping_status.', 'mcp-expose-abilities' ) );
					}
					$post_data_base['ping_status'] = $ping_status;
				}

				$updated = array();
				$skipped = array();

				foreach ( $ids as $post_id ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						$skipped[] = array(
							'id'      => $post_id,
							'message' => esc_html__( 'Post not found.', 'mcp-expose-abilities' ),
						);
						continue;
					}
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						$skipped[] = array(
							'id'      => $post_id,
							'message' => esc_html__( 'Permission denied to edit this post.', 'mcp-expose-abilities' ),
						);
						continue;
					}

					$result = wp_update_post( array_merge( array( 'ID' => $post_id ), $post_data_base ), true );
					if ( is_wp_error( $result ) ) {
						$skipped[] = array(
							'id'      => $post_id,
							'message' => esc_html( $result->get_error_message() ),
						);
						continue;
					}

					$updated[] = $post_id;
				}

				return array(
					'success' => true,
					'updated' => $updated,
					'skipped' => $skipped,
					'message' => sprintf(
						/* translators: %d: number of posts updated */
						esc_html__( 'Updated discussion status for %d post(s).', 'mcp-expose-abilities' ),
						count( $updated )
					),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Update
	// =========================================================================
	wp_register_ability(
		'content/update-page',
		array(
			'label'               => 'Update Page',
			'description'         => 'Update page. Params: id (required), title, content, excerpt, status, slug, parent_id, menu_order, template, featured_image_id.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => 'Page ID to update.',
					),
					'title'      => array(
						'type'        => 'string',
						'description' => 'New page title.',
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'New page content.',
					),
					'allow_design_markup_loss' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Allow replacing content even when existing GenerateBlocks/design markup would be removed. Defaults to false.',
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => 'New page excerpt.',
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
						'description' => 'New page status.',
					),
					'slug'       => array(
						'type'        => 'string',
						'description' => 'New page slug.',
					),
					'parent'     => array(
						'type'        => 'integer',
						'description' => 'New parent page ID.',
					),
					'menu_order' => array(
						'type'        => 'integer',
						'description' => 'New menu order.',
					),
					'template'   => array(
						'type'        => 'string',
						'description' => 'New page template slug.',
					),
					'featured_image_id' => array(
						'type'        => 'integer',
						'description' => 'Attachment ID to set as featured image. Use 0 to remove the current featured image.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'link'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Page ID is required', 'mcp-expose-abilities' ) );
				}

				$page = get_post( $input['id'] );
				if ( ! $page || 'page' !== $page->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Page not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'delete_post', $page->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to delete this page.', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'edit_post', $page->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to edit this page.', 'mcp-expose-abilities' ) );
				}

				mcp_expose_normalize_assigned_template( (int) $page->ID, $page->post_type );

				$page_data = array( 'ID' => $input['id'] );

				if ( isset( $input['title'] ) ) {
					$page_data['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['content'] ) ) {
					$design_guard = mcp_expose_validate_content_design_markup_preserved( (string) $page->post_content, (string) $input['content'], $input );
					if ( is_wp_error( $design_guard ) ) {
						return array( 'success' => false, 'message' => esc_html( $design_guard->get_error_message() ) );
					}
					$page_data['post_content'] = $input['content'];
				}
				if ( isset( $input['excerpt'] ) ) {
					$page_data['post_excerpt'] = $input['excerpt'];
				}
				if ( isset( $input['status'] ) ) {
					$page_data['post_status'] = $input['status'];
				}
				if ( isset( $input['slug'] ) ) {
					$page_data['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( isset( $input['parent'] ) ) {
					$page_data['post_parent'] = (int) $input['parent'];
				}
				if ( isset( $input['menu_order'] ) ) {
					$page_data['menu_order'] = (int) $input['menu_order'];
				}

				$translation_guard_snapshot = mcp_expose_capture_translation_sibling_state( (int) $input['id'] );
				$result = wp_update_post( $page_data, true );

				if ( is_wp_error( $result ) ) {
					mcp_expose_restore_translation_sibling_state( $translation_guard_snapshot );
					return array( 'success' => false, 'message' => esc_html( $result->get_error_message() ) );
				}

				if ( isset( $input['template'] ) ) {
					$template_slug = (string) $input['template'];

					if ( '' === $template_slug || 'default' === $template_slug ) {
						delete_post_meta( $input['id'], '_wp_page_template' );
					} elseif ( ! mcp_expose_is_valid_template_slug( $template_slug, $page->post_type ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'Invalid page template.', 'mcp-expose-abilities' ) );
					} else {
						update_post_meta( $input['id'], '_wp_page_template', $template_slug );
					}
				}
				if ( array_key_exists( 'featured_image_id', $input ) ) {
					$featured_image_id = (int) $input['featured_image_id'];
					if ( $featured_image_id > 0 ) {
						$thumbnail_result = mcp_expose_set_featured_image( (int) $input['id'], $featured_image_id );
						if ( is_wp_error( $thumbnail_result ) ) {
							mcp_expose_restore_translation_sibling_state( $translation_guard_snapshot );
							return array( 'success' => false, 'message' => esc_html( $thumbnail_result->get_error_message() ) );
						}
					} else {
						delete_post_thumbnail( $input['id'] );
					}
				}
				$translation_guard = mcp_expose_restore_translation_sibling_state( $translation_guard_snapshot );
				$translation_guard['shutdown_restore_scheduled'] = mcp_expose_schedule_translation_sibling_state_restore( $translation_guard_snapshot );

				return array(
					'success'           => true,
					'id'                => $input['id'],
					'link'              => get_permalink( $input['id'] ),
					'message'           => esc_html__( 'Page updated successfully', 'mcp-expose-abilities' ),
					'translation_guard' => $translation_guard,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Delete
	// =========================================================================
	wp_register_ability(
		'content/delete-page',
		array(
			'label'               => 'Delete Page',
			'description'         => 'Delete page. Params: id (required), force (optional, true=permanent).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'Page ID to delete.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, permanently deletes. If false, moves to trash.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Page ID is required', 'mcp-expose-abilities' ) );
				}

				$page = get_post( $input['id'] );
				if ( ! $page || 'page' !== $page->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Page not found', 'mcp-expose-abilities' ) );
				}

				$force  = ! empty( $input['force'] );
				$result = wp_delete_post( $input['id'], $force );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => esc_html__( 'Failed to delete page', 'mcp-expose-abilities' ) );
				}

				$message = $force ? esc_html__( 'Page permanently deleted', 'mcp-expose-abilities' ) : esc_html__( 'Page moved to trash', 'mcp-expose-abilities' );
				return array( 'success' => true, 'message' => $message );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// REVISIONS - List
	// =========================================================================
	wp_register_ability(
		'content/list-revisions',
		array(
			'label'               => 'List Revisions',
			'description'         => 'List revisions. Params: id (required), per_page.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => 'Post/Page ID to get revisions for.',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'revisions' => array( 'type' => 'array' ),
					'total'     => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post/Page ID is required', 'mcp-expose-abilities' ) );
				}

				$post = get_post( $input['id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
				}

				$per_page  = $input['per_page'] ?? 10;
				$revisions = wp_get_post_revisions( $input['id'], array( 'posts_per_page' => $per_page ) );

				$result = array();
				foreach ( $revisions as $revision ) {
					$author = get_user_by( 'id', $revision->post_author );
					$result[] = array(
						'id'       => $revision->ID,
						'date'     => $revision->post_date,
						'modified' => $revision->post_modified,
						'author'   => $author ? $author->display_name : 'Unknown',
						'title'    => $revision->post_title,
					);
				}

				return array(
					'success'   => true,
					'revisions' => $result,
					'total'     => count( $result ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// REVISIONS - Get
	// =========================================================================
	wp_register_ability(
		'content/get-revision',
		array(
			'label'               => 'Get Revision',
			'description'         => 'Get revision. Params: id (required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Revision ID to retrieve.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'id'        => array( 'type' => 'integer' ),
					'parent_id' => array( 'type' => 'integer' ),
					'date'      => array( 'type' => 'string' ),
					'author'    => array( 'type' => 'string' ),
					'title'     => array( 'type' => 'string' ),
					'content'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Revision ID is required', 'mcp-expose-abilities' ) );
				}

				$revision = get_post( $input['id'] );
				if ( ! $revision || 'revision' !== $revision->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Revision not found', 'mcp-expose-abilities' ) );
				}

				$author = get_user_by( 'id', $revision->post_author );

				return array(
					'success'   => true,
					'id'        => $revision->ID,
					'parent_id' => $revision->post_parent,
					'date'      => $revision->post_date,
					'modified'  => $revision->post_modified,
					'author'    => $author ? $author->display_name : 'Unknown',
					'title'     => $revision->post_title,
					'content'   => $revision->post_content,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// REVISIONS - Restore
	// =========================================================================
	wp_register_ability(
		'content/restore-revision',
		array(
			'label'               => 'Restore Revision',
			'description'         => 'Restore a post, page, or custom post type from an existing revision. Params: id (required revision ID).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Revision ID to restore.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'integer' ),
					'parent_id'   => array( 'type' => 'integer' ),
					'post_type'   => array( 'type' => 'string' ),
					'post_status' => array( 'type' => 'string' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Revision ID is required', 'mcp-expose-abilities' ) );
				}

				$revision = get_post( (int) $input['id'] );
				if ( ! $revision || 'revision' !== $revision->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Revision not found', 'mcp-expose-abilities' ) );
				}

				$parent = get_post( (int) $revision->post_parent );
				if ( ! $parent ) {
					return array( 'success' => false, 'message' => esc_html__( 'Revision parent not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'edit_post', (int) $parent->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Current user cannot edit the revision parent', 'mcp-expose-abilities' ) );
				}

				$lock_token = mcp_expose_acquire_post_write_lock( (int) $parent->ID, 'content/restore-revision' );
				if ( is_wp_error( $lock_token ) ) {
					return array( 'success' => false, 'message' => esc_html( $lock_token->get_error_message() ) );
				}

				try {
					$restored = wp_restore_post_revision( (int) $revision->ID );
				} finally {
					mcp_expose_release_post_write_lock( (int) $parent->ID, $lock_token );
				}

				if ( ! $restored ) {
					return array( 'success' => false, 'message' => esc_html__( 'Revision restore failed', 'mcp-expose-abilities' ) );
				}

				$restored_post = get_post( (int) $parent->ID );

				return array(
					'success'     => true,
					'id'          => (int) $revision->ID,
					'parent_id'   => (int) $parent->ID,
					'post_type'   => $restored_post ? (string) $restored_post->post_type : (string) $parent->post_type,
					'post_status' => $restored_post ? (string) $restored_post->post_status : (string) $parent->post_status,
					'message'     => esc_html__( 'Revision restored successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function ( $input = array() ): bool {
				$input = is_array( $input ) ? $input : array();
				if ( empty( $input['id'] ) ) {
					return current_user_can( 'edit_posts' );
				}

				$revision = get_post( (int) $input['id'] );
				if ( ! $revision || 'revision' !== $revision->post_type ) {
					return current_user_can( 'edit_posts' );
				}

				return current_user_can( 'edit_post', (int) $revision->post_parent );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PAGES - Patch
	// =========================================================================
	wp_register_ability(
		'content/patch-page',
		array(
			'label'               => 'Patch Page Content',
			'description'         => 'Patch page content. Params: id (required), find (required), replace (required), regex (optional).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'find', 'replace' ),
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Page ID to patch.',
					),
					'find'    => array(
						'type'        => 'string',
						'description' => 'String or regex pattern to find.',
					),
					'replace' => array(
						'type'        => 'string',
						'description' => 'Replacement string. Supports backreferences ($1, $2, etc.) when using regex.',
					),
					'regex'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, treat "find" as a regex pattern.',
					),
					'limit'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Maximum replacements (-1 for all). Only applies to non-regex mode.',
					),
					'allow_design_markup_loss' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Allow patching content even when existing GenerateBlocks/design markup would be removed. Defaults to false.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
					'message'      => array( 'type' => 'string' ),
					'link'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Page ID is required', 'mcp-expose-abilities' ) );
				}
				if ( ! isset( $input['find'] ) || '' === $input['find'] ) {
					return array( 'success' => false, 'message' => esc_html__( 'Find string is required', 'mcp-expose-abilities' ) );
				}
				if ( ! isset( $input['replace'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Replace string is required', 'mcp-expose-abilities' ) );
				}

				$post_id    = (int) $input['id'];
				$lock_token = mcp_expose_acquire_post_write_lock( $post_id, 'content/patch-page' );
				if ( is_wp_error( $lock_token ) ) {
					return array( 'success' => false, 'message' => esc_html( $lock_token->get_error_message() ) );
				}

				try {
					$page = get_post( $post_id );
					if ( ! $page || 'page' !== $page->post_type ) {
						return array( 'success' => false, 'message' => esc_html__( 'Page not found', 'mcp-expose-abilities' ) );
					}

					$content   = $page->post_content;
					$find      = $input['find'];
					$replace   = $input['replace'];
					$use_regex = ! empty( $input['regex'] );
					$limit     = isset( $input['limit'] ) ? (int) $input['limit'] : -1;
					$count     = 0;

					if ( $use_regex ) {
						$new_content = preg_replace( $find, $replace, $content, -1, $count );
						if ( null === $new_content ) {
							return array( 'success' => false, 'message' => esc_html__( 'Invalid regex pattern', 'mcp-expose-abilities' ) );
						}
					} else {
						if ( -1 === $limit ) {
							$new_content = str_replace( $find, $replace, $content, $count );
						} else {
							$new_content = preg_replace( '/' . preg_quote( $find, '/' ) . '/', $replace, $content, $limit, $count );
						}
					}

					if ( 0 === $count ) {
						return array(
							'success'      => true,
							'id'           => $post_id,
							'replacements' => 0,
							'message'      => 'No matches found - content unchanged',
							'link'         => get_permalink( $post_id ),
						);
					}

					$design_guard = mcp_expose_validate_content_design_markup_preserved( (string) $content, (string) $new_content, $input );
					if ( is_wp_error( $design_guard ) ) {
						return array( 'success' => false, 'message' => esc_html( $design_guard->get_error_message() ) );
					}

					$result = wp_update_post( array(
						'ID'           => $post_id,
						'post_content' => $new_content,
					), true );

					if ( is_wp_error( $result ) ) {
						return array( 'success' => false, 'message' => esc_html( $result->get_error_message() ) );
					}

					return array(
						'success'      => true,
						'id'           => $post_id,
						'replacements' => $count,
						'message'      => "Successfully replaced {$count} occurrence(s)",
						'link'         => get_permalink( $post_id ),
					);
				} finally {
					mcp_expose_release_post_write_lock( $post_id, $lock_token );
				}
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CATEGORIES - List
	// =========================================================================
	wp_register_ability(
		'content/list-categories',
		array(
			'label'               => 'List Categories',
			'description'         => 'List categories. Params: hide_empty, parent (all optional).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'hide_empty' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Hide categories with no posts.',
					),
					'parent'     => array(
						'type'        => 'integer',
						'description' => 'Filter by parent category ID. Use 0 for top-level.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'categories' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'hide_empty' => $input['hide_empty'] ?? false,
				);

				if ( isset( $input['parent'] ) ) {
					$args['parent'] = $input['parent'];
				}

				$categories = get_categories( $args );

				return array(
					'categories' => array_map( function ( $cat ) {
						return array(
							'id'          => $cat->term_id,
							'name'        => $cat->name,
							'slug'        => $cat->slug,
							'description' => $cat->description,
							'parent_id'   => $cat->parent,
							'count'       => $cat->count,
						);
					}, $categories ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CATEGORIES - Create
	// =========================================================================
	wp_register_ability(
		'content/create-category',
		array(
			'label'               => 'Create Category',
			'description'         => 'Create category. Params: name (required), slug, description, parent.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => 'The category name.',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'The category slug (optional, auto-generated from name if not provided).',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'The category description (optional).',
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => 'Parent category ID (optional). Use 0 for top-level.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'name'    => array( 'type' => 'string' ),
					'slug'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input ): array {
				$args = array();

				if ( ! empty( $input['slug'] ) ) {
					$args['slug'] = $input['slug'];
				}

				if ( ! empty( $input['description'] ) ) {
					$args['description'] = $input['description'];
				}

				if ( isset( $input['parent'] ) ) {
					$args['parent'] = (int) $input['parent'];
				}

				$result = wp_insert_term( $input['name'], 'category', $args );

				if ( is_wp_error( $result ) ) {
					if ( $result->get_error_code() === 'term_exists' ) {
						$existing_term = get_term( $result->get_error_data(), 'category' );
						return array(
							'success' => true,
							'id'      => $existing_term->term_id,
							'name'    => $existing_term->name,
							'slug'    => $existing_term->slug,
							'message' => esc_html__( 'Category already exists.', 'mcp-expose-abilities' ),
						);
					}
					return array(
						'success' => false,
						'message' => esc_html( $result->get_error_message() ),
					);
				}

				$term = get_term( $result['term_id'], 'category' );

				return array(
					'success' => true,
					'id'      => $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'message' => esc_html__( 'Category created successfully.', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_categories' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// CATEGORIES - Update
	// =========================================================================
	wp_register_ability(
		'content/update-category',
		array(
			'label'               => 'Update Category',
			'description'         => 'Update category. Params: id (required), name, slug, description, parent.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => 'Category ID to update.',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'New category name.',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'New category slug.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'New category description. Pass empty string to clear.',
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => 'New parent category ID. Use 0 for top-level.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'parent_id'   => array( 'type' => 'integer' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$term_id = (int) ( $input['id'] ?? 0 );

				if ( $term_id <= 0 ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Valid category ID is required.', 'mcp-expose-abilities' ),
					);
				}

				$term = get_term( $term_id, 'category' );
				if ( ! $term || is_wp_error( $term ) ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Category not found.', 'mcp-expose-abilities' ),
					);
				}

				$update_args = array();

				if ( array_key_exists( 'name', $input ) ) {
					$name = trim( (string) $input['name'] );
					if ( '' === $name ) {
						return array(
							'success' => false,
							'message' => esc_html__( 'Category name cannot be empty.', 'mcp-expose-abilities' ),
						);
					}
					$update_args['name'] = sanitize_text_field( $name );
				}

				if ( array_key_exists( 'slug', $input ) ) {
					$update_args['slug'] = sanitize_title( (string) $input['slug'] );
				}

				if ( array_key_exists( 'description', $input ) ) {
					$update_args['description'] = sanitize_textarea_field( (string) $input['description'] );
				}

				if ( array_key_exists( 'parent', $input ) ) {
					$update_args['parent'] = (int) $input['parent'];
				}

				if ( empty( $update_args ) ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'No fields provided for update.', 'mcp-expose-abilities' ),
					);
				}

				$result = wp_update_term( $term_id, 'category', $update_args );
				if ( is_wp_error( $result ) ) {
					return array(
						'success' => false,
						'message' => esc_html( $result->get_error_message() ),
					);
				}

				$updated = get_term( $term_id, 'category' );
				if ( ! $updated || is_wp_error( $updated ) ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Category update failed.', 'mcp-expose-abilities' ),
					);
				}

				return array(
					'success'     => true,
					'id'          => $updated->term_id,
					'name'        => $updated->name,
					'slug'        => $updated->slug,
					'description' => $updated->description,
					'parent_id'   => (int) $updated->parent,
					'message'     => esc_html__( 'Category updated successfully.', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_categories' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// TAGS - List
	// =========================================================================
	wp_register_ability(
		'content/list-tags',
		array(
			'label'               => 'List Tags',
			'description'         => 'List tags. Params: hide_empty, search (all optional).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'hide_empty' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'search'     => array(
						'type'        => 'string',
						'description' => 'Search tags by name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'tags' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$args = array(
					'hide_empty' => $input['hide_empty'] ?? false,
				);

				if ( ! empty( $input['search'] ) ) {
					$args['search'] = $input['search'];
				}

				$tags = get_tags( $args );

				return array(
					'tags' => array_map( function ( $tag ) {
						return array(
							'id'    => $tag->term_id,
							'name'  => $tag->name,
							'slug'  => $tag->slug,
							'count' => $tag->count,
						);
					}, $tags ?: array() ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// TAGS - Create
	// =========================================================================
	wp_register_ability(
		'content/create-tag',
		array(
			'label'               => 'Create Tag',
			'description'         => 'Create tag. Params: name (required), slug, description.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => 'The tag name.',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'The tag slug (optional, auto-generated from name if not provided).',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'The tag description (optional).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'name'    => array( 'type' => 'string' ),
					'slug'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input ): array {
				$args = array();

				if ( ! empty( $input['slug'] ) ) {
					$args['slug'] = $input['slug'];
				}

				if ( ! empty( $input['description'] ) ) {
					$args['description'] = $input['description'];
				}

				$result = wp_insert_term( $input['name'], 'post_tag', $args );

				if ( is_wp_error( $result ) ) {
					// Check if tag already exists
					if ( $result->get_error_code() === 'term_exists' ) {
						$existing_term = get_term( $result->get_error_data(), 'post_tag' );
						return array(
							'success' => true,
							'id'      => $existing_term->term_id,
							'name'    => $existing_term->name,
							'slug'    => $existing_term->slug,
							'message' => esc_html__( 'Tag already exists.', 'mcp-expose-abilities' ),
						);
					}
					return array(
						'success' => false,
						'message' => esc_html( $result->get_error_message() ),
					);
				}

				$term = get_term( $result['term_id'], 'post_tag' );

				return array(
					'success' => true,
					'id'      => $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'message' => esc_html__( 'Tag created successfully.', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_categories' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// TAGS - Update
	// =========================================================================
	wp_register_ability(
		'content/update-tag',
		array(
			'label'               => 'Update Tag',
			'description'         => 'Update tag. Params: id (required), name, slug, description.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => 'Tag ID to update.',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'New tag name.',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'New tag slug.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'New tag description. Pass empty string to clear.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$term_id = (int) ( $input['id'] ?? 0 );

				if ( $term_id <= 0 ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Valid tag ID is required.', 'mcp-expose-abilities' ),
					);
				}

				$term = get_term( $term_id, 'post_tag' );
				if ( ! $term || is_wp_error( $term ) ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Tag not found.', 'mcp-expose-abilities' ),
					);
				}

				$update_args = array();

				if ( array_key_exists( 'name', $input ) ) {
					$name = trim( (string) $input['name'] );
					if ( '' === $name ) {
						return array(
							'success' => false,
							'message' => esc_html__( 'Tag name cannot be empty.', 'mcp-expose-abilities' ),
						);
					}
					$update_args['name'] = sanitize_text_field( $name );
				}

				if ( array_key_exists( 'slug', $input ) ) {
					$update_args['slug'] = sanitize_title( (string) $input['slug'] );
				}

				if ( array_key_exists( 'description', $input ) ) {
					$update_args['description'] = sanitize_textarea_field( (string) $input['description'] );
				}

				if ( empty( $update_args ) ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'No fields provided for update.', 'mcp-expose-abilities' ),
					);
				}

				$result = wp_update_term( $term_id, 'post_tag', $update_args );
				if ( is_wp_error( $result ) ) {
					return array(
						'success' => false,
						'message' => esc_html( $result->get_error_message() ),
					);
				}

				$updated = get_term( $term_id, 'post_tag' );
				if ( ! $updated || is_wp_error( $updated ) ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Tag update failed.', 'mcp-expose-abilities' ),
					);
				}

				return array(
					'success'     => true,
					'id'          => $updated->term_id,
					'name'        => $updated->name,
					'slug'        => $updated->slug,
					'description' => $updated->description,
					'message'     => esc_html__( 'Tag updated successfully.', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_categories' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - List
	// =========================================================================
	wp_register_ability(
		'content/list-media',
		array(
			'label'               => 'List Media',
			'description'         => 'List media. Params: per_page, page, mime_type, search (all optional).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'include_totals' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Include total counts (disables no_found_rows optimization).',
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'mime_type' => array(
						'type'        => 'string',
						'description' => 'Filter by MIME type (e.g., "image", "image/jpeg", "application/pdf").',
					),
					'search'    => array(
						'type'        => 'string',
						'description' => 'Search media by filename or title.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'media'       => array( 'type' => 'array' ),
					'returned'    => array( 'type' => 'integer' ),
					'has_more'    => array( 'type' => 'boolean' ),
					'total'       => array( 'type' => array( 'integer', 'null' ) ),
					'total_pages' => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$pagination = mcp_expose_parse_pagination( $input, 20, 100 );
				$include_totals = ! empty( $input['include_totals'] );
				$args = array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'posts_per_page'         => $pagination['per_page'],
					'paged'                  => $pagination['page'],
					'orderby'                => 'date',
					'order'                  => 'DESC',
					// Performance optimizations.
					'no_found_rows'          => ! $include_totals,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				);

				if ( ! empty( $input['mime_type'] ) ) {
					$args['post_mime_type'] = $input['mime_type'];
				}
				if ( ! empty( $input['search'] ) ) {
					$args['s'] = $input['search'];
				}

				$query = new WP_Query( $args );
				$media = array();

				foreach ( $query->posts as $item ) {
					$media[] = array(
						'id'        => $item->ID,
						'title'     => $item->post_title,
						'filename'  => basename( get_attached_file( $item->ID ) ),
						'mime_type' => $item->post_mime_type,
						'url'       => wp_get_attachment_url( $item->ID ),
						'date'      => $item->post_date,
						'alt_text'  => get_post_meta( $item->ID, '_wp_attachment_image_alt', true ),
					);
				}

				$returned = count( $media );
				$total = $include_totals ? (int) $query->found_posts : null;
				$total_pages = $include_totals ? (int) $query->max_num_pages : null;
				$has_more = $include_totals
					? $pagination['page'] < (int) $query->max_num_pages
					: $returned === $pagination['per_page'];

				return array(
					'media'       => $media,
					'returned'    => $returned,
					'has_more'    => $has_more,
					'total'       => $total,
					'total_pages' => $total_pages,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - List
	// =========================================================================
	wp_register_ability(
		'content/list-users',
		array(
			'label'               => 'List Users',
			'description'         => 'List users. Params: role, per_page, page, search (all optional).',
			'category'            => 'user',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'role'     => array(
						'type'        => 'string',
						'description' => 'Filter by role (e.g., "administrator", "editor", "author").',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'search'   => array(
						'type'        => 'string',
						'description' => 'Search users by name or email.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'users' => array( 'type' => 'array' ),
					'total' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$pagination = mcp_expose_parse_pagination( $input, 20, 100 );
				$args = array(
					'number' => $pagination['per_page'],
					'paged'  => $pagination['page'],
				);

				if ( ! empty( $input['role'] ) ) {
					$args['role'] = $input['role'];
				}
				if ( ! empty( $input['search'] ) ) {
					$args['search'] = '*' . $input['search'] . '*';
				}

				$user_query = new WP_User_Query( $args );
				$users      = array();

				foreach ( $user_query->get_results() as $user ) {
					$users[] = array(
						'id'           => $user->ID,
						'username'     => $user->user_login,
						'email'        => $user->user_email,
						'display_name' => $user->display_name,
						'roles'        => $user->roles,
						'registered'   => $user->user_registered,
					);
				}

				return array(
					'users' => $users,
					'total' => (int) $user_query->get_total(),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// POSTS - Patch (Find & Replace)
	// =========================================================================
	wp_register_ability(
		'content/patch-post',
		array(
			'label'               => 'Patch Post Content',
			'description'         => 'Patch post content. Params: id (required), find (required), replace (required), regex (optional).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'find', 'replace' ),
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Post ID to patch.',
					),
					'find'    => array(
						'type'        => 'string',
						'description' => 'String or regex pattern to find.',
					),
					'replace' => array(
						'type'        => 'string',
						'description' => 'Replacement string. For regex, supports backreferences ($1, $2, etc.).',
					),
					'regex'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, treat "find" as a regex pattern.',
					),
					'limit'   => array(
						'type'        => 'integer',
						'default'     => -1,
						'description' => 'Max replacements (-1 for all). Only applies to non-regex mode.',
					),
					'allow_design_markup_loss' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Allow patching content even when existing GenerateBlocks/design markup would be removed. Defaults to false.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Validate the patch and report replacements without writing content.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
					'message'      => array( 'type' => 'string' ),
					'link'         => array( 'type' => 'string' ),
					'dry_run'      => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Post ID is required', 'mcp-expose-abilities' ) );
				}
				if ( ! isset( $input['find'] ) || '' === $input['find'] ) {
					return array( 'success' => false, 'message' => esc_html__( 'Find string is required', 'mcp-expose-abilities' ) );
				}
				if ( ! isset( $input['replace'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Replace string is required', 'mcp-expose-abilities' ) );
				}

				$post_id    = (int) $input['id'];
				$lock_token = mcp_expose_acquire_post_write_lock( $post_id, 'content/patch-post' );
				if ( is_wp_error( $lock_token ) ) {
					return array( 'success' => false, 'message' => esc_html( $lock_token->get_error_message() ) );
				}

				try {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'message' => esc_html__( 'Post not found', 'mcp-expose-abilities' ) );
					}

					$content   = $post->post_content;
					$find      = $input['find'];
					$replace   = $input['replace'];
					$use_regex = ! empty( $input['regex'] );
					$limit     = $input['limit'] ?? -1;
					$count     = 0;

					if ( $use_regex ) {
						// Regex mode.
						$new_content = preg_replace( $find, $replace, $content, -1, $count );
						if ( null === $new_content ) {
							return array( 'success' => false, 'message' => esc_html__( 'Invalid regex pattern', 'mcp-expose-abilities' ) );
						}
					} else {
						// Plain text mode with optional limit.
						if ( $limit === -1 ) {
							$new_content = str_replace( $find, $replace, $content, $count );
						} else {
							// Manual limited replacement.
							$new_content = $content;
							$count       = 0;
							$pos         = 0;
							while ( $count < $limit && ( $pos = strpos( $new_content, $find, $pos ) ) !== false ) {
								$new_content = substr_replace( $new_content, $replace, $pos, strlen( $find ) );
								$pos        += strlen( $replace );
								$count++;
							}
						}
					}

					if ( $count === 0 ) {
						return array(
							'success'      => true,
							'id'           => $post_id,
							'replacements' => 0,
							'message'      => 'No matches found - content unchanged',
							'link'         => get_permalink( $post_id ),
						);
					}

						$design_guard = mcp_expose_validate_content_design_markup_preserved( (string) $content, (string) $new_content, $input );
						if ( is_wp_error( $design_guard ) ) {
							return array( 'success' => false, 'message' => esc_html( $design_guard->get_error_message() ) );
						}

						$source_design_gate = mcp_expose_validate_devenia_editorial_source_post_gate(
							$post,
							(string) $post->post_type,
							(string) $post->post_status,
							(string) $new_content,
							$input,
							'content/patch-post'
						);
						if ( is_wp_error( $source_design_gate ) ) {
							return array( 'success' => false, 'message' => esc_html( $source_design_gate->get_error_message() ) );
						}

						if ( ! empty( $input['dry_run'] ) ) {
							return array(
								'success'      => true,
								'dry_run'      => true,
								'id'           => $post_id,
								'replacements' => $count,
								'message'      => "Dry run passed. Would replace {$count} occurrence(s).",
								'link'         => get_permalink( $post_id ),
							);
						}

						$result = wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => $new_content,
						),
						true
					);

					if ( is_wp_error( $result ) ) {
						return array( 'success' => false, 'message' => esc_html( $result->get_error_message() ) );
					}

					return array(
						'success'      => true,
						'id'           => $post_id,
						'replacements' => $count,
						'message'      => "Successfully replaced {$count} occurrence(s)",
						'link'         => get_permalink( $post_id ),
					);
				} finally {
					mcp_expose_release_post_write_lock( $post_id, $lock_token );
				}
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// SEARCH - Global Search
	// =========================================================================
	wp_register_ability(
		'content/search',
		array(
			'label'               => 'Search Content',
			'description'         => 'Search content. Params: query (required), type (optional: post/page/media), per_page.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'query' ),
				'properties'           => array(
					'query'      => array(
						'type'        => 'string',
						'description' => 'Search query.',
					),
					'post_types' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'default'     => array( 'post', 'page' ),
						'description' => 'Post types to search (e.g., ["post", "page", "attachment"]).',
					),
					'per_page'   => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results' => array( 'type' => 'array' ),
					'total'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['query'] ) ) {
					return array( 'results' => array(), 'total' => 0 );
				}

					$query = new WP_Query( array(
						's'                      => $input['query'],
						'post_type'              => $input['post_types'] ?? array( 'post', 'page' ),
						'post_status'            => 'publish',
						'posts_per_page'         => $input['per_page'] ?? 10,
						// Keep found_rows enabled because the response exposes total count.
						'no_found_rows'          => false,
						'update_post_term_cache' => false,
						'update_post_meta_cache' => false,
					) );

				$results = array();
				foreach ( $query->posts as $post ) {
					$results[] = array(
						'id'        => $post->ID,
						'title'     => $post->post_title,
						'type'      => $post->post_type,
						'excerpt'   => wp_trim_words( $post->post_excerpt ?: $post->post_content, 20 ),
						'link'      => get_permalink( $post->ID ),
						'date'      => $post->post_date,
					);
				}

				return array(
					'results' => $results,
					'total'   => (int) $query->found_posts,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'read' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
	// =========================================================================
	// PLUGINS - Upload & Install
	// =========================================================================
	wp_register_ability(
		'plugins/upload',
		array(
			'label'               => 'Upload Plugin',
			'description'         => 'Uploads and installs a plugin from a URL (zip file). Can optionally activate after install and overwrite existing plugin.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'url' ),
				'properties'           => array(
					'url'       => array(
						'type'        => 'string',
						'description' => 'URL to the plugin zip file.',
					),
					'activate'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Activate the plugin after installation.',
					),
					'overwrite' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Overwrite existing plugin if it exists.',
					),
					'confirm_dangerous_action' => mcp_expose_dangerous_action_confirmation_schema( 'plugins/upload' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'message'   => array( 'type' => 'string' ),
					'plugin'    => array( 'type' => 'string' ),
					'activated' => array( 'type' => 'boolean' ),
				),
			),
				'execute_callback'    => function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$code_write_error = mcp_expose_plugin_code_write_error_response(
						mcp_expose_require_plugin_code_write_enabled( 'plugins/upload', $input ),
						'plugins/upload'
					);
					if ( null !== $code_write_error ) {
						return $code_write_error;
					}
					$confirmation_error = mcp_expose_dangerous_action_error_response(
						mcp_expose_confirm_dangerous_action( $input, 'plugins/upload' ),
						'plugins/upload'
					);
					if ( null !== $confirmation_error ) {
						return $confirmation_error;
					}

					if ( empty( $input['url'] ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'Plugin URL is required', 'mcp-expose-abilities' ) );
					}

					$url_check = mcp_expose_validate_remote_download_url( (string) $input['url'] );
					if ( is_wp_error( $url_check ) ) {
						return array( 'success' => false, 'message' => $url_check->get_error_message() );
					}

					$size_check = mcp_expose_validate_remote_download_size( (string) $input['url'], MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES );
					if ( is_wp_error( $size_check ) ) {
						return array( 'success' => false, 'message' => $size_check->get_error_message() );
					}

					// Download the zip file.
					$download_file = download_url( (string) $input['url'] );
					if ( is_wp_error( $download_file ) ) {
						/* translators: %s: Error message */
						return array( 'success' => false, 'message' => esc_html__( 'Download failed: ', 'mcp-expose-abilities' ) . esc_html( $download_file->get_error_message() ) );
					}
					$download_size = is_file( $download_file ) ? filesize( $download_file ) : false;
					if ( false !== $download_size && $download_size > MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES ) {
						wp_delete_file( $download_file );
						return array(
							'success' => false,
							'message' => sprintf( 'Plugin zip exceeds limit of %d bytes.', MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES ),
						);
					}

					$result = mcp_expose_install_plugin_zip( $download_file, $input );
					wp_delete_file( $download_file );

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'install_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - Upload From Base64
	// =========================================================================
	wp_register_ability(
		'plugins/upload-base64',
		array(
			'label'               => 'Upload Plugin (Base64 or Zip Path)',
			'description'         => 'Uploads and installs a plugin from base64-encoded zip content or a local zip path. Can optionally activate after install and overwrite existing plugin.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'content_base64' => array(
						'type'        => 'string',
						'description' => 'Base64-encoded zip file content.',
					),
					'zip_path'       => array(
						'type'        => 'string',
						'description' => 'Absolute path to a local plugin zip on the WordPress server.',
					),
					'filename'       => array(
						'type'        => 'string',
						'description' => 'Optional filename used for the temp zip.',
					),
					'activate'       => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Activate the plugin after installation.',
					),
					'overwrite'      => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Overwrite existing plugin if it exists.',
					),
					'confirm_dangerous_action' => mcp_expose_dangerous_action_confirmation_schema( 'plugins/upload-base64' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'message'   => array( 'type' => 'string' ),
					'plugin'    => array( 'type' => 'string' ),
					'activated' => array( 'type' => 'boolean' ),
				),
			),
				'execute_callback'    => function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$code_write_error = mcp_expose_plugin_code_write_error_response(
						mcp_expose_require_plugin_code_write_enabled( 'plugins/upload-base64', $input ),
						'plugins/upload-base64'
					);
					if ( null !== $code_write_error ) {
						return $code_write_error;
					}
					$confirmation_error = mcp_expose_dangerous_action_error_response(
						mcp_expose_confirm_dangerous_action( $input, 'plugins/upload-base64' ),
						'plugins/upload-base64'
					);
					if ( null !== $confirmation_error ) {
						return $confirmation_error;
					}

					if ( ! empty( $input['zip_path'] ) ) {
						$zip_path = wp_normalize_path( $input['zip_path'] );
					if ( ! is_file( $zip_path ) || ! is_readable( $zip_path ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'zip_path must point to a readable .zip file', 'mcp-expose-abilities' ) );
					}
						if ( ! str_ends_with( $zip_path, '.zip' ) ) {
							return array( 'success' => false, 'message' => esc_html__( 'zip_path must point to a .zip file', 'mcp-expose-abilities' ) );
						}
						$zip_size = is_file( $zip_path ) ? filesize( $zip_path ) : false;
						if ( false !== $zip_size && $zip_size > MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES ) {
							return array(
								'success' => false,
								'message' => sprintf( 'Plugin zip exceeds limit of %d bytes.', MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES ),
							);
						}

						return mcp_expose_install_plugin_zip( $zip_path, $input );
					}

				if ( empty( $input['content_base64'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'content_base64 or zip_path is required', 'mcp-expose-abilities' ) );
				}

					$decoded = base64_decode( $input['content_base64'], true );
					if ( false === $decoded ) {
						return array( 'success' => false, 'message' => esc_html__( 'Invalid base64 payload', 'mcp-expose-abilities' ) );
					}
					if ( strlen( $decoded ) > MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES ) {
						return array(
							'success' => false,
							'message' => sprintf( 'Decoded plugin zip exceeds limit of %d bytes.', MCP_EXPOSE_MAX_PLUGIN_ZIP_BYTES ),
						);
					}

				$filename = ! empty( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : 'plugin.zip';
				if ( ! str_ends_with( $filename, '.zip' ) ) {
					$filename .= '.zip';
				}

				$temp_file = wp_tempnam( $filename );
				if ( ! $temp_file ) {
					return array( 'success' => false, 'message' => esc_html__( 'Unable to create temporary file', 'mcp-expose-abilities' ) );
				}

				$bytes_written = file_put_contents( $temp_file, $decoded );
				if ( false === $bytes_written ) {
					wp_delete_file( $temp_file );
					return array( 'success' => false, 'message' => esc_html__( 'Failed to write temporary zip file', 'mcp-expose-abilities' ) );
				}

				$result = mcp_expose_install_plugin_zip( $temp_file, $input );
				wp_delete_file( $temp_file );

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'install_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
		);

	// =========================================================================
	// PLUGINS - Search WordPress.org Directory
	// =========================================================================
	wp_register_ability(
		'plugins/search-directory',
		array(
			'label'               => 'Search WordPress.org Plugin Directory',
			'description'         => 'Search the official WordPress.org plugin directory by keyword.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'search' ),
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => 'Keyword search for the WordPress.org plugin directory.',
					),
					'page'     => array(
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Page number of results.',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => 10,
						'description' => 'Results per page (max 20).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'message'  => array( 'type' => 'string' ),
					'plugins'  => array( 'type' => 'array' ),
					'total'    => array( 'type' => 'integer' ),
					'pages'    => array( 'type' => 'integer' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$term  = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
				if ( '' === $term ) {
					return array(
						'success'  => false,
						'message'  => esc_html__( 'search is required', 'mcp-expose-abilities' ),
						'plugins'  => array(),
						'total'    => 0,
						'pages'    => 0,
						'page'     => 1,
						'per_page' => 10,
					);
				}

				$pagination = mcp_expose_parse_pagination( $input, 10, 20 );
				$api        = plugins_api(
					'query_plugins',
					array(
						'search'   => $term,
						'page'     => $pagination['page'],
						'per_page' => $pagination['per_page'],
						'fields'   => array(
							'sections'          => false,
							'banners'           => false,
							'reviews'           => false,
							'ratings'           => true,
							'downloaded'        => true,
							'active_installs'   => true,
							'short_description' => true,
							'last_updated'      => true,
							'tested'            => true,
							'requires'          => true,
							'requires_php'      => true,
							'icons'             => true,
						),
					)
				);

				if ( is_wp_error( $api ) ) {
					return array(
						'success'  => false,
						'message'  => esc_html( $api->get_error_message() ),
						'plugins'  => array(),
						'total'    => 0,
						'pages'    => 0,
						'page'     => $pagination['page'],
						'per_page' => $pagination['per_page'],
					);
				}

				$results = array();
					foreach ( $api->plugins as $plugin ) {
						$plugin      = is_array( $plugin ) ? $plugin : (array) $plugin;
						$slug        = sanitize_key( (string) ( $plugin['slug'] ?? '' ) );
						$plugin_file = mcp_expose_find_plugin_file_by_slug( $slug );
						$results[]   = array(
						'slug'              => $slug,
						'name'              => wp_strip_all_tags( (string) ( $plugin['name'] ?? '' ) ),
						'version'           => (string) ( $plugin['version'] ?? '' ),
						'author'            => wp_strip_all_tags( (string) ( $plugin['author'] ?? '' ) ),
						'short_description' => wp_strip_all_tags( (string) ( $plugin['short_description'] ?? '' ) ),
						'rating'            => isset( $plugin['rating'] ) ? (int) $plugin['rating'] : 0,
						'active_installs'   => isset( $plugin['active_installs'] ) ? (int) $plugin['active_installs'] : 0,
						'downloaded'        => isset( $plugin['downloaded'] ) ? (int) $plugin['downloaded'] : 0,
						'last_updated'      => (string) ( $plugin['last_updated'] ?? '' ),
						'tested'            => (string) ( $plugin['tested'] ?? '' ),
						'requires'          => (string) ( $plugin['requires'] ?? '' ),
						'requires_php'      => (string) ( $plugin['requires_php'] ?? '' ),
						'installed'         => '' !== $plugin_file,
						'active'            => '' !== $plugin_file ? is_plugin_active( $plugin_file ) : false,
							'plugin'            => $plugin_file,
						);
					}

					$total_results = count( $results );
					$total_pages   = 1;
					if ( isset( $api->info ) ) {
						if ( is_object( $api->info ) ) {
							$total_results = isset( $api->info->results ) ? (int) $api->info->results : $total_results;
							$total_pages   = isset( $api->info->pages ) ? (int) $api->info->pages : $total_pages;
						} elseif ( is_array( $api->info ) ) {
							$total_results = isset( $api->info['results'] ) ? (int) $api->info['results'] : $total_results;
							$total_pages   = isset( $api->info['pages'] ) ? (int) $api->info['pages'] : $total_pages;
						}
					}

					return array(
						'success'  => true,
						'message'  => '',
						'plugins'  => $results,
						'total'    => $total_results,
						'pages'    => $total_pages,
						'page'     => $pagination['page'],
						'per_page' => $pagination['per_page'],
					);
				},
			'permission_callback' => function (): bool {
				return current_user_can( 'install_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - Install From WordPress.org Directory
	// =========================================================================
	wp_register_ability(
		'plugins/install-directory',
		array(
			'label'               => 'Install Plugin From WordPress.org Directory',
			'description'         => 'Install a plugin from the official WordPress.org plugin directory by slug. Can optionally activate after install.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'slug' ),
				'properties'           => array(
					'slug'      => array(
						'type'        => 'string',
						'description' => 'WordPress.org plugin slug.',
					),
					'activate'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Activate the plugin after installation.',
					),
					'overwrite' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Reinstall the plugin if it is already installed.',
					),
					'confirm_dangerous_action' => mcp_expose_dangerous_action_confirmation_schema( 'plugins/install-directory' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'message'   => array( 'type' => 'string' ),
					'plugin'    => array( 'type' => 'string' ),
					'slug'      => array( 'type' => 'string' ),
					'version'   => array( 'type' => 'string' ),
					'activated' => array( 'type' => 'boolean' ),
					'installed' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$code_write_error = mcp_expose_plugin_code_write_error_response(
					mcp_expose_require_plugin_code_write_enabled( 'plugins/install-directory', $input ),
					'plugins/install-directory'
				);
				if ( null !== $code_write_error ) {
					return $code_write_error;
				}
				$confirmation_error = mcp_expose_dangerous_action_error_response(
					mcp_expose_confirm_dangerous_action( $input, 'plugins/install-directory' ),
					'plugins/install-directory'
				);
				if ( null !== $confirmation_error ) {
					return $confirmation_error;
				}
				$slug  = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';
				if ( '' === $slug ) {
					return array( 'success' => false, 'message' => esc_html__( 'slug is required', 'mcp-expose-abilities' ) );
				}

				return mcp_expose_install_directory_plugin( $slug, $input );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'install_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - List
	// =========================================================================
	wp_register_ability(
		'plugins/list',
		array(
			'label'               => 'List Plugins',
			'description'         => 'List plugins. Params: status (all/active/inactive, optional), search (optional; matches plugin file, slug, name, author, or description).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive' ),
						'default'     => 'all',
						'description' => 'Filter by plugin status.',
					),
					'search' => array(
						'type'        => 'string',
						'description' => 'Optional case-insensitive search term. Matches plugin file, slug, name, author, or description.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array( 'type' => 'array' ),
					'total'   => array( 'type' => 'integer' ),
					'status'  => array( 'type' => 'string' ),
					'search'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$all_plugins    = get_plugins();
				$active_plugins = get_option( 'active_plugins', array() );
				$status_filter  = $input['status'] ?? 'all';
				$search         = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
				$search_needle  = strtolower( $search );

				$plugins = array();
				foreach ( $all_plugins as $file => $data ) {
					$is_active = in_array( $file, $active_plugins, true );
					$slug      = dirname( $file );

					if ( '.' === $slug ) {
						$slug = basename( $file, '.php' );
					}

					if ( 'active' === $status_filter && ! $is_active ) {
						continue;
					}
					if ( 'inactive' === $status_filter && $is_active ) {
						continue;
					}

					if ( '' !== $search_needle ) {
						$haystack = strtolower(
							implode(
								' ',
								array(
									$file,
									$slug,
									$data['Name'] ?? '',
									$data['Author'] ?? '',
									$data['Description'] ?? '',
								)
							)
						);

						if ( false === strpos( $haystack, $search_needle ) ) {
							continue;
						}
					}

					$plugins[] = array(
						'file'        => $file,
						'slug'        => $slug,
						'name'        => $data['Name'],
						'version'     => $data['Version'],
						'author'      => $data['Author'],
						'description' => $data['Description'],
						'active'      => $is_active,
					);
				}

				return array(
					'plugins' => $plugins,
					'total'   => count( $plugins ),
					'status'  => $status_filter,
					'search'  => $search,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
		);

	// =========================================================================
	// PLUGINS - List Available Updates
	// =========================================================================
	wp_register_ability(
		'plugins/list-updates',
		array(
			'label'               => 'List Plugin Updates',
			'description'         => 'List available updates for installed plugins.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'updates' => array( 'type' => 'array' ),
					'total'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$updates = mcp_expose_get_plugin_updates();
				return array(
					'success' => true,
					'message' => '',
					'updates' => $updates,
					'total'   => count( $updates ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'update_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - Update
	// =========================================================================
	wp_register_ability(
		'plugins/update',
		array(
			'label'               => 'Update Plugin',
			'description'         => 'Update an installed plugin to the latest available version.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Plugin file path (e.g., "plugin-folder/plugin-file.php").',
					),
					'confirm_dangerous_action' => mcp_expose_dangerous_action_confirmation_schema( 'plugins/update' ),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'          => array( 'type' => 'boolean' ),
					'message'          => array( 'type' => 'string' ),
					'plugin'           => array( 'type' => 'string' ),
					'previous_version' => array( 'type' => 'string' ),
					'current_version'  => array( 'type' => 'string' ),
					'updated'          => array( 'type' => 'boolean' ),
					'active_before'    => array( 'type' => 'boolean' ),
					'active_after'     => array( 'type' => 'boolean' ),
					'reactivated'      => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				if ( empty( $input['plugin'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Plugin parameter is required', 'mcp-expose-abilities' ) );
				}
				$plugin_file = (string) $input['plugin'];

				$confirmation_error = mcp_expose_dangerous_action_error_response(
					mcp_expose_confirm_dangerous_action( $input, 'plugins/update' ),
					'plugins/update'
				);
				if ( null !== $confirmation_error ) {
					return $confirmation_error;
				}

				$code_write_error = mcp_expose_plugin_code_write_error_response(
					mcp_expose_require_plugin_update_allowed( 'plugins/update', $plugin_file ),
					'plugins/update'
				);
				if ( null !== $code_write_error ) {
					return $code_write_error;
				}

				return mcp_expose_update_plugin( $plugin_file );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'update_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - Switch
	// =========================================================================
	wp_register_ability(
		'plugins/switch',
		array(
			'label'               => 'Switch Plugins',
			'description'         => 'Activate one installed plugin and optionally deactivate one or more other installed plugins in the same request.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'activate_plugin'    => array(
						'type'        => 'string',
						'description' => 'Plugin file path to activate.',
					),
					'deactivate_plugins' => array(
						'type'        => 'array',
						'description' => 'Optional list of plugin file paths to deactivate first.',
						'items'       => array(
							'type' => 'string',
						),
					),
				),
				'required'             => array( 'activate_plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'             => array( 'type' => 'boolean' ),
					'message'             => array( 'type' => 'string' ),
					'activated_plugin'    => array( 'type' => 'string' ),
					'deactivated_plugins' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				if ( empty( $input['activate_plugin'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'activate_plugin is required', 'mcp-expose-abilities' ) );
				}

				$deactivate_plugins = array();
				if ( ! empty( $input['deactivate_plugins'] ) && is_array( $input['deactivate_plugins'] ) ) {
					$deactivate_plugins = $input['deactivate_plugins'];
				}

				return mcp_expose_switch_plugins( (string) $input['activate_plugin'], $deactivate_plugins );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - Delete
	// =========================================================================
	wp_register_ability(
		'plugins/delete',
		array(
			'label'               => 'Delete Plugin',
			'description'         => 'Delete plugin. Params: plugin (required, e.g. "folder/file.php").',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Plugin file path (e.g., "plugin-folder/plugin-file.php").',
					),
					'confirm_dangerous_action' => mcp_expose_dangerous_action_confirmation_schema( 'plugins/delete' ),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				$code_write_error = mcp_expose_plugin_code_write_error_response(
					mcp_expose_require_plugin_code_write_enabled( 'plugins/delete', $input ),
					'plugins/delete'
				);
				if ( null !== $code_write_error ) {
					return $code_write_error;
				}
				$confirmation_error = mcp_expose_dangerous_action_error_response(
					mcp_expose_confirm_dangerous_action( $input, 'plugins/delete' ),
					'plugins/delete'
				);
				if ( null !== $confirmation_error ) {
					return $confirmation_error;
				}
				if ( empty( $input['plugin'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Plugin parameter is required', 'mcp-expose-abilities' ) );
				}

				$plugin_file = $input['plugin'];

				// Check if plugin exists.
				$all_plugins = get_plugins();
				if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
					/* translators: %s: Plugin file name */
					return array( 'success' => false, 'message' => esc_html__( 'Plugin not found: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ) );
				}

				// Check if plugin is active.
				if ( is_plugin_active( $plugin_file ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Cannot delete active plugin. Deactivate it first.', 'mcp-expose-abilities' ) );
				}

				// Delete the plugin.
				$deleted = delete_plugins( array( $plugin_file ) );
				if ( is_wp_error( $deleted ) ) {
					/* translators: %s: Error message */
					return array( 'success' => false, 'message' => esc_html__( 'Delete failed: ', 'mcp-expose-abilities' ) . esc_html( $deleted->get_error_message() ) );
				}

				return array(
					'success' => true,
					/* translators: %s: Plugin file name */
					'message' => esc_html__( 'Plugin deleted successfully: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - Activate
	// =========================================================================
	wp_register_ability(
		'plugins/activate',
		array(
			'label'               => 'Activate Plugin',
			'description'         => 'Activates an installed plugin. Params: plugin (required, e.g. "folder/file.php").',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Plugin file path (e.g., "plugin-folder/plugin-file.php").',
					),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				if ( empty( $input['plugin'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Plugin parameter is required', 'mcp-expose-abilities' ) );
				}

				$plugin_file = $input['plugin'];

				// Check if plugin exists.
				$all_plugins = get_plugins();
				if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Plugin not found: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ) );
				}

				// Check if already active.
				if ( is_plugin_active( $plugin_file ) ) {
					return array( 'success' => true, 'message' => esc_html__( 'Plugin is already active: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ) );
				}

				// Activate the plugin.
				$result = activate_plugin( $plugin_file );
				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Activation failed: ', 'mcp-expose-abilities' ) . esc_html( $result->get_error_message() ) );
				}

				return array(
					'success' => true,
					'message' => esc_html__( 'Plugin activated successfully: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// PLUGINS - Deactivate
	// =========================================================================
	wp_register_ability(
		'plugins/deactivate',
		array(
			'label'               => 'Deactivate Plugin',
			'description'         => 'Deactivates an active plugin. Params: plugin (required, e.g. "folder/file.php").',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Plugin file path (e.g., "plugin-folder/plugin-file.php").',
					),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				if ( empty( $input['plugin'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Plugin parameter is required', 'mcp-expose-abilities' ) );
				}

				$plugin_file = $input['plugin'];

				// Check if plugin exists.
				$all_plugins = get_plugins();
				if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Plugin not found: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ) );
				}

				// Check if already inactive.
				if ( ! is_plugin_active( $plugin_file ) ) {
					return array( 'success' => true, 'message' => esc_html__( 'Plugin is already inactive: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ) );
				}

				// Deactivate the plugin.
				deactivate_plugins( $plugin_file );

				// Verify deactivation.
				if ( is_plugin_active( $plugin_file ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Deactivation failed for: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ) );
				}

				return array(
					'success' => true,
					'message' => esc_html__( 'Plugin deactivated successfully: ', 'mcp-expose-abilities' ) . esc_html( $plugin_file ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - List
	// =========================================================================
	wp_register_ability(
		'menus/list',
		array(
			'label'               => 'List Menus',
			'description'         => 'List menus. No params.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'menus'     => array( 'type' => 'array' ),
					'locations' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$menus     = wp_get_nav_menus();
				$locations = get_nav_menu_locations();
				$registered_locations = get_registered_nav_menus();

				$menu_list = array();
				foreach ( $menus as $menu ) {
					$menu_list[] = array(
						'id'          => $menu->term_id,
						'name'        => $menu->name,
						'slug'        => $menu->slug,
						'description' => $menu->description,
						'count'       => $menu->count,
					);
				}

				$location_list = array();
				foreach ( $registered_locations as $location => $description ) {
					$location_list[ $location ] = array(
						'description' => $description,
						'menu_id'     => $locations[ $location ] ?? 0,
					);
				}

				return array(
					'success'   => true,
					'menus'     => $menu_list,
					'locations' => $location_list,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Get Menu Items
	// =========================================================================
	wp_register_ability(
		'menus/get-items',
		array(
			'label'               => 'Get Menu Items',
			'description'         => 'Get menu items. Params: id or location (one required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => 'Menu ID.',
					),
					'location' => array(
						'type'        => 'string',
						'description' => 'Menu location slug (used if ID not provided).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'menu'    => array( 'type' => 'object' ),
					'items'   => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input   = is_array( $input ) ? $input : array();
				$menu_id = 0;

				if ( ! empty( $input['id'] ) ) {
					$menu_id = (int) $input['id'];
				} elseif ( ! empty( $input['location'] ) ) {
					$locations = get_nav_menu_locations();
					$menu_id   = $locations[ $input['location'] ] ?? 0;
				}

				if ( ! $menu_id ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu ID or location required', 'mcp-expose-abilities' ) );
				}

				$menu = wp_get_nav_menu_object( $menu_id );
				if ( ! $menu ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu not found', 'mcp-expose-abilities' ) );
				}

				$items      = wp_get_nav_menu_items( $menu_id );
				$item_list  = array();

				if ( $items ) {
					foreach ( $items as $item ) {
						$model = mcp_expose_get_nav_menu_item_model( $item );
						if ( $model ) {
							$item_list[] = $model;
						}
					}
				}

				return array(
					'success' => true,
					'menu'    => array(
						'id'    => $menu->term_id,
						'name'  => $menu->name,
						'slug'  => $menu->slug,
						'count' => $menu->count,
					),
					'items'   => $item_list,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Create Menu
	// =========================================================================
	wp_register_ability(
		'menus/create',
		array(
			'label'               => 'Create Menu',
			'description'         => 'Create menu. Params: name (required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'Menu name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu name is required', 'mcp-expose-abilities' ) );
				}

				$menu_id = wp_create_nav_menu( sanitize_text_field( $input['name'] ) );

				if ( is_wp_error( $menu_id ) ) {
					return array( 'success' => false, 'message' => esc_html( $menu_id->get_error_message() ) );
				}

				return array(
					'success' => true,
					'id'      => $menu_id,
					'message' => esc_html__( 'Menu created successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Add Menu Item
	// =========================================================================
	wp_register_ability(
		'menus/add-item',
		array(
			'label'               => 'Add Menu Item',
			'description'         => 'Adds a new item to a navigation menu.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'menu_id', 'title' ),
				'properties'           => array(
					'menu_id'   => array(
						'type'        => 'integer',
						'description' => 'Menu ID to add item to.',
					),
					'title'     => array(
						'type'        => 'string',
						'description' => 'Menu item title.',
					),
					'url'       => array(
						'type'        => 'string',
						'description' => 'URL for custom links.',
					),
					'object'    => array(
						'type'        => 'string',
						'description' => 'Object type (page, post, category, custom).',
						'default'     => 'custom',
					),
					'object_id' => array(
						'type'        => 'integer',
						'description' => 'Object ID (for pages/posts/categories).',
					),
					'parent'    => array(
						'type'        => 'integer',
						'description' => 'Parent menu item ID (for submenus).',
						'default'     => 0,
					),
					'position'  => array(
						'type'        => 'integer',
						'description' => 'Menu position/order.',
					),
					'target'    => array(
						'type'        => 'string',
						'enum'        => array( '', '_blank' ),
						'description' => 'Link target (_blank for new window).',
					),
					'classes'   => array(
						'type'        => 'string',
						'description' => 'CSS classes (space-separated).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['menu_id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu ID is required', 'mcp-expose-abilities' ) );
				}
				if ( empty( $input['title'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Title is required', 'mcp-expose-abilities' ) );
				}

				$menu = wp_get_nav_menu_object( $input['menu_id'] );
				if ( ! $menu ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu not found', 'mcp-expose-abilities' ) );
				}

				$item_data = mcp_expose_build_nav_menu_item_payload( $input );
				if ( is_wp_error( $item_data ) ) {
					return array( 'success' => false, 'message' => esc_html( $item_data->get_error_message() ), 'code' => $item_data->get_error_code() );
				}

				$item = mcp_expose_write_nav_menu_item( (int) $input['menu_id'], 0, $item_data );
				if ( is_wp_error( $item ) ) {
					return array( 'success' => false, 'message' => esc_html( $item->get_error_message() ), 'code' => $item->get_error_code() );
				}

				return array(
					'success' => true,
					'id'      => (int) $item['id'],
					'item'    => $item,
					'message' => esc_html__( 'Menu item added successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Update Menu Item
	// =========================================================================
	wp_register_ability(
		'menus/update-item',
		array(
			'label'               => 'Update Menu Item',
			'description'         => 'Update menu item. Params: menu_id, item_id (required), title, url, parent, position, target, classes.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'menu_id', 'item_id' ),
				'properties'           => array(
					'menu_id'  => array(
						'type'        => 'integer',
						'description' => 'Menu ID.',
					),
					'item_id'  => array(
						'type'        => 'integer',
						'description' => 'Menu item ID to update.',
					),
					'title'    => array(
						'type'        => 'string',
						'description' => 'New title.',
					),
					'url'      => array(
						'type'        => 'string',
						'description' => 'New URL.',
					),
					'parent'   => array(
						'type'        => 'integer',
						'description' => 'New parent menu item ID.',
					),
					'position' => array(
						'type'        => 'integer',
						'description' => 'New position/order.',
					),
					'target'   => array(
						'type'        => 'string',
						'description' => 'Link target.',
					),
					'classes'  => array(
						'type'        => 'string',
						'description' => 'CSS classes.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['menu_id'] ) || empty( $input['item_id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu ID and item ID are required', 'mcp-expose-abilities' ) );
				}

				$item = get_post( $input['item_id'] );
				if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu item not found', 'mcp-expose-abilities' ) );
				}

				$existing = mcp_expose_get_nav_menu_item_model( $item );
				if ( ! $existing ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu item could not be normalized', 'mcp-expose-abilities' ) );
				}

				$item_data = mcp_expose_build_nav_menu_item_payload( $input, $existing );
				if ( is_wp_error( $item_data ) ) {
					return array( 'success' => false, 'message' => esc_html( $item_data->get_error_message() ), 'code' => $item_data->get_error_code() );
				}

				$updated = mcp_expose_write_nav_menu_item( (int) $input['menu_id'], (int) $input['item_id'], $item_data );
				if ( is_wp_error( $updated ) ) {
					return array( 'success' => false, 'message' => esc_html( $updated->get_error_message() ), 'code' => $updated->get_error_code() );
				}

				return array(
					'success' => true,
					'item'    => $updated,
					'message' => esc_html__( 'Menu item updated successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Upsert Menu Item
	// =========================================================================
	wp_register_ability(
		'menus/upsert-item',
		array(
			'label'               => 'Upsert Menu Item',
			'description'         => 'Create or update a menu item by object identity, or by URL for custom links.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'menu_id', 'title' ),
				'properties'           => array(
					'menu_id'   => array(
						'type'        => 'integer',
						'description' => 'Menu ID.',
					),
					'title'     => array(
						'type'        => 'string',
						'description' => 'Menu item title.',
					),
					'url'       => array(
						'type'        => 'string',
						'description' => 'URL for custom links.',
					),
					'object'    => array(
						'type'        => 'string',
						'description' => 'Object type (page, post, category, custom).',
						'default'     => 'custom',
					),
					'object_id' => array(
						'type'        => 'integer',
						'description' => 'Object ID for pages, posts, or categories.',
					),
					'parent'    => array(
						'type'        => 'integer',
						'description' => 'Parent menu item ID.',
						'default'     => 0,
					),
					'position'  => array(
						'type'        => 'integer',
						'description' => 'Menu position/order.',
					),
					'target'    => array(
						'type'        => 'string',
						'enum'        => array( '', '_blank' ),
						'description' => 'Link target.',
					),
					'classes'   => array(
						'type'        => 'string',
						'description' => 'CSS classes.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'action'  => array( 'type' => 'string' ),
					'item'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['menu_id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu ID is required', 'mcp-expose-abilities' ) );
				}
				if ( empty( $input['title'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Title is required', 'mcp-expose-abilities' ) );
				}

				$menu = wp_get_nav_menu_object( $input['menu_id'] );
				if ( ! $menu ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu not found', 'mcp-expose-abilities' ) );
				}

				$existing = mcp_expose_find_nav_menu_item_for_upsert( (int) $input['menu_id'], $input );
				$payload  = mcp_expose_build_nav_menu_item_payload( $input, $existing );
				if ( is_wp_error( $payload ) ) {
					return array( 'success' => false, 'message' => esc_html( $payload->get_error_message() ), 'code' => $payload->get_error_code() );
				}

				$item = mcp_expose_write_nav_menu_item(
					(int) $input['menu_id'],
					$existing ? (int) $existing['id'] : 0,
					$payload
				);
				if ( is_wp_error( $item ) ) {
					return array( 'success' => false, 'message' => esc_html( $item->get_error_message() ), 'code' => $item->get_error_code() );
				}

				$action = $existing ? 'updated' : 'created';
				return array(
					'success' => true,
					'id'      => (int) $item['id'],
					'action'  => $action,
					'item'    => $item,
					'message' => esc_html( sprintf(
						/* translators: %s: action created or updated. */
						__( 'Menu item %s successfully', 'mcp-expose-abilities' ),
						$action
					) ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Delete Menu Item
	// =========================================================================
	wp_register_ability(
		'menus/delete-item',
		array(
			'label'               => 'Delete Menu Item',
			'description'         => 'Delete menu item. Params: item_id (required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'item_id' ),
				'properties'           => array(
					'item_id' => array(
						'type'        => 'integer',
						'description' => 'Menu item ID to delete.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['item_id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Item ID is required', 'mcp-expose-abilities' ) );
				}

				$item = get_post( $input['item_id'] );
				if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu item not found', 'mcp-expose-abilities' ) );
				}

				$result = wp_delete_post( $input['item_id'], true );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => esc_html__( 'Failed to delete menu item', 'mcp-expose-abilities' ) );
				}

				return array(
					'success' => true,
					'message' => esc_html__( 'Menu item deleted successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MENUS - Assign to Location
	// =========================================================================
	wp_register_ability(
		'menus/assign-location',
		array(
			'label'               => 'Assign Menu to Location',
			'description'         => 'Assigns a menu to a theme location.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'menu_id', 'location' ),
				'properties'           => array(
					'menu_id'  => array(
						'type'        => 'integer',
						'description' => 'Menu ID to assign (use 0 to unassign).',
					),
					'location' => array(
						'type'        => 'string',
						'description' => 'Theme location slug.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! isset( $input['menu_id'] ) || empty( $input['location'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Menu ID and location are required', 'mcp-expose-abilities' ) );
				}

				$registered = get_registered_nav_menus();
				if ( ! isset( $registered[ $input['location'] ] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Invalid menu location', 'mcp-expose-abilities' ) );
				}

				$locations = get_nav_menu_locations();
				$locations[ $input['location'] ] = (int) $input['menu_id'];
				set_theme_mod( 'nav_menu_locations', $locations );

				$action = $input['menu_id'] > 0 ? 'assigned' : 'unassigned';
				return array(
					'success' => true,
					/* translators: %s: Action ("assigned" or "unassigned"). */
					'message' => esc_html( sprintf( __( 'Menu %s to location successfully', 'mcp-expose-abilities' ), $action ) ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// WIDGETS - List Sidebars
	// =========================================================================
	wp_register_ability(
		'widgets/list-sidebars',
		array(
			'label'               => 'List Widget Sidebars',
			'description'         => 'List sidebars. No params.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'sidebars' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wp_registered_sidebars;

				$sidebars = array();
				foreach ( $wp_registered_sidebars as $id => $sidebar ) {
					$sidebars[] = array(
						'id'          => $id,
						'name'        => $sidebar['name'],
						'description' => $sidebar['description'] ?? '',
					);
				}

				return array(
					'success'  => true,
					'sidebars' => $sidebars,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// WIDGETS - Get Sidebar Widgets
	// =========================================================================
	wp_register_ability(
		'widgets/get-sidebar',
		array(
			'label'               => 'Get Sidebar Widgets',
			'description'         => 'Get sidebar widgets. Params: sidebar_id (required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'sidebar_id' ),
				'properties'           => array(
					'sidebar_id' => array(
						'type'        => 'string',
						'description' => 'Sidebar ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'sidebar' => array( 'type' => 'object' ),
					'widgets' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wp_registered_sidebars, $wp_registered_widgets;
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['sidebar_id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Sidebar ID is required', 'mcp-expose-abilities' ) );
				}

				$sidebar_id = $input['sidebar_id'];
				if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Sidebar not found', 'mcp-expose-abilities' ) );
				}

				// Get sidebars widgets via option (wp_get_sidebars_widgets is flagged by plugin check).
				$sidebars_widgets = get_option( 'sidebars_widgets', array() );
				$sidebars_widgets = (array) apply_filters( 'sidebars_widgets', $sidebars_widgets );
				$widget_ids       = $sidebars_widgets[ $sidebar_id ] ?? array();
				$widgets          = array();

				foreach ( $widget_ids as $widget_id ) {
					if ( isset( $wp_registered_widgets[ $widget_id ] ) ) {
						$widget = $wp_registered_widgets[ $widget_id ];
						$widgets[] = array(
							'id'   => $widget_id,
							'name' => $widget['name'],
						);
					}
				}

				return array(
					'success' => true,
					'sidebar' => array(
						'id'   => $sidebar_id,
						'name' => $wp_registered_sidebars[ $sidebar_id ]['name'],
					),
					'widgets' => $widgets,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// WIDGETS - List Available Widgets
	// =========================================================================
	wp_register_ability(
		'widgets/list-available',
		array(
			'label'               => 'List Available Widgets',
			'description'         => 'List available widgets. No params.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'widgets' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wp_widget_factory;

				$widgets = array();
				foreach ( $wp_widget_factory->widgets as $class => $widget ) {
					$widgets[] = array(
						'id_base'     => $widget->id_base,
						'name'        => $widget->name,
						'description' => $widget->widget_options['description'] ?? '',
					);
				}

				return array(
					'success' => true,
					'widgets' => $widgets,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - List
	// =========================================================================
	wp_register_ability(
		'users/list',
		array(
			'label'               => 'List Users (Extended)',
			'description'         => 'List users extended. Params: role, per_page, page, orderby, order (all optional).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'role'     => array(
						'type'        => 'string',
						'description' => 'Filter by role (administrator, editor, author, contributor, subscriber).',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
					),
					'page'     => array(
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
					),
					'orderby'  => array(
						'type'    => 'string',
						'enum'    => array( 'ID', 'login', 'nicename', 'email', 'registered', 'display_name' ),
						'default' => 'display_name',
					),
					'order'    => array(
						'type'    => 'string',
						'enum'    => array( 'ASC', 'DESC' ),
						'default' => 'ASC',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'users'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$pagination = mcp_expose_parse_pagination( $input, 20, 100 );
				$args = array(
					'number'  => $pagination['per_page'],
					'paged'   => $pagination['page'],
					'orderby' => $input['orderby'] ?? 'display_name',
					'order'   => $input['order'] ?? 'ASC',
				);

				if ( ! empty( $input['role'] ) ) {
					$args['role'] = $input['role'];
				}

				$query = new WP_User_Query( $args );
				$users = array();

				foreach ( $query->get_results() as $user ) {
					$users[] = array(
						'id'           => $user->ID,
						'login'        => $user->user_login,
						'email'        => $user->user_email,
						'display_name' => $user->display_name,
						'nicename'     => $user->user_nicename,
						'url'          => $user->user_url,
						'registered'   => $user->user_registered,
						'roles'        => $user->roles,
					);
				}

				$total = $query->get_total();
				return array(
					'success'     => true,
					'users'       => $users,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $pagination['per_page'] ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - Get
	// =========================================================================
	wp_register_ability(
		'users/get',
		array(
			'label'               => 'Get User',
			'description'         => 'Get user. Params: id, login, or email (one required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'User ID.',
					),
					'login' => array(
						'type'        => 'string',
						'description' => 'Username (used if ID not provided).',
					),
					'email' => array(
						'type'        => 'string',
						'description' => 'Email address (used if ID and login not provided).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'user'    => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$user  = null;

				if ( ! empty( $input['id'] ) ) {
					$user = get_user_by( 'id', $input['id'] );
				} elseif ( ! empty( $input['login'] ) ) {
					$user = get_user_by( 'login', $input['login'] );
				} elseif ( ! empty( $input['email'] ) ) {
					$user = get_user_by( 'email', $input['email'] );
				}

				if ( ! $user ) {
					return array( 'success' => false, 'message' => esc_html__( 'User not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'edit_user', $user->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to view this user.', 'mcp-expose-abilities' ) );
				}

				return array(
					'success' => true,
					'user'    => array(
						'id'           => $user->ID,
						'login'        => $user->user_login,
						'email'        => $user->user_email,
						'display_name' => $user->display_name,
						'first_name'   => $user->first_name,
						'last_name'    => $user->last_name,
						'nickname'     => $user->nickname,
						'nicename'     => $user->user_nicename,
						'url'          => $user->user_url,
						'description'  => $user->description,
						'registered'   => $user->user_registered,
						'roles'        => $user->roles,
						'caps'         => array_keys( array_filter( $user->allcaps ) ),
					),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - Create
	// =========================================================================
	wp_register_ability(
		'users/create',
		array(
			'label'               => 'Create User',
			'description'         => 'Create user. Params: username, email (required), password, first_name, last_name, display_name, role, url, description.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'username', 'email' ),
				'properties'           => array(
					'username'     => array(
						'type'        => 'string',
						'description' => 'Username (login name).',
					),
					'email'        => array(
						'type'        => 'string',
						'description' => 'Email address.',
					),
					'password'     => array(
						'type'        => 'string',
						'description' => 'Password (auto-generated if not provided).',
					),
					'first_name'   => array(
						'type'        => 'string',
						'description' => 'First name.',
					),
					'last_name'    => array(
						'type'        => 'string',
						'description' => 'Last name.',
					),
					'display_name' => array(
						'type'        => 'string',
						'description' => 'Display name.',
					),
					'role'         => array(
						'type'        => 'string',
						'description' => 'User role.',
						'default'     => 'subscriber',
					),
					'url'          => array(
						'type'        => 'string',
						'description' => 'User website URL.',
					),
					'description'  => array(
						'type'        => 'string',
						'description' => 'User bio/description.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'id'       => array( 'type' => 'integer' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['username'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Username is required', 'mcp-expose-abilities' ) );
				}
				if ( empty( $input['email'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Email is required', 'mcp-expose-abilities' ) );
				}

				$userdata = array(
					'user_login' => sanitize_user( $input['username'] ),
					'user_email' => sanitize_email( $input['email'] ),
					'user_pass'  => $input['password'] ?? wp_generate_password(),
					'role'       => $input['role'] ?? 'subscriber',
				);

				if ( ! empty( $input['first_name'] ) ) {
					$userdata['first_name'] = sanitize_text_field( $input['first_name'] );
				}
				if ( ! empty( $input['last_name'] ) ) {
					$userdata['last_name'] = sanitize_text_field( $input['last_name'] );
				}
				if ( ! empty( $input['display_name'] ) ) {
					$userdata['display_name'] = sanitize_text_field( $input['display_name'] );
				}
				if ( ! empty( $input['url'] ) ) {
					$userdata['user_url'] = esc_url_raw( $input['url'] );
				}
				if ( ! empty( $input['description'] ) ) {
					$userdata['description'] = sanitize_textarea_field( $input['description'] );
				}

				$user_id = wp_insert_user( $userdata );

				if ( is_wp_error( $user_id ) ) {
					return array( 'success' => false, 'message' => esc_html( $user_id->get_error_message() ) );
				}

				return array(
					'success' => true,
					'id'      => $user_id,
					'message' => esc_html__( 'User created successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'create_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// USERS - Update
	// =========================================================================
	wp_register_ability(
		'users/update',
		array(
			'label'               => 'Update User',
			'description'         => 'Update user. Params: id (required), email, password, first_name, last_name, display_name, nickname, role, url, description.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => 'User ID to update.',
					),
					'email'        => array(
						'type'        => 'string',
						'description' => 'New email address.',
					),
					'password'     => array(
						'type'        => 'string',
						'description' => 'New password.',
					),
					'first_name'   => array(
						'type'        => 'string',
						'description' => 'New first name.',
					),
					'last_name'    => array(
						'type'        => 'string',
						'description' => 'New last name.',
					),
					'display_name' => array(
						'type'        => 'string',
						'description' => 'New display name.',
					),
					'nickname'     => array(
						'type'        => 'string',
						'description' => 'New nickname.',
					),
					'role'         => array(
						'type'        => 'string',
						'description' => 'New role.',
					),
					'url'          => array(
						'type'        => 'string',
						'description' => 'New website URL.',
					),
					'description'  => array(
						'type'        => 'string',
						'description' => 'New bio/description.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'User ID is required', 'mcp-expose-abilities' ) );
				}

				$user = get_user_by( 'id', $input['id'] );
				if ( ! $user ) {
					return array( 'success' => false, 'message' => esc_html__( 'User not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'edit_user', $user->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to update this user.', 'mcp-expose-abilities' ) );
				}

				$userdata = array( 'ID' => $input['id'] );

				if ( isset( $input['email'] ) ) {
					$userdata['user_email'] = sanitize_email( $input['email'] );
				}
				if ( isset( $input['password'] ) ) {
					$userdata['user_pass'] = $input['password'];
				}
				if ( isset( $input['first_name'] ) ) {
					$userdata['first_name'] = sanitize_text_field( $input['first_name'] );
				}
				if ( isset( $input['last_name'] ) ) {
					$userdata['last_name'] = sanitize_text_field( $input['last_name'] );
				}
				if ( isset( $input['display_name'] ) ) {
					$userdata['display_name'] = sanitize_text_field( $input['display_name'] );
				}
				if ( isset( $input['nickname'] ) ) {
					$userdata['nickname'] = sanitize_text_field( $input['nickname'] );
				}
				if ( isset( $input['role'] ) ) {
					if ( ! current_user_can( 'promote_user', $user->ID ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'Permission denied to change user role.', 'mcp-expose-abilities' ) );
					}
					$userdata['role'] = $input['role'];
				}
				if ( isset( $input['url'] ) ) {
					$userdata['user_url'] = esc_url_raw( $input['url'] );
				}
				if ( isset( $input['description'] ) ) {
					$userdata['description'] = sanitize_textarea_field( $input['description'] );
				}

				$result = wp_update_user( $userdata );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => esc_html( $result->get_error_message() ) );
				}

				return array(
					'success' => true,
					'message' => esc_html__( 'User updated successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// USERS - Delete
	// =========================================================================
	wp_register_ability(
		'users/delete',
		array(
			'label'               => 'Delete User',
			'description'         => 'Delete user. Params: id (required), reassign_to.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => 'User ID to delete.',
					),
					'reassign_to' => array(
						'type'        => 'integer',
						'description' => 'User ID to reassign content to. If not provided, content will be deleted.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'User ID is required', 'mcp-expose-abilities' ) );
				}

				$user = get_user_by( 'id', $input['id'] );
				if ( ! $user ) {
					return array( 'success' => false, 'message' => esc_html__( 'User not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'delete_user', $user->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to delete this user.', 'mcp-expose-abilities' ) );
				}

				// Don't allow deleting yourself.
				if ( $input['id'] === get_current_user_id() ) {
					return array( 'success' => false, 'message' => esc_html__( 'Cannot delete your own account', 'mcp-expose-abilities' ) );
				}

				// `wp_delete_user()` is defined in an admin include that may not be loaded
				// for REST/MCP requests.
				if ( ! function_exists( 'wp_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}

				if ( ! function_exists( 'wp_delete_user' ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'User deletion function unavailable', 'mcp-expose-abilities' ) );
				}

				$reassign = ! empty( $input['reassign_to'] ) ? (int) $input['reassign_to'] : null;
				$result   = wp_delete_user( $input['id'], $reassign );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => esc_html__( 'Failed to delete user', 'mcp-expose-abilities' ) );
				}

				return array(
					'success' => true,
					'message' => esc_html__( 'User deleted successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Upload
	// =========================================================================
	wp_register_ability(
		'media/upload',
		array(
			'label'               => 'Upload Media',
			'description'         => 'Uploads a file to the media library from a URL.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'url' ),
				'properties'           => array(
					'url'         => array(
						'type'        => 'string',
						'description' => 'URL of the file to upload.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'Title for the media item.',
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => 'Caption for the media item.',
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => 'Alt text for images.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Description for the media item.',
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => 'Post ID to attach the media to.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'id'      => array( 'type' => 'integer' ),
					'url'     => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

					if ( empty( $input['url'] ) ) {
						return array( 'success' => false, 'message' => esc_html__( 'URL is required', 'mcp-expose-abilities' ) );
					}
					$url_check = mcp_expose_validate_remote_download_url( (string) $input['url'] );
					if ( is_wp_error( $url_check ) ) {
						return array( 'success' => false, 'message' => $url_check->get_error_message() );
					}
					$size_check = mcp_expose_validate_remote_download_size( (string) $input['url'], MCP_EXPOSE_MAX_MEDIA_DOWNLOAD_BYTES );
					if ( is_wp_error( $size_check ) ) {
						return array( 'success' => false, 'message' => $size_check->get_error_message() );
					}

					$post_id = $input['post_id'] ?? 0;

					// Download file to temp location.
					$tmp = download_url( (string) $input['url'] );
					if ( is_wp_error( $tmp ) ) {
						/* translators: %s: Error message */
						return array( 'success' => false, 'message' => esc_html( $tmp->get_error_message() ) );
					}
					$tmp_size = is_file( $tmp ) ? filesize( $tmp ) : false;
					if ( false !== $tmp_size && $tmp_size > MCP_EXPOSE_MAX_MEDIA_DOWNLOAD_BYTES ) {
						wp_delete_file( $tmp );
						return array(
							'success' => false,
							'message' => sprintf( 'Downloaded media exceeds limit of %d bytes.', MCP_EXPOSE_MAX_MEDIA_DOWNLOAD_BYTES ),
						);
					}

				// Get filename from URL.
				$filename = basename( wp_parse_url( $input['url'], PHP_URL_PATH ) );
				if ( empty( $filename ) ) {
					$filename = 'uploaded-file';
				}

				$file_array = array(
					'name'     => $filename,
					'tmp_name' => $tmp,
				);

				// Upload to media library.
				$attachment_id = media_handle_sideload( $file_array, $post_id );

				// Clean up temp file.
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}

				if ( is_wp_error( $attachment_id ) ) {
					return array( 'success' => false, 'message' => esc_html( $attachment_id->get_error_message() ) );
				}

				// Update attachment metadata.
				if ( ! empty( $input['title'] ) ) {
					wp_update_post( array(
						'ID'         => $attachment_id,
						'post_title' => sanitize_text_field( $input['title'] ),
					) );
				}
				if ( ! empty( $input['caption'] ) ) {
					wp_update_post( array(
						'ID'           => $attachment_id,
						'post_excerpt' => sanitize_text_field( $input['caption'] ),
					) );
				}
				if ( ! empty( $input['description'] ) ) {
					wp_update_post( array(
						'ID'           => $attachment_id,
						'post_content' => sanitize_textarea_field( $input['description'] ),
					) );
				}
				if ( ! empty( $input['alt_text'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
				}

				return array(
					'success' => true,
					'id'      => $attachment_id,
					'url'     => wp_get_attachment_url( $attachment_id ),
					'message' => esc_html__( 'Media uploaded successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Upload Base64
	// =========================================================================
	wp_register_ability(
		'media/upload-base64',
		array(
			'label'               => 'Upload Media From Base64',
			'description'         => 'Uploads a media attachment from base64-encoded file content. Params: filename, mime_type, base64, title, caption, alt_text, description, post_id.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'filename', 'mime_type', 'base64' ),
				'properties'           => array(
					'filename'    => array(
						'type'        => 'string',
						'description' => 'Target filename, including extension.',
					),
					'mime_type'   => array(
						'type'        => 'string',
						'description' => 'Expected MIME type, such as image/webp or image/jpeg.',
					),
					'base64'      => array(
						'type'        => 'string',
						'description' => 'Base64-encoded file content. A data URI prefix is allowed.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'Attachment title.',
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => 'Attachment caption.',
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => 'Image alt text.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Attachment description.',
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => 'Optional post ID to attach the media to.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'id'       => array( 'type' => 'integer' ),
					'url'      => array( 'type' => 'string' ),
					'width'    => array( 'type' => 'integer' ),
					'height'   => array( 'type' => 'integer' ),
					'filesize' => array( 'type' => 'integer' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$filename  = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';
				$mime_type = isset( $input['mime_type'] ) ? sanitize_mime_type( (string) $input['mime_type'] ) : '';
				$base64    = isset( $input['base64'] ) ? mcp_expose_normalize_base64( (string) $input['base64'] ) : '';
				$post_id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

				if ( '' === $filename || '' === $mime_type || '' === $base64 ) {
					return array( 'success' => false, 'message' => esc_html__( 'filename, mime_type, and base64 are required.', 'mcp-expose-abilities' ) );
				}

				$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml', 'application/pdf' );
				if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'MIME type is not allowed.', 'mcp-expose-abilities' ) );
				}

				$file_type = wp_check_filetype( $filename );
				if ( empty( $file_type['type'] ) || $file_type['type'] !== $mime_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Filename extension and MIME type do not match.', 'mcp-expose-abilities' ) );
				}

				$file_data = base64_decode( $base64, true );
				if ( false === $file_data ) {
					return array( 'success' => false, 'message' => esc_html__( 'Invalid base64 content.', 'mcp-expose-abilities' ) );
				}

				if ( strlen( $file_data ) > MCP_EXPOSE_MAX_MEDIA_BASE64_BYTES ) {
					return array(
						'success' => false,
						'message' => sprintf( 'Media exceeds limit of %d bytes.', MCP_EXPOSE_MAX_MEDIA_BASE64_BYTES ),
					);
				}

				$upload = wp_upload_bits( $filename, null, $file_data );
				if ( ! empty( $upload['error'] ) ) {
					return array( 'success' => false, 'message' => esc_html( (string) $upload['error'] ) );
				}

				$file_path = (string) $upload['file'];
				$file_url  = (string) $upload['url'];
				$file_size = file_exists( $file_path ) ? (int) filesize( $file_path ) : strlen( $file_data );

				$title = isset( $input['title'] ) && '' !== trim( (string) $input['title'] )
					? sanitize_text_field( (string) $input['title'] )
					: preg_replace( '/\.[^.]+$/', '', $filename );

				$attachment_id = wp_insert_attachment(
					array(
						'post_mime_type' => $mime_type,
						'post_title'     => $title,
						'post_excerpt'   => isset( $input['caption'] ) ? sanitize_text_field( (string) $input['caption'] ) : '',
						'post_content'   => isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '',
						'post_status'    => 'inherit',
					),
					$file_path,
					$post_id
				);

				if ( is_wp_error( $attachment_id ) ) {
					wp_delete_file( $file_path );
					return array( 'success' => false, 'message' => esc_html( $attachment_id->get_error_message() ) );
				}

				$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
				wp_update_attachment_metadata( $attachment_id, $metadata );

				if ( isset( $input['alt_text'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
				}

				$width  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
				$height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

				return array(
					'success'  => true,
					'id'       => (int) $attachment_id,
					'url'      => $file_url,
					'width'    => $width,
					'height'   => $height,
					'filesize' => $file_size,
					'message'  => esc_html__( 'Media uploaded successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Get
	// =========================================================================
	wp_register_ability(
		'media/get',
		array(
			'label'               => 'Get Media Item',
			'description'         => 'Get media. Params: id (required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Media attachment ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'media'   => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Media ID is required', 'mcp-expose-abilities' ) );
				}

				$attachment = get_post( $input['id'] );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Media not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'read_post', $attachment->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to view this media item.', 'mcp-expose-abilities' ) );
				}

				$metadata = wp_get_attachment_metadata( $input['id'] );
				$sizes    = array();

				if ( ! empty( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size => $data ) {
						$sizes[ $size ] = array(
							'url'    => wp_get_attachment_image_url( $input['id'], $size ),
							'width'  => $data['width'],
							'height' => $data['height'],
						);
					}
				}

				return array(
					'success' => true,
					'media'   => array(
						'id'          => $attachment->ID,
						'title'       => $attachment->post_title,
						'caption'     => $attachment->post_excerpt,
						'description' => $attachment->post_content,
						'alt_text'    => get_post_meta( $input['id'], '_wp_attachment_image_alt', true ),
						'mime_type'   => $attachment->post_mime_type,
						'url'         => wp_get_attachment_url( $input['id'] ),
						'date'        => $attachment->post_date,
						'modified'    => $attachment->post_modified,
						'author_id'   => (int) $attachment->post_author,
						'parent_id'   => (int) $attachment->post_parent,
						'width'       => $metadata['width'] ?? null,
						'height'      => $metadata['height'] ?? null,
						'file'        => $metadata['file'] ?? null,
						'sizes'       => $sizes,
					),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Update
	// =========================================================================
	wp_register_ability(
		'media/update',
		array(
			'label'               => 'Update Media Item',
			'description'         => 'Update media. Params: id (required), title, caption, alt_text, description.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => 'Media attachment ID.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'New title.',
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => 'New caption.',
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => 'New alt text.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'New description.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Media ID is required', 'mcp-expose-abilities' ) );
				}

				$attachment = get_post( $input['id'] );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Media not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'edit_post', $attachment->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to update this media item.', 'mcp-expose-abilities' ) );
				}

				$post_data = array( 'ID' => $input['id'] );

				if ( isset( $input['title'] ) ) {
					$post_data['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['caption'] ) ) {
					$post_data['post_excerpt'] = sanitize_text_field( $input['caption'] );
				}
				if ( isset( $input['description'] ) ) {
					$post_data['post_content'] = sanitize_textarea_field( $input['description'] );
				}

				$result = wp_update_post( $post_data, true );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => esc_html( $result->get_error_message() ) );
				}

				if ( isset( $input['alt_text'] ) ) {
					update_post_meta( $input['id'], '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
				}

				return array(
					'success' => true,
					'message' => esc_html__( 'Media updated successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// MEDIA - Delete
	// =========================================================================
	wp_register_ability(
		'media/delete',
		array(
			'label'               => 'Delete Media Item',
			'description'         => 'Permanently deletes a media item and its files.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'Media attachment ID.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Force permanent deletion (default true for media).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['id'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Media ID is required', 'mcp-expose-abilities' ) );
				}

				$attachment = get_post( $input['id'] );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return array( 'success' => false, 'message' => esc_html__( 'Media not found', 'mcp-expose-abilities' ) );
				}

				if ( ! current_user_can( 'delete_post', $attachment->ID ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Permission denied to delete this media item.', 'mcp-expose-abilities' ) );
				}

				$force  = $input['force'] ?? true;
				$result = wp_delete_attachment( $input['id'], $force );

				if ( ! $result ) {
					return array( 'success' => false, 'message' => esc_html__( 'Failed to delete media', 'mcp-expose-abilities' ) );
				}

				return array(
					'success' => true,
					'message' => esc_html__( 'Media deleted successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// SYSTEM - Get Transient
	// =========================================================================
	wp_register_ability(
		'system/get-transient',
		array(
			'label'               => 'Get Transient',
			'description'         => 'Get transient. Params: name (required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'The transient name to retrieve.',
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'value'   => array(),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Transient name is required', 'mcp-expose-abilities' ), 'value' => null );
				}

				$value = get_transient( $input['name'] );

				if ( false === $value ) {
					return array( 'success' => false, 'message' => esc_html__( 'Transient not found or expired', 'mcp-expose-abilities' ), 'value' => null );
				}

				return array(
					'success' => true,
					'value'   => $value,
					'message' => esc_html__( 'Transient retrieved successfully', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// SYSTEM - Ability Timings
	// =========================================================================
	wp_register_ability(
		'system/ability-timings',
		array(
			'label'               => 'Ability Timings',
			'description'         => 'Returns recent slow or failed MCP ability calls captured by this plugin.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'           => array(
						'type'        => 'integer',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'Maximum number of timing entries to return.',
					),
					'ability'         => array(
						'type'        => 'string',
						'description' => 'Optional exact ability name filter.',
					),
					'min_duration_ms' => array(
						'type'        => 'number',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Optional minimum duration filter in milliseconds.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'threshold_ms' => array( 'type' => 'number' ),
					'count'        => array( 'type' => 'integer' ),
					'entries'      => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				return mcp_expose_get_ability_timings( $input );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// SYSTEM - Debug Log
	// =========================================================================
	wp_register_ability(
		'system/debug-log',
		array(
			'label'               => 'Read Debug Log',
			'description'         => 'Reads the WordPress debug.log file. Returns the last N lines, optionally filtered by a search pattern.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'lines'  => array(
						'type'        => 'integer',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 500,
						'description' => 'Number of lines to return from the end of the log.',
					),
					'filter' => array(
						'type'        => 'string',
						'description' => 'Optional filter string. Only lines containing this text will be returned.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'lines'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				$log_file = WP_CONTENT_DIR . '/debug.log';

				if ( ! file_exists( $log_file ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Debug log file not found', 'mcp-expose-abilities' ), 'lines' => array() );
				}

				if ( ! is_readable( $log_file ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Debug log file not readable', 'mcp-expose-abilities' ), 'lines' => array() );
				}

				$num_lines = isset( $input['lines'] ) ? min( max( 1, (int) $input['lines'] ), 500 ) : 50;
				$filter    = isset( $input['filter'] ) ? $input['filter'] : '';

					$scan_lines = empty( $filter ) ? $num_lines : min( 5000, $num_lines * 20 );
					$all_lines  = mcp_expose_read_tail_lines( $log_file, $scan_lines );

				// Apply filter if specified
				if ( ! empty( $filter ) ) {
					$all_lines = array_filter( $all_lines, function( $line ) use ( $filter ) {
						return stripos( $line, $filter ) !== false;
					} );
				}

				// Get last N lines
				$result_lines = array_slice( $all_lines, -$num_lines );

				return array(
					'success' => true,
					'lines'   => array_values( $result_lines ),
					/* translators: %d: Number of lines returned. */
					'message' => esc_html( sprintf( _n( 'Returned %d line', 'Returned %d lines', count( $result_lines ), 'mcp-expose-abilities' ), count( $result_lines ) ) ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// SYSTEM - Toggle Debug Mode
	// =========================================================================
	wp_register_ability(
		'system/toggle-debug',
		array(
			'label'               => 'Toggle Debug Mode',
			'description'         => 'Toggles WP_DEBUG on or off in wp-config.php. Can also set WP_DEBUG_LOG and WP_DEBUG_DISPLAY.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'debug'         => array(
						'type'        => 'boolean',
						'description' => 'Set WP_DEBUG to true or false.',
					),
					'debug_log'     => array(
						'type'        => 'boolean',
						'description' => 'Set WP_DEBUG_LOG to true or false. Optional.',
					),
					'debug_display' => array(
						'type'        => 'boolean',
						'description' => 'Set WP_DEBUG_DISPLAY to true or false. Optional.',
					),
				),
				'required'             => array( 'debug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'changes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( ! isset( $input['debug'] ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'Missing required parameter: debug', 'mcp-expose-abilities' ), 'changes' => array() );
				}

				$wp_config_path = ABSPATH . 'wp-config.php';

				// Initialize WP_Filesystem.
				global $wp_filesystem;
				WP_Filesystem();

				if ( ! $wp_filesystem->exists( $wp_config_path ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'wp-config.php not found', 'mcp-expose-abilities' ), 'changes' => array() );
				}

				if ( ! $wp_filesystem->is_writable( $wp_config_path ) ) {
					return array( 'success' => false, 'message' => esc_html__( 'wp-config.php is not writable', 'mcp-expose-abilities' ), 'changes' => array() );
				}

				$content = $wp_filesystem->get_contents( $wp_config_path );
				if ( false === $content ) {
					return array( 'success' => false, 'message' => esc_html__( 'Failed to read wp-config.php', 'mcp-expose-abilities' ), 'changes' => array() );
				}

				$changes   = array();
				$debug_val = $input['debug'] ? 'true' : 'false';

				// Update or add WP_DEBUG
				if ( preg_match( "/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)/i", $content ) ) {
					$content   = preg_replace(
						"/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)/i",
						"define( 'WP_DEBUG', {$debug_val} )",
						$content
					);
					$changes[] = "WP_DEBUG set to {$debug_val}";
				} else {
					// Add before "That's all" comment or at end
					$insert = "define( 'WP_DEBUG', {$debug_val} );\n";
					if ( strpos( $content, "/* That's all" ) !== false ) {
						$content = str_replace( "/* That's all", $insert . "/* That's all", $content );
					} else {
						$content .= "\n" . $insert;
					}
					$changes[] = "WP_DEBUG added and set to {$debug_val}";
				}

				// Handle WP_DEBUG_LOG if specified
				if ( isset( $input['debug_log'] ) ) {
					$log_val = $input['debug_log'] ? 'true' : 'false';
					if ( preg_match( "/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(true|false)\s*\)/i", $content ) ) {
						$content   = preg_replace(
							"/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(true|false)\s*\)/i",
							"define( 'WP_DEBUG_LOG', {$log_val} )",
							$content
						);
						$changes[] = "WP_DEBUG_LOG set to {$log_val}";
					} elseif ( $input['debug_log'] ) {
						// Only add if setting to true
						$insert = "define( 'WP_DEBUG_LOG', true );\n";
						$content = preg_replace(
							"/(define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)\s*;)/i",
							"$1\n" . $insert,
							$content
						);
						$changes[] = "WP_DEBUG_LOG added and set to true";
					}
				}

				// Handle WP_DEBUG_DISPLAY if specified
				if ( isset( $input['debug_display'] ) ) {
					$display_val = $input['debug_display'] ? 'true' : 'false';
					if ( preg_match( "/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*(true|false)\s*\)/i", $content ) ) {
						$content   = preg_replace(
							"/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*(true|false)\s*\)/i",
							"define( 'WP_DEBUG_DISPLAY', {$display_val} )",
							$content
						);
						$changes[] = "WP_DEBUG_DISPLAY set to {$display_val}";
					} elseif ( ! $input['debug_display'] ) {
						// Only add if setting to false (to hide errors)
						$insert = "define( 'WP_DEBUG_DISPLAY', false );\n";
						$content = preg_replace(
							"/(define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)\s*;)/i",
							"$1\n" . $insert,
							$content
						);
						$changes[] = "WP_DEBUG_DISPLAY added and set to false";
					}
				}

				// Write changes
				$result = $wp_filesystem->put_contents( $wp_config_path, $content, FS_CHMOD_FILE );
				if ( false === $result ) {
					return array( 'success' => false, 'message' => esc_html__( 'Failed to write wp-config.php', 'mcp-expose-abilities' ), 'changes' => array() );
				}

				return array(
					'success' => true,
					'message' => esc_html__( 'wp-config.php updated successfully', 'mcp-expose-abilities' ),
					'changes' => $changes,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// OPTIONS - Get Option
	// =========================================================================
	wp_register_ability(
		'options/get',
		array(
			'label'               => 'Get Option',
			'description'         => 'Get option. Params: name (required).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'The option name to retrieve (e.g., "blogname", "rank_math_options_titles").',
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'name'    => array( 'type' => 'string' ),
					'value'   => array( 'description' => 'The option value (type varies)' ),
					'type'    => array( 'type' => 'string', 'description' => 'PHP type of the value' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'name' => '', 'value' => null, 'type' => 'null' );
				}

					$name = sanitize_key( $input['name'] );

					if ( mcp_expose_is_sensitive_option_name( $name ) ) {
						return array(
							'success' => false,
							'name'    => $name,
							'value'   => null,
							'type'    => 'null',
							'message' => esc_html__( 'This option is protected and cannot be retrieved via MCP.', 'mcp-expose-abilities' ),
						);
					}

					$value = get_option( $name, null );

				if ( null === $value ) {
					return array(
						'success' => false,
						'name'    => $name,
						'value'   => null,
						'type'    => 'null',
						'message' => esc_html__( 'Option not found', 'mcp-expose-abilities' ),
					);
				}

				return array(
					'success' => true,
					'name'    => $name,
					'value'   => $value,
					'type'    => gettype( $value ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// OPTIONS - Update Option
	// =========================================================================
	wp_register_ability(
		'options/update',
		array(
			'label'               => 'Update Option',
			'description'         => 'Update option. Params: name, value (required), key (optional for array options).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'description' => 'The option name to update.',
					),
					'value' => array(
						'description' => 'The new value (can be string, number, boolean, array, or object).',
					),
					'key'   => array(
						'type'        => 'string',
						'description' => 'Optional: If the option is an array, update only this specific key within it.',
					),
					'confirm_dangerous_action' => mcp_expose_dangerous_action_confirmation_schema( 'options/update' ),
				),
				'required'             => array( 'name', 'value' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'name'      => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
					'old_value' => array( 'description' => 'Previous value (for verification)' ),
					'new_value' => array( 'description' => 'New value after update' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = is_array( $input ) ? $input : array();
				$confirmation_error = mcp_expose_dangerous_action_error_response(
					mcp_expose_confirm_dangerous_action( $input, 'options/update' ),
					'options/update'
				);
				if ( null !== $confirmation_error ) {
					return $confirmation_error;
				}

				if ( empty( $input['name'] ) ) {
					return array( 'success' => false, 'name' => '', 'message' => esc_html__( 'Missing required parameter: name', 'mcp-expose-abilities' ) );
				}

				$name = sanitize_key( $input['name'] );

					if ( mcp_expose_is_sensitive_option_name( $name ) ) {
						return array(
							'success' => false,
							'name'    => $name,
							/* translators: %s: Option name. */
							'message' => esc_html( sprintf( __( "Option '%s' is protected and cannot be modified via MCP for security reasons.", 'mcp-expose-abilities' ), $name ) ),
					);
				}
				$new_value = $input['value'];
				$key       = isset( $input['key'] ) ? $input['key'] : null;
				$old_value = get_option( $name );

				// If updating a specific key within an array option
				if ( null !== $key && is_array( $old_value ) ) {
					$updated_value       = $old_value;
					$old_key_value       = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
					$updated_value[ $key ] = $new_value;

					$result = update_option( $name, $updated_value );

					return array(
						'success'   => $result,
						'name'      => $name,
						'key'       => $key,
						'message'   => $result ? "Updated key '{$key}' in option '{$name}'" : 'Update failed or value unchanged',
						'old_value' => $old_key_value,
						'new_value' => $new_value,
					);
				}

				// Update entire option
				$result = update_option( $name, $new_value );

				return array(
					'success'   => $result,
					'name'      => $name,
					'message'   => $result ? "Option '{$name}' updated successfully" : 'Update failed or value unchanged',
					'old_value' => $old_value,
					'new_value' => $new_value,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// OPTIONS - List Options (search)
	// =========================================================================
	wp_register_ability(
		'options/list',
		array(
			'label'               => 'List Options',
			'description'         => 'List options. Params: search (required, SQL LIKE pattern), per_page.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => 'Search pattern (SQL LIKE pattern, e.g., "rank_math%" or "%seo%").',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 200,
						'description' => 'Number of options to return.',
					),
				),
				'required'             => array( 'search' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'options' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name' => array( 'type' => 'string' ),
								'type' => array( 'type' => 'string' ),
								'size' => array( 'type' => 'integer', 'description' => 'Approximate size in bytes' ),
							),
						),
					),
					'total'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				global $wpdb;

				$input = is_array( $input ) ? $input : array();

				if ( empty( $input['search'] ) ) {
					return array( 'success' => false, 'options' => array(), 'total' => 0, 'message' => esc_html__( 'Missing search pattern', 'mcp-expose-abilities' ) );
				}

				$search   = $input['search'];
				$per_page = isset( $input['per_page'] ) ? min( (int) $input['per_page'], 200 ) : 50;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
						$search,
						$per_page
					),
					ARRAY_A
				);

				$options = array();
				foreach ( $results as $row ) {
					$value     = maybe_unserialize( $row['option_value'] );
					$options[] = array(
						'name' => $row['option_name'],
						'type' => gettype( $value ),
						'size' => strlen( $row['option_value'] ),
					);
				}

				return array(
					'success' => true,
					'options' => $options,
					'total'   => count( $options ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// COMMENTS - List
	// =========================================================================
	wp_register_ability(
		'comments/list',
		array(
			'label'               => 'List Comments',
			'description'         => 'List comments. Params: status, post_id, author_email, per_page, page, search (all optional).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'  => array(
						'type'        => 'string',
						'enum'        => array( 'approve', 'hold', 'spam', 'trash', 'all' ),
						'default'     => 'all',
						'description' => 'Filter by comment status. "approve" = approved, "hold" = pending moderation.',
					),
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by post ID.',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of comments to return.',
					),
					'orderby' => array(
						'type'        => 'string',
						'enum'        => array( 'comment_date', 'comment_ID' ),
						'default'     => 'comment_date',
						'description' => 'Field to order by.',
					),
					'order'   => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'default'     => 'DESC',
						'description' => 'Sort order.',
					),
				),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'comments' => array( 'type' => 'array' ),
						'total'    => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => function ( array $params ): array {
				$args = array(
					'number'  => $params['per_page'] ?? 20,
					'orderby' => $params['orderby'] ?? 'comment_date',
					'order'   => $params['order'] ?? 'DESC',
				);

				if ( ! empty( $params['status'] ) && 'all' !== $params['status'] ) {
					$args['status'] = $params['status'];
				}

				if ( ! empty( $params['post_id'] ) ) {
					$args['post_id'] = $params['post_id'];
				}

				$comments = get_comments( $args );
				$data     = array();

				foreach ( $comments as $comment ) {
					$data[] = array(
						'id'           => (int) $comment->comment_ID,
						'post_id'      => (int) $comment->comment_post_ID,
						'post_title'   => get_the_title( $comment->comment_post_ID ),
						'author'       => $comment->comment_author,
						'author_email' => $comment->comment_author_email,
						'content'      => $comment->comment_content,
						'status'       => wp_get_comment_status( $comment ),
						'date'         => $comment->comment_date,
						'parent'       => (int) $comment->comment_parent,
					);
				}

				return array(
					'success'  => true,
					'comments' => $data,
					'total'    => count( $data ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// COMMENTS - Get
	// =========================================================================
	wp_register_ability(
		'comments/get',
		array(
			'label'               => 'Get Comment',
			'description'         => 'Get comment. Params: id (required).',
			'category'            => 'site',
				'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'The comment ID.',
					),
				),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'comment' => array( 'type' => 'object' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'            => function ( array $params ): array {
				$comment = get_comment( $params['id'] );

				if ( ! $comment ) {
					return array(
						'success' => false,
						'error'   => 'Comment not found.',
					);
				}

				if ( ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
					return array(
						'success' => false,
						'error'   => 'You do not have permission to access this comment.',
					);
				}

				return array(
					'success' => true,
					'comment' => array(
						'id'           => (int) $comment->comment_ID,
						'post_id'      => (int) $comment->comment_post_ID,
						'post_title'   => get_the_title( $comment->comment_post_ID ),
						'author'       => $comment->comment_author,
						'author_email' => $comment->comment_author_email,
						'author_url'   => $comment->comment_author_url,
						'author_ip'    => $comment->comment_author_IP,
						'content'      => $comment->comment_content,
						'status'       => wp_get_comment_status( $comment ),
						'date'         => $comment->comment_date,
						'parent'       => (int) $comment->comment_parent,
						'user_id'      => (int) $comment->user_id,
					),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// COMMENTS - Approve/Update Status
	// =========================================================================
	wp_register_ability(
		'comments/update-status',
		array(
			'label'               => 'Update Comment Status',
			'description'         => 'Approves, holds, spams, or trashes a comment.',
			'category'            => 'site',
				'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'The comment ID.',
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'approve', 'hold', 'spam', 'trash' ),
						'description' => 'New status: approve (publish), hold (pending), spam, or trash.',
					),
				),
					'required'             => array( 'id', 'status' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'comment_id' => array( 'type' => 'integer' ),
						'new_status' => array( 'type' => 'string' ),
						'message'    => array( 'type' => 'string' ),
						'error'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'            => function ( array $params ): array {
				$comment = get_comment( $params['id'] );

				if ( ! $comment ) {
					return array(
						'success' => false,
						'error'   => 'Comment not found.',
					);
				}

				if ( ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
					return array(
						'success' => false,
						'error'   => 'You do not have permission to update this comment.',
					);
				}

				// Map status to WordPress values.
				$status_map = array(
					'approve' => 1,
					'hold'    => 0,
					'spam'    => 'spam',
					'trash'   => 'trash',
				);

				$result = wp_set_comment_status( $params['id'], $status_map[ $params['status'] ] );

				if ( ! $result ) {
					return array(
						'success' => false,
						'error'   => 'Failed to update comment status.',
					);
				}

				return array(
					'success'    => true,
					'comment_id' => $params['id'],
					'new_status' => $params['status'],
					'message'    => 'Comment status updated.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// COMMENTS - Reply
	// =========================================================================
	wp_register_ability(
		'comments/reply',
		array(
			'label'               => 'Reply to Comment',
			'description'         => 'Posts a reply to an existing comment.',
			'category'            => 'site',
				'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'parent_id' => array(
						'type'        => 'integer',
						'description' => 'The parent comment ID to reply to.',
					),
					'content'   => array(
						'type'        => 'string',
						'description' => 'The reply content.',
					),
					'author'    => array(
						'type'        => 'string',
						'description' => 'Author name for the reply.',
					),
					'email'     => array(
						'type'        => 'string',
						'description' => 'Author email for the reply.',
					),
					'user_id'   => array(
						'type'        => 'integer',
						'description' => 'WordPress user ID to associate with the comment. Defaults to authenticated user.',
					),
				),
					'required'             => array( 'parent_id', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'comment_id' => array( 'type' => 'integer' ),
						'message'    => array( 'type' => 'string' ),
						'error'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'            => function ( array $params ): array {
				$parent = get_comment( $params['parent_id'] );

				if ( ! $parent ) {
					return array(
						'success' => false,
						'error'   => 'Parent comment not found.',
					);
				}

				if ( ! current_user_can( 'edit_comment', $parent->comment_ID ) ) {
					return array(
						'success' => false,
						'error'   => 'You do not have permission to reply to this comment.',
					);
				}

				$user = wp_get_current_user();

				// Use provided user_id or fall back to authenticated user.
				$comment_user_id = $params['user_id'] ?? $user->ID;
				$comment_user    = $comment_user_id !== $user->ID ? get_userdata( $comment_user_id ) : $user;

				if ( ! $comment_user && isset( $params['user_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'User ID ' . $params['user_id'] . ' not found.',
					);
				}

				if ( $comment_user_id !== $user->ID && ! current_user_can( 'edit_user', $comment_user_id ) ) {
					return array(
						'success' => false,
						'error'   => 'You do not have permission to post as this user.',
					);
				}

				$comment_data = array(
					'comment_post_ID'      => $parent->comment_post_ID,
					'comment_content'      => $params['content'],
					'comment_parent'       => $params['parent_id'],
					'comment_author'       => $params['author'] ?? $comment_user->display_name,
					'comment_author_email' => $params['email'] ?? $comment_user->user_email,
					'user_id'              => $comment_user_id,
					'comment_approved'     => 1,
				);

				$comment_id = wp_insert_comment( $comment_data );

				if ( ! $comment_id ) {
					return array(
						'success' => false,
						'error'   => 'Failed to create reply.',
					);
				}

				return array(
					'success'    => true,
					'comment_id' => $comment_id,
					'message'    => 'Reply posted successfully.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// COMMENTS - Create
	// =========================================================================
	wp_register_ability(
		'comments/create',
		array(
			'label'               => 'Create Comment',
			'description'         => 'Create comment. Params: post_id, content (required), author, email, user_id, parent_id.',
			'category'            => 'site',
				'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'   => array(
						'type'        => 'integer',
						'description' => 'The post ID to comment on.',
					),
					'content'   => array(
						'type'        => 'string',
						'description' => 'The comment content.',
					),
					'author'    => array(
						'type'        => 'string',
						'description' => 'Author name for the comment.',
					),
					'email'     => array(
						'type'        => 'string',
						'description' => 'Author email for the comment.',
					),
					'user_id'   => array(
						'type'        => 'integer',
						'description' => 'WordPress user ID to associate with the comment. Defaults to authenticated user.',
					),
					'parent_id' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Parent comment ID for threading (0 for top-level).',
					),
				),
					'required'             => array( 'post_id', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'comment_id' => array( 'type' => 'integer' ),
						'message'    => array( 'type' => 'string' ),
						'error'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => function ( array $params ): array {
				$post = get_post( $params['post_id'] );

				if ( ! $post ) {
					return array(
						'success' => false,
						'error'   => 'Post not found.',
					);
				}

				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					return array(
						'success' => false,
						'error'   => 'You do not have permission to comment on this post.',
					);
				}

				$user = wp_get_current_user();

				// Use provided user_id or fall back to authenticated user.
				$comment_user_id = $params['user_id'] ?? $user->ID;
				$comment_user    = $comment_user_id !== $user->ID ? get_userdata( $comment_user_id ) : $user;

				if ( ! $comment_user && isset( $params['user_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'User ID ' . $params['user_id'] . ' not found.',
					);
				}

				if ( $comment_user_id !== $user->ID && ! current_user_can( 'edit_user', $comment_user_id ) ) {
					return array(
						'success' => false,
						'error'   => 'You do not have permission to post as this user.',
					);
				}

				$comment_data = array(
					'comment_post_ID'      => $params['post_id'],
					'comment_content'      => $params['content'],
					'comment_parent'       => $params['parent_id'] ?? 0,
					'comment_author'       => $params['author'] ?? $comment_user->display_name,
					'comment_author_email' => $params['email'] ?? $comment_user->user_email,
					'user_id'              => $comment_user_id,
					'comment_approved'     => 1,
				);

				$comment_id = wp_insert_comment( $comment_data );

				if ( ! $comment_id ) {
					return array(
						'success' => false,
						'error'   => 'Failed to create comment.',
					);
				}

				return array(
					'success'    => true,
					'comment_id' => $comment_id,
					'message'    => 'Comment created successfully.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);


	// =========================================================================
	// TAXONOMIES - Associate with Post Type
	// =========================================================================
	// Associates a taxonomy with a post type. Some plugins register taxonomies
	// that aren't automatically associated with all post types.
	// =========================================================================
	wp_register_ability(
		'taxonomy/associate-with-post-type',
		array(
			'label'               => 'Associate Taxonomy with Post Type',
			'description'         => 'Associates a taxonomy with a post type. Required when taxonomies from Toolset or other plugins are not automatically available for a post type.',
			'category'            => 'site',
				'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy'  => array(
						'type'        => 'string',
						'description' => 'The taxonomy name to associate.',
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'The post type name to associate the taxonomy with.',
					),
				),
					'required'             => array( 'taxonomy', 'post_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'            => function ( array $params ): array {
				$taxonomy  = sanitize_key( $params['taxonomy'] );
				$post_type = sanitize_key( $params['post_type'] );

				if ( ! taxonomy_exists( $taxonomy ) ) {
					return array(
						'success' => false,
						'error'   => "Taxonomy '{$taxonomy}' does not exist.",
					);
				}

				if ( ! post_type_exists( $post_type ) ) {
					return array(
						'success' => false,
						'error'   => "Post type '{$post_type}' does not exist.",
					);
				}

				// Store association persistently
				$stored = get_option( 'mcp_taxonomy_associations', array() );
				$new_assoc = array( 'taxonomy' => $taxonomy, 'post_type' => $post_type );

				// Check if already stored
				$already_exists = false;
				foreach ( $stored as $existing ) {
					if ( $existing['taxonomy'] === $taxonomy && $existing['post_type'] === $post_type ) {
						$already_exists = true;
						break;
					}
				}

				if ( ! $already_exists ) {
					$stored[] = $new_assoc;
					update_option( 'mcp_taxonomy_associations', $stored );
				}

				// Apply association immediately
				$result = register_taxonomy_for_object_type( $taxonomy, $post_type );

				// Also update Toolset wpcf-custom-types if it exists for this post type
				$wpcf_types = get_option( 'wpcf-custom-types', array() );
				if ( isset( $wpcf_types[ $post_type ] ) ) {
					$taxonomies = $wpcf_types[ $post_type ]['taxonomies'] ?? array();
					if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
						$taxonomies[] = $taxonomy;
						$wpcf_types[ $post_type ]['taxonomies'] = $taxonomies;
						update_option( 'wpcf-custom-types', $wpcf_types );
					}
				}

				if ( $result || $already_exists ) {
					return array(
						'success' => true,
						'message' => $already_exists
							? "Taxonomy '{$taxonomy}' was already associated with post type '{$post_type}'."
							: "Taxonomy '{$taxonomy}' is now associated with post type '{$post_type}'.",
					);
				} else {
					return array(
						'success' => false,
						'error'   => "Failed to associate taxonomy. The taxonomy may not support this post type.",
					);
				}
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);


	// =========================================================================
	// COMMENTS - Delete
	// =========================================================================
	wp_register_ability(
		'comments/delete',
		array(
			'label'               => 'Delete Comment',
			'description'         => 'Permanently deletes a comment.',
			'category'            => 'site',
				'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'The comment ID to delete.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, permanently delete. If false, move to trash.',
					),
				),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'            => function ( array $params ): array {
				$comment = get_comment( $params['id'] );

				if ( ! $comment ) {
					return array(
						'success' => false,
						'error'   => 'Comment not found.',
					);
				}

				if ( ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
					return array(
						'success' => false,
						'error'   => 'You do not have permission to delete this comment.',
					);
				}

				$force  = $params['force'] ?? false;
				$result = wp_delete_comment( $params['id'], $force );

				if ( ! $result ) {
					return array(
						'success' => false,
						'error'   => 'Failed to delete comment.',
					);
				}

				return array(
					'success' => true,
					'message' => $force ? esc_html__( 'Comment permanently deleted.', 'mcp-expose-abilities' ) : esc_html__( 'Comment moved to trash.', 'mcp-expose-abilities' ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'moderate_comments' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);


	// =========================================================================
	// Apply stored taxonomy-post type associations on init
	// =========================================================================
	$stored = get_option( 'mcp_taxonomy_associations', array() );
	if ( ! empty( $stored ) && is_array( $stored ) ) {
		foreach ( $stored as $assoc ) {
			if ( ! empty( $assoc['taxonomy'] ) && ! empty( $assoc['post_type'] ) ) {
				register_taxonomy_for_object_type( $assoc['taxonomy'], $assoc['post_type'] );
			}
		}
	}

}


/**
 * Apply taxonomy associations on WordPress init.
 * This ensures associations persist across requests.
 */
function mcp_apply_taxonomy_associations_init(): void {
	$stored = get_option( 'mcp_taxonomy_associations', array() );
	if ( ! empty( $stored ) && is_array( $stored ) ) {
		foreach ( $stored as $assoc ) {
			if ( ! empty( $assoc['taxonomy'] ) && ! empty( $assoc['post_type'] ) ) {
				register_taxonomy_for_object_type( $assoc['taxonomy'], $assoc['post_type'] );
			}
		}
	}
}
add_action( 'init', 'mcp_apply_taxonomy_associations_init', 20 );

add_action( 'wp_abilities_api_init', 'mcp_register_content_abilities' );
