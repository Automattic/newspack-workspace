### All Submissions:

* [x] Have you followed the [Newspack Contributing guidelines](https://github.com/Automattic/newspack-workspace/blob/main/.github/CONTRIBUTING.md)?
* [x] Does your code follow the [WordPress coding standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/) and [VIP Go coding standards](https://vip.wordpress.com/documentation/vip-go/code-review-blockers-warnings-notices/)?
* [x] Have you checked to ensure there aren't other open [Pull Requests](../../pulls) for the same update/change?

Readers signing up for a newsletter were being interrupted by a modal titled "Register" telling them an account was about to be created for their email. We've since received feedback that this prompt in the newsletter sign up context (vs. reader registration) is confusing and adds friction.

This removes that step from the newsletter signup form, returning the flow to how it worked before the prompt was added in June 2026.

## What changes

- Submitting a Newsletter Subscription block no longer shows the registration step, and goes right through, with no chance to check the address first.
- The Reader Registration block still shows the prompt. Someone using that block is registering for an account, so confirming the account makes more sense to them.
- Only sites with "Verify new reader accounts" switched off ever saw this prompt, so nothing changes for anyone on the default setting.

Closes NPPM-3412.

## What needs human eyes?

I'm marking this PR as needing review for two reasons:

* I left some of the original code/formatting: I kept the newsletter submit inside its own named function so it's easier to add a similar check, and I left the helper the removed code called in newspack-plugin just in case there's a plugin version mismatch between `newspack-newsletters` and `newspack-plugin`. I wouldn't mind a quick check to see if either is overkill (mostly the second one).
* I'd still like any feedback about the PR itself (too much info, not enough, whatever!).

## How to test

1. In Audience → Configuration, turn off "Verify new reader accounts". If the toggle is disabled, a published content gate requires verification.
2. Connect an ESP with at least one active list. The Newsletter Subscription block renders nothing without one.
3. Add a Newsletter Subscription block to a page and view it logged out.
4. On `release` & in an incognito window, subscribe with an address that has never been used. A modal appears saying an account will be created.
5. Switch to this branch, and build.
6. In a new incognito window, subscribe with another unused address. The form submits directly, with no modal.

<details>
<summary><strong>Technical details</strong></summary>

**What was happening:** the subscribe block's submit handler called `maybeConfirmRegistration()` on `window.newspackReaderActivation`. That helper returns immediately when `verify_new_reader_accounts` is on, so the prompt only appeared when a publisher had turned verification off, no content gate forced it back on, and a `/reader-activation/check-email` preflight reported the address as new. It arrived as a side effect of the publisher-controllable verification setting in `b5bb70970` (#223), which wired the same step into all three registration entry points at once.

**The change:** the handler calls `submitSubscribe()` directly. The helper's availability guard and its cancel-path teardown go with it. `submitSubscribe` stays a named function rather than being inlined, so a future check has somewhere to go.

**Scope:** ticket-literal. `maybeConfirmRegistration`, the `/reader-activation/check-email` route, the modal markup, and the auth-modal and registration-block call sites all stay. A second commit updates two comments in newspack-plugin that described the RAS-scope attachment as consumed cross-plugin; that consumer was this call site, so the comments now state the real reason it's kept — older newspack-newsletters builds still look it up on the global.

**This is a rollback, not a replacement.** Diffing `f67bb6543^...HEAD`, the only remaining delta in this handler is the `submitSubscribe` wrapper plus the unrelated post-registration verification block, so the flow returns to its pre-June shape.

**Known trade, accepted deliberately:** with verification off, a mistyped address now provisions an account and authenticates the browser as that reader with no acknowledgement, since the post-registration verification branch is also skipped in that configuration. This isn't a new exposure — it's how the flow behaved before June 2026 — and giving newsletter subscribers a suitable acknowledgement is left to the broader reader-flow review rather than bolted onto an urgent fix.

**Tests:** no automated coverage exists for this view script and there's no front-end harness to extend, so the behaviour rests on the manual steps above. Full suites green on the branch: 362 JS tests, 829 PHP tests.

**Self-review:** `/newspack:code-review` (deep + Newspack WP-expert reviewers, Codex, plus a consequences-synthesis pass). No blockers. Findings resolved into two recorded design decisions (the block divergence, the deferred acknowledgement), one comment fix applied here, and two nits routed to the flow review — missing `.catch()` handlers on the submit path, pre-existing and unchanged by this diff, and the absence of view-script test coverage.

</details>

### Other information:

* [x] Have you added an explanation of what your changes do and why you'd like us to include them?
* [ ] Have you written new tests for your changes, as applicable?
* [x] Have you successfully run tests with your changes locally?

🤖 Generated with the help of [Claude Code](https://claude.com/claude-code)
