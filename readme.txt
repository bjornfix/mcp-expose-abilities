=== MCP Expose Abilities ===
Contributors: basicus
Tags: mcp, ai, automation, content, rest-api
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 3.0.83
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI assistants edit your WordPress site via MCP.

== Description ==

This plugin exposes WordPress functionality through MCP (Model Context Protocol), enabling AI assistants to directly interact with your WordPress site. No more copy-pasting between chat and admin.

Core WordPress abilities for content, menus, users, media, widgets, plugins, options, and system management.

= Compatibility =

* Requires WordPress 6.9 or newer
* Tested up to WordPress 7.0
* Requires PHP 8.0 or newer
* Maintained against the WordPress 6.9 release line together with the supported add-on plugins

== Installation ==

1. Confirm WordPress 6.9 or later is active and provides the WordPress Abilities API
2. Install and activate WordPress MCP Adapter from https://github.com/WordPress/mcp-adapter/
3. Download https://downloads.devenia.com/mcp-expose-abilities.zip
4. Upload the ZIP through WordPress Admin > Plugins > Add New > Upload Plugin
5. Activate the plugin
6. Optionally install add-on plugins for vendor-specific features

= Supported Add-ons =

Install add-ons only when your site actually uses that product:

* Elementor: mcp-abilities-elementor
* GeneratePress / GenerateBlocks: mcp-abilities-generatepress
* Cloudflare: mcp-abilities-cloudflare
* Google Workspace: mcp-abilities-workspace
* Rank Math: mcp-abilities-rankmath
* Wordfence: mcp-abilities-wordfence
* Brevo: mcp-abilities-brevo
* Toolset: mcp-abilities-toolset
* WPML / SitePress: mcp-abilities-sitepress
* Formidable Forms: mcp-abilities-formidable
* Store Locator: mcp-abilities-store-locator

== Changelog ==

= 3.0.83 =
* Restored: confirmed plugin installs from the official WordPress.org directory no longer require the global arbitrary-code opt-in.
* Security: URL/base64 plugin uploads and plugin deletion remain behind the server-side code-write gate; plugin updates keep their signed-policy or global opt-in requirement.

= 3.0.82 =
* Hardened: `media/upload-base64` verifies the declared type against the uploaded bytes, rejects unsupported SVG uploads, checks access to an optional parent post, and cleans up failed attachments.

= 3.0.81 =
* Added: an Application Password-authenticated caller can revoke only the credential authenticating its current request.
* Security: self-revocation requires explicit confirmation, takes no user ID or UUID input, and reads the captured Core UUID back as absent before reporting success.

= 3.0.80 =
* Added: callers who can manage users and edit the exact target user can create one WordPress Application Password through `users/create-restricted-application-password`, but only for a user without generic WordPress Core write authority.
* Security: credential creation requires exact confirmation and returns the secret only as a libsodium sealed box for the supplied recipient key.

= 3.0.79 =
* Route create, update, patch, and revision restore through one vendor-neutral Content Write Gate.
* Keep only vendor-neutral Core layout markers in the public plugin and union Adapter markers.

= 3.0.78 =
* Added: published source pages are validated through the canonical registered source-design Interface before `content/create-page` or `content/update-page` mutates WordPress.
* Changed: explicit `full_rebuild` intent still permits complete replacement, but it no longer bypasses source-page design validation.
* Added: neutral site adapters can extend guarded design markers and reject a proposed content write before mutation without adding site policy to this public plugin.
* Changed: manifest-gated plugin-update decisions now use a neutral update-policy filter.
* Fixed: `content/update-page` validates a requested template before changing page content, preventing an invalid template from leaving a partial update behind.

= 3.0.77 =
* Added: `content/update-post` and `content/update-page` accept an explicit `content_write_mode=full_rebuild` intent for complete design replacements while ordinary guarded writes still block accidental design-markup loss.

= 3.0.76 =
* Fixed: `content/patch-post` and `content/patch-page` clear stale invalid assigned-template metadata immediately before content writes, preventing successful patches from returning an `Invalid page template` error.

= 3.0.75 =
* Added: `comments/update-author-url` narrowly updates or clears one comment author URL with per-comment permission checks and write verification.

= 3.0.74 =
* Added: `plugins/list-updates` accepts `force_refresh` to refresh WordPress and registered updater data through a typed Interface before listing available updates.

= 3.0.73 =
* Fixed: `content/patch-post` now passes correctly slashed Gutenberg content to WordPress, keeping design-neutral approval hashes stable through the downstream source publish gate.

= 3.0.72 =
* Deepened: design-neutral source patch approval now has one shared approval path for MCP preflight and the downstream source publish gate filter.

= 3.0.71 =
* Fixed: design-neutral `content/patch-post` writes can carry hash-bound approval through registered site policy.

= 3.0.70 =
* Added: `content/patch-post` can perform an explicitly justified design-neutral patch on source content when registered site policy confirms the patch does not worsen validation.

= 3.0.69 =
* Fixed: `content/update-post` and `content/update-page` no longer call `wp_update_post()` for featured-image-only or taxonomy-only updates, avoiding unrelated publish/design hooks on partial updates.

= 3.0.68 =
* Added: Dry-run support for MCP post create, update, and patch writes.
* Fixed: registered source-content policy preflight now also blocks status-only publishes of invalid drafts.

= 3.0.67 =
* Added: optional site-policy preflight for MCP post create/update/patch writes.

= 3.0.66 =
* Added: `content/update-tag` for correcting tag names, slugs, and descriptions through MCP without direct REST or database access.

= 3.0.65 =
* Fixed: `content/update-post` and `content/update-page` now protect translated sibling content and critical Elementor/featured-image meta from WPML/Polylang-style sync hooks during generic updates.
* Added: content update responses include `translation_guard` details showing which translated siblings were protected and restored.

= 3.0.64 =
* Fixed: `meta/update-post-meta` and `meta/delete-post-meta` now trigger a post refresh for SEO indexable rebuilds when `_yoast_wpseo_*` fields change.

= 3.0.63 =
* Added: `meta/get-post-meta` reads explicit post meta keys with per-post capability checks for narrow diagnostics.

= 3.0.62 =
* Fixed: `content/restore-post` and `content/update-page` now clear stale invalid assigned page-template metadata before WordPress status/content writes, so trashed legacy pages from old themes can be restored and updated safely.

= 3.0.61 =

* Fixed: `content/list-pages` now accepts the documented `search` parameter and passes it through to the WordPress page query.

= 3.0.60 =
* Added: `content/list-posts` now supports `status:trash` for explicit trash inspection.
* Added: `content/restore-post` restores posts, pages, and custom post types from trash with per-post edit permission checks.

= 3.0.59 =
* Changed: plugin-code-write guards now pass structured ability name and input to a dedicated filter, allowing trusted upload gates without request-body parsing or stack inspection.
* Fixed: plugin uploads with `overwrite:true` now recover from empty stale target directories left by failed installs.

= 3.0.58 =
* Added: `content/restore-revision` ability for restoring posts, pages, and custom post types through WordPress revisions without transporting block content through JSON.

= 3.0.57 =
* Added: MCP HTTP shutdown timing fallback records long-running or fatal MCP REST requests even when adapter-level observability does not fire.

= 3.0.56 =
* Added: MCP Adapter transport requests are now recorded in `system/ability-timings` when they fail or exceed the timing threshold, including method/tool context for discovery and `tools/list` diagnostics.

= 3.0.55 =
* Fixed: `content/patch-page` and `content/patch-post` now use a short per-post write lock so concurrent patch calls against the same item cannot overwrite each other with stale content.

= 3.0.54 =
* Added: `system/ability-timings` exposes a bounded read-only log of slow or failed ability calls.
* Improved: ability callbacks now record timing data only when calls fail or exceed the default 1000 ms threshold.

= 3.0.53 =
* Added: `menus/upsert-item` creates or updates menu items idempotently by page/post/category identity or custom URL.
* Improved: menu add/update now use one normalized nav menu item module with write readback, object/type preservation, title persistence, and contract-test coverage.

= 3.0.52 =
* Fixed: menu item title updates now also persist the underlying nav menu item post title, so frontend labels do not fall back to stale object labels.

= 3.0.51 =
* Fixed: `menus/add-item` now validates page/post/category object IDs before creating non-custom menu items.
* Fixed: `menus/update-item` now preserves existing menu item fields when only changing title, URL, parent, position, target, or classes.

= 3.0.50 =
* Security: `plugins/update` can run through MCP only with explicit confirmation and either the global code-write opt-in or approval from a registered update-policy adapter; generic plugin code writes remain disabled by default.

= 3.0.49 =
* Security: `options/update` now blocks theme bootstrap options `template` and `stylesheet`.

= 3.0.48 =
* Security: plugin code write abilities are disabled by default unless server-side configuration explicitly enables `MCP_EXPOSE_ENABLE_PLUGIN_CODE_WRITES`.
* Security: WordPress.org plugin install, plugin update, and plugin delete now require explicit per-ability confirmation when plugin code writes are enabled.

= 3.0.47 =
* Security: MCP transport and generic execute-ability entrypoints now default to `manage_options` via adapter capability filters.
* Security: high-risk `plugins/upload`, `plugins/upload-base64`, and `options/update` calls now require explicit per-ability confirmation.

= 3.0.46 =
* Improved: generic post meta writes now use a single post meta write policy interface with a filterable protected-key registry.
* Added: local ability contract harness for verifying protected Elementor meta writes are rejected before side effects.

= 3.0.45 =
* Security: generic content/meta abilities now block protected Elementor meta keys and require dedicated `elementor/*` abilities for Elementor document writes.
* Changed: plugin ZIP uploads now use WordPress core `Plugin_Upgrader` instead of direct plugin-directory unzip/copy operations.

= 3.0.44 =
* Fixed: `plugins/update` now preserves active plugin state across WordPress-native plugin updates and reports the before/after activation state.

= 3.0.43 =
* Fixed: `plugins/upload` no longer defines a temporary `get_current_screen()` stub, avoiding a fatal redeclare when WordPress loads admin screen helpers during REST/MCP plugin installs.

= 3.0.42 =
* Added: `plugins/list` now supports a `search` parameter for filtering installed plugins by file, slug, name, author, or description.
* Fixed: `plugins/list` now accepts no-argument execution through the MCP proxy like the other null-safe list abilities.

= 3.0.41 =
* Fixed: broad content update and patch abilities now block accidental removal of existing GenerateBlocks/design markup unless explicitly overridden.

= 3.0.40 =
* Added: `content/update-discussion-status` for opening or closing comments and pings on posts/pages.

= 3.0.39 =
* Added: `media/upload-base64` for uploading local/generated media files into the WordPress media library through MCP

= 3.0.38 =
* Added: `content/update-post` now supports updating the local post date with the `date` parameter
* Added: post meta support via `content/create-post`, `content/update-post`, `meta/update-post-meta`, and `meta/delete-post-meta`
* Security: post meta writes now check per-key `edit_post_meta` / `delete_post_meta` capabilities before modifying metadata

= 3.0.37 =
* Docs: removed the stray `Claude` mention from the GitHub README workflow wording

= 3.0.36 =
* Fixed: `plugins/search-directory` now handles WordPress.org directory rows correctly when plugin data is returned as arrays instead of objects
* Fixed: `plugins/list-updates` now accepts no-argument execution through the MCP proxy like the other null-safe list abilities

= 3.0.35 =
* Added: `plugins/search-directory` to search the official WordPress.org plugin directory from MCP
* Added: `plugins/install-directory` to install plugins from the official WordPress.org directory by slug
* Added: `plugins/list-updates` and `plugins/update` for WordPress-native plugin update discovery and execution
* Added: `plugins/switch` to toggle between installed plugins with rollback if the target activation fails

= 3.0.34 =
* Docs: added a clearer GitHub onboarding path with setup order, first-success checks, and add-on selection guidance
* Docs: added explicit WordPress and PHP compatibility notes
* Docs: corrected ecosystem add-on and ability counts, including Formidable plus the current Elementor and Rank Math totals
* Docs: replaced the stale hardcoded Abilities API ZIP URL with the generic latest-release link
* Docs: fixed the GitHub release badge so it follows the actual latest release

= 3.0.33 =
* Fixed: plugin upload paths now validate local ZIP signatures before unzip so corrupted payloads fail with a direct ZIP-validation error
* Improved: pairs with proxy-side HTTP JSON limit hardening so larger `plugins/upload-base64` requests are not rejected or truncated at the MCP proxy layer

= 3.0.31 =
* Fixed: featured-image create/update paths are now idempotent when the requested image is already assigned

= 3.0.30 =
* Fixed: `plugins/upload` and `plugins/upload-base64` now fall back to `copy_dir()` when filesystem `move()` fails after unzip
* Improved: plugin install failures now report the underlying filesystem context instead of only `Failed to move plugin to plugins directory`

= 3.0.29 =
* Fixed: `content/update-post` now clears stale invalid assigned page-template metadata before running unrelated post updates
* Fixed: `content/update-page` now clears stale invalid assigned templates on update and validates explicit `template` input
* Fixed: `content/create-page` now validates explicit page-template slugs before saving them

= 3.0.28 =
* Added: `featured_image_id` support to `content/create-post`, `content/update-post`, `content/create-page`, and `content/update-page`
* Improved: `content/get-post` and `content/get-page` now also return `featured_image_id` alongside the featured image URL

= 3.0.27 =
* Fixed: `content/get-next-post` now applies the `after_id` floor correctly by allowing the query filter to run

= 3.0.26 =
* Added: `content/get-next-post` to find the next real post when IDs have gaps
* Improved: `content/list-posts` now accepts case-insensitive `order` values and friendly `orderby` aliases like `id` and `slug`
* Improved: `content/get-post` now supports `post_type` for slug lookups and returns clearer context when a requested post is missing

= 3.0.25 =
* Fixed: `users/delete` now loads `wp-admin/includes/user.php` before calling `wp_delete_user()` in REST/MCP contexts

= 3.0.24 =
* Performance: system/debug-log now tails logs without reading whole file into memory
* Security: options/get now blocks sensitive/protected option names
* Schema: Added output schemas for comments and taxonomy association abilities

= 3.0.23 =
* Added: `content/update-category` ability
* Fixed: Translator comment for placeholder string in post type validation
* Fixed: Stable tag alignment with plugin version

= 3.0.17 =
* Fixed: Use literal text domain in translation calls
* Fixed: Add translators comments for placeholder strings

= 3.0.16 =
* Added: include_totals flag and has_more/returned output for list-posts/list-pages/list-media to avoid expensive counts by default

= 3.0.15 =
* Added: plugins/upload-base64 now accepts zip_path for server-local zip installs
* Fixed: no-params abilities accept null input (menus/list, widgets/list-sidebars, widgets/list-available)

= 3.0.14 =
* Fixed: plugins/delete now loads core file helpers before deletion

= 3.0.13 =
* Added: Shared pagination normalization for core list abilities

= 3.0.12 =
* Fixed: plugins/upload now loads WordPress download helpers in non-admin contexts

= 3.0.11 =
* Added: plugins/upload-base64 ability for local file uploads

= 3.0.10 =
* Added: content/create-category ability

= 3.0.9 =
* Security: Added per-item capability checks for content, media, users, and comments

= 3.0.8 =
* Added: plugins/activate ability to activate installed plugins
* Added: plugins/deactivate ability to deactivate active plugins

= 3.0.7 =
* Improved: All 47 ability descriptions now include parameter hints

= 3.0.6 =
* Added: comments/create ability for top-level comments

= 3.0.5 =
* Added: plugins/delete ability to remove inactive plugins

= 3.0.4 =
* Fixed: Use WP_Filesystem API instead of native PHP functions
* Fixed: Replaced wp_get_sidebars_widgets with direct option call

= 3.0.3 =
* Added: Revisions and comments abilities
* Added: author_id parameter for content creation

= 3.0.0 =
* Breaking: Modular architecture - vendor-specific abilities moved to add-on plugins
* Core plugin now contains only WordPress-native abilities (45)
