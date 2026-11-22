#!/usr/bin/env bash
# resolve-env.sh - Resolve .project.ini into .project.local.ini and docker/.env.
#
# In order:
#   1. Preserve existing valid values from .project.local.ini (idempotent re-runs).
#   2. Run ACTION/RUN directives to generate values where missing.
#   3. Validate with CHECK directives.
#   4. Export keys with EXPORT directives to docker/.env.

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMPLATE="${PROJECT_DIR}/.project.ini"
LOCAL="${PROJECT_DIR}/.project.local.ini"
DOCKER_ENV="${PROJECT_DIR}/docker/.env"

if [[ ! -f "${TEMPLATE}" ]]; then
    echo "ERROR: .project.ini not found at ${TEMPLATE}" >&2
    exit 1
fi

# --- helpers --------------------------------------------------------------

ini_get_local() {
    # Read a key from .project.local.ini if present.
    # Args: <section> <key>
    local section="$1" key="$2"
    [[ -f "${LOCAL}" ]] || return 1
    awk -v section="[${section}]" -v key="${key}" '
        $0 == section { in_section = 1; next }
        /^\[/         { in_section = 0 }
        in_section && $0 ~ "^"key"[[:space:]]*=" {
            sub("^[^=]*=[[:space:]]*", "", $0)
            gsub(/^"|"$/, "", $0)
            print $0
            exit
        }
    ' "${LOCAL}"
}

is_changeme() {
    [[ "${1:-CHANGE_ME}" == "CHANGE_ME" || -z "${1:-}" ]]
}

# --- parse template -------------------------------------------------------
# Pipeline: read .project.ini line by line, accumulate directives, and on each
# key/value emit a resolved record. We write .project.local.ini in the same
# section order and docker/.env from EXPORT directives.

mkdir -p "$(dirname "${DOCKER_ENV}")"

TMP_LOCAL="$(mktemp)"
TMP_ENV="$(mktemp)"
trap 'rm -f "${TMP_LOCAL}" "${TMP_ENV}"' EXIT

declare -a directives=()
current_section=""
header_emitted=0

emit_header() {
    cat <<'EOF' > "${TMP_LOCAL}"
; Resolved from .project.ini by bin/resolve-env.sh
; DO NOT commit this file. Secrets live here.

EOF
    cat <<'EOF' > "${TMP_ENV}"
# Generated from .project.ini by bin/resolve-env.sh
# DO NOT commit docker/.env with real secrets.

EOF
    header_emitted=1
}

emit_header

resolve_key() {
    local section="$1" key="$2" template_value="$3"
    local existing run_cmd action="" export_spec="" check="" desc=""
    local value=""

    for d in "${directives[@]:-}"; do
        case "$d" in
            DESC:*)   desc="${d#DESC: }" ;;
            ACTION:*) action="${d#ACTION: }" ;;
            RUN:*)    run_cmd="${d#RUN: }" ;;
            CHECK:*)  check="${d#CHECK: }" ;;
            EXPORT:*) export_spec="${d#EXPORT: }" ;;
        esac
    done

    existing="$(ini_get_local "${section}" "${key}" || true)"

    if [[ -n "${existing}" ]] && ! is_changeme "${existing}"; then
        value="${existing}"
    elif [[ -n "${action:-}" && -n "${run_cmd:-}" ]]; then
        value="$(eval "${run_cmd}")"
    else
        value="${template_value}"
    fi

    # CHECK validation (only the "MUST NOT equal CHANGE_ME" pattern is supported here)
    if [[ "${check}" == *"MUST NOT equal \"CHANGE_ME\""* ]]; then
        if is_changeme "${value}"; then
            echo "WARNING: ${section}.${key} still equals CHANGE_ME - set it manually in .project.local.ini" >&2
        fi
    fi

    # Persist to .project.local.ini
    if [[ -n "${desc}" ]]; then
        echo "; DESC: ${desc}" >> "${TMP_LOCAL}"
    fi
    if [[ -n "${export_spec}" ]]; then
        echo "; EXPORT: ${export_spec}" >> "${TMP_LOCAL}"
    fi
    echo "${key} = \"${value}\"" >> "${TMP_LOCAL}"
    echo "" >> "${TMP_LOCAL}"

    # Export to docker/.env if requested
    if [[ -n "${export_spec}" ]]; then
        # EXPORT: <path> [<format>] [<export_key>]
        local path fmt export_key
        read -r path fmt export_key <<< "${export_spec}"
        export_key="${export_key:-$(echo "${key}" | tr '[:lower:]' '[:upper:]')}"
        fmt="${fmt:-dotenv}"

        case "${fmt}" in
            env)         echo "${export_key}=${value}" >> "${TMP_ENV}" ;;
            env_quoted)  echo "${export_key}=\"${value}\"" >> "${TMP_ENV}" ;;
            shell)       echo "export ${export_key}=${value}" >> "${TMP_ENV}" ;;
            dotenv|raw)  echo "${export_key}=${value}" >> "${TMP_ENV}" ;;
            *)           echo "${export_key}=${value}" >> "${TMP_ENV}" ;;
        esac
    fi
}

while IFS= read -r line || [[ -n "${line}" ]]; do
    # Directive line
    if [[ "${line}" =~ ^\;[[:space:]]+([A-Z]+):[[:space:]]*(.+)$ ]]; then
        directives+=("${BASH_REMATCH[1]}: ${BASH_REMATCH[2]}")
        continue
    fi
    # Section header
    if [[ "${line}" =~ ^\[(.+)\]$ ]]; then
        current_section="${BASH_REMATCH[1]}"
        directives=()
        echo "[${current_section}]" >> "${TMP_LOCAL}"
        continue
    fi
    # Key = value
    if [[ "${line}" =~ ^([a-zA-Z_][a-zA-Z0-9_]*)[[:space:]]*=[[:space:]]*\"?([^\"]*)\"?$ ]]; then
        key="${BASH_REMATCH[1]}"
        value="${BASH_REMATCH[2]}"
        resolve_key "${current_section}" "${key}" "${value}"
        directives=()
        continue
    fi
    # Blank line / file-level comment → drop, do not echo
done < "${TEMPLATE}"

mv "${TMP_LOCAL}" "${LOCAL}"
mv "${TMP_ENV}" "${DOCKER_ENV}"
chmod 600 "${LOCAL}" "${DOCKER_ENV}"

echo "Resolved → ${LOCAL}"
echo "Exported → ${DOCKER_ENV}"
