# WissKI Cloud Account Manager

## Introduction
This module provides an account creation page at `/cloud-account-manager/create` which can be used to create a new account.
It is intended to be used in combination with the [WissKI Cloud API Daemon]().
If you submit the account form, an email with a validation link is sent to the email address you provided. After the validation, the provision of your WissKI Cloud instance at <subdomain>.wisski.cloud starts and can be checked at `/cloud-account-manager/check/<your validation code>`.
## Requirements
* A correct configured and functional [WissKI Cloud API Daemon](). If you use docker, be sure to have the daemon and drupal site in the same network.
* A valid SMTP System - you may need additional modules for SMTP, i.e. [SMTP Authentication Support](https://www.drupal.org/project/smtp).
## Installation
- Clone this repository into your modules folder and enable the module.
- Enable the module `WissKI Cloud Account Manager` in the Drupal UI.

## Configuration
- Go to `/admin/config/system/cloud-account-manager` and configure the module.

## Maintainers

## License
