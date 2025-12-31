#!/usr/bin/env bash
set -e

echo "Installing dependencies with platform requirements ignored..."
composer install --ignore-platform-reqs --no-interaction --no-dev --optimize-autoloader

echo "Build complete!"
