#!/usr/bin/env bash
set -euo pipefail

echo "[entrypoint] running docker-install (idempotent)..."
php /var/www/html/bin/docker-install.php

echo "[entrypoint] starting apache..."
exec "$@"
