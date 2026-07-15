# NPPD-1753 — Implement admin-side Subscribers management

## Context

[NPPD-1753](https://linear.app/a8c/issue/NPPD-1753) builds the real admin-side Subscribers management surface from Thomas's signed-off i2 prototype (clickable at `admin.php?page=newspack-subscribers-demo` in debug mode). NPPD-1815 (group managers — the SpaceNews multi-manager regression) is folded in. Thomas already shipped the design + real manager backend as PRs #539 (admin prototype, mock) and #540 (real data layer + My Account) — but **only onto `feat/subscribers-demo-prototype`, which is 9 ahead / 235 behind `origin/main`; nothing has reached main**. A multi-reviewer code review of that work found one blocker (peer-manager removal enforced client-side only) plus doc/perf/test findings, all to be folded into this work.

**Settled decisions** (user-confirmed this session):
- Full ticket as an ordered PR series **targeting main** ("port real code to main in slices"); the prototype branch stays a design sandbox.
- Ship incrementally, **visible** — the real page appears for admins as soon as the first usable slice lands. No *new* debug/feature flag is introduced; the wizard rides the **existing Access Control flag** (`Content_Gate::is_newspack_feature_enabled()` / `NEWSPACK_CONTENT_GATES`), the same gate the rest of the group-subscription code already uses, so the menu/routes are absent only on sites without Access Control (which have no group data to manage). Confirmed with the user (2026-07-15): "no feature gate" meant no new flag, reusing the AC flag is correct.
- SpaceNews manager backfill lands **early** (PR 2).
- Review findings folded in (PR 1).

**Key facts discovered:**
- The migrator is standalone drop-in tooling (not in any repo's tracked code): `repos/plugins/migrate-memberships/migrate-memberships.php` — commands `wp newspack migrate-teams` (+ `migrate-team-products`, `migrate-manual-members`, `migrate-membership-gates`). Confirmed role-drop site: `migrate-teams` reads the team's `_member_id` post meta and adds everyone via raw `add_user_meta( '_newspack_group_subscription', $sub_id )` — Teams roles never consulted. Teams roles survive in user meta `_wc_memberships_for_teams_team_{team_id}_role` (`owner|manager|member`), readable raw without the SkyVerge API. Team→subscription resolution: linked active `_subscription_id` → else owner's active group-enabled sub → else new $0 sub (`created_via = 'migration'`). Idempotent; Teams source data survives. **The migrator writes no joined-at meta** — migrated members have no "Member since" (PR 4 must render that gracefully).
- The prototype README (`src/wizards/subscribersDemo/README.md` on the prototype branch) is the design contract: everything outside `data/` is presentation and **copies verbatim**; only `data/` is rewritten against REST (~70% carries over). Preserve the `onComplete({ message, mutate })` optimistic-update contract; data hooks mirror the existing `useAvatars` pattern.
- Existing REST (`Group_Subscription_API`, ns `newspack-group-subscription/v1`: `/search-users`, `/members`, `/invite`, `/invite-link`) already authorizes `manage_woocommerce` — admin-usable as-is. Missing: read endpoints, manager mutation endpoint, subscriber-list query.
- `Group_Subscription_Settings::get_group_subscription_ids()` is the HPOS-safe site-wide group query (5-min transient). No site-wide reader-list endpoint exists — net-new.

**Source checkouts:** prototype code at `/Users/adbo/lol/a8c/worktrees/review-subscribers-managers` (read-only source for verbatim copies); work happens in isolated-env worktrees off `main` (`n env create <name> --worktree newspack-plugin:<branch>`). All paths below under `plugins/newspack-plugin/`.

## Global design decisions

- **Wizard identity:** new `src/wizards/subscribers/` (webpack auto-discovers → `dist/subscribers.js`) + new `includes/wizards/class-subscribers-wizard.php` (class `Subscribers_Wizard`), slug `newspack-subscribers`, registered in `includes/class-wizards.php` as an Audience submenu via `$parent_slug = 'newspack-audience'` + `add_submenu_page` (model: `includes/wizards/audience/class-audience-content-gates.php`). `add_page()` / `register_api_endpoints()` / enqueue all short-circuit on `is_feature_enabled()` → the existing Access Control flag (see Settled decisions). Copy presentation files verbatim from the prototype per PR; normalize text domain to `newspack-plugin`.
- **Permissions:** wizard page + `newspack/v1/wizard/newspack-subscribers/*` routes → `Wizard::api_permissions_check()` (`manage_options`); WooCommerce-touching writes additionally assert `manage_woocommerce`. Group-maintenance writes reuse `Group_Subscription_API` so admin + My Account share one authz path.
- **Response shapes** mirror the prototype data model exactly (field names, status vocabulary, `amount` as number, dates `YYYY-MM-DD`) — data-shape drift is the one thing that breaks the 1:1 UI reuse. Group `id` = subscription ID; `role` computed owner / manager / member; WCS→prototype status map documented in the endpoint class.
- **Pagination (net-new convention):** `page`/`per_page`(≤100)/`search`/`orderby`/`order` + typed filters; response `{ items, total, pages }`. Maps 1:1 to the DataViews `view` object; switching SubscriberList to controlled server-side pagination is the only sanctioned presentation edit.

## PR series

### PR 1 — Port manager backend (#540) + review fixes + authz tests
Port from prototype, hand-rebased onto current main (files diverged over 235 commits):
- `includes/plugins/woocommerce-subscriptions/group-subscription/class-group-subscription.php` — manager meta const, `get_managers()` reverse-lookup, `add_manager()`/`remove_manager()`, `get_managed_subscriptions_for_user()` manager-of branch, `update_members()` cleanup, cache-invalidation hook.
- `class-group-subscription-myaccount.php` — `handle_set_manager_role()` (owner-or-`manage_woocommerce` admin-post). **Exclude** #440's `handle_request_seats` (PR 7).
- Templates `templates/v1/group-subscription-members.php` (role-aware kebab), `templates/v1/group.php` manager slice only.
- Tests `tests/unit-tests/plugins/woocommerce-subscriptions/group-subscription/class-group-subscription-managers.php`, reconciled with main's newer tests.

Review fixes folded in:
1. **BLOCKER:** new testable predicate `Group_Subscription::can_actor_remove_member( $actor_id, $target_id, $subscription )` (owner/`manage_woocommerce` remove anyone but owner; a manager removes plain members only — never another manager of the same group) applied in **both** `handle_remove_member()` and `Group_Subscription_API::api_update_members()` remove path. Role model: exactly one owner (the subscription customer; billing), any number of managers (maintenance, no billing), members.
2. Per-request static cache for `get_managers()`/`get_members()` keyed by subscription ID, busted by the existing `maybe_reset_cache_on_user_meta` hook (~5 `get_users()`/render → 2).
3. Seat-count consistency in `group.php`: display and limit-check agree on whether the owner counts (align on `get_members()` semantics; confirm direction with Thomas).
4. `remove_manager()` errors on a non-manager target.
5. `$unique=true` nit **rejected** — manager meta is repeatable (one row per managed subscription); keep the existing dedupe guard, note in PR description.
6. Stale docblocks (`get_managed_subscriptions_for_user`, `user_is_member` "(not manager)", filter `@param`s).

New tests: `handle_set_manager_role` gating (owner ✓ / member ✗ / peer manager ✗ / admin ✓); peer-removal rule through both handler and REST; `remove_manager` non-manager error; cache invalidation.
Ships: My Account promote/demote goes live. Depends: none.

### PR 2 — Port the migrator into newspack-plugin + manager handling + backfill
Merge the `migrate-memberships.php` drop-in (`repos/plugins/migrate-memberships/`) into the plugin as proper CLI classes, retiring the drop-in (user-confirmed: in-plugin beats deploying another plugin per site; CLI classes load only under `WP_CLI` — zero web-request cost).
- New `includes/cli/` classes (namespace `Newspack\CLI`, one class per command per existing convention), registered in `includes/cli/class-initializer.php`: `migrate-teams`, `migrate-team-products`, `migrate-manual-members`, and new **`backfill-team-managers`**. The unrelated `migrate-membership-gates` port splits into a follow-up PR if this one balloons.
- **Refactor to the real data layer during the port:** membership adds via `Group_Subscription::update_members()` (writes joined-at, which the drop-in's raw `add_user_meta` omitted) and managers via `Group_Subscription::add_manager()` — first **verify side effects** (data events, ESP sync, emails) stay suppressed during migration, preserving the drop-in's deliberate WC-email suppression; keep raw-meta writes where the data layer would fire unwanted hooks.
- **Manager handling in `migrate-teams`:** in the members loop, read each member's Teams role from user meta `_wc_memberships_for_teams_team_{$team_id}_role`; for `manager` (and not the subscription owner), promote after membership is added.
- **`backfill-team-managers`** (SpaceNews): dry-run by default + `--live`; iterate `wc_memberships_team` posts; resolve subscription mirroring the migrator (linked active `_subscription_id` → owner's active group-enabled sub; report unresolvable); for role=manager members already group members, `add_manager()`; per-team report table (found / already manager / added / not-a-member). Idempotent.
- Tests: PHPUnit with the existing wc-mocks (fixture team posts + role meta → migrated group state incl. managers and joined-at; backfill idempotency). PHPCS-clean (the drop-in isn't — mechanical cleanup).
- Run backfill on SpaceNews **after** PR 1 is released. Verification: dry-run transcript from a SpaceNews staging copy; spot-check `get_managers()` post-run. Depends: PR 1.

### PR 3 — Wizard shell + read endpoints + both L0 lists (first visible slice)
PHP:
- `includes/wizards/class-subscribers-wizard.php` (adapted from prototype `class-subscribers-demo.php`): Audience submenu gated on the Access Control flag (`is_feature_enabled()`), localized config (`groupLabel`/`groupLabelPlural` via `Group_Subscription::get_label()`, currency, `showAvatars`) under `window.newspackSubscribers`; port the real `POST …/avatars` endpoint verbatim; register in `class-wizards.php` + `class-newspack.php`. **Status:** DONE + code-reviewed (2 passes) — shell, `/avatars`, `GET …/groups`, `GET …/subscribers` (paged `WP_User_Query`, per-item hydration, status/plan filter inversion, WCS→prototype status map). 18 PHPUnit tests green; PHPCS clean. JS port still pending.
- `GET /wizard/newspack-subscribers/subscribers` — paged `WP_User_Query` over reader roles; per-page hydration via `wcs_get_users_subscriptions()` + group roles. Subscription-status/plan filters run inverted (HPOS-safe subscription query → customer IDs → `include`). Sort by name/memberSince only initially.
- `GET /wizard/newspack-subscribers/groups` — `get_group_subscription_ids()` + hydration (owner, seat limit, member count via PR 1's cache).

JS (verbatim copies + new `data/`): `index.js` (routes `/`, `/groups`), `screens/SubscriberList.jsx` (incl. hidden Group-role column), `screens/GroupList.jsx`, `style.scss`, `format.js`, `status.js`, `labels.js`; new `data/use-subscribers.js`, `data/use-groups.js`, copied `data/use-avatars.js`. No `storage.js`, no mocks. Interim row click-through → native admin screens (`user-edit.php`, Woo subscription edit) until PR 4/5 add internal routes. **Status:** DONE + browser-verified. New wizard dir `src/wizards/subscribers/` (classes renamed `newspack-subscribers-demo`→`newspack-subscribers`, config global `window.newspackSubscribers`). `SubscriberList` uses controlled **server-side** pagination (`useSubscribers(view)`→`viewToParams`: page/per_page/search/orderby[name|memberSince]/order + status[]); status is the only server-backed filter this slice (plan/group-role filters deferred to PR 6/later). `GroupList` loads the full set client-side (`useGroups`) and filters/sorts via `filterSortAndPaginate`; owner/members/status/plan read from the embedded item. Group-role labels + `GROUP_STATUS_*` maps + `ROLE_LABELS` moved into `status.js`/`labels.js` (mock modules not copied). Click-through uses a server-provided `editUrl` (added to both item shapes: `get_edit_user_link()` for readers, HPOS-safe `get_edit_order_url()` for groups).
Tests: PHPUnit shape/pagination/filter-inversion/403 (+`editUrl` assertions) — 18 tests/77 assertions green, full WCS group 89/183 no regression; jest for both hooks (`viewToParams` matrix + fetch/error wiring) — 10 tests green; PHPCS clean. Browser-verified on a seeded isolated env (`nppd1753-pr3`, AC flag on, 10 customers/8 subs + 1 seeded group owner/manager/member): menu under Audience, both lists render real data (individual+group merged, group owner/3-of-5-seats), server-side search + status-filter inversion (active=8 incl. group-status inheritance, free readers excluded), row click-through to native edit. Depends: PR 1.

### PR 4 — Group detail + manager admin UI + group-maintenance flows
PHP:
- `GET /wizard/newspack-subscribers/groups/{id}` (members w/ `joinedAt` + computed role, invites via `Group_Subscription_Invite`, `seatRequest` null until PR 7). `joinedAt` may be null for migrated members (the migrator never wrote it) — render "—".
- Extend `class-group-subscription-api.php`: `POST|DELETE /managers` (**in-handler owner-or-`manage_woocommerce` gate** — stricter than the shared callback), `POST /invite/accept` (accept-on-behalf, `manage_woocommerce`-only, creates accounts for unknown emails), resend-invite semantics confirmed against `class-group-subscription-invite.php`.
- `POST /wizard/newspack-subscribers/groups/{id}/seats` (free seat-limit change via `Group_Subscription_Settings`; payment-link branch PR 7).

JS (verbatim): `GroupDetail.jsx`, `SubscriberNotices.jsx`+test, flows `ConfirmFlow`, `AddMembers`, `InviteMember`, `AcceptInvite`, `RemoveMember`, `AdjustSeats` (link branch hidden), `Regenerate/DisableLink`, `Resend/CancelInvite`, `steps.jsx`, `use-portals.js`; new `data/use-group.js` preserving mutate+refetch. Make/Remove manager stay instant kebab actions. `SubscriptionDetailsDrawer` deferred to PR 5 (omit its kebab entry). Depends: PR 1, 3.

### PR 5 — Person profile + core billing (refund/cancel, reactivate, payment methods)
PHP (`includes/wizards/subscribers/` section class): `GET /subscribers/{id}` full shape (`wcs_get_users_subscriptions`, `WC_Payment_Tokens::get_customer_tokens()`, `wc_get_orders` — all HPOS-safe); writes `POST /subscriptions/{id}/cancel|refund` (`wc_create_refund([ 'refund_payment' => true ])`, surface gateway errors verbatim), `POST /subscriptions/{id}/reactivate` (free = `update_status('active')`; charge = renewal order + gateway token payment; link = pending renewal + customer-payment-page email), `POST /subscriptions/{id}/payment-method` (via `woocommerce_subscription_payment_meta` machinery, not raw meta), `DELETE /payment-methods/{token}` + set-default (refuse deleting a token backing an active sub).

JS (verbatim): `PersonProfile.jsx`, flows `Refund`, `GuidedFix`, `ChangePaymentMethod`, `RemovePayment`, `SubscriptionDetailsDrawer` (restores PR 4 deferral), `free-access.jsx`, `subscription-actions.js`; new `data/use-subscriber.js`. **Deferred:** `PaymentUpdateFlow` (raw card entry — PCI; payment-link is the universal fallback, `hasUsableCard` already gates), `AddSubscriptionFlow`, `PlanChangeFlow`, notes/tags/newsletters (per-section empty states). Depends: PR 3 (PR 4 for drawer parity).

### PR 6 — Add subscription, resubscribe, plan change, team-plan group creation
- `GET …/plans` (real WCS subscription products partitioned Digital/Print/Team; Team = group-enabled) replacing plan constants.
- `POST …/subscribers/{id}/subscriptions` — modes: link (pending order + `wcs_create_subscription()` + pay-link email), free (active $0 sub; free-for-N-cycles conversion possibly a fast follow — decision point), charge (saved token only). Team plan → subscription + group-enable + seat limit.
- `POST /subscriptions/{id}/change-plan` — same-family line-item swap + recalc (not WCS cart switching); reject group downgrade below member count.
- JS: `AddSubscriptionFlow.jsx`, `PlanChangeFlow.jsx` verbatim; un-hide PR 5 conditionals. Depends: PR 5.

### PR 7 — Polish: #440 port, notes/tags/newsletters, seat requests end-to-end
- **Port #440** (requested-limit meta + helpers in `class-group-subscription-settings.php`, `handle_request_seats()`, request-seats form in `group.php`), rebased onto main.
- Notes/tags CRUD endpoints (user meta; site-wide known-tags option); newsletters bridge to Newspack Newsletters ESP lists (hidden when absent); `POST …/groups/{id}/seat-request` `action=decline|mark-paid|send-link`.
- JS: `NoteFlow`, `TagsFlow` verbatim; newsletter toggles; AdjustSeats payment-link branch; GroupDetail seat-request notice CTAs; GroupList request badge; tags/newsletters populated in list shape. Final sweep + README for `src/wizards/subscribers/` documenting the endpoint contract. Depends: PR 4, 5, 6.

## Risks
- **HPOS:** all subscription/order queries via `wcs_get_subscriptions`/`wc_get_orders`/`wcs_get_users_subscriptions` (precedent: #426 regression in this subsystem).
- **Scale:** filter-inversion `include` arrays capped (~10k) with graceful fallback; lastPayment/status sorting deferred; consider denormalized status user meta later.
- **Gateway variance:** token operations via gateway payment-meta APIs; verify on Stripe first; hide charge-now when the gateway lacks token support. Refunds depend on gateway `refunds` capability.
- **Port drift:** PR 1/PR 7 ports are manual rebases over 235 commits of divergence; main's newer tests are the safety net.
- **Backfill preconditions:** dry-run default protects SpaceNews; teams whose subscription resolves only via the owner-fallback (or not at all) are reported, not guessed.
- **Migrator port side effects:** switching from raw meta to `update_members()`/`add_manager()` may fire data-events/ESP-sync/email hooks the drop-in silently avoided — audit and suppress during migration runs before adopting the data layer.

## Housekeeping (needs user confirmation for Linear writes)
- Update NPPD-1753 description: L0 "role folds into Subscription column" superseded by #539 (plan + "(Group)" in Subscription column; role via hidden Group-role column); note manager data layer/My Account done via #539/#540 pending port.
- Comment on NPPD-1753 linking this plan/PR series once PR 1 is up.

## Verification
- Each PR: `n test-php --group WooCommerce_Subscriptions_Integration` (+ new groups) and `pnpm --filter newspack-plugin test` in the env worktree; PHPCS via `./vendor/bin/phpcs`.
- Per-PR isolated env: `n env create nppd1753-prN --worktree newspack-plugin:<branch>`, `n setup --env … --woocommerce --yes`; build JS **inside the env container** (`docker exec newspack_env_… /var/scripts/build-repos.sh newspack-plugin` + `opcache_reset`, per the isolated-env JS build quirk).
- Browser verification (Playwright MCP): PR 1 — owner promote/demote in My Account, manager view w/o billing, forged peer-removal POST rejected; PR 3 — Subscribers page under Audience, lists paginate/filter against real data; PR 4 — full group-maintenance walkthrough incl. Make/Remove manager; PR 5/6 — Stripe sandbox: refund, cancel, reactivate (all 3 modes), change payment method, add-subscription modes; PR 7 — owner requests seats → admin badge/notice → mark-paid applies limit.
- PR 2: dry-run transcript on a SpaceNews staging copy before any live run.
- Red/green TDD where applicable — notably the PR 1 blocker (failing peer-removal test first) and endpoint shape contracts.
