#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_dir="${root_dir}/extension"
build_dir="${root_dir}/build"

if [[ ! -f "${source_dir}/meta.xml" ]]; then
    printf 'Missing extension/meta.xml\n' >&2
    exit 1
fi

mkdir -p "${build_dir}"

version="$(xmllint --xpath 'string(/module/version)' "${source_dir}/meta.xml")"
source_release="$(xmllint --xpath 'string(/module/release)' "${source_dir}/meta.xml")"

if [[ ! "${version}" =~ ^[0-9]+(\.[0-9]+){1,2}$ ]]; then
    printf 'Invalid version in extension/meta.xml: %s\n' "${version}" >&2
    exit 1
fi

if [[ ! "${source_release}" =~ ^[0-9]+$ ]]; then
    printf 'Invalid release in extension/meta.xml: %s\n' "${source_release}" >&2
    exit 1
fi

latest_release=$((source_release - 1))
shopt -s nullglob
for existing_archive in "${build_dir}/skamasle-ols-plesk-${version}-"*.zip; do
    filename="${existing_archive##*/}"
    if [[ "${filename}" =~ ^skamasle-ols-plesk-([0-9]+(\.[0-9]+){1,2})-([0-9]+)\.zip$ ]] \
        && [[ "${BASH_REMATCH[1]}" == "${version}" ]]; then
        release="${BASH_REMATCH[3]}"
        if ((release > latest_release)); then
            latest_release="${release}"
        fi
    fi
done
shopt -u nullglob

release=$((latest_release + 1))
archive="${build_dir}/skamasle-ols-plesk-${version}-${release}.zip"

if [[ -e "${archive}" ]]; then
    printf 'Refusing to overwrite existing build: %s\n' "${archive}" >&2
    exit 1
fi

staging_dir="$(mktemp -d "${TMPDIR:-/tmp}/skamasle-ols-build.XXXXXX")"
trap 'rm -rf "${staging_dir}"' EXIT
cp -a "${source_dir}/." "${staging_dir}/"

if [[ -f "${root_dir}/logo.png" ]]; then
    cp -a "${root_dir}/logo.png" "${staging_dir}/logo.png"
fi

sed -i \
    "s#<release>${source_release}</release>#<release>${release}</release>#" \
    "${staging_dir}/meta.xml"

xmllint --noout "${staging_dir}/meta.xml"

(
    cd "${staging_dir}"
    zip -q -r "${archive}" . \
        -x '*.swp' \
        -x '*~'
)

printf 'Created %s\n' "${archive}"
