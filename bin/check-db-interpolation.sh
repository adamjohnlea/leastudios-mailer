#!/usr/bin/env bash
#
# leastudios-* DB-interpolation tripwire.
#
# Suite-wide rule (see leastudios-dev-tools/CLAUDE.md > Database):
#   No interpolated table/column names in $wpdb calls — use %i in $wpdb->prepare().
#
# This script fails the lint job if it finds either:
#   1. A $wpdb->(prepare|get_results|get_row|get_var|query)() call whose argument
#      contains brace-style ${...} interpolation.
#   2. A `phpcs:ignore` or `phpcs:disable` for
#      WordPress.DB.PreparedSQL.InterpolatedNotPrepared.
#
# Files genuinely exempt (whitelisted-vocabulary cases such as dynamic IN-lists
# or filter-keyed WHERE clauses) can be listed in `.dblint-allow` at the plugin
# root, one path per line. Adding a file to that list must always be accompanied
# by a rationale comment inline at the offending site.
#
# This file is shared-by-duplication across all leastudios-* plugins (kept
# byte-identical by leastudios-dev-tools/bin/check-shared.sh).

set -u
set -o pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

ALLOWLIST=".dblint-allow"
declare -a ALLOWED=()
if [[ -f "$ALLOWLIST" ]]; then
	while IFS= read -r line; do
		line="${line%$'\r'}"
		[[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
		ALLOWED+=("$line")
	done < "$ALLOWLIST"
fi

is_allowed() {
	local file="$1"
	# Normalize leading "./" so find output matches allowlist entries.
	file="${file#./}"
	local allowed
	for allowed in "${ALLOWED[@]+"${ALLOWED[@]}"}"; do
		if [[ "$file" == "$allowed" ]]; then
			return 0
		fi
	done
	return 1
}

INTERPOLATION_PATTERN='\$wpdb->(prepare|get_results|get_row|get_var|query)\([^)]*\{\$'
IGNORE_PATTERN='phpcs:(ignore|disable).*WordPress\.DB\.PreparedSQL\.InterpolatedNotPrepared'

if [[ ! -d src ]]; then
	echo "[lint:db OK] no src/ directory (nothing to check)."
	exit 0
fi

FAILED=0
INTERPOLATION_HITS=""
IGNORE_HITS=""

while IFS= read -r -d '' file; do
	if is_allowed "$file"; then
		continue
	fi
	match=$(grep -EHn "$INTERPOLATION_PATTERN" "$file" 2>/dev/null) || true
	if [[ -n "$match" ]]; then
		INTERPOLATION_HITS+="${match}"$'\n'
	fi
	match=$(grep -EHn "$IGNORE_PATTERN" "$file" 2>/dev/null) || true
	if [[ -n "$match" ]]; then
		IGNORE_HITS+="${match}"$'\n'
	fi
done < <(find src -type f -name '*.php' -print0)

if [[ -n "$INTERPOLATION_HITS" ]]; then
	printf '%s' "$INTERPOLATION_HITS" >&2
	echo >&2
	echo "[lint:db FAIL] \$wpdb calls with brace-style interpolation found above." >&2
	echo "  Use %i for table/column identifiers; bind values via %s/%d in \$wpdb->prepare()." >&2
	echo "  If this is a genuinely-safe case (e.g. a whitelisted-vocabulary fragment)," >&2
	echo "  add the file path to .dblint-allow and document why in an inline comment." >&2
	FAILED=1
fi

if [[ -n "$IGNORE_HITS" ]]; then
	[[ -n "$INTERPOLATION_HITS" ]] && echo >&2
	printf '%s' "$IGNORE_HITS" >&2
	echo >&2
	echo "[lint:db FAIL] phpcs:ignore/disable for InterpolatedNotPrepared found above." >&2
	echo "  Convert the interpolation to %i instead of silencing the sniff." >&2
	echo "  If genuinely whitelisted, add the file to .dblint-allow with a rationale." >&2
	FAILED=1
fi

if [[ "$FAILED" -ne 0 ]]; then
	exit 1
fi

echo "[lint:db OK] no \$wpdb interpolation antipatterns found in src/."
