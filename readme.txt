=== MCP Expose Abilities ===
Contributors: devenia
Tags: mcp, ai, automation, content, rest-api
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 3.0.49
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

1. Install and activate the required plugins:
   - Abilities API (official release ZIP): https://github.com/WordPress/abilities-api/releases/latest
   - MCP Adapter: https://github.com/WordPress/mcp-adapter
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin
5. (Optional) Install add-on plugins for vendor-specific features

== Changelog ==

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
