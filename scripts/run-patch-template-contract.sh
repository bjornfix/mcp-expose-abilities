#!/usr/bin/env bash
set -euo pipefail

PROXY_DIR="${PROXY_DIR:-/media/bjorn/Stuff/Prosjekter/wordpress-mcp-proxy}"
VPS="${VPS:-hetzner-vps}"
DEV_PATH="${DEV_PATH:-/var/www/dev.devenia.com}"
INVALID_TEMPLATE="legacy-removed-theme-template.php"
ORIGINAL_CONTENT='<p><a href="https://example.com/">Plain text</a></p>'
EXPECTED_CONTENT='<p>Plain text</p>'

read -r POST_ID PAGE_ID < <(
	ssh "$VPS" "cd '$DEV_PATH' &&
		post_id=\$(wp --allow-root post create --post_type=post --post_status=draft --post_title='Patch template post contract' --post_content='$ORIGINAL_CONTENT' --porcelain) &&
		page_id=\$(wp --allow-root post create --post_type=page --post_status=draft --post_title='Patch template page contract' --post_content='$ORIGINAL_CONTENT' --porcelain) &&
		wp --allow-root post meta update \"\$post_id\" _wp_page_template '$INVALID_TEMPLATE' >/dev/null &&
		wp --allow-root post meta update \"\$page_id\" _wp_page_template '$INVALID_TEMPLATE' >/dev/null &&
		printf '%s %s\\n' \"\$post_id\" \"\$page_id\""
)

cleanup() {
	ssh "$VPS" "cd '$DEV_PATH' && wp --allow-root post delete '$POST_ID' '$PAGE_ID' --force >/dev/null 2>&1 || true" >/dev/null 2>&1 || true
}
trap cleanup EXIT

run_ability() {
	local ability="$1"
	local params="$2"
	(cd "$PROXY_DIR" && npm run --silent http:call -- execute_ability --site dev --ability "$ability" --params "$params")
}

post_output="$(run_ability content/patch-post "{\"id\":$POST_ID,\"find\":\"<a href=\\\"https://example.com/\\\">Plain text</a>\",\"replace\":\"Plain text\"}")"
page_output="$(run_ability content/patch-page "{\"id\":$PAGE_ID,\"find\":\"<a href=\\\"https://example.com/\\\">Plain text</a>\",\"replace\":\"Plain text\"}")"

jq -e '.success == true and .data.success == true and .data.replacements == 1' <<<"$post_output" >/dev/null
jq -e '.success == true and .data.success == true and .data.replacements == 1' <<<"$page_output" >/dev/null

IFS=$'\t' read -r POST_CONTENT PAGE_CONTENT POST_TEMPLATE PAGE_TEMPLATE < <(
	ssh "$VPS" "cd '$DEV_PATH' &&
		post_content=\$(wp --allow-root post get '$POST_ID' --field=post_content) &&
		page_content=\$(wp --allow-root post get '$PAGE_ID' --field=post_content) &&
		post_template=\$(wp --allow-root post meta get '$POST_ID' _wp_page_template 2>/dev/null || true) &&
		page_template=\$(wp --allow-root post meta get '$PAGE_ID' _wp_page_template 2>/dev/null || true) &&
		printf '%s\\t%s\\t%s\\t%s\\n' \"\$post_content\" \"\$page_content\" \"\$post_template\" \"\$page_template\""
)

if [[ "$POST_CONTENT" != "$EXPECTED_CONTENT" || "$PAGE_CONTENT" != "$EXPECTED_CONTENT" ]]; then
	printf 'Patched content mismatch. post=%q page=%q\n' "$POST_CONTENT" "$PAGE_CONTENT" >&2
	exit 1
fi

if [[ -n "$POST_TEMPLATE" || -n "$PAGE_TEMPLATE" ]]; then
	printf 'Stale template meta remained. post=%q page=%q\n' "$POST_TEMPLATE" "$PAGE_TEMPLATE" >&2
	exit 1
fi

printf 'Patch template contract passed for post=%s page=%s\n' "$POST_ID" "$PAGE_ID"
