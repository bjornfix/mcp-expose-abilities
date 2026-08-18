#!/usr/bin/env bash
set -euo pipefail

SITE="${SITE:-gmekka}"
POST_ID="${POST_ID:-36148}"
MENU_ID="${MENU_ID:-}"
MENU_POST_ID="${MENU_POST_ID:-$POST_ID}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
PROXY_DIR="${PROXY_DIR:-${WORKSPACE_ROOT}/wordpress-mcp-proxy}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TEST_TITLE="MCP Guard Contract Test ${STAMP}"
MENU_TEST_TITLE="MCP Menu Contract ${STAMP}"

run_ability() {
  local ability="$1"
  local params="$2"

  (cd "$PROXY_DIR" && npm run http:call -- execute_ability --site "$SITE" --ability "$ability" --params "$params")
}

expect_protected_meta_block() {
  local ability="$1"
  local params="$2"
  local output

  output="$(run_ability "$ability" "$params")"

  if ! grep -q '"success": false' <<<"$output"; then
    printf 'Expected %s to return success=false.\n%s\n' "$ability" "$output" >&2
    return 1
  fi

  if ! grep -q 'Protected Elementor meta key' <<<"$output"; then
    printf 'Expected %s to reject protected Elementor meta.\n%s\n' "$ability" "$output" >&2
    return 1
  fi
}

expect_protected_meta_block \
  "meta/update-post-meta" \
  "{\"post_id\":${POST_ID},\"meta\":{\"_elementor_data\":\"[]\"}}"

expect_protected_meta_block \
  "meta/delete-post-meta" \
  "{\"post_id\":${POST_ID},\"meta\":{\"_elementor_data\":null}}"

expect_protected_meta_block \
  "content/update-post" \
  "{\"id\":${POST_ID},\"meta_input\":{\"_elementor_data\":\"[]\"}}"

expect_protected_meta_block \
  "content/create-post" \
  "{\"title\":\"${TEST_TITLE}\",\"status\":\"draft\",\"meta_input\":{\"_elementor_data\":\"[]\"}}"

expect_dangerous_action_block() {
  local ability="$1"
  local params="$2"
  local output

  output="$(run_ability "$ability" "$params")"

  if ! grep -q '"success": false' <<<"$output"; then
    printf 'Expected %s to return success=false without explicit dangerous-action confirmation.\n%s\n' "$ability" "$output" >&2
    return 1
  fi

  if ! grep -q 'requires explicit confirmation' <<<"$output"; then
    printf 'Expected %s to require explicit dangerous-action confirmation.\n%s\n' "$ability" "$output" >&2
    return 1
  fi
}

expect_plugin_code_write_disabled() {
  local ability="$1"
  local params="$2"
  local output

  output="$(run_ability "$ability" "$params")"

  if ! grep -q '"success": false' <<<"$output"; then
    printf 'Expected %s to return success=false while plugin code writes are disabled.\n%s\n' "$ability" "$output" >&2
    return 1
  fi

  if ! grep -q 'plugin code write ability' <<<"$output"; then
    printf 'Expected %s to report plugin code writes disabled.\n%s\n' "$ability" "$output" >&2
    return 1
  fi
}

expect_plugin_code_write_disabled \
  "plugins/upload-base64" \
  '{"content_base64":"eA==","filename":"mcp-guard-contract-test.zip","activate":false,"overwrite":false}'

expect_plugin_code_write_disabled \
  "plugins/upload-base64" \
  '{"content_base64":"eA==","filename":"mcp-guard-contract-test.zip","activate":false,"overwrite":false,"confirm_dangerous_action":"plugins/upload-base64"}'

expect_dangerous_action_block \
  "plugins/update" \
  '{"plugin":"hello.php"}'

expect_plugin_code_write_disabled \
  "plugins/update" \
  '{"plugin":"hello.php","confirm_dangerous_action":"plugins/update"}'

expect_plugin_code_write_disabled \
  "plugins/delete" \
  '{"plugin":"hello.php","confirm_dangerous_action":"plugins/delete"}'

expect_dangerous_action_block \
  "options/update" \
  "{\"name\":\"mcp_guard_contract_test_${STAMP}\",\"value\":\"blocked\"}"

elementor_output="$(run_ability "elementor/update-data" "{\"id\":${POST_ID},\"data\":[]}")"
if grep -q "Ability 'elementor/update-data' not found" <<<"$elementor_output"; then
  printf 'Skipping elementor/update-data contract; ability is not registered on site=%s.\n' "$SITE"
elif ! grep -q '"success": false' <<<"$elementor_output" || ! grep -q 'requires explicit confirmation' <<<"$elementor_output"; then
  printf 'Expected elementor/update-data to require explicit dangerous-action confirmation.\n%s\n' "$elementor_output" >&2
  exit 1
fi

search_output="$(run_ability "content/search" "{\"query\":\"${TEST_TITLE}\",\"per_page\":5}")"
if ! grep -q '"total": 0' <<<"$search_output"; then
  printf 'Expected create-post guard to avoid creating "%s".\n%s\n' "$TEST_TITLE" "$search_output" >&2
  exit 1
fi

if [[ -n "$MENU_ID" ]]; then
  menu_add_output="$(run_ability "menus/upsert-item" "{\"menu_id\":${MENU_ID},\"title\":\"${MENU_TEST_TITLE}\",\"object\":\"page\",\"object_id\":${MENU_POST_ID},\"position\":99}")"
  if ! grep -q '"success": true' <<<"$menu_add_output"; then
    printf 'Expected menus/upsert-item to create/update a page menu item.\n%s\n' "$menu_add_output" >&2
    exit 1
  fi
  if ! grep -q '"type": "post_type"' <<<"$menu_add_output"; then
    printf 'Expected menu item to remain post_type, not custom.\n%s\n' "$menu_add_output" >&2
    exit 1
  fi
  if ! grep -q "\"object_id\": ${MENU_POST_ID}" <<<"$menu_add_output"; then
    printf 'Expected menu item object_id to remain %s.\n%s\n' "$MENU_POST_ID" "$menu_add_output" >&2
    exit 1
  fi

  menu_item_id="$(sed -n 's/.*"id": \([0-9][0-9]*\).*/\1/p' <<<"$menu_add_output" | head -n1)"
  if [[ -z "$menu_item_id" ]]; then
    printf 'Expected menus/upsert-item output to include item id.\n%s\n' "$menu_add_output" >&2
    exit 1
  fi

  menu_update_output="$(run_ability "menus/update-item" "{\"menu_id\":${MENU_ID},\"item_id\":${menu_item_id},\"title\":\"${MENU_TEST_TITLE} Updated\",\"position\":100}")"
  if ! grep -q '"success": true' <<<"$menu_update_output"; then
    printf 'Expected menus/update-item to update a page menu item.\n%s\n' "$menu_update_output" >&2
    exit 1
  fi
  if ! grep -q '"type": "post_type"' <<<"$menu_update_output"; then
    printf 'Expected menu item update to preserve post_type.\n%s\n' "$menu_update_output" >&2
    exit 1
  fi
  if ! grep -q "\"object_id\": ${MENU_POST_ID}" <<<"$menu_update_output"; then
    printf 'Expected menu item update to preserve object_id %s.\n%s\n' "$MENU_POST_ID" "$menu_update_output" >&2
    exit 1
  fi
  if ! grep -q "\"post_title\": \"${MENU_TEST_TITLE} Updated\"" <<<"$menu_update_output"; then
    printf 'Expected menu item update to persist the nav_menu_item post_title.\n%s\n' "$menu_update_output" >&2
    exit 1
  fi

  run_ability "menus/delete-item" "{\"item_id\":${menu_item_id}}" >/dev/null
else
  printf 'Skipping menu contracts; set MENU_ID and optionally MENU_POST_ID to enable them.\n'
fi

printf 'Ability contracts passed for site=%s post_id=%s\n' "$SITE" "$POST_ID"
