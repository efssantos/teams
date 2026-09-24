# Compatibility matrix

This matrix records what has been verified. It does not claim compatibility
merely because the source code parses.

| Component | Target | Verification status |
| --- | --- | --- |
| GLPI | 11.x | Static API review completed; live installation pending |
| PHP | 8.2+ | Declared by plugin and Composer; runtime lint pending |
| Microsoft Graph | Not required for bot message transport | Graph provisioning still pending |
| Bot Framework Connector | v3 activities endpoint | Contract reviewed; live bot test pending |
| Microsoft Teams | Current Teams bot channel | Live tenant test pending |
| Microsoft Entra ID | OAuth 2.0 and client credentials | Contract reviewed; live tenant test pending |
| MySQL/MariaDB | Version supported by GLPI 11.x | Live database migration test pending |

The GLPI high-level REST API is versioned by URL. The adapter uses
`/api.php/v2`, allowing GLPI to select the latest v2 minor version; the final
release must record the exact version returned by `/api.php/v2/doc` in the test
environment.
