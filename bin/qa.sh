#!/usr/bin/env bash
set -euo pipefail
composer install
composer qa
npm run lint:js
npm run lint:css
