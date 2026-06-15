#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
requested_archive_name="${SKAMASLE_OLS_ARCHIVE_NAME:-}"
if [[ -n "${requested_archive_name}" ]]; then
    archive="${requested_archive_name}"
else
    archive="$(find "${root_dir}/build" -maxdepth 1 -type f \
        -name 'skamasle-ols-plesk-*.zip' -printf '%f\n' | sort -V | tail -n 1)"
fi

if [[ -z "${archive}" ]]; then
    printf 'No extension archive found\n' >&2
    exit 1
fi

archive_path="${root_dir}/build/${archive}"
unzip -tq "${archive_path}" >/dev/null

entries="$(unzip -Z1 "${archive_path}")"
module_id="$(unzip -p "${archive_path}" meta.xml \
    | xmllint --xpath 'string(/module/id)' -)"
vendor="$(unzip -p "${archive_path}" meta.xml \
    | xmllint --xpath 'string(/module/vendor)' -)"

[[ "${module_id}" == 'skamasle-ols' ]]
[[ "${vendor}" == 'Skamasle' ]]
grep -qx 'meta.xml' <<<"${entries}"
grep -qx 'logo.png' <<<"${entries}"
grep -qx 'config/state-schema-v1.json' <<<"${entries}"
grep -qx 'htdocs/index.php' <<<"${entries}"
grep -qx 'plib/library/ControlCommand.php' <<<"${entries}"
grep -qx 'plib/library/ControlPlaneStatus.php' <<<"${entries}"
grep -qx 'plib/library/DesiredStateValidator.php' <<<"${entries}"
grep -qx 'plib/library/DomainReadiness.php' <<<"${entries}"
grep -qx 'plib/library/HtaccessScanner.php' <<<"${entries}"
grep -qx 'plib/library/PhpHandlerParser.php' <<<"${entries}"
grep -qx 'plib/library/PleskTemplateManager.php' <<<"${entries}"
grep -qx 'plib/library/StateStore.php' <<<"${entries}"
grep -qx 'plib/library/TransactionJournal.php' <<<"${entries}"
grep -qx 'sbin/skamasle-ols-lsphp-probe' <<<"${entries}"
grep -qx 'sbin/skamasle-olsctl' <<<"${entries}"
grep -qx 'plib/templates/custom/domain/service/proxy.php' <<<"${entries}"

if grep -Eq '^(tests|daemon|scripts|fixtures)/' <<<"${entries}"; then
    printf 'Development files leaked into %s\n' "${archive}" >&2
    exit 1
fi

if grep -Eq '(^|/)(plesk-ols|openlitespeed)(ctl|-lsphp-probe)?$' <<<"${entries}"; then
    printf 'Legacy product identity leaked into %s\n' "${archive}" >&2
    exit 1
fi

printf 'PASS package %s\n' "${archive}"
