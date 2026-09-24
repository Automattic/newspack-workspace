# Integration Smoke Tests

Integration smoke tests that exercise Newspack features against a real WordPress + WooCommerce environment. Unlike the PHPUnit suite under `tests/unit-tests/`, these scripts are run manually via `wp eval-file` and require the full plugin stack to be active.

## Running

```bash
wp eval-file tests/integration/<script>.php
```

Each script prints `PASS`/`FAIL` lines per scenario, a final `N/M PASSED` summary, and exits non-zero on any failure. Scripts create their own fixtures and clean up after themselves.

## Scripts

### `card-expiry-warning-smoke.php`

End-to-end coverage for the Card Expiry Warning email (`includes/plugins/woocommerce-subscriptions/class-card-expiry-warning.php`).

**Requires:** WooCommerce, WooCommerce Subscriptions, Newspack Newsletters, and Reader Activation enabled.

Exercises cron scheduling, happy-path send, idempotency, the payment-method-update flag clear, new-card re-send, and the unattached-card no-op. Also pins the publisher-respect machinery: first-deploy seed (no send, marks SENT_META on in-window pairs, flips `SEEDED_OPTION`), the `$bypass_idempotency` arg's escape-hatch contract, SQL-level `LIMIT` on the discovery query, and the `wp newspack card-expiry-warning-backfill` CLI command's dry-run + bypass behaviors.
