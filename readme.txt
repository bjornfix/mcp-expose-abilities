=== MCP Expose Abilities ===
Contributors: devenia
Tags: mcp, ai, automation, content, rest-api
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 3.0.6
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI assistants edit your WordPress site via MCP.

== Description ==

This plugin exposes WordPress functionality through MCP (Model Context Protocol), enabling AI assistants like Claude to directly interact with your WordPress site. No more copy-pasting between chat and admin.

Core WordPress abilities for content, menus, users, media, widgets, plugins, options, and system management.

== Installation ==

1. Install and activate the required plugins (Abilities API and MCP Adapter)
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin
5. (Optional) Install add-on plugins for vendor-specific features

== Changelog ==

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
