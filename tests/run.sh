#!/bin/sh
set -eu

docker compose -f docker-compose.test.yml run --rm test
