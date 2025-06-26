Files in the `.devpanel` directory control DevPanel deployment for this app.


## Startup scripts

- [`custom_package_installer.sh`](custom_package_installer.sh): Installs
  extra system software. Use sudo to run as root.
- [`init-container.sh`](init-container.sh): Checks for a database dump and
  imports it.
- [`init.sh`](init.sh): Performs additional startup tasks. Supporting files:
  - [`composer_setup.sh`](composer_setup.sh): Generates composer.json and
    composer.lock.
  - [`settings.devpanel.php`](settings.devpanel.php): Settings for running
    Drupal as a DevPanel app.
  - [`drupal-settings.patch`](drupal-settings.patch): Patch for settings.php
    to include settings.devpanel.php.
  - [`warm`](warm): Loads any path to build caches. If no path is provided,
    defaults to /.


## Git integration

- [`config.yml`](config.yml): Defines tasks to run when Git is configured to
  update the app automatically.


## Deployment

- [`re-config.sh`](re-config.sh): Runs when container configuration is
  changed in DevPanel or the app is deployed to a hosting provider.


## Creating a Docker image

- [`create_quickstart.sh`](quickstart/create_quickstart.sh): Archives the
  database and files for the _Drupal Forge Docker Publish Workflow_ which can be
  added in [GitHub Actions](https://github.com/drupalforge/starter_template/actions).
