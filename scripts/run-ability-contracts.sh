#!/usr/bin/env bash
set -euo pipefail

SITE="${SITE:-gmekka}"
POST_ID="${POST_ID:-36148}"
PROXY_DIR="${PROXY_DIR:-/media/bjorn/Stuff/Prosjekter/wordpress-mcp-proxy}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TEST_TITLE="MCP Guard Contract Test ${STAMP}"

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

expect_plugin_code_write_disabled \
  "plugins/install-directory" \
  '{"slug":"hello-dolly","activate":false,"overwrite":false,"confirm_dangerous_action":"plugins/install-directory"}'

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

expect_dangerous_action_block \
  "elementor/update-data" \
  "{\"id\":${POST_ID},\"data\":[]}"

search_output="$(run_ability "content/search" "{\"query\":\"${TEST_TITLE}\",\"per_page\":5}")"
if ! grep -q '"total": 0' <<<"$search_output"; then
  printf 'Expected create-post guard to avoid creating "%s".\n%s\n' "$TEST_TITLE" "$search_output" >&2
  exit 1
fi

printf 'Ability contracts passed for site=%s post_id=%s\n' "$SITE" "$POST_ID"
