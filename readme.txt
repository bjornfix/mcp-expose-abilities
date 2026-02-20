=== MCP Expose Abilities ===
Contributors: devenia
Tags: mcp, ai, automation, content, rest-api
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 3.0.24
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI assistants edit your WordPress site via MCP.

== Description ==

This plugin exposes WordPress functionality through MCP (Model Context Protocol), enabling AI assistants to directly interact with your WordPress site. No more copy-pasting between chat and admin.

Core WordPress abilities for content, menus, users, media, widgets, plugins, options, and system management.

== Installation ==

1. Install and activate the required plugins:
   - Abilities API (official release ZIP): https://github.com/WordPress/abilities-api/releases/download/v0.4.0/abilities-api.zip
   - MCP Adapter: https://github.com/WordPress/mcp-adapter
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin
5. (Optional) Install add-on plugins for vendor-specific features

== Changelog ==

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
