#!/usr/bin/env bash
set -euo pipefail

echo "[entrypoint] running docker-install (idempotent)..."
php /var/www/html/bin/docker-install.php

# Fee-bump loop: ticks every 60s and self-throttles internally against the
# `fee_bump_interval_min` setting (Admin Panel -> Mempool & Fee Bumping), so
# the effective interval is configurable at runtime without touching this
# container's process supervision.
echo "[entrypoint] starting fee-bump loop..."
(
  while true; do
    php /var/www/html/bin/fee-bump-cron.php || true
    sleep 60
  done
) &

echo "[entrypoint] starting apache..."
exec "$@"
