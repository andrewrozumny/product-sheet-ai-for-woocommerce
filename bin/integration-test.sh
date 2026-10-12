#!/usr/bin/env bash
# Integration test: runs checks through WP-CLI in the lab site.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# The plugin lives in <site>/wp-content/plugins/<slug>. A dev site may ship a ./wp wrapper next to <site>.
SITE_ROOT="${SITE_ROOT:-$(cd "$PLUGIN_DIR/../../.." && pwd)}"
if [ -z "${WP_CLI:-}" ]; then
	if [ -x "$SITE_ROOT/../wp" ]; then WP_CLI="$SITE_ROOT/../wp"; else WP_CLI="wp --path=$SITE_ROOT"; fi
fi
LAB_ROOT="$SITE_ROOT"
DEBUG_LOG="${LAB_ROOT}/wp-content/debug.log"
PLUGIN_PATH_MARK="plugins/product-sheet-ai-for-woocommerce/"

before=0
[ -f "$DEBUG_LOG" ] && before=$(wc -c < "$DEBUG_LOG" | tr -d ' ')

# --- Scenarios ---------------------------------------------------------------
loaded="$($WP_CLI eval 'echo class_exists( "ProductSheetAI\\Plugin" ) ? "loaded" : "missing";')"
if [ "$loaded" != "loaded" ]; then
	echo "FAIL: ProductSheetAI\\Plugin is ${loaded}; is the plugin active?" >&2
	exit 1
fi
echo "Integration: plugin loaded"
# The scenarios mock the AI provider, short-circuit the settings with a fake key and clean up after themselves.
$WP_CLI eval-file "wp-content/plugins/product-sheet-ai-for-woocommerce/tests/Integration/run.php"
# --- End of scenarios --------------------------------------------------------

# --- debug.log check (keep) --------------------------------------------------
if [ -f "$DEBUG_LOG" ]; then
	new_lines="$(tail -c +"$((before + 1))" "$DEBUG_LOG" | grep -E 'PHP (Fatal|Warning)' | grep -F "$PLUGIN_PATH_MARK" || true)"
	if [ -n "$new_lines" ]; then
		echo "FAIL: PHP Fatal/Warning from this plugin in debug.log:" >&2
		echo "$new_lines" >&2
		exit 1
	fi
fi
echo "Integration: OK"
