# Tests

Automated unit and integration tests are added in the authentication and
provider stages. The skeleton stage only establishes the test layout and does
not claim end-to-end integration coverage.

The authentication unit tests use PHPUnit 11.5, matching the test toolchain of
GLPI 11. Run them from the plugin directory with:

```text
composer install
vendor/bin/phpunit tests/Unit
```
