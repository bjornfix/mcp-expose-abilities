# MCP Expose Abilities

Let AI assistants edit your WordPress site via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-expose-abilities)](https://github.com/bjornfix/mcp-expose-abilities/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 3.0.65
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This plugin exposes WordPress functionality through MCP (Model Context Protocol), enabling AI assistants to directly interact with your WordPress site. No more copy-pasting between chat and admin.

**Example:** "Fix the phone numbers in these 25 articles to be clickable tel: links." - Done in 30 seconds, all 25 articles.

## The Real Workflow

In practice, the human should not have to memorize the whole ecosystem.

The normal pattern is:

1. point Codex or another MCP-capable agent to this repository
2. let the agent read the README and wiki
3. let the agent work out the required stack and relevant add-ons
4. give the agent a clear task with boundaries

The human's job is mostly to describe the goal.
The agent's job is to figure out the mechanics.

## Why This Feels Different

Most WordPress AI demos still leave you doing the boring part yourself.

This ecosystem is different because the agent can actually do the work inside WordPress:

- fix repetitive content issues across many pages
- update menus, media, plugins, comments, and options
- work with real builder and plugin ecosystems like Elementor, GeneratePress, Rank Math, and Wordfence
- handle the kind of site maintenance people usually postpone because it is repetitive and dull

That changes the experience from:

- `Here is what you should do in wp-admin`

to:

- `Tell the agent what needs doing, and let it carry out the work`

## Before vs After

### Before

- ask the AI what to do
- copy the answer into WordPress by hand
- click through wp-admin for the repetitive bits
- lose momentum because the task is boring
- postpone the cleanup, maintenance, or optimization work again

### After

- tell the agent what needs doing
- let it inspect the site directly
- let it make the targeted change
- verify the result
- move on to the next useful improvement instead of getting stuck in admin drudgery

That difference is the whole point of this ecosystem.

## Who It Is For

This is a good fit for:

- agencies managing many WordPress sites
- companies with repetitive content and operations work
- organizations that want AI to do real maintenance, not just generate text
- technical teams that are tired of copy-paste workflows between chat and wp-admin

It is especially useful when work gets postponed simply because the manual version is boring.

If you want the more specific buyer case, start here:

- [Who Benefits Most](https://github.com/bjornfix/mcp-expose-abilities/wiki/Who-Benefits-Most)

## Documentation

For setup and troubleshooting beyond the quick start, use the wiki:

- [Why Teams Use It](https://github.com/bjornfix/mcp-expose-abilities/wiki/Why-Teams-Use-It)
- [Use Cases](https://github.com/bjornfix/mcp-expose-abilities/wiki/Use-Cases)
- [Who It Is For](https://github.com/bjornfix/mcp-expose-abilities/wiki/Who-It-Is-For)
- [Who Benefits Most](https://github.com/bjornfix/mcp-expose-abilities/wiki/Who-Benefits-Most)
- [Alternatives](https://github.com/bjornfix/mcp-expose-abilities/wiki/Alternatives)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)
- [Install Order and Dependencies](https://github.com/bjornfix/mcp-expose-abilities/wiki/Install-Order-and-Dependencies)
- [First Working MCP Connection](https://github.com/bjornfix/mcp-expose-abilities/wiki/First-Working-MCP-Connection)
- [Which Add-On Do I Need?](https://github.com/bjornfix/mcp-expose-abilities/wiki/Which-Add-On-Do-I-Need%3F)
- [Troubleshooting](https://github.com/bjornfix/mcp-expose-abilities/wiki/Troubleshooting)
- [Examples](https://github.com/bjornfix/mcp-expose-abilities/wiki/Examples)

If you are using an AI agent, the simplest instruction is often just:

- `Read https://github.com/bjornfix/mcp-expose-abilities and figure out the stack before making changes.`

## Start Here

If you are new to the stack, use this order:

1. Install **Abilities API**
2. Install **MCP Adapter**
3. Install **MCP Expose Abilities** (this plugin)
4. Confirm you can list and execute core abilities
5. Add only the vendor-specific plugins you actually need

If you skip step 4 and start installing add-ons immediately, troubleshooting gets harder than it needs to be.

## What You Actually Need

For a minimal working setup, you only need:

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api)
- [MCP Adapter](https://github.com/WordPress/mcp-adapter)
- **MCP Expose Abilities** (this plugin)

Everything else in the ecosystem is optional.

## 5-Minute Setup

1. Install and activate the required plugins:
   - Abilities API: https://github.com/WordPress/abilities-api/releases/latest
   - MCP Adapter: https://github.com/WordPress/mcp-adapter
   - MCP Expose Abilities: download the latest release from this repo
2. Verify the Abilities API plugin is installed as `wp-content/plugins/abilities-api/abilities-api.php`
3. Activate all three plugins in WordPress
4. Confirm the MCP adapter route is reachable on your site
5. Run a simple read-only ability first, such as listing posts or reading a page

## First Success Check

Before adding Elementor, Cloudflare, Gmail, or anything else, confirm the core stack works.

Good first tests:

- list posts
- get a page by ID
- list menus
- list installed plugins

If those work, the stack is wired correctly. If they do not, fix the core stack before adding add-ons.

## Modular Architecture

Version 3.0 introduced a modular architecture. The core plugin provides WordPress-native abilities, while vendor-specific features are available as separate add-on plugins:

| Plugin | Abilities | Description |
|--------|-----------|-------------|
| **MCP Expose Abilities** (core) | 69 | WordPress core: content, menus, users, media, widgets, plugins, options, comments, taxonomy, system |
| [MCP Abilities - Filesystem](https://github.com/bjornfix/mcp-abilities-filesystem) | 11 | File operations with security hardening |
| [MCP Abilities - Elementor](https://github.com/bjornfix/mcp-abilities-elementor) | 40 | Elementor page builder integration |
| [MCP Abilities - GeneratePress](https://github.com/bjornfix/mcp-abilities-generatepress) | 26 | GeneratePress theme + GenerateBlocks |
| [MCP Abilities - Cloudflare](https://github.com/bjornfix/mcp-abilities-cloudflare) | 4 | Cloudflare cache management |
| [MCP Abilities - Google Workspace](https://github.com/bjornfix/mcp-abilities-workspace) | 16 | Gmail API via Workspace service account |
| [MCP Abilities - Rank Math](https://github.com/bjornfix/mcp-abilities-rankmath) | 23 | Rank Math SEO metadata access |
| [MCP Abilities - Wordfence](https://github.com/bjornfix/mcp-abilities-wordfence) | 11 | Wordfence security status + blocks |
| [MCP Abilities - Brevo](https://github.com/bjornfix/mcp-abilities-brevo) | 22 | Brevo contacts, lists, campaigns |
| [MCP Abilities - Advanced Ads](https://github.com/bjornfix/mcp-abilities-advads) | 17 | Advanced Ads management |
| [MCP Abilities - Toolset](https://github.com/bjornfix/mcp-abilities-toolset) | 38 | Toolset post types, custom fields, taxonomies, relationships |
| [MCP Abilities - SitePress](https://github.com/bjornfix/mcp-abilities-sitepress) | 10 | WPML translation mapping, language-switcher recovery, and QA checks |
| [MCP Abilities - Formidable](https://github.com/bjornfix/mcp-abilities-formidable) | 6 | Formidable Forms settings, usage tracing, styles, and CSS cache controls |
| [MCP Abilities - Store Locator](https://github.com/bjornfix/mcp-abilities-store-locator) | 9 | Store Locator settings, templates, store records, categories, and transient cleanup |

**Total ecosystem: 297 abilities**

Install only what you need. Running GeneratePress? Install that add-on. Don't use Elementor? Skip it.

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api) plugin (WordPress core team)
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin (WordPress core team)
- Use the official Abilities API release ZIP (`abilities-api.zip`) so it installs as `wp-content/plugins/abilities-api/abilities-api.php`

## WordPress Compatibility

- Requires WordPress 6.9 or newer
- Tested up to WordPress 7.0
- Requires PHP 8.0 or newer
- Maintained against the WordPress 6.9 release line together with the supported add-on plugins

## Installation

1. Install and activate the required plugins:
   - Abilities API (official release ZIP): https://github.com/WordPress/abilities-api/releases/latest
   - MCP Adapter: https://github.com/WordPress/mcp-adapter
2. Download the latest release from [Releases](https://github.com/bjornfix/mcp-expose-abilities/releases)
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin
5. (Optional) Install add-on plugins for vendor-specific features

## Which Add-On Should I Install?

Install add-ons only when your site actually uses that product:

- Elementor site: install [`mcp-abilities-elementor`](https://github.com/bjornfix/mcp-abilities-elementor)
- GeneratePress / GenerateBlocks site: install [`mcp-abilities-generatepress`](https://github.com/bjornfix/mcp-abilities-generatepress)
- Cloudflare-managed site: install [`mcp-abilities-cloudflare`](https://github.com/bjornfix/mcp-abilities-cloudflare)
- Gmail / Workspace automation: install [`mcp-abilities-workspace`](https://github.com/bjornfix/mcp-abilities-workspace)
- Rank Math site: install [`mcp-abilities-rankmath`](https://github.com/bjornfix/mcp-abilities-rankmath)
- Wordfence site: install [`mcp-abilities-wordfence`](https://github.com/bjornfix/mcp-abilities-wordfence)
- Brevo site: install [`mcp-abilities-brevo`](https://github.com/bjornfix/mcp-abilities-brevo)
- Toolset site: install [`mcp-abilities-toolset`](https://github.com/bjornfix/mcp-abilities-toolset)
- WPML site: install [`mcp-abilities-sitepress`](https://github.com/bjornfix/mcp-abilities-sitepress)
- Formidable Forms site: install [`mcp-abilities-formidable`](https://github.com/bjornfix/mcp-abilities-formidable)
- Store Locator site: install [`mcp-abilities-store-locator`](https://github.com/bjornfix/mcp-abilities-store-locator)

Do not install every add-on by default. Most sites only need one or two.

## Common Failure Pattern

The most common onboarding mistake is treating this like one plugin instead of a stack.

When something does not work, check in this order:

1. Is Abilities API active?
2. Is MCP Adapter active?
3. Is MCP Expose Abilities active?
4. Does the core plugin work without any add-ons?
5. Is the vendor plugin itself installed and active?
6. Only then debug the specific add-on

## Recent Changes

### 3.0.50

- Security: `plugins/update` can run through MCP only for Devenia manifest-managed packages with explicit confirmation; generic plugin code writes remain disabled by default.

### 3.0.49

- Security: `options/update` now blocks theme bootstrap options `template` and `stylesheet`.

### 3.0.48

- Security: plugin code write abilities are disabled by default unless server-side configuration explicitly enables `MCP_EXPOSE_ENABLE_PLUGIN_CODE_WRITES`.
- Security: WordPress.org plugin install, plugin update, and plugin delete now require explicit per-ability confirmation when plugin code writes are enabled.

### 3.0.47

- Security: MCP transport and generic execute-ability entrypoints now default to `manage_options` via adapter capability filters.
- Security: high-risk `plugins/upload`, `plugins/upload-base64`, and `options/update` calls now require explicit per-ability confirmation.

### 3.0.46

- Improved generic post meta writes to use one post meta write policy interface with a filterable protected-key registry.
- Added a local ability contract harness for verifying protected Elementor meta writes are rejected before side effects.

### 3.0.45

- Security: generic content/meta abilities now block protected Elementor meta keys and require dedicated `elementor/*` abilities for Elementor document writes.
- Changed plugin ZIP uploads to use WordPress core `Plugin_Upgrader` instead of direct plugin-directory unzip/copy operations.

### 3.0.44
- Fixed `plugins/update` so plugins that were active before a WordPress-native update are reactivated if WordPress leaves them inactive after the upgrader run.
- Added `active_before`, `active_after`, and `reactivated` fields to the `plugins/update` response.

### 3.0.43

- Fixed: `plugins/upload` no longer defines a temporary `get_current_screen()` stub, avoiding a fatal redeclare when WordPress loads admin screen helpers during REST/MCP plugin installs.

### 3.0.42

- Added efficient `plugins/list` filtering with a `search` parameter and null-safe no-argument input handling.

### 3.0.41

- Fixed broad content update and patch abilities so they block accidental removal of existing GenerateBlocks/design markup unless explicitly overridden.

### 3.0.40

- Added `content/update-discussion-status` for opening or closing comments and pings on posts/pages.

### 3.0.39

- Added `media/upload-base64` for uploading local or generated media files into the WordPress media library through MCP.

### 3.0.38

- Added `date` support to `content/update-post` for updating local post publish dates.
- Added post meta support via `content/create-post`, `content/update-post`, `meta/update-post-meta`, and `meta/delete-post-meta`.
- Security: post meta writes now check per-key `edit_post_meta` / `delete_post_meta` capabilities before modifying metadata.

### 3.0.37

- Docs: removed the stray `Claude` mention from the README workflow wording.

### 3.0.36

- Fixed `plugins/search-directory` so WordPress.org search results are populated correctly when the API returns array-shaped plugin rows.
- Fixed `plugins/list-updates` so it accepts no-argument execution through the MCP proxy like the older null-safe list abilities.

### 3.0.35

- Added `plugins/search-directory` to search the official WordPress.org plugin directory from MCP.
- Added `plugins/install-directory` to install WordPress.org plugins by slug.
- Added `plugins/list-updates` and `plugins/update` for WordPress-native plugin update discovery and execution.
- Added `plugins/switch` to toggle between installed plugins with rollback if the target activation fails.

### 3.0.34

- Docs: added a clearer GitHub onboarding path with `Start Here`, setup order, first-success checks, and add-on selection guidance.
- Docs: added explicit WordPress and PHP compatibility notes.
- Docs: corrected ecosystem add-on and ability counts, including the Formidable add-on and the current Elementor and Rank Math totals.
- Docs: replaced the stale hardcoded Abilities API ZIP URL with the generic latest-release link.
- Docs: fixed the GitHub release badge so it follows the actual latest release.

### 3.0.33

- Validates local plugin ZIP signatures before unzip so corrupted `plugins/upload` or `plugins/upload-base64` payloads fail with a direct ZIP-validation error.
- Intended to pair with the MCP proxy HTTP transport fix that raises the default JSON body limit for large base64 plugin uploads.

## Core Plugin Abilities (68)

### Content Management (25)

| Ability | Description |
|---------|-------------|
| `content/list-posts` | List posts with filtering by status, category, author, search |
| `content/get-post` | Get single post by ID or slug |
| `content/get-next-post` | Find the next existing post after an ID, even when IDs have gaps |
| `content/create-post` | Create new post, including `featured_image_id` |
| `content/update-post` | Update existing post, including `featured_image_id` |
| `content/delete-post` | Delete post (trash or permanent) |
| `content/restore-post` | Restore a post, page, or custom post type from trash |
| `content/patch-post` | Find/replace in post content |
| `content/list-pages` | List pages with filtering |
| `content/get-page` | Get single page by ID or slug |
| `content/create-page` | Create new page, including `featured_image_id` |
| `content/update-page` | Update existing page, including `featured_image_id` |
| `content/update-discussion-status` | Open or close comments and pings for posts/pages |
| `content/delete-page` | Delete page |
| `content/patch-page` | Find/replace in page content |
| `content/list-categories` | List all categories |
| `content/create-category` | Create new category |
| `content/update-category` | Update existing category |
| `content/list-tags` | List all tags |
| `content/create-tag` | Create new tag |
| `content/list-media` | List media items |
| `content/list-users` | List users |
| `content/search` | Search across posts, pages, media |
| `content/list-revisions` | List revisions for a post/page |
| `content/get-revision` | Get specific revision details |

### Menu Management (8)

| Ability | Description |
|---------|-------------|
| `menus/list` | List all menus and theme locations |
| `menus/get-items` | Get items from a menu |
| `menus/create` | Create new menu |
| `menus/add-item` | Add item to menu |
| `menus/update-item` | Update menu item |
| `menus/upsert-item` | Create or update an item by object identity or custom URL |
| `menus/delete-item` | Delete menu item |
| `menus/assign-location` | Assign menu to theme location |

### User Management (5)

| Ability | Description |
|---------|-------------|
| `users/list` | List users with roles |
| `users/get` | Get user by ID, login, or email |
| `users/create` | Create new user |
| `users/update` | Update user |
| `users/delete` | Delete user (can reassign content) |

### Media Library (4)

| Ability | Description |
|---------|-------------|
| `media/upload` | Upload media from URL |
| `media/get` | Get media item details and sizes |
| `media/update` | Update title, alt, caption |
| `media/delete` | Delete media item |

### Widget Management (3)

| Ability | Description |
|---------|-------------|
| `widgets/list-sidebars` | List all widget areas |
| `widgets/get-sidebar` | Get widgets in a sidebar |
| `widgets/list-available` | List available widget types |

### Plugin Management (11)

| Ability | Description |
|---------|-------------|
| `plugins/upload` | Upload plugin from URL |
| `plugins/upload-base64` | Upload plugin from local file (base64 or zip path) |
| `plugins/search-directory` | Search the official WordPress.org plugin directory |
| `plugins/install-directory` | Install plugin from the official WordPress.org plugin directory by slug |
| `plugins/list` | List installed plugins |
| `plugins/list-updates` | List available plugin updates |
| `plugins/update` | Update an installed plugin |
| `plugins/activate` | Activate installed plugin |
| `plugins/deactivate` | Deactivate active plugin |
| `plugins/switch` | Activate one plugin and deactivate one or more others |
| `plugins/delete` | Delete inactive plugin |

### Comments (6)

| Ability | Description |
|---------|-------------|
| `comments/list` | List comments with filtering |
| `comments/get` | Get single comment details |
| `comments/create` | Create top-level comment |
| `comments/reply` | Reply to existing comment |
| `comments/update-status` | Update comment status (approve, spam, trash) |
| `comments/delete` | Delete comment |

### Options (3)

| Ability | Description |
|---------|-------------|
| `options/get` | Get option value |
| `options/update` | Update option (protected options blocked) |
| `options/list` | List all options |

### System (4)

| Ability | Description |
|---------|-------------|
| `system/get-transient` | Get transient value |
| `system/ability-timings` | Read recent slow or failed ability timings |
| `system/debug-log` | Read debug.log file |
| `system/toggle-debug` | Toggle WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY |

### Taxonomy Utilities (1)

| Ability | Description |
|---------|-------------|
| `taxonomy/associate-with-post-type` | Associate a taxonomy with a post type and persist the mapping |

## Add-on Plugin Abilities

### Filesystem (mcp-abilities-filesystem) - 11 abilities

| Ability | Description |
|---------|-------------|
| `filesystem/get-changelog` | Get plugin/theme changelog |
| `filesystem/read-file` | Read file contents (security hardened) |
| `filesystem/write-file` | Write file (PHP code blocked) |
| `filesystem/append-file` | Append to file |
| `filesystem/list-directory` | List directory contents |
| `filesystem/delete-file` | Delete file (creates backup) |
| `filesystem/delete-directory` | Delete directory (optional recursive) |
| `filesystem/file-info` | Get file metadata |
| `filesystem/create-directory` | Create directory |
| `filesystem/copy-file` | Copy file |
| `filesystem/move-file` | Move/rename file |

### Elementor (mcp-abilities-elementor) - 40 abilities

See the add-on readme for the full list. Common abilities:

| Ability | Description |
|---------|-------------|
| `elementor/get-data` | Get Elementor JSON for a page |
| `elementor/update-data` | Replace Elementor JSON |
| `elementor/patch-data` | Find/replace in Elementor JSON |
| `elementor/update-element` | Update specific element by ID |
| `elementor/list-templates` | List saved templates |
| `elementor/clear-cache` | Clear CSS cache |

### GeneratePress (mcp-abilities-generatepress) - 26 abilities

See the add-on readme for the full list. Common abilities:

| Ability | Description |
|---------|-------------|
| `generatepress/get-settings` | Get theme settings |
| `generatepress/update-settings` | Update theme settings |
| `generatepress/get-typography` | Get typography rules and font manager |
| `generatepress/list-elements` | List GeneratePress Elements |
| `generatepress/list-modules` | List module statuses |
| `generateblocks/get-global-styles` | Get global styles |
| `generateblocks/update-global-styles` | Update global styles |
| `generateblocks/clear-cache` | Clear CSS cache |

### Cloudflare (mcp-abilities-cloudflare) - 4 abilities

| Ability | Description |
|---------|-------------|
| `cloudflare/clear-cache` | Clear Cloudflare cache (entire site or specific URLs) |
| `cloudflare/get-zone` | Get resolved Cloudflare zone context |
| `cloudflare/get-development-mode` | Read development mode status |
| `cloudflare/set-development-mode` | Enable/disable development mode |

### Google Workspace (mcp-abilities-workspace) - 16 abilities

| Ability | Description |
|---------|-------------|
| `gmail/configure` | Set up Gmail API service account credentials |
| `gmail/status` | Check API connection status and configuration |
| `gmail/list-labels` | List labels |
| `gmail/get-label` | Get label by ID |
| `gmail/create-label` | Create label |
| `gmail/update-label` | Update label |
| `gmail/delete-label` | Delete label |
| `gmail/list` | List inbox messages with filtering |
| `gmail/list-threads` | List threads |
| `gmail/get` | Get full email content by ID |
| `gmail/get-thread` | Get thread details |
| `gmail/get-attachment` | Fetch attachment as base64 |
| `gmail/send` | Send email with HTML, attachments, CC, BCC |
| `gmail/modify` | Modify labels (archive, mark read/unread, etc.) |
| `gmail/reply` | Reply to an existing email thread |
| `email/send` | Send email via WordPress wp_mail (non-Gmail fallback) |

## Usage with MCP Clients

### 1. Create Application Password

WordPress Admin → Users → Your Profile → Application Passwords

### 2. Add MCP Server

Configure your MCP client to connect to:

`https://yoursite.com/wp-json/mcp/mcp-adapter-default-server`

Use HTTP transport with a Basic Auth header generated from your WordPress username and application password.

### 3. Start Using

Your MCP client can now edit your WordPress site through conversation.

## Examples

### Create a new page

```json
{
  "ability_name": "content/create-page",
  "parameters": {
    "title": "About Us",
    "content": "<!-- wp:paragraph --><p>Hello world!</p><!-- /wp:paragraph -->",
    "status": "publish"
  }
}
```

### Add menu item

```json
{
  "ability_name": "menus/add-item",
  "parameters": {
    "menu_id": 5,
    "title": "Contact",
    "url": "/contact/"
  }
}
```

### Upload media from URL

```json
{
  "ability_name": "media/upload",
  "parameters": {
    "url": "https://example.com/image.jpg",
    "title": "Hero Image",
    "alt_text": "Beautiful sunset"
  }
}
```

### Batch find/replace

```json
{
  "ability_name": "content/patch-post",
  "parameters": {
    "id": 123,
    "find": "+44 203 3181 832",
    "replace": "<a href=\"tel:+442033181832\">+44 203 3181 832</a>"
  }
}
```

## Security

- **Authentication required** - Uses WordPress application passwords
- **Permission checks** - Every ability verifies user capabilities
- **Your server** - AI connects to your site, you control access
- **Protected options** - Critical settings blocked from modification
- **Filesystem hardening** - PHP code detection, path traversal protection (in add-on)

## Architecture

Three-plugin stack plus optional add-ons:

1. **[Abilities API](https://github.com/WordPress/abilities-api)** - Framework for registering abilities (WordPress core team)
2. **[MCP Adapter](https://github.com/WordPress/mcp-adapter)** - MCP protocol layer (WordPress core team)
3. **MCP Expose Abilities** (this plugin) - Core WordPress abilities
4. **Add-on plugins** (optional) - Vendor-specific abilities

## Changelog

### 3.0.65

- Fixed `content/update-post` and `content/update-page` so generic post/page
  updates protect translated sibling content and critical Elementor/featured
  image meta from WPML/Polylang-style sync hooks.
- Added `translation_guard` details to content update responses so callers can
  verify which translated siblings were protected and restored.

### 3.0.64

- Fixed `meta/update-post-meta` and `meta/delete-post-meta` so updates to
  `_yoast_wpseo_*` fields trigger a post refresh for SEO indexable rebuilds.

### 3.0.63

- Added `meta/get-post-meta` for narrow, read-only inspection of explicit post
  meta keys with per-post capability checks.

### 3.0.62

- Fixed `content/restore-post` and `content/update-page` so stale invalid
  assigned page-template metadata is cleared before WordPress status/content
  writes, allowing legacy trashed pages from old themes to be restored safely.

### 3.0.61

- Fixed `content/list-pages` so the documented `search` parameter is accepted
  and passed through to the WordPress page query.

### 3.0.60
- Added: `content/list-posts` now supports `status:trash` for explicit trash inspection.
- Added: `content/restore-post` restores posts, pages, and custom post types from trash with per-post edit permission checks.

### 3.0.59
- Changed: plugin-code-write guards now pass structured ability name and input to a dedicated filter, allowing trusted upload gates without request-body parsing or stack inspection.
- Fixed: plugin uploads with `overwrite:true` now recover from empty stale target directories left by failed installs.

### 3.0.58
- Added: `content/restore-revision` ability for restoring posts, pages, and custom post types through WordPress revisions without transporting block content through JSON.

### 3.0.57
- Added: MCP HTTP shutdown timing fallback records long-running or fatal MCP REST requests even when adapter-level observability does not fire.

### 3.0.56
- Added: MCP Adapter transport requests are now recorded in `system/ability-timings` when they fail or exceed the timing threshold, including method/tool context for discovery and `tools/list` diagnostics.

### 3.0.55
- Fixed: `content/patch-page` and `content/patch-post` now use a short per-post write lock so concurrent patch calls against the same item cannot overwrite each other with stale content.

### 3.0.54
- Added: `system/ability-timings` exposes a bounded read-only log of slow or failed ability calls.
- Improved: ability callbacks now record timing data only when calls fail or exceed the default 1000 ms threshold.

### 3.0.53
- Added: `menus/upsert-item` creates or updates menu items idempotently by page/post/category identity or custom URL.
- Improved: menu add/update now use one normalized nav menu item module with write readback, object/type preservation, title persistence, and contract-test coverage.

### 3.0.52
- Fixed: menu item title updates now also persist the underlying nav menu item post title, so frontend labels do not fall back to stale object labels.

### 3.0.51
- Fixed: `menus/add-item` now validates page/post/category object IDs before creating non-custom menu items.
- Fixed: `menus/update-item` now preserves existing menu item fields when only changing title, URL, parent, position, target, or classes.

### 3.0.50
- Security: `plugins/update` can run through MCP only for Devenia manifest-managed packages with explicit confirmation; generic plugin code writes remain disabled by default.

### 3.0.49
- Security: `options/update` now blocks theme bootstrap options `template` and `stylesheet`.

### 3.0.48
- Security: plugin code write abilities are disabled by default unless server-side configuration explicitly enables `MCP_EXPOSE_ENABLE_PLUGIN_CODE_WRITES`.
- Security: WordPress.org plugin install, plugin update, and plugin delete now require explicit per-ability confirmation when plugin code writes are enabled.

### 3.0.47
- Security: MCP transport and generic execute-ability entrypoints now default to `manage_options` via adapter capability filters.
- Security: high-risk `plugins/upload`, `plugins/upload-base64`, and `options/update` calls now require explicit per-ability confirmation.

### 3.0.46
- Improved: generic post meta writes now use a single post meta write policy interface with a filterable protected-key registry.
- Added: local ability contract harness for verifying protected Elementor meta writes are rejected before side effects.

### 3.0.45
- Security: generic content/meta abilities now block protected Elementor meta keys and require dedicated `elementor/*` abilities for Elementor document writes.
- Changed: plugin ZIP uploads now use WordPress core `Plugin_Upgrader` instead of direct plugin-directory unzip/copy operations.

### 3.0.44
- Fixed: `plugins/update` now preserves active plugin state across WordPress-native plugin updates and reports the before/after activation state.

### 3.0.42
- Added: `plugins/list` now supports a `search` parameter for filtering installed plugins by file, slug, name, author, or description.
- Fixed: `plugins/list` now accepts no-argument execution through the MCP proxy like the other null-safe list abilities.

### 3.0.41
- Fixed: broad content update and patch abilities now block accidental removal of existing GenerateBlocks/design markup unless explicitly overridden.

### 3.0.40
- Added: `content/update-discussion-status` for opening or closing comments and pings on posts/pages.

### 3.0.39
- Added: `media/upload-base64` for uploading local/generated media files into the WordPress media library through MCP

### 3.0.38
- Added: `content/update-post` now supports updating the local post date with the `date` parameter
- Added: post meta support via `content/create-post`, `content/update-post`, `meta/update-post-meta`, and `meta/delete-post-meta`
- Security: post meta writes now check per-key `edit_post_meta` / `delete_post_meta` capabilities before modifying metadata

### 3.0.37
- Docs: removed the stray `Claude` mention from the GitHub README workflow wording

### 3.0.36
- Fixed: `plugins/search-directory` now handles WordPress.org directory rows correctly when plugin data is returned as arrays instead of objects
- Fixed: `plugins/list-updates` now accepts no-argument execution through the MCP proxy like the other null-safe list abilities

### 3.0.35
- Added: `plugins/search-directory` to search the official WordPress.org plugin directory from MCP
- Added: `plugins/install-directory` to install plugins from the official WordPress.org directory by slug
- Added: `plugins/list-updates` and `plugins/update` for WordPress-native plugin update discovery and execution
- Added: `plugins/switch` to toggle between installed plugins with rollback if the target activation fails

### 3.0.34
- Docs: added a clearer GitHub onboarding path with `Start Here`, setup order, first-success checks, and add-on selection guidance
- Docs: added explicit WordPress and PHP compatibility notes
- Docs: corrected ecosystem add-on and ability counts, including the Formidable add-on and the current Elementor and Rank Math totals
- Docs: replaced the stale hardcoded Abilities API ZIP URL with the generic latest-release link
- Docs: fixed the GitHub release badge so it follows the actual latest release

### 3.0.33
- Fixed: plugin upload paths now validate local ZIP signatures before unzip so corrupted payloads fail with a direct ZIP-validation error
- Improved: pairs with proxy-side HTTP JSON limit hardening so larger `plugins/upload-base64` requests are not rejected or truncated at the MCP proxy layer

### 3.0.31
- Fixed: featured-image create/update paths are now idempotent when the requested image is already assigned

### 3.0.30
- Fixed: `plugins/upload` and `plugins/upload-base64` now fall back to `copy_dir()` when filesystem `move()` fails after unzip
- Improved: plugin install failures now include the underlying filesystem context

### 3.0.29
- Fixed: `content/update-post` now clears stale invalid assigned page-template metadata before unrelated post updates
- Fixed: `content/update-page` now clears stale invalid assigned templates on update and validates explicit `template` input
- Fixed: `content/create-page` now validates explicit page-template slugs before saving them

### 3.0.28
- Added `featured_image_id` support to post/page create and update abilities
- Added `featured_image_id` to `content/get-post` and `content/get-page`

### 3.0.27
- Fixed: `content/get-next-post` now applies the `after_id` floor correctly by allowing the query filter to run

### 3.0.26
- Added: `content/get-next-post` to find the next existing post after an ID, even when IDs have gaps
- Improved: `content/list-posts` now accepts case-insensitive `order` values and friendly `orderby` aliases like `id` and `slug`
- Improved: `content/get-post` now accepts `post_type` for slug lookups and returns clearer missing-post context
### 3.0.25
- Fixed: `users/delete` now loads `wp-admin/includes/user.php` before calling `wp_delete_user()` in REST/MCP contexts

### 3.0.24
- Performance: debug log reader now tails file content instead of loading full files
- Security: `options/get` blocks sensitive option names (tokens, keys, secrets)
- Schema: output schemas added for comments and taxonomy-association abilities

### 3.0.23
- Added: `content/update-category` ability
- Fixed: Translator comment for placeholder string in post type validation
- Fixed: Stable tag alignment with plugin version

### 3.0.17
- Fixed: Use literal text domain in translation calls
- Fixed: Add translators comments for placeholder strings

### 3.0.16
- Added: `include_totals` flag plus `has_more`/`returned` output for list-posts/list-pages/list-media to avoid expensive counts by default

### 3.0.15
- Added: plugins/upload-base64 now accepts `zip_path` for server-local zip installs
- Fixed: no-params abilities accept null input (menus/list, widgets/list-sidebars, widgets/list-available)

### 3.0.14
- Fixed: plugins/delete now loads core file helpers before deletion

### 3.0.13
- Added: Shared pagination normalization for core list abilities

### 3.0.12
- Fixed: plugins/upload now loads WordPress download helpers in non-admin contexts

### 3.0.11
* Added: plugins/upload-base64 ability for local file uploads

### 3.0.10
- Added: `content/create-category` ability

### 3.0.9
- Security: Added per-item capability checks for content, media, users, and comments

### 3.0.8
- Added: `plugins/activate` ability to activate installed plugins
- Added: `plugins/deactivate` ability to deactivate active plugins

### 3.0.7
- Improved: All 47 ability descriptions now include parameter hints

### 3.0.6
- Added: `comments/create` ability for top-level comments

### 3.0.5
- Added: `plugins/delete` ability to remove inactive plugins

### 3.0.4
- Fixed: Use WP_Filesystem API instead of native PHP functions
- Fixed: Replaced wp_get_sidebars_widgets with direct option call

### 3.0.3
- Added: Revisions abilities (`content/list-revisions`, `content/get-revision`)
- Added: Comments abilities (list, get, create, reply, update-status, delete)
- Added: `author_id` parameter for content creation

### 3.0.0
- **Breaking:** Modular architecture - vendor-specific abilities moved to add-on plugins
- Core plugin now contains only WordPress-native abilities
- Add-on plugins: Filesystem (10), Elementor (6), GeneratePress (5), Cloudflare (1), Google Workspace (8)
- Cleaner installation - install only what you need

### 2.2.12
- Security: Added protected options blocklist (active_plugins, siteurl, admin_email, etc.)
- Security: Prevents accidental site breakage via options/update

### 2.2.11
- Security: Added UTF-7 and UTF-16 encoding bypass detection
- Security: Blocks encoded PHP injection attempts

### 2.2.10
- Security: Major filesystem security hardening
- Security: PHP code detection in file writes
- Security: Path traversal protection
- Security: Restricted to wp-content directory

### 2.1.0
- Added: Filesystem abilities
- Added: Options abilities
- Added: System abilities
- Added: Cloudflare cache clear ability
- Added: `elementor/update-element` for targeted element updates

### 2.0.0
- Added: Menu, User, Media, Widget, Page abilities

### 1.0.0
- Initial release

## Contributing

PRs welcome! For vendor-specific abilities, consider creating an add-on plugin.

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [MCP Abilities - Toolset](https://github.com/bjornfix/mcp-abilities-toolset)
- [Abilities API](https://github.com/WordPress/abilities-api) (WordPress core team)
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) (WordPress core team)

## Star and Share

If this ecosystem saves you time, gives your team a saner way to handle WordPress work, or helps you finally get through the repetitive maintenance nobody wants to do, please:

- star the repo
- share it with people running WordPress sites
- point them to the wiki so they can see what the ecosystem can actually do

Why do it?

Because this is good for the WordPress ecosystem as a whole. The more people use agent-friendly open WordPress tooling, the more of the boring but important work actually gets done instead of sitting in a backlog forever.
