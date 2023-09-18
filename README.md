# OS2Forms payment

## Installation

```sh
composer require os2forms/payment
drush pm:enable os2forms_payment
```

Settings.php / Settings.local.php

```sh
$settings['os2forms_payment']['checkout_key'] = 'CHECKOUT_KEY, both test and production, can be retrieved from Nets admin panel';
$settings['os2forms_payment']['secret_key'] = 'SECRET_KEY, both test and production, can be retrieved from Nets admin panel';
$settings['os2forms_payment']['terms_url'] = 'Static page containing terms and conditions';
$settings['os2forms_payment']['test_mode'] = 'Boolean describing whether the module is operated in test mode';
```

## Setup

Ensure that the values described above is set in your Settings.php <br><br>
Create a new webform <br>
Goto Indstillinger -> Formular -> Form preview settings -> Enable preview page (Obligatorisk) <br>
Insert the payment module in the webform.<br>
Go to the payment element settings and select the element containing the amount to pay (field types: Skjult, Vælg).<br>
Test if the Nets gateway appears on the Review page.

## Coding standards

```sh
docker run --rm --interactive --tty --volume ${PWD}:/app itkdev/php8.1-fpm:latest composer install
docker run --rm --interactive --tty --volume ${PWD}:/app itkdev/php8.1-fpm:latest composer coding-standards-check

docker run --rm --interactive --tty --volume ${PWD}:/app node:18 yarn --cwd /app install
docker run --rm --interactive --tty --volume ${PWD}:/app node:18 yarn --cwd /app coding-standards-check
```

## Code analysis

```sh
docker run --rm --interactive --tty --volume ${PWD}:/app itkdev/php8.1-fpm:latest composer install
docker run --rm --interactive --tty --volume ${PWD}:/app itkdev/php8.1-fpm:latest composer code-analysis
```
