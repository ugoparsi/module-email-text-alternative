# Email Text Alternative for Maho

Add a plain-text version of a rendered HTML email to the same message. Customers can read it in mail clients configured to show plain text.

## Install

The module requires PHP 8.5.1 or later and Maho 26.11.3 or a later patch in the 26.11 series. It also supports the Maho 27.1 development branch.

From your Maho Composer project, register the GitHub repository and install the module.

```bash
composer config repositories.email-text-alternative vcs https://github.com/ugoparsi/module-email-text-alternative
composer require 'ugoparsi/module-email-text-alternative:^0.1'
./maho cache:flush
```

For a Maho source checkout, set `COMPOSER_ROOT_VERSION` on the Composer install command. Use `26.11.3` for that release tag, or `27.1.x-dev` for the 27.1 development branch.

## Enable plain-text alternatives

In **System > Configuration > System > Mail Sending Settings**, set **Add Plain-Text Alternative** to **Yes**. You can set it for all stores, a website, or a store view. It defaults to **No**.

Maho records the setting from the email template's store view when it prepares the message. A queue worker uses that setting when it sends the email. Existing text bodies, HTML, and attachments are preserved. A message with empty conversion output goes out with its original body.

The module processes emails sent through Maho's template dispatcher. Generated text can include Markdown-style headings and links or content hidden by CSS. Let the email queue finish before removing the module so it can remove its internal marker from those messages.

## Test

Install the module in a local Maho source checkout with a fresh test database. The tests use a simulated Symfony mail transport.

```bash
vendor/bin/pest vendor/ugoparsi/module-email-text-alternative/tests/Integration/EmailTextAlternativeTest.php
```

## License

PHP source and tests use [OSL-3.0](LICENSE.txt). Module configuration uses [AFL-3.0](LICENSE_AFL.txt).
