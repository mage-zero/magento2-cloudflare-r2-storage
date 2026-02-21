#!/bin/sh

set -e

REPOSITORY_URL="${REPOSITORY_URL:-https://repo-magento-mirror.fooman.co.nz/}"

# Remove any default Magento repo entries and replace with the mirror.
composer config --unset repositories.repo.magento.com >/dev/null 2>&1 || true
composer config --unset repositories.magento >/dev/null 2>&1 || true
composer config --unset repositories.0 >/dev/null 2>&1 || true
composer config repositories.magento composer "${REPOSITORY_URL}"

# Older Magento test matrices can include packages that Composer flags as insecure.
# We keep CI installable for compatibility verification by disabling hard blocking.
composer config --global --no-plugins audit.block-insecure false || true
