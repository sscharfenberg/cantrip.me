#!/usr/bin/env bash
# Report TypeScript/Vue declarations in resources/app/ that are not
# immediately preceded by a `/** ... */` docblock.
#
# Usage:
#   resources/scripts/audit-docblocks.sh           # summary + per-file counts
#   resources/scripts/audit-docblocks.sh --list    # full file:line listing
#
# Heuristic (deliberately simple, runs in pure awk — no deps):
#   - .ts: any `export function | async function | const | class | default function`
#     at column 0 that is NOT preceded by a line consisting only of `*/`.
#   - .vue: top-level `function NAME(...)` or `const NAME = (...) =>`
#     declarations inside a `<script>` block, same docblock check.
#   - Only catches column-0 declarations, which is the codebase convention.
#   - A non-JSDoc comment (e.g. `// foo`) still counts as "no docblock".
#
# The list is advisory, not a lint rule — trivial one-line handlers
# (`const onClose = () => emit("close")`) rarely warrant a docblock.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Script lives at resources/scripts/, scan target is resources/app/.
TARGET_DIR="$(cd "${SCRIPT_DIR}/../app" && pwd)"

if [[ ! -d "${TARGET_DIR}" ]]; then
    echo "error: expected ${TARGET_DIR} to exist" >&2
    exit 1
fi

MODE="${1:-summary}"

TS_RESULTS=$(
    find "${TARGET_DIR}" -name "*.ts" -not -path "*/node_modules/*" -print0 \
        | xargs -0 awk '
            FNR==1 { closer=-999 }
            # A docblock closer is any line ending in `*/` — covers both the
            # multi-line `/** … */` form (where `*/` is on its own line) and
            # the single-line `/** … */` form (where `*/` trails content).
            /\*\/[[:space:]]*$/ { closer=FNR; next }
            /^export (async function|function|const|class|default function)/ {
                if (FNR - closer != 1) print FILENAME ":" FNR ": " $0
            }
        '
)

VUE_RESULTS=$(
    find "${TARGET_DIR}" -name "*.vue" -print0 \
        | xargs -0 awk '
            FNR==1 { closer=-999; in_script=0 }
            /^<script/ { in_script=1; next }
            /^<\/script>/ { in_script=0; next }
            !in_script { next }
            /\*\/[[:space:]]*$/ { closer=FNR; next }
            /^(async )?function [a-zA-Z_$][a-zA-Z0-9_$]*[[:space:]]*[(<]/ {
                if (FNR - closer != 1) print FILENAME ":" FNR ": " $0
                next
            }
            /^const [a-zA-Z_$][a-zA-Z0-9_$]*[[:space:]]*=[[:space:]]*(async )?(\([^)]*\)[[:space:]]*(:[^=]+)?[[:space:]]*=>|function)/ {
                if (FNR - closer != 1) print FILENAME ":" FNR ": " $0
            }
        '
)

ts_count=$(printf '%s' "${TS_RESULTS}" | grep -c . || true)
vue_count=$(printf '%s' "${VUE_RESULTS}" | grep -c . || true)

if [[ "${MODE}" == "--list" || "${MODE}" == "-l" ]]; then
    [[ -n "${TS_RESULTS}" ]] && printf '%s\n' "${TS_RESULTS}"
    [[ -n "${VUE_RESULTS}" ]] && printf '%s\n' "${VUE_RESULTS}"
    echo
fi

echo "Undocumented TS exports : ${ts_count}"
echo "Undocumented Vue fns    : ${vue_count}"
echo

echo "Worst .vue offenders:"
printf '%s\n' "${VUE_RESULTS}" \
    | awk -F: 'NF { file[$1]++ } END { for (f in file) printf "  %3d  %s\n", file[f], f }' \
    | sort -rn \
    | head -15