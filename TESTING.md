# Testing

## Local unit tests

The plugin requires PHP 8.2 or newer and PHPUnit 11.5 or newer for the
development test suite:

```text
composer install
vendor/bin/phpunit --testsuite "GLPI Microsoft Teams Integration unit tests"
```

The test suite must be run from the plugin directory. It must not receive real
client secrets, access tokens, refresh tokens, or production URLs.

## Static checks

Run PHP syntax validation before packaging:

```text
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
composer validate --no-check-publish
```

## Integration prerequisites

Integration testing requires an isolated GLPI 11.x installation, a test
Microsoft Entra tenant, a Bot Framework registration, a Teams test team/channel,
and a GLPI OAuth client using Authorization Code grant. The test tenant must not
contain production tickets or credentials.

The Bot Framework endpoint must be reachable over HTTPS and configured as the
messaging endpoint of the bot. Bot activities are validated using the Bot
Framework OpenID metadata and JWT signature before any GLPI operation is made.

## Integration scenarios

| Scenario | Expected result |
| --- | --- |
| Invalid bearer token | HTTP 401; no GLPI request |
| Invalid JSON/activity | HTTP 400; no GLPI request |
| Duplicate `activity.id` | HTTP 200; command is not repeated |
| User without GLPI link | Informative Teams response; no ticket mutation |
| `vincular` | OAuth authorization URL is returned |
| New ticket hook | Event enters outbox; GLPI operation is not blocked by Teams failure |
| Follow-up hook | Sanitized content enters outbox |
| Solution/closure/status hook | Correct notification flag is respected |
| Create ticket command | Ticket is created by the linked GLPI user |
| Ticket detail command | GLPI permissions determine visibility |
| Follow-up command without permission | GLPI rejects the operation |
| Status command without permission | GLPI rejects the operation |
| Teams HTTP 429 | `Retry-After` and bounded retry are respected |
| Teams timeout | GLPI transaction remains independent; error is logged |
| Five failed delivery attempts | Outbox item becomes `failed` |
| Secret in content/log context | Value is redacted and never sent to Teams |

## Manual verification record

The release record must include:

- exact GLPI version;
- PHP version and enabled extensions;
- Microsoft Entra tenant/cloud;
- bot application ID (never the secret);
- Teams scope and test channel;
- date/time of each scenario;
- HTTP status and sanitized request ID;
- PHPUnit and PHP lint output.

Tests not executed in the current development environment must remain marked as
pending; they must not be reported as passed.
