# Repository Guidelines

## Project Structure & Module Organization
- Root uses Composer; Drupal web root is `web/`.
- Custom code: `web/modules/custom/` and `web/themes/custom/`.
- Contrib code: `web/modules/contrib/` and `web/themes/contrib/`.
- Config sync: `config/`; recipes: `recipes/`; dependencies: `vendor/`.
- Tests live under each module: `web/modules/custom/<module>/tests/src/{Unit,Kernel,Functional}`.

## Build, Test, and Development Commands
- `ddev start`: Boot the Docker dev environment.
- `ddev composer install`: Install PHP deps and scaffold Drupal into `web/`.
- `ddev launch`: Open the site in a browser.
- `ddev drush cr`: Rebuild Drupal caches during development.
- `ddev drush cex -y` / `ddev drush cim -y`: Export/import config to/from `config/`.
- `ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist`: Run all PHPUnit tests.
- Lint: `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom`.

## Coding Style & Naming Conventions
- PHP follows Drupal Coding Standard (PSR-12 aligned); two-space indentation, no tabs.
- Modules/themes use `snake_case`; classes use `PascalCase`.
- PSR-4 autoloading: place classes under your module’s `src/` namespace.
- Keep functions small, DI-friendly; avoid global state; prefer services.

## Testing Guidelines
- Framework: PHPUnit.
- Location: mirror code namespaces under `tests/src/{Unit,Kernel,Functional}`.
- Naming: suffix test classes with `Test` (e.g., `MyServiceTest`).
- Run focused tests: `ddev exec vendor/bin/phpunit --filter MyServiceTest -c web/core/phpunit.xml.dist`.
- Keep tests deterministic; mock external services; assert explicit outcomes.

## Commit & Pull Request Guidelines
- Commits: clear, imperative subjects (<= 72 chars). Example: `Add queue worker for CSV imports`.
- Reference related issue IDs when applicable.
- PRs: concise description, reproduction steps, expected vs. actual, and screenshots for UI changes.
- Note config changes; include `ddev drush cex -y` diff when relevant.

## Security & Configuration Tips
- Never commit secrets; use DDEV env files as needed.
- Respect `config_ignore`; export only intended config.
- Prefer `ddev drush` for site ops to ensure consistent environment behavior.
