#!/usr/bin/env bash

set -euo pipefail

# die [<message>]
function die() {
    local s=$?
    printf '%s: %s\n' "${0##*/}" "${1-command failed}" >&2
    ((!s)) && exit 1 || exit "$s"
}

# usage [<error-message>]
function usage() {
    if (($#)); then
        cat >&2 && false || die "$@"
    else
        cat
    fi <<EOF
usage: ${0##*/}                        generate everything
       ${0##*/} --assets               generate code and documentation${1:+
}
EOF
    exit
}

# generate <file> <command> [<argument>...]
function generate() {
    local FILE=$1
    shift
    printf '==> generating %s\n' "$FILE"
    "$@" >"$FILE"
}

[[ ${BASH_SOURCE[0]} -ef scripts/generate.sh ]] ||
    die "must run from root of package folder"

ASSETS=1
if [[ ${1-} == -* ]]; then
    ASSETS=0
fi
while [[ ${1-} == -* ]]; do
    case "$1" in
    --assets)
        ASSETS=1
        ;;
    -h | --help)
        usage
        ;;
    *)
        usage "invalid argument: $1"
        ;;
    esac
    shift
done

if ((ASSETS)); then
    generate docs/Usage.md bin/changelog _md
fi
