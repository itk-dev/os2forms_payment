# OS2Forms payment

## Installation

```sh
composer require os2forms/payment
drush pm:enable os2forms_payment
```

Define settings in `settings.local.php`:

```sh
// CHECKOUT_KEY, both test and production, can be retrieved from Nets admin panel
$settings['os2forms_payment']['checkout_key'] = '';

// SECRET_KEY, both test and production, can be retrieved from Nets admin panel
$settings['os2forms_payment']['secret_key'] = '';

// Static page containing terms and conditions, e.g. /node/87
$settings['os2forms_payment']['terms_url'] = '';

// Boolean describing whether the module is operated in test mode
$settings['os2forms_payment']['test_mode'] = TRUE;
```

## Setup

Make sure that the setting values described above are set.

1. Create a new webform
2. Goto Indstillinger -> Formular ->
 Form preview settings -> Enable preview page (Obligatorisk)
3. Add a payent element on the form
4. Go to the payment element settings and select the element containing the
amount to pay (field types: Skjult, Vælg).
5. Test the form. The Nets gateway should appear on the Review page.

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
