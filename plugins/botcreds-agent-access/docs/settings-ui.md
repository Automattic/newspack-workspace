# Settings UI cleanup — 2.4.0-rc.8

Long scope and site-management instructions now use accessible contextual help. Keep the account-wide site-management consequence visible. Help is a sibling of each permission label, never a label child or disabled fieldset control.

The explicitly requested advanced review used gpt-6-astra (high reasoning). Implemented its three must-fixes: help available in disabled permission sections; delayed pointer-gap dismissal; accurate connected-state messaging. Implemented the five focused design suggestions: 800px responsive settings table, bounded integration list/count, effective write-mode summary, concise labels with contextual detail, persistent save/error/unsaved feedback with in-flight edit tracking. Follow-up: translated JavaScript text and deduplicated live-region updates.

## Verification

- 61 hosted WordPress site-management assertions and 93 scope regressions pass.
- 19 real HTTPS cookie/application-password assertions pass on the dedicated QA site.
- 30 existing browser UI/payload assertions and 53 design/help/accessibility/save-state assertions pass.
- Browser tests use actual PHP-rendered profile markup, production JS/CSS and the same WordPress core styles; AJAX transport is synthetic. These are not live wp-admin browser end-to-end claims. Actual backend authorization/save checks are separate HTTPS tests.
- WordPress 7.1 fixtures need base-styles/admin-schemes.css plus an admin-color-* body class; otherwise theme variables leave checked controls and primary buttons white-on-white.
- 20 native PHP files lint. All 27 distribution files match ZIP/source/installed hashes.
- Plugin Check: 0 errors, 21 pre-existing warnings; no new signatures against the original 2.3.2 baseline.
- Temporary users, credentials, cookie sessions, grants, logs, counters and MU test route removed. QA plugin restored byte-identical to 2.3.2; active plugin and table inventories unchanged.

No publisher staging install, Git push or WordPress.org publication performed. Installable candidate: /Users/bob/Public/botcreds-agent-access-2.4.0-rc.8.zip. Detailed receipts: reports/agent-access-ui-20261106/ in the agent workspace.
