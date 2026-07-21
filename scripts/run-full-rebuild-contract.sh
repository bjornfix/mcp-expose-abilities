#!/usr/bin/env bash
set -euo pipefail

PROXY_DIR="${PROXY_DIR:-/media/bjorn/Stuff/Prosjekter/wordpress-mcp-proxy}"
VPS="${VPS:-hetzner-vps}"
DEV_PATH="${DEV_PATH:-/var/www/dev.devenia.com}"
OLD_CONTENT='<!-- wp:generateblocks/container {"className":"dv-page-999-legacy"} --><div class="wp-block-generateblocks-container dv-page-999-legacy"><p>Old design</p></div><!-- /wp:generateblocks/container -->'
NEW_CONTENT='<!-- wp:generateblocks/container {"className":"dv-surface dv-surface--brand"} --><div class="wp-block-generateblocks-container dv-surface dv-surface--brand"><p>New design</p></div><!-- /wp:generateblocks/container -->'
PLAIN_CONTENT='<!-- wp:paragraph --><p>Accidental flattening</p><!-- /wp:paragraph -->'

old_content_b64="$(printf '%s' "$OLD_CONTENT" | base64 -w0)"
PAGE_ID="$(ssh "$VPS" "cd '$DEV_PATH' && wp --allow-root --skip-plugins --skip-themes post create --post_type=page --post_status=draft --post_title='Full rebuild contract' --post_content=\"\$(printf '%s' '$old_content_b64' | base64 -d)\" --porcelain")"

cleanup() {
	ssh "$VPS" "cd '$DEV_PATH' && wp --allow-root --skip-plugins --skip-themes post delete '$PAGE_ID' --force >/dev/null 2>&1 || true" >/dev/null 2>&1 || true
}
trap cleanup EXIT

run_ability() {
	local params="$1"
	(cd "$PROXY_DIR" && npm run --silent http:call -- execute_ability --site dev --ability content/update-page --params "$params")
}

rebuild_params="$(jq -cn --argjson id "$PAGE_ID" --arg content "$NEW_CONTENT" '{id:$id,content:$content,content_write_mode:"full_rebuild"}')"
rebuild_output="$(run_ability "$rebuild_params")"

jq -e '.success == true and .data.success == true' <<<"$rebuild_output" >/dev/null

stored_content_b64="$(ssh "$VPS" "cd '$DEV_PATH' && wp --allow-root --skip-plugins --skip-themes post get '$PAGE_ID' --field=post_content | base64 -w0")"
stored_content="$(printf '%s' "$stored_content_b64" | base64 -d)"
if [[ "$stored_content" != "$NEW_CONTENT" ]]; then
	printf 'Full rebuild content mismatch.\nExpected: %s\nActual: %s\n' "$NEW_CONTENT" "$stored_content" >&2
	exit 1
fi

guarded_params="$(jq -cn --argjson id "$PAGE_ID" --arg content "$PLAIN_CONTENT" '{id:$id,content:$content}')"
guarded_output="$(run_ability "$guarded_params")"

jq -e '.success == true and .data.success == false' <<<"$guarded_output" >/dev/null
if ! grep -q 'Content update blocked because it would remove existing design markup' <<<"$guarded_output"; then
	printf 'Expected guarded update to retain design-loss protection.\n%s\n' "$guarded_output" >&2
	exit 1
fi

printf 'Full rebuild contract passed for page=%s\n' "$PAGE_ID"
