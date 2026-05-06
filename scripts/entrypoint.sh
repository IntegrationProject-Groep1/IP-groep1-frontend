#!/bin/bash
set -e

DRUSH=/opt/drupal/vendor/bin/drush

# Wait for the database to be ready (max 60s)
echo "[entrypoint] Waiting for database..."
for i in $(seq 1 30); do
    if $DRUSH sql:query "SELECT 1" > /dev/null 2>&1; then
        echo "[entrypoint] Database ready."
        break
    fi
    sleep 2
done

# Enable rabbitmq_sender so all dependent modules can resolve their services
echo "[entrypoint] Enabling rabbitmq_sender module..."
$DRUSH en rabbitmq_sender -y 2>&1 || echo "[entrypoint] WARNING: could not enable rabbitmq_sender"

# Rebuild the service container
echo "[entrypoint] Clearing Drupal cache..."
$DRUSH cr 2>&1 || echo "[entrypoint] WARNING: drush cr failed"

echo "[entrypoint] Starting Apache..."
exec apache2-foreground
