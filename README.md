# Azure AD Delta Sync for Drupal

Drupal module for [Azure AD Delta Sync](https://github.com/itk-dev/azure-ad-delta-sync).

## Installation

```sh
composer require itk-dev/azure_ad_delta_sync
vendor/bin/drush pm:enable adgangsstyring
```

Go to `/admin/config/adgangsstyring` to set up the module.

You will probably want to add api keys in `settings.local.php`, i.e.

```php
# settings.local.php

$config['adgangsstyring.settings']['client_id'] = '…';
$config['adgangsstyring.settings']['client_secret'] = '…';
$config['adgangsstyring.settings']['group_id'] = '…';
$config['adgangsstyring.settings']['tenant_id'] = '…';
```

Furthermore, you may want to install the [Config
Ignore](https://www.drupal.org/project/config_ignore) module and ignore the
`adgangsstyring.settings` config if committing config to a version control
system.

## Usage

A cron job should run the following command at regular intervals:

```sh
vendor/bin/drush adgangsstyring:run --force
```

Run `vendor/bin/drush adgangsstyring:run --help` for details on the command.

## Coding standards

```sh
composer install
composer coding-standards-check
```

```sh
composer coding-standards-apply
```
