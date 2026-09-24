# GLPI Microsoft Teams Integration

GLPI plugin that connects GLPI tickets with Microsoft Teams. GLPI remains the
system of record: ticket events are queued in GLPI and delivered to Teams by a
cron task, while messages received from Teams are authenticated and executed
through the GLPI API on behalf of the linked user.

This repository is intended for contributors as well as GLPI administrators.
The implementation is still evolving, so the status and limitations below are
part of the project documentation.

## Current status

Implemented:

- GLPI plugin installation, activation, configuration, and uninstall hooks;
- GLPI 11 plugin configuration storage, including encrypted secrets;
- database tables for routes, bot keys, user links, OAuth state, events,
  outbox entries, and logs;
- GLPI hooks for new tickets, ticket updates, follow-ups, and solutions;
- asynchronous notification delivery through the GLPI cron system;
- idempotent outbox events, bounded retries, `Retry-After` support, and
  exponential backoff;
- Microsoft Entra client-credentials authentication for the Bot Framework
  Connector;
- proactive Teams messages with Adaptive Cards and links back to GLPI;
- Bot Framework activity endpoint with JWT signature, issuer, audience,
  tenant, service URL, and expiration validation;
- duplicate activity protection using the activity ID;
- one-time GLPI OAuth linking flow for Teams users;
- encrypted per-user GLPI access and refresh token storage;
- Teams commands for creating, listing, viewing, and updating GLPI tickets;
- content sanitization and secret-safe logging;
- unit tests for HTTP responses, OAuth, token validation, command parsing, and
  content sanitization.

Not complete yet:

- live end-to-end verification against a GLPI 11 installation and a test
  Microsoft Entra/Teams tenant;
- a full administration UI for multiple entity routes;
- Teams interactive Adaptive Card actions;
- Microsoft Graph-based application/team provisioning;
- live integration tests and a finalized release compatibility report.

Do not describe a pending integration scenario as verified unless it has been
run in an isolated test environment and recorded as described in
[TESTING.md](TESTING.md).

## Requirements

- GLPI 11.0 or newer;
- PHP 8.2 or newer;
- PHP cURL extension;
- MySQL or MariaDB supported by the installed GLPI version;
- Composer for development and PHPUnit tests;
- a Microsoft Entra application and Bot Framework registration for Teams
  communication;
- a publicly reachable HTTPS GLPI URL for the webhook and OAuth callback.

The plugin uses GLPI's `/api.php/v2` API for user actions. The exact API
minor version exposed by the target GLPI installation must be recorded during
integration verification.

## Repository layout

```text
.
├── front/
│   ├── config.form.php              GLPI administration page
│   ├── glpi-oauth.callback.php      GLPI OAuth callback
│   └── teams.webhook.php            Bot Framework activity endpoint
├── src/
│   ├── Api/                          HTTP, GLPI, OAuth, and Teams clients
│   ├── Authentication/               OAuth state and token storage
│   ├── Controller/                   Webhook request orchestration
│   ├── Exception/                    Domain and provider exceptions
│   ├── Security/                     Bot Framework JWT validation
│   ├── Service/                      Configuration, commands, events, and outbox
│   └── PluginTeamsPluginCronTask.php GLPI cron adapter
├── tests/Unit/                       PHPUnit unit tests
├── tests/Integration/                Live-test documentation and reserved tests
├── docs/compatibility.md             Verified compatibility matrix
├── hook.php                          GLPI hooks, installation, and uninstall
├── setup.php                         Plugin registration and prerequisites
├── TESTING.md                        Test and release verification guide
└── composer.json                     PHP and PHPUnit metadata
```

The `GlpiPlugin\\Teams\\` namespace maps to `src/` through Composer PSR-4
autoloading. GLPI's plugin loader still includes the classes from `setup.php`
when the plugin is installed in a GLPI instance.

## Install the plugin in GLPI

For a development checkout:

1. Place this repository at `<glpi>/plugins/teams`. The directory name must be
   `teams`, because it is the plugin key used by GLPI.
2. Open **Setup > Plugins** in GLPI.
3. Install and activate **GLPI Microsoft Teams Integration**.
4. Open the plugin configuration page and complete the provider settings below.
5. Run or wait for the GLPI cron task after creating a test ticket.

Installation creates the plugin tables and registers the outbox cron task.
Uninstall removes the plugin tables and configuration values. Export or back
up any data before uninstalling; uninstall is not a migration-safe way to
reset a production installation.

## Configuration

The configuration page is available from the plugin entry in GLPI. The user
needs the GLPI configuration update permission to save settings. Secrets may
be left blank when rotating or editing other fields; a blank secret keeps the
stored value.

### Microsoft Entra ID and bot

| Setting | Purpose |
| --- | --- |
| `tenant_id` | Microsoft Entra directory/tenant identifier |
| `bot_app_id` | Bot application/client ID used by the Bot Framework token flow |
| `bot_app_secret` | Secret for the bot application |
| `client_id`, `client_secret` | Reserved provider application settings retained by the configuration model |
| `teams_app_id` | Teams application identifier, retained for provider setup and future capabilities |

The bot access token is requested from the tenant-specific Microsoft identity
endpoint. Never commit the secret or paste it into an issue, test fixture, or
log.

### Message routing and public URLs

| Setting | Purpose |
| --- | --- |
| `default_team_id` | Default Teams team identifier |
| `default_channel_id` | Default Teams channel identifier |
| `default_conversation_id` | Bot Framework conversation identifier |
| `default_service_url` | Bot Framework service URL for the conversation |
| `webhook_url` | Public Bot Framework webhook URL used when registering the bot |
| `base_url` | Public GLPI base URL used for API requests and ticket links |

The default route requires all of `default_team_id`,
`default_channel_id`, `default_conversation_id`, and `default_service_url`.
The outbox can also resolve an active route from
`glpi_plugin_teams_routes` by GLPI entity. A complete route-management UI is
not implemented yet.

### GLPI OAuth

| Setting | Purpose |
| --- | --- |
| `glpi_redirect_uri` | Exact HTTPS callback registered in the GLPI OAuth client |
| `glpi_api_client_id` | GLPI OAuth client ID |
| `glpi_api_client_secret` | GLPI OAuth client secret |
| `glpi_oauth_scope` | Requested GLPI OAuth scope; default is `api user email` |

The redirect URI must match the URL configured in GLPI exactly. The callback
requires an authenticated GLPI session and consumes a one-time state value.

### Notification switches

The integration must be enabled before notifications are sent. Each event can
be enabled or disabled independently:

| Setting | Event |
| --- | --- |
| `notify_new_ticket` | New ticket |
| `notify_ticket_update` | General ticket update |
| `notify_followup` | New follow-up |
| `notify_status` | Status change |
| `notify_priority` | Priority change |
| `notify_assignment` | Assignment change |
| `notify_solution` | Solution |
| `notify_closure` | Closure |

The administration page also provides connection and test-message actions and
shows pending, processing, sent, and failed outbox counts.

## Public endpoints

When the plugin is installed at `<glpi>/plugins/teams`, the entry points are:

| Endpoint | Use |
| --- | --- |
| `/plugins/teams/front/teams.webhook.php` | Bot Framework activity endpoint |
| `/plugins/teams/front/glpi-oauth.callback.php` | GLPI OAuth linking callback |

Configure the bot's Bot Framework messaging endpoint to the webhook URL. The
endpoint must be HTTPS and must be able to receive the
`Authorization: Bearer` header. Do not bypass token validation at a reverse
proxy.

## Teams commands

Send these messages to the bot. `#` before a ticket number is optional.

| Command | Result |
| --- | --- |
| `ajuda`, `help` | Show the command list |
| `vincular`, `associar`, `link` | Start GLPI account linking |
| `meus chamados`, `meus tickets` | List tickets where the linked user is the requester |
| `chamado <id>`, `ticket <id>` | Show ticket details |
| `novo chamado \\| título \\| descrição` | Create a ticket |
| `acompanhar <id> \\| comentário` | Add a public follow-up |
| `status <id> \\| <status id>` | Update a ticket status |

The linked user's GLPI permissions still apply. A Teams identity without an
active GLPI link cannot perform ticket operations. The current list command
filters the GLPI API result to requester-owned tickets and returns at most 20
items.

## Runtime flow

### GLPI to Teams

1. A GLPI item hook receives a ticket, follow-up, solution, or update event.
2. `NotificationService` checks the corresponding notification flag,
   sanitizes the payload, and creates an idempotent outbox event.
3. GLPI cron invokes the outbox worker, which locks pending entries.
4. `TeamsMessageService` resolves the entity route or default route and builds
   a text message plus Adaptive Card.
5. `TeamsBotClient` obtains a bot token and sends the activity to the Bot
   Framework Connector.
6. Temporary provider failures are retried with `Retry-After` or bounded
   exponential backoff. After five attempts, the entry is marked `failed`.

The hook only queues the notification. A Teams outage should not block the
original GLPI ticket transaction.

### Teams to GLPI

1. Bot Framework sends an activity to `teams.webhook.php`.
2. `BotFrameworkTokenValidator` loads the Bot Framework OpenID metadata/JWKS
   and validates the bearer token before processing the activity.
3. `EventStoreService` prevents a duplicate activity ID from being executed
   twice.
4. `TeamsCommandParser` sanitizes and parses the message.
5. `UserMappingService` finds the linked GLPI user and refreshes the OAuth
   token when necessary.
6. `GlpiClient` calls `/api.php/v2` with the linked user's bearer token.
7. The bot replies in the originating conversation with a success or safe
   error message.

## Security and data handling

- Configuration secrets are registered with GLPI's secured configuration and
  GLPIKey mechanism.
- Per-user OAuth access and refresh tokens use GLPI secured fields.
- OAuth state is stored hashed, expires, and can be consumed only once.
- Bot activities require a valid signed JWT; issuer, audience, tenant, service
  URL, and time claims are checked.
- Incoming and outgoing content is sanitized and length-limited.
- Logs use request IDs and redact sensitive values. Provider responses,
  authorization codes, and token contents must not be exposed to users.
- Tests must use fake values only. Do not use production tickets, URLs, client
  secrets, access tokens, refresh tokens, or tenant credentials.

If you discover a security issue, do not open a public issue with credentials
or an exploit. Contact the project maintainers privately and include the
smallest reproducible details possible.

## Development setup

From the plugin directory:

```bash
composer install
```

The production dependency list is intentionally small: PHP 8.2+ is required.
PHPUnit 11.5+ is a development dependency. The unit suite does not require a
live GLPI installation or Microsoft credentials.

## Tests and checks

Run the unit tests:

```bash
vendor/bin/phpunit tests/Unit
```

Or run the named suite:

```bash
vendor/bin/phpunit --testsuite "GLPI Microsoft Teams Integration unit tests"
```

Run PHP syntax checks in PowerShell:

```powershell
Get-ChildItem -Recurse -Filter *.php |
    ForEach-Object { php -l $_.FullName }
composer validate --no-check-publish
```

Before claiming a release is tested, follow the scenarios and manual record
requirements in [TESTING.md](TESTING.md). Live integration tests belong in
`tests/Integration/` and must be isolated from production services. See
[docs/compatibility.md](docs/compatibility.md) for the current verification
matrix.

## Contributing

1. Create a focused branch from the current development branch.
2. Read the relevant code and tests before changing behavior. Preserve GLPI
   hook contracts and the separation between provider clients and services.
3. Add or update unit tests for parser, validation, storage, retry, and error
   handling changes. Add live scenarios only under the integration-test rules.
4. Run PHPUnit, PHP lint, and Composer validation locally.
5. Update this README, `TESTING.md`, or `docs/compatibility.md` when public
   behavior, configuration, security assumptions, or verification status
   changes.
6. Open a pull request describing the behavior change, migration impact,
   security impact, tests run, and any pending manual verification.

Useful contribution rules:

- Keep provider-specific HTTP code in `src/Api/`.
- Keep orchestration and business rules in `src/Service/`.
- Keep web entry points thin; construct dependencies and delegate to a
  controller or service.
- Preserve idempotency for events and webhook activities.
- Never make Teams delivery a synchronous dependency of a GLPI write.
- Avoid logging secrets, authorization codes, bearer tokens, refresh tokens,
  or full provider payloads.
- Do not silently broaden ticket visibility beyond the linked user's GLPI
  permissions.

## Troubleshooting

### The plugin does not appear in GLPI

Confirm that the directory is exactly `<glpi>/plugins/teams`, that GLPI can
read `setup.php`, and that the server is running PHP 8.2 or newer.

### Notifications remain pending

Confirm that the integration is enabled, all default route fields are set, the
GLPI cron system is running, and the Bot Framework service URL is reachable.
Inspect the outbox counts and latest log entry on the configuration page.

### The bot returns an authentication or configuration message

Check the tenant ID, bot application ID/secret, public HTTPS webhook URL, and
that the activity is arriving with its bearer token intact. Also verify that
the Bot Framework service URL in the activity matches the configured route.

### `vincular` does not complete

Verify the GLPI OAuth client, exact redirect URI, API client credentials,
scope, public `base_url`, and that the browser session is authenticated to
GLPI when the callback is opened.

### A user cannot access a ticket from Teams

The integration does not elevate GLPI permissions. Link the correct GLPI
account and verify that the account is allowed to view or modify the ticket in
GLPI itself.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
