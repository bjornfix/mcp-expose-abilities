<?php
/**
 * Narrow native core/theme maintenance and one fixed configuration constant.
 *
 * @package MCP_Expose_Abilities
 */
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Require native administrator and file-modification authority for a fixed task. */
function mcp_expose_maintenance_permission( string $capability ): bool {
	return current_user_can( 'manage_options' ) && current_user_can( $capability ) && wp_is_file_mod_allowed( 'mcp_native_maintenance' );
}

/** Accept only official WordPress publisher download origins, without credentials. */
function mcp_expose_official_maintenance_package( string $url, string $kind, string $slug = '', string $version = '' ): bool {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] )
		|| isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['port'] ) ) { return false; }
	$host = strtolower( $parts['host'] ?? '' );
	$path = $parts['path'] ?? '';
	if ( 'theme' === $kind ) {
		return 'downloads.wordpress.org' === $host && '/theme/' . $slug . '.' . $version . '.zip' === $path;
	}
	return in_array( $host, array( 'downloads.wordpress.org', 'wordpress.org' ), true )
		&& '' !== $version && (bool) preg_match( '~^/(?:release/(?:[A-Za-z_]+/)?|)wordpress-' . preg_quote( $version, '~' ) . '(?:-[A-Za-z0-9._-]+)?\.zip$~D', $path );
}

/** Read only the installed native WordPress version after an upgrade. */
function mcp_expose_installed_core_version(): string {
	require ABSPATH . WPINC . '/version.php';
	return (string) $wp_version;
}

/** Update only the selected currently offered stable WordPress release. */
function mcp_expose_update_wordpress_core( $input = array() ): array {
	$ability = 'system/update-wordpress-core';
	if ( ! mcp_expose_maintenance_permission( 'update_core' ) ) { return array( 'success' => false, 'code' => 'permission_denied', 'software_changed' => false ); }
	$confirmation = mcp_expose_dangerous_action_error_response( mcp_expose_confirm_dangerous_action( (array) $input, $ability ), $ability );
	if ( null !== $confirmation ) { return $confirmation; }
	$target = (string) ( $input['target_version'] ?? '' );
	if ( ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $target ) ) { return array( 'success' => false, 'code' => 'invalid_stable_version', 'software_changed' => false ); }
	$stage = 'preflight'; $attempted = false; $before = null;
	try {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$before = mcp_expose_installed_core_version();
		if ( $target === $before ) { return array( 'success' => true, 'updated' => false, 'current_version' => $before, 'verification_scope' => 'installed_version' ); }
		if ( ( $input['expected_current_version'] ?? null ) !== $before ) { return array( 'success' => false, 'code' => 'stale_installed_version', 'software_changed' => false, 'current_version' => $before ); }
		$offer = get_preferred_from_update_core();
		if ( ! is_object( $offer ) || 'upgrade' !== ( $offer->response ?? '' ) || $target !== ( $offer->current ?? '' ) || ! version_compare( $target, $before, '>' ) ) {
			return array( 'success' => false, 'code' => 'stable_offer_changed_or_unavailable', 'software_changed' => false );
		}
		$packages = (array) ( $offer->packages ?? array() );
		if ( empty( $packages['full'] ) ) { return array( 'success' => false, 'code' => 'official_package_unavailable', 'software_changed' => false ); }
		foreach ( $packages as $kind => $package ) {
			$package_version = 'rollback' === $kind ? $before : $target;
			if ( ! empty( $package ) && ( ! is_string( $package ) || ! mcp_expose_official_maintenance_package( $package, 'core', '', $package_version ) ) ) { return array( 'success' => false, 'code' => 'non_official_package', 'software_changed' => false ); }
		}
		$stage = 'native_upgrade'; $attempted = true;
		$skin = new WP_Ajax_Upgrader_Skin();
		$result = ( new Core_Upgrader( $skin ) )->upgrade( $offer, array( 'pre_check_md5' => true, 'attempt_rollback' => true ) );
		$stage = 'readback';
		$after = mcp_expose_installed_core_version();
		$verified = ! is_wp_error( $result ) && false !== $result && $target === $after;
		return array( 'success' => $verified, 'updated' => $before !== $after, 'previous_version' => $before, 'current_version' => $after,
			'write_outcome' => 'read_back', 'verification_required' => ! $verified, 'native_upgrade_confirmed' => ! is_wp_error( $result ) && false !== $result );
	} catch ( Throwable $error ) {
		$after = null;
		if ( $attempted ) {
			try { $after = mcp_expose_installed_core_version(); } catch ( Throwable $read_error ) { $after = null; }
		}
		return array( 'success' => false, 'failed_stage' => $stage, 'software_changed' => null !== $after ? $before !== $after : ( $attempted ? null : false ),
			'previous_version' => $before, 'current_version' => $after, 'write_outcome' => null !== $after ? 'read_back' : ( $attempted ? 'unknown' : 'not_attempted' ), 'verification_required' => $attempted );
	}
}

/** Update an installed theme from its exact official native offer; never switch themes. */
function mcp_expose_update_theme( $input = array() ): array {
	$ability = 'themes/update';
	if ( ! mcp_expose_maintenance_permission( 'update_themes' ) ) { return array( 'success' => false, 'code' => 'permission_denied', 'software_changed' => false ); }
	$confirmation = mcp_expose_dangerous_action_error_response( mcp_expose_confirm_dangerous_action( (array) $input, $ability ), $ability );
	if ( null !== $confirmation ) { return $confirmation; }
	$stylesheet = (string) ( $input['stylesheet'] ?? '' );
	$target = (string) ( $input['target_version'] ?? '' );
	if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $stylesheet ) || '' === $target || strlen( $target ) > 64 ) { return array( 'success' => false, 'code' => 'invalid_theme', 'software_changed' => false ); }
	$stage = 'preflight'; $attempted = false; $before = null;
	try {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$installed = wp_get_themes();
		if ( ! isset( $installed[ $stylesheet ] ) ) { return array( 'success' => false, 'code' => 'theme_not_installed', 'software_changed' => false ); }
		$before = (string) $installed[ $stylesheet ]->get( 'Version' );
		if ( $target === $before ) { return array( 'success' => true, 'updated' => false, 'theme' => $stylesheet, 'current_version' => $before, 'verification_scope' => 'installed_version' ); }
		if ( ( $input['expected_current_version'] ?? null ) !== $before ) { return array( 'success' => false, 'code' => 'stale_installed_version', 'software_changed' => false ); }
		$cache = get_site_transient( 'update_themes' );
		$offer = is_object( $cache ) && isset( $cache->response[ $stylesheet ] ) ? (object) $cache->response[ $stylesheet ] : null;
		if ( ! $offer || $target !== ( $offer->new_version ?? '' ) || ! version_compare( $target, $before, '>' ) ) { return array( 'success' => false, 'code' => 'theme_offer_changed_or_unavailable', 'software_changed' => false ); }
		if ( ! mcp_expose_official_maintenance_package( (string) ( $offer->package ?? '' ), 'theme', $stylesheet, $target ) ) { return array( 'success' => false, 'code' => 'non_official_package', 'software_changed' => false ); }
		$active_stylesheet = get_stylesheet(); $active_template = get_template();
		$stage = 'native_upgrade'; $attempted = true;
		$skin = new WP_Ajax_Upgrader_Skin();
		$result = ( new Theme_Upgrader( $skin ) )->upgrade( $stylesheet );
		$stage = 'readback';
		wp_clean_themes_cache();
		$after = (string) wp_get_theme( $stylesheet )->get( 'Version' );
		$preserved = $active_stylesheet === get_stylesheet() && $active_template === get_template();
		$verified = true === $result && $target === $after && $preserved;
		return array( 'success' => $verified, 'updated' => $before !== $after, 'theme' => $stylesheet,
			'previous_version' => $before, 'current_version' => $after, 'active_theme_preserved' => $preserved,
			'write_outcome' => 'read_back', 'verification_required' => ! $verified, 'native_upgrade_confirmed' => true === $result );
	} catch ( Throwable $error ) {
		$after = null; $preserved = null;
		if ( $attempted ) {
			try {
				wp_clean_themes_cache();
				$after = (string) wp_get_theme( $stylesheet )->get( 'Version' );
				$preserved = $active_stylesheet === get_stylesheet() && $active_template === get_template();
			} catch ( Throwable $read_error ) { $after = null; }
		}
		return array( 'success' => false, 'failed_stage' => $stage, 'software_changed' => null !== $after ? $before !== $after : ( $attempted ? null : false ),
			'theme' => $stylesheet, 'previous_version' => $before, 'current_version' => $after, 'active_theme_preserved' => $preserved,
			'write_outcome' => null !== $after ? 'read_back' : ( $attempted ? 'unknown' : 'not_attempted' ), 'verification_required' => $attempted );
	}
}

/** Replace one unconditional literal definition. Comments, strings and dynamic definitions are not editable. */
function mcp_expose_patch_core_auto_update_constant( string $content ): ?array {
	$tokens = token_get_all( $content, TOKEN_PARSE );
	$significant = array(); $offset = 0; $curly = 0; $parentheses = 0;
	foreach ( $tokens as $token ) {
		$text = is_array( $token ) ? $token[1] : $token;
		$id = is_array( $token ) ? $token[0] : null;
		if ( ! in_array( $id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$significant[] = array( 'text' => $text, 'id' => $id, 'offset' => $offset, 'curly' => $curly, 'parentheses' => $parentheses );
		}
		if ( null === $id ) {
			if ( '{' === $text ) { ++$curly; } elseif ( '}' === $text ) { --$curly; }
			if ( '(' === $text ) { ++$parentheses; } elseif ( ')' === $text ) { --$parentheses; }
		}
		$offset += strlen( $text );
	}
	$definitions = array();
	foreach ( $significant as $index => $token ) {
		if ( 'define' !== strtolower( ltrim( $token['text'], '\\' ) ) ) { continue; }
		$name = $significant[ $index + 2 ]['text'] ?? '';
		if ( ! in_array( $name, array( "'WP_AUTO_UPDATE_CORE'", '"WP_AUTO_UPDATE_CORE"' ), true ) ) { continue; }
		$previous = $significant[ $index - 1 ] ?? null;
		$value = $significant[ $index + 4 ] ?? array();
		$simple = 0 === $token['curly'] && 0 === $token['parentheses']
			&& ( null === $previous || T_OPEN_TAG === $previous['id'] || in_array( $previous['text'], array( ';', '}' ), true ) )
			&& '(' === ( $significant[ $index + 1 ]['text'] ?? '' ) && ',' === ( $significant[ $index + 3 ]['text'] ?? '' )
			&& in_array( strtolower( $value['text'] ?? '' ), array( 'true', 'false' ), true )
			&& ')' === ( $significant[ $index + 5 ]['text'] ?? '' ) && ';' === ( $significant[ $index + 6 ]['text'] ?? '' );
		$definitions[] = $simple ? $value : null;
	}
	if ( 1 !== count( $definitions ) || null === $definitions[0] ) { return null; }
	$value = $definitions[0];
	$before = 'true' === strtolower( $value['text'] );
	return array( 'before' => $before, 'after' => true,
		'content' => $before ? $content : substr_replace( $content, 'true', $value['offset'], strlen( $value['text'] ) ) );
}

/** Enable only WP_AUTO_UPDATE_CORE=true using fixed-path native filesystem access. */
function mcp_expose_set_core_auto_update_policy( $input = array() ): array {
	$ability = 'system/set-core-auto-update-policy';
	if ( ! mcp_expose_maintenance_permission( 'update_core' ) ) { return array( 'success' => false, 'code' => 'permission_denied', 'applied' => false ); }
	if ( true !== ( $input['enabled'] ?? null ) ) { return array( 'success' => false, 'code' => 'only_enable_supported', 'applied' => false ); }
	$confirm = (string) ( $input['confirm_dangerous_action'] ?? '' );
	if ( '' !== $confirm && $ability !== $confirm ) { return mcp_expose_dangerous_action_error_response( mcp_expose_confirm_dangerous_action( (array) $input, $ability ), $ability ); }
	$path = ABSPATH . 'wp-config.php'; $temporary = null; $locked = false; $attempted = false;
	try {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) || 'direct' !== $wp_filesystem->method || ! $wp_filesystem->exists( $path ) ) { return array( 'success' => false, 'code' => 'direct_config_access_unavailable', 'applied' => false ); }
		$content = $wp_filesystem->get_contents( $path );
		if ( ! is_string( $content ) ) { return array( 'success' => false, 'code' => 'config_read_unavailable', 'applied' => false ); }
		$patch = mcp_expose_patch_core_auto_update_constant( $content );
		if ( null === $patch || $patch['before'] !== mcp_expose_core_auto_update_constant() ) { return array( 'success' => false, 'code' => 'unsupported_or_shadowed_config_definition', 'applied' => false ); }
		$hash = hash( 'sha256', $content );
		$summary = array( 'before' => $patch['before'], 'after' => true, 'config_hash' => $hash, 'raw_config_returned' => false );
		if ( '' === $confirm ) { return array( 'success' => true, 'applied' => false, 'preview' => true ) + $summary; }
		if ( $patch['before'] ) { return array( 'success' => true, 'applied' => false, 'unchanged' => true ) + $summary; }
		if ( ! hash_equals( $hash, (string) ( $input['expected_config_hash'] ?? '' ) ) ) { return array( 'success' => false, 'code' => 'stale_config', 'applied' => false ); }
		if ( ! $wp_filesystem->is_writable( $path ) || ! $wp_filesystem->is_writable( ABSPATH ) ) { return array( 'success' => false, 'code' => 'config_not_writable', 'applied' => false ); }
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$locked = WP_Upgrader::create_lock( 'mcp_core_policy', 60 );
		if ( ! $locked ) { return array( 'success' => false, 'code' => 'config_write_in_progress', 'applied' => false ); }
		if ( $content !== $wp_filesystem->get_contents( $path ) ) { return array( 'success' => false, 'code' => 'stale_config', 'applied' => false ); }
		// A PHP suffix avoids a plaintext configuration file in the document root.
		$temporary = ABSPATH . '.mcp-core-policy-' . wp_generate_uuid4() . '.php';
		$permissions = $wp_filesystem->getchmod( $path );
		if ( ! is_string( $permissions ) || ! preg_match( '/^[0-7]{3,4}$/D', $permissions ) ) { return array( 'success' => false, 'code' => 'config_metadata_unavailable', 'applied' => false ); }
		$mode = octdec( $permissions );
		if ( ! $wp_filesystem->put_contents( $temporary, $patch['content'], $mode ) || $patch['content'] !== $wp_filesystem->get_contents( $temporary ) ) { return array( 'success' => false, 'code' => 'config_prepare_failed', 'applied' => false ); }
		$owner = $wp_filesystem->owner( $path ); $group = $wp_filesystem->group( $path );
		if ( false === $owner || false === $group || $owner !== $wp_filesystem->owner( $temporary ) || $group !== $wp_filesystem->group( $temporary ) || $mode !== octdec( $wp_filesystem->getchmod( $temporary ) ) ) {
			return array( 'success' => false, 'code' => 'config_metadata_cannot_be_preserved', 'applied' => false );
		}
		if ( $content !== $wp_filesystem->get_contents( $path ) ) { return array( 'success' => false, 'code' => 'stale_config', 'applied' => false ); }
		$attempted = true;
		$moved = $wp_filesystem->move( $temporary, $path, true );
		if ( $moved ) { wp_opcache_invalidate( $path, true ); }
		$after = $wp_filesystem->get_contents( $path );
		$verified = $moved && $patch['content'] === $after;
		$applied = $patch['content'] === $after ? true : ( $content === $after ? false : null );
		return array( 'success' => $verified, 'applied' => $applied, 'before' => $patch['before'], 'after' => true === $applied ? true : ( false === $applied ? $patch['before'] : null ),
			'write_outcome' => is_string( $after ) ? 'read_back' : 'unknown', 'verification_required' => true,
			'effective_policy_requires_fresh_request' => true, 'raw_config_returned' => false );
	} catch ( Throwable $error ) {
		$applied = $attempted ? null : false;
		if ( $attempted && isset( $patch ) ) {
			try {
				$readback = $wp_filesystem->get_contents( $path );
				$applied = $patch['content'] === $readback ? true : ( $content === $readback ? false : null );
			} catch ( Throwable $read_error ) { $applied = null; }
		}
		return array( 'success' => false, 'code' => 'native_config_operation_failed', 'applied' => $applied,
			'write_outcome' => null !== $applied ? 'read_back' : 'unknown', 'verification_required' => $attempted, 'raw_config_returned' => false );
	} finally {
		try {
			if ( null !== $temporary && isset( $wp_filesystem ) && $wp_filesystem->exists( $temporary ) ) { $wp_filesystem->delete( $temporary ); }
			if ( $locked ) { WP_Upgrader::release_lock( 'mcp_core_policy' ); }
		} catch ( Throwable $cleanup_error ) {
			// Never expose native paths or error text from cleanup. The native lock expires.
		}
	}
}
