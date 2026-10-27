# Design: `newspack:wp-release-test` Skill

**Date:** 2026-04-15  
**Status:** Draft  
**Author:** Laurel Fulford

---

## Problem

Newspack WordPress major release testing is a recurring, time-sensitive process coordinated across multiple team members. The core pain points are:

1. **No forcing function for timing.** The existing Linear template is a flat checklist with no due dates, so nothing flows through the Linear→Slack integration to nudge people.
2. **Timeline is externally dictated.** WordPress release schedules are done on their own timeline outside of planned Newspack work, competing with other priorities.
3. **High coordinator overhead.** The testing lead must manually track all deadlines, delegate tasks, and chase people — with no scaffolding to support them.

**The fix:** A skill that takes three dates from the WordPress release schedule, creates a fully-dated Linear project (with all issues, sections, and due dates), and uses the existing Linear→Slack integration to handle nudging automatically.

---

## Skill Overview

**Name:** `newspack:wp-release-test`  
**Location:** `plugins/newspack/skills/wp-release-test/SKILL.md` in newspack-devkit  
**Audience:** Whoever is serving as testing lead for a given WP release

**What it does:**
- Creates a Linear project with all issues and calculated due dates
- Surfaces the right instructions and reminders at each phase
- Assists with writing tasks when humans bring it source material

**What it does NOT do:**
- Write the P2 post, help site post, or release notes autonomously (no Google Docs access for Source of Truth)
- Clone sites or interact with The Knife
- Send Slack messages directly

---

## Invocation

```
newspack:wp-release-test --version 7.1 --rc1 2026-04-01 --release 2026-04-22
newspack:wp-release-test --version 7.1 --rc1 2026-04-01 --release 2026-04-22 --dry-run
```

**Arguments:**
- `--version` — WordPress version number (e.g. `7.1`)
- `--rc1` — Date RC1 is expected to land (`YYYY-MM-DD`)
- `--release` — Date the final release is scheduled (`YYYY-MM-DD`)
- `--dry-run` — Generate the full checklist for review without creating anything in Linear

The `--rc1` and `--release` dates come from the [WordPress release schedule](https://make.wordpress.org/core/). The skill calculates all milestone due dates from these two anchors.

**If invoked mid-cycle** (after beta has already been announced), the skill assesses the current date, surfaces any overdue tasks immediately, and creates the Linear project with dates calculated from the remaining timeline.

---

## Checklist Review & Dry Run

Before creating anything in Linear, the skill always outputs a full human-readable checklist for review. This includes:

- **Project details:** title, team (`NEWS`), Linear URL it will be created under
- **Every issue:** title, section, due date (as a real date, not just a formula), and full description text
- **Every sub-task:** title and parent issue
- **Any caveats:** e.g. which clone sites need to be networked together

After presenting the checklist, the skill asks for explicit confirmation before proceeding. Nothing is created in Linear until the user confirms.

**`--dry-run` mode:** Generates the full checklist and stops — no confirmation prompt, nothing created. Useful for reviewing the plan ahead of time, sharing with the team, or sanity-checking dates before committing.

---

## Timing Formulas

All due dates are calculated relative to `rc1_date` and `release_date`:

| Milestone | Due Date Formula |
|---|---|
| Nominate testing lead | As soon as possible (flag immediately) |
| Review Source of Truth, draft changes list | `rc1_date - 14` |
| Clone sites + set to latest WP beta | `rc1_date - 7` |
| Create Slack channel | `rc1_date - 7` |
| Publish internal P2 post | `rc1_date - 7` |
| Update publisher test sites to beta | `rc1_date` |
| Publish external help.newspack.com post | `rc1_date` |
| Testing complete | `release_date - 14` |
| Issue triage complete | `release_date - 10` |
| Release notes finalized | `release_date - 1` |
| Post-release cleanup | `release_date + 2` |

---

## Linear Project Structure

**Project title:** `WordPress X.X Testing`

The skill creates the following sections and issues. All issues include calculated due dates and brief descriptions with key instructions.

---

### Section: Coordinator

| Issue | Due | Notes in description |
|---|---|---|
| Nominate testing lead | `rc1 - 14` | Role should alternate between Product and Support |
| Review Source of Truth; draft list of relevant changes | `rc1 - 14` | Source of Truth is usually found by searching Slack/Linear. Note: many features don't apply to Classic themes. |
| Create Slack channel | `rc1 - 7` | Naming convention: `#newspack-wp[version]-testing` |
| Set up clone sites | `rc1 - 7` | Parent issue with 3 subtasks (see below) |

**Subtasks for "Set up clone sites":**

| Subtask | Notes in description |
|---|---|
| Clone 4 publisher sites via The Knife | Use frugal clone process. Name each clone `wp[##]test-[sitename].newspackstaging.com` (e.g. `wp71test-example.newspackstaging.com`). 2 sites must be networked together (to each other -- not pointing at a live site). Contact an LE if DB > 3GB. Disable WooCommerce Subscriptions to avoid billing live customers. |
| Update each clone to the latest RC via The Knife | Sites will auto-update to subsequent RCs automatically once set. Can be done immediately after cloning. |
| Update the 4 clone testing issues in Linear with the real site URLs | Replace placeholder names (e.g. `wp71test-[sitename].newspackstaging.com`) with the actual URLs of the cloned sites. |
| Draft + publish internal P2 post | `rc1 - 7` | Include: release date, links to schedule + Source of Truth, list of relevant changes, Linear board link, Slack channel, volunteer requests, testing deadline (at least 1 week before the first RC). |
| Update publisher test sites to latest WP beta | `rc1` | Use The Knife. List of publisher test sites is included in our Staging Update Scripts repo: https://github.com/Automattic/newspack-staging-update-scripts/blob/3b3b905157c3340c803fc7e6a07adf1c0873cdd7/atomic/utils/functions.sh#L423. |
| Draft + publish external help.newspack.com post | `rc1` | Add preamble for publishers. Include "Upcoming Changes" category to limit audience and trigger #newspack-community-development ping. |
| Write publisher-facing release notes for newspack.com | `release - 7` | Needs: graphic from Design, commentary from Editorial (based on call for testing but publisher-focused), screenshots. Assign to "Release Notes" category. Set author to Newspack (not newspackadmin). Use Single Release Notes template. |
| Finalize and publish release notes | `release - 1` | Manually publish after release is synced to atomic sites. |

---

### Section: Issue Triage

| Issue | Due | Notes in description |
|---|---|---|
| Triage reported issues | `release - 10` | Product team: replicate issues, confirm they don't occur in previous WP versions, prioritize those unique to the new release. Flag major issues immediately. |

---

### Section: Clone Testing (repeated for each clone — 3 to 4 total)

One section per cloned site. Section and issue titles use the clone's staging URL as a placeholder, e.g. `wp71test-[sitename].newspackstaging.com`. The skill creates 4 placeholder sections by default; unused ones can be deleted if only 3 sites are cloned.

Each clone gets one parent issue titled after its staging URL, with the following subtasks. Due: `release - 14`.

| Subtask | Notes in description |
|---|---|
| Test desktop front-end experience | |
| Test mobile front-end experience | |
| Test Campaigns | |
| Test editing posts and pages | |
| Test the Customizer and adding/editing widgets | |
| Test sponsored content | |
| Test Newspack Listings | |
| Test Newspack Newsletters | Requires setup with a test Mailchimp account |
| Test Events Calendar | |
| Test the Donation Flow | Requires setup with a test Stripe account |

**Notes in parent issue description:** Clones are frugal (no images). Certain services are disconnected by default (Jetpack, ESPs, Stripe/Woo) — testers can connect to their own test accounts carefully. Legacy widget persistence issue is a known Core bug and can be ignored. Report any issues found as new issues in Linear with clear replication steps and screenshots.

---

### Section: Core Features

One issue per plugin/theme. Due: `release - 14`.

- newspack-plugin
- newspack-blocks
- newspack-popups
- newspack-newsletters
- newspack-ads
- newspack-theme
- newspack-block-theme
- newspack-listings
- newspack-sponsors
- newspack-network
- newspack-multibranded-site
- The Events Calendar
- Co-Authors Plus

---

### Section: Post-Release Cleanup

| Issue | Due | Notes in description |
|---|---|---|
| Switch publisher test sites back to current WP | `release + 2` | Use The Knife |
| Delete cloned sites | `release + 2` | |
| Archive Slack channel | `release + 2` | |
| Close Linear project | `release + 2` | |
| Set Slack alert for next WP release beta announcement | `release + 2` | So the team knows when to start again |

---

## Division of Labor

| Task | Claude | Human |
|---|---|---|
| Create Linear project + all issues with due dates | ✓ | |
| Find Source of Truth doc in Linear/Slack | ✓ (searches) | Pastes content in to use |
| Draft writing tasks (posts, release notes) | Assists when given source material | Writes, reviews, publishes |
| Create Slack channel | Provides name + instructions | Creates it |
| Clone sites / interact with The Knife | | ✓ |
| Testing | | Testers |
| Issue triage | | Product team |
| Post-release cleanup | Surfaces checklist | Executes |

---

## MCP Tool Dependencies

- `mcp__newspack__submit-linear-issue` — creates Linear issues with due dates, labels, and descriptions

**Linear team:** `NEWS` (Newspack Team identifier)

**Open question for implementation:** Verify whether `submit-linear-issue` supports:
- Setting due dates on issues
- Creating a parent project under the **Newspack Team** (`NEWS`)
- Grouping issues into sections/columns
- Creating sub-tasks/child issues
- Assigning labels or team members

If project/section creation isn't supported, the skill should instruct the user to create the project manually under the Newspack Team, then Claude populates it with dated issues.

---

## Skill File Structure

```
plugins/newspack/skills/wp-release-test/
  SKILL.md          # Main skill (invocation, procedure, Linear structure)
  linear-issues.md  # Full issue list with timing formulas and descriptions
```

The issue list is separated into `linear-issues.md` because it's heavy reference (~100+ lines) that Claude loads when creating the project.

---

## Open Questions / Future Work

- **Site cloning via newspackstaging.com API:** Investigate whether the newspackstaging.com API supports programmatic site creation. If so, the skill could be augmented to spin up safe frugal clones automatically when passed a list of source sites — eliminating the manual Knife step entirely. Needs research into API auth, available endpoints, and whether "frugal clone" and "set to WP beta" options are supported.

---

## Out of Scope

- Minor release testing (e.g. 7.1.1) — handbook explicitly says Newspack only tests major releases
- Beta testing (only RC versions)
- Interacting with The Knife directly (until API investigation is complete)
- Posting to Slack directly
- Autonomous writing without source material
