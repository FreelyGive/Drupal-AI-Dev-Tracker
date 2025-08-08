# Repository Guidelines

## Project Structure & Module Organization
- Root uses Composer; web root is `web/`.
- Custom code lives in `web/modules/custom/` and `web/themes/custom/`.
- Contrib modules/themes install to `web/modules/contrib/` and `web/themes/contrib/`.
- Configuration sync is tracked in `config/`.
- Recipes live in `recipes/`; vendor dependencies in `vendor/`.

## Build, Test, and Development Commands
- `ddev start`: Start the Docker dev environment.
- `ddev composer install`: Install PHP dependencies and scaffold Drupal into `web/`.
- `ddev launch`: Open the site in your browser.
- `ddev drush cr`: Rebuild caches during development.
- `ddev drush cex -y` / `ddev drush cim -y`: Export/import config to/from `config/`.
- `ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist`: Run PHPUnit tests.

## Coding Style & Naming Conventions
- PHP: Drupal Coding Standard (PSR-12 aligned). Two-space indentation, no tabs.
- File and directory names: modules/themes use `snake_case`; classes use `PascalCase`.
- Linting: `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom`.
- Follow PSR-4 autoloading; place classes under your module’s `src/` namespace.

## Testing Guidelines
- Framework: PHPUnit (available via `require-dev`).
- Location: Place tests under `web/modules/custom/<module>/tests/src/{Unit,Kernel,Functional}`.
- Naming: Suffix test classes with `Test`; mirror namespace of code under test.
- Run focused tests: `ddev exec vendor/bin/phpunit --filter MyServiceTest -c web/core/phpunit.xml.dist`.
- Add tests for new features and regressions; keep them deterministic.

## Commit & Pull Request Guidelines
- Commits: Use clear, imperative subjects (<= 72 chars). Example: `Fix dashboard calendar import edge cases`.
- Reference related issue IDs when applicable.
- Pull requests: include a concise description, reproduction steps, expected/actual results, and screenshots for UI changes.
- Keep PRs focused and small; note config changes (attach `drush cex` diff when relevant).

## Security & Configuration Tips
- Do not commit secrets; keep credentials out of the repo. Use DDEV environment files when needed.
- Respect `config_ignore` for protected settings; export only intended config.
- Prefer `ddev drush` for site ops to ensure a consistent environment.
