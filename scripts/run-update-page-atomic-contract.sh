#!/usr/bin/env bash
set -euo pipefail

CLI_CALL="${CLI_CALL:-/media/bjorn/Stuff/Prosjekter/plugins/wordpress-plugins/scripts/dev-wp-cli-call-ability.sh}"
PAGE_ID=""
ORIGINAL_CONTENT='<!-- wp:paragraph --><p>Original atomic content.</p><!-- /wp:paragraph -->'
REPLACEMENT_CONTENT='<!-- wp:paragraph --><p>This must not persist.</p><!-- /wp:paragraph -->'

call_ability() {
	local ability="$1"
	local params="$2"
	"$CLI_CALL" "$ability" "$params"
}

cleanup() {
	if [[ -n "$PAGE_ID" ]]; then
		call_ability content/delete-page "$(jq -cn --argjson id "$PAGE_ID" '{id:$id,force:true}')" >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

created="$(call_ability content/create-page "$(jq -cn --arg content "$ORIGINAL_CONTENT" '{title:"Update page atomic contract",status:"draft",content:$content}')")"
PAGE_ID="$(jq -er '.data.id' <<<"$created")"

update="$(call_ability content/update-page "$(jq -cn --argjson id "$PAGE_ID" --arg content "$REPLACEMENT_CONTENT" '{id:$id,content:$content,template:"missing-template-contract.php"}')")"
if ! jq -e '.data.success == false' <<<"$update" >/dev/null; then
	printf 'FAIL: invalid template did not reject update-page.\n%s\n' "$update" >&2
	exit 1
fi

stored="$(call_ability content/get-page "$(jq -cn --argjson id "$PAGE_ID" '{id:$id}')")"
actual="$(jq -er '.data.content | if type == "object" then .raw else . end' <<<"$stored")"
if [[ "$actual" != "$ORIGINAL_CONTENT" ]]; then
	printf 'FAIL: update-page mutated content before rejecting invalid template.\nExpected: %s\nActual: %s\n' "$ORIGINAL_CONTENT" "$actual" >&2
	exit 1
fi

printf 'Update-page atomic preflight contract passed for page=%s\n' "$PAGE_ID"
