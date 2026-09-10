#!/bin/sh
set -eu

sh -n "$0"
docker compose -f docker-compose.test.yml config --quiet
docker compose -f docker-compose.test.yml build test
docker compose -f docker-compose.test.yml run --rm test
docker compose -f docker-compose.test.yml run --rm nginx-test
