#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_URL="${REPOSITORY_URL:-https://repo-magento-mirror.fooman.co.nz/}"

# Remove any default Magento repo entries and replace with the mirror.
composer config --unset repositories.repo.magento.com >/dev/null 2>&1 || true
composer config --unset repositories.magento >/dev/null 2>&1 || true
composer config --unset repositories.0 >/dev/null 2>&1 || true
composer config repositories.magento composer "${REPOSITORY_URL}"
