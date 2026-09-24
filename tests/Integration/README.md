# Integration tests

This directory is reserved for tests that require a real GLPI 11.x instance and
Microsoft services. They are intentionally not executed in the unit-test job
and must never contain production credentials.

Before adding executable integration tests, provide a test-only environment
file outside version control with:

```text
GLPI_BASE_URL=https://glpi-test.example
GLPI_CLIENT_ID=...
GLPI_CLIENT_SECRET=...
TEAMS_TENANT_ID=...
TEAMS_BOT_APP_ID=...
TEAMS_BOT_APP_SECRET=...
TEAMS_SERVICE_URL=https://smba.trafficmanager.net/teams
TEAMS_CONVERSATION_ID=...
```

The current repository contains the scenario checklist in `TESTING.md`; no live
integration result is claimed until the environment is supplied and the tests
are executed.
