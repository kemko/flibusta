#!/bin/sh
set -eu

sh -n "$0"
docker compose config --quiet
docker compose -f docker-compose.test.yml config --quiet
docker compose -f application/tools/external_services_config/docker-compose.yml config --quiet
docker compose -f docker-compose.test.yml build test
docker compose -f docker-compose.test.yml run --rm test
docker compose -f docker-compose.test.yml run --rm nginx-test

docker compose -f docker-compose.test.yml --profile conversion build epub-converter
docker compose -f docker-compose.test.yml --profile conversion run --rm epub-converter
