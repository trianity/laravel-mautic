# Contributing

Use PHP 8.5 and Laravel 13 for the default development setup, with PHP 8.4 and
Laravel 12 retained in CI. Install with `composer install`, then run:

```bash
composer test
composer format
composer analyse
composer check
```

The foundation uses Testbench directly, without pest-plugin-laravel. To work on the
Laravel 12 compatibility branch locally, constrain development dependencies:

```bash
composer require --dev --no-update 'laravel/framework:^12.0' 'orchestra/testbench:^10.0' 'pestphp/pest:^4.4'
composer update --with-all-dependencies
composer check
```

These changes select a local test matrix entry. Restore the broad development
constraints from the repository before committing; do not restore source files
containing your work. CI independently selects each framework version.

Write regression tests for observable behavior. Unit tests do not bootstrap Laravel;
feature tests use Orchestra Testbench. Mock the PSR-18 client or Guzzle handler, not
Laravel's HTTP facade. Never use production Mautic data or credentials in tests.

A pull request should explain its behavior and relevant checks, including any
matrix or real-server limitations. Contribution license: MIT.
