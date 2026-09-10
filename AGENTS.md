# Repository Guidelines

## Project Structure & Module Organization

This repository runs a local Flibusta library using PHP 8.1, PostgreSQL, and nginx in Docker.

- `application/public/`: web entry points, CSS, bundled JavaScript readers, fonts, and icons.
- `application/modules/<feature>/`: page modules, typically `index.php` and `module.conf`.
- `application/opds/`: OPDS catalog endpoints.
- `application/`: shared initialization, database access, rendering, and helpers.
- `application/tools/`: database import, conversion, and maintenance scripts; `external_services_config/` documents alternate deployments.
- `phpdocker/`: container builds and server configuration.
- `Flibusta.Net/`, `FlibustaSQL/`, and `cache/`: local book archives, SQL dumps, and generated data; contents are ignored by Git.
- `blob/`: README screenshots.

## Build, Test, and Development Commands

Run from the repository root:

- `docker compose build`: build PostgreSQL and PHP images.
- `docker compose up -d`: start the stack; browse `http://localhost:27100` or `/opds/`.
- `docker compose logs php-fpm webserver`: inspect application and server errors.
- `docker compose exec php-fpm php -l /application/public/index.php`: syntax-check a PHP file; substitute each changed path.
- `docker compose config --quiet`: validate Compose configuration.
- `tests/run.sh`: build the isolated test stack, validate both Compose examples, run PHP/Python coverage, syntax checks, and nginx validation.

Place SQL dumps in `FlibustaSQL/` and book ZIPs in `Flibusta.Net/`, then initialize through “Сервис” → “Обновление базы”. Containers need write access to dumps and cache; maintenance scripts need executable permissions.

## Coding Style & Naming Conventions

Match surrounding code: PHP commonly uses tabs, snake_case variables and functions, and uppercase constants. Keep existing indentation in shell, Python, and YAML files. Follow module naming patterns and use PDO parameters for user-supplied query values. No formatter or lint configuration is included. Avoid unrelated formatting changes or edits to bundled vendor assets.

## Testing Guidelines

Run `tests/run.sh` for application changes. It requires Docker and enforces at least 80% coverage for new PHP and Python logic. Syntax-check changed PHP files and manually exercise affected pages with a populated local database when a UI change needs it. Check search, book reading, favorites, or OPDS when relevant. Record reproduction steps and expected results for bug fixes; use disposable data for import testing.

## Commit & Pull Request Guidelines

History uses short descriptive subjects in English and Russian, without a consistent Conventional Commits format. Keep commits focused. PRs should describe behavior changes, verification, and configuration or data implications. Link relevant issues and include screenshots for visible UI changes.

## Configuration & Secrets

Use `FLIBUSTA_DB*` settings and `FLIBUSTA_DBPASSWORD_FILE` as configured in Compose. Never commit real credentials, downloaded archives, or database dumps. Review secret-file permissions when configuring a deployment.
