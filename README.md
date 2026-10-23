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

// Page containing terms and conditions URL, including protocol.
$settings['os2forms_payment']['terms_url'] = '';

// Page containing merchant terms URL, including protocol.
$settings['os2forms_payment']['merchant_terms_url'] = '';

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

## Development

We use [DDEV Drupal Contrib](https://github.com/ddev/ddev-drupal-contrib) for development[^1].

[^1]: The DDEV environment has been created by running

      ```shell
      ddev config --project-type=drupal10 --docroot=Drupal
      ddev add-on get ddev/ddev-drupal-contrib
      ddev start
      ddev poser --no-interaction
      ddev symlink-project
      # Detect expected Drupal and PHP versions.
      ddev config --update
      ```

      (cf. <https://github.com/ddev/ddev-drupal-contrib#install>)

Start the show by running

```shell
task ddev:start
```

Run `task` to see other useful development tasks.
