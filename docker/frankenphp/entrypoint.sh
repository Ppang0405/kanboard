#!/bin/bash
# FrankenPHP entrypoint script for Kanboard

set -e

# Create data directories if they don't exist
mkdir -p /app/data/files /app/data/cache /app/plugins

# Set correct ownership
chown -R www-data:www-data /app/data /app/plugins

# Create default config.php if it doesn't exist in data directory
if [ ! -f /app/data/config.php ]; then
    cat > /app/data/config.php << 'EOF'
<?php
// Kanboard configuration for FrankenPHP
// Copy this file and customize as needed

defined('ENABLE_URL_REWRITE') or define('ENABLE_URL_REWRITE', true);
defined('LOG_DRIVER') or define('LOG_DRIVER', 'stderr');
EOF
    chown www-data:www-data /app/data/config.php
fi

# Symlink config from data directory if not exists in app root
if [ ! -f /app/config.php ] && [ -f /app/data/config.php ]; then
    ln -sf /app/data/config.php /app/config.php
fi

# Execute the main command (FrankenPHP)
exec "$@"
