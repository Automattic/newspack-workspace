# WP Release Testing Skill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a `newspack:wp-release-test` skill that takes three dates from the WordPress release schedule and creates a fully-dated Linear project with all testing issues, due dates, and sub-tasks — so the existing Linear→Slack integration handles team nudging automatically.

**Architecture:** The skill is two markdown files: `SKILL.md` (the procedure Claude follows when invoked) and `linear-issues.md` (a reference file containing the full issue definitions loaded at creation time). Claude uses the **Linear MCP tools** (OAuth session-scoped, re-auth once per session) to create the project and all issues. A checklist preview is always shown before anything is created; `--dry-run` stops there.

**Tech Stack:** Linear MCP (`linear_createProject`, `linear_createIssue`, `linear_getTeams`, `linear_archiveProject`), `python3` (date arithmetic), Claude Code Skill format

**Auth note:** The Linear MCP uses OAuth and is session-scoped — the user must run `claude mcp auth linear` once at the start of each Claude Code session before invoking this skill. This is acceptable given the skill is used 3–4×/year.

---

## Spec Note

The spec's "Coordinator" section has a formatting artifact: `Draft + publish internal P2 post`, `Update publisher test sites`, `Draft + publish external help.newspack.com post`, `Write publisher-facing release notes`, and `Finalize and publish release notes` appear in the sub-task table for "Set up clone sites" but each has its own due date — they are separate top-level coordinator issues. This plan treats them that way.

---

## File Structure

```
~/.claude/plugins/marketplaces/newspack-devkit/plugins/newspack/skills/wp-release-test/
  SKILL.md          # Skill procedure: argument parsing, date calc, checklist, Linear API calls
  linear-issues.md  # Reference: full issue definitions, descriptions, subtask lists
```

**Local path** (for development and immediate use):
`/Users/laurelfulford/.claude/plugins/marketplaces/newspack-devkit/plugins/newspack/skills/wp-release-test/`

**Final destination** (after testing): PR to `newspack-devkit` repo at same relative path.

---

## Task 1: Discover Linear MCP Tools and Get NEWS Team ID

Verify what Linear MCP tools are available, confirm they support everything we need (project creation, issue creation with due dates, sub-tasks via parentId), and retrieve the NEWS team ID to hardcode in the skill.

**Files:** None created — this is research only.

**Prerequisite:** Run `claude mcp auth linear` in the terminal before starting this task. The OAuth flow must complete before any Linear MCP tools will respond.

- [ ] **Step 1: List available Linear MCP tools**

Call `linear_getViewer` or use MCP tool discovery to confirm the Linear MCP is authenticated and responding. List all available `linear_*` tools.

- [ ] **Step 2: Fetch the NEWS team ID**

Call `linear_getTeams` to get all teams. Find the team with key `NEWS` and record its `id` UUID. This will be hardcoded in `SKILL.md`.

- [ ] **Step 3: Verify project creation**

Call `linear_createProject` with:
- `name`: `"TEST DELETE ME"`
- `teamIds`: `["NEWS_TEAM_ID"]`

Expected: a project `id` and `url`. Save the project `id`.

- [ ] **Step 4: Verify issue creation with due date**

Call `linear_createIssue` with:
- `title`: `"Test issue"`
- `teamId`: NEWS team UUID
- `projectId`: project ID from Step 3
- `dueDate`: `"2026-05-01"`

Expected: an issue `id` and `url`. Confirm `dueDate` is accepted.

- [ ] **Step 5: Verify sub-task creation**

Call `linear_createIssue` with:
- `title`: `"Test subtask"`
- `teamId`: NEWS team UUID
- `projectId`: project ID from Step 3
- `parentId`: issue ID from Step 4

Expected: success. Verify in Linear that the issue appears as a child of the parent.

- [ ] **Step 6: Delete the test project**

Call `linear_archiveProject` (or `linear_deleteProject` if available) with the project ID from Step 3. Confirm it's removed.

- [ ] **Step 7: Record the NEWS team ID and confirmed tool names**

Note the team UUID and exact MCP tool signatures — these go into `SKILL.md` as constants and in the allowed-tools list.

---

## Task 2: Create `linear-issues.md`

This file is the single source of truth for the full issue list. The skill reads it when creating the Linear project.

**Files:**
- Create: `~/.claude/plugins/marketplaces/newspack-devkit/plugins/newspack/skills/wp-release-test/linear-issues.md`

- [ ] **Step 1: Create the directory**

```bash
mkdir -p ~/.claude/plugins/marketplaces/newspack-devkit/plugins/newspack/skills/wp-release-test
```

- [ ] **Step 2: Write linear-issues.md**

Create the file with the full issue definitions. Use `DUE_RC1_MINUS_14`, `DUE_RC1_MINUS_7`, `DUE_RC1`, `DUE_RELEASE_MINUS_14`, `DUE_RELEASE_MINUS_10`, `DUE_RELEASE_MINUS_7`, `DUE_RELEASE_MINUS_1`, `DUE_RELEASE_PLUS_2`, and `VERSION` as tokens the skill replaces with real values at runtime.

```markdown
# Linear Issue Definitions

Tokens replaced at runtime: VERSION, DUE_RC1_MINUS_14, DUE_RC1_MINUS_7, DUE_RC1, DUE_RELEASE_MINUS_14, DUE_RELEASE_MINUS_10, DUE_RELEASE_MINUS_7, DUE_RELEASE_MINUS_1, DUE_RELEASE_PLUS_2

---

## Coordinator Issues

### Nominate testing lead
Due: DUE_RC1_MINUS_14
Description: Nominate a testing lead for WordPress VERSION. The role should alternate between Product and Support team members when possible. The lead is not responsible for doing everything themselves, but is responsible for delegating each step and ensuring it is done on time.

### Review Source of Truth; draft list of relevant changes
Due: DUE_RC1_MINUS_14
Description: Find the WordPress VERSION Source of Truth document (usually found by searching Slack or Linear). Go through it and make note of any changes that may affect Newspack sites. Test the latest beta to confirm what's included. Note: many features are not available for Classic themes, and that's not always clear in their descriptions.

### Create Slack channel
Due: DUE_RC1_MINUS_7
Description: Create a Slack channel for WordPress VERSION testing. Naming convention: #newspack-wpVERSION-testing (e.g. #newspack-wp71-testing, removing the dot).

### Set up clone sites
Due: DUE_RC1_MINUS_7
Description: Clone 4 publisher sites for testing. See subtasks.
Subtasks:
- Clone 4 publisher sites via The Knife | Use the frugal clone process (no images). Name each clone wp[##]test-[sitename].newspackstaging.com (e.g. wp71test-example.newspackstaging.com). 2 of the 4 sites must be networked together (connected to each other — not pointing at a live site). Contact an LE to assist if the DB size is larger than 3GB. Make sure WooCommerce Subscriptions is not processing payments on the clones.
- Update each clone to the latest RC via The Knife | Sites will auto-update to subsequent RCs automatically once set. Can be done immediately after cloning, even before RC1 is released.
- Update the 4 clone testing issues in Linear with the real site URLs | Once sites are cloned and named, replace the placeholder names in the 4 clone testing issues with the actual staging URLs.

### Draft + publish internal P2 post
Due: DUE_RC1_MINUS_7
Description: Write and publish the call for testing on the internal Newspack P2. Include: the release date, links to the WordPress VERSION release schedule and Source of Truth, the list of relevant changes (from the Source of Truth review issue), a link to the Linear board, the Slack channel name, a request for volunteers to test clone sites, a request for Product volunteers to test product tasks, and a deadline for testing to be completed (at least 1 week before the release, ideally 2).

### Update publisher test sites to latest WP beta
Due: DUE_RC1
Description: Using The Knife, update the publisher test sites to the latest WordPress beta version. The list of publisher test sites is here: https://github.com/Automattic/newspack-staging-update-scripts/blob/3b3b905157c3340c803fc7e6a07adf1c0873cdd7/atomic/utils/functions.sh#L423

### Draft + publish external help.newspack.com post
Due: DUE_RC1
Description: Publish a copy of the call for testing on help.newspack.com. Add a preamble encouraging publishers to read up on the features coming in WordPress VERSION, and warn that while our notes include everything we think will change on Newspack sites, we may have missed things. Include the "Upcoming Changes" category — this limits the post to certain readers and pings publishers in the #newspack-community-development channel when published.

### Write publisher-facing release notes for newspack.com
Due: DUE_RELEASE_MINUS_7
Description: Write the public-facing Release Notes post for newspack.com. It should include: a graphic from Newspack Design, commentary crafted by the Newspack Editorial Team (based on the call for testing post, but written for publishers and highlighting features they'll find most exciting), and screenshots. Use the Source of Truth and other posts for information but do not copy them directly. Loop in someone from the Dotorg side (e.g. @annezazu) to review as early as possible. Important: assign to the "Release Notes" category (required for the #announcements Slack ping), set the author to Newspack (not newspackadmin), and use the Single Release Notes template.

### Finalize and publish release notes
Due: DUE_RELEASE_MINUS_1
Description: Ensure the release notes post is fully finalized (feedback addressed, screenshots in, featured image set). Manually publish after the WordPress VERSION release is synced to atomic sites.

---

## Issue Triage

### Triage reported issues
Due: DUE_RELEASE_MINUS_10
Description: Product team: go through issues reported during testing. Replicate each issue to confirm it's real. Confirm whether it occurs in previous versions of WordPress or only in WordPress VERSION. Prioritize issues unique to the new release. Flag any major issues immediately — ideally at least one week before the release so there's time to fix them.

---

## Clone Testing Issues (created 4 times, one per clone)

Parent issue title: wp[VERSION_NODOT]test-[sitename].newspackstaging.com
Due: DUE_RELEASE_MINUS_14
Description: Test WordPress VERSION on this cloned site. Clones are frugal (no images, but you can upload some if it helps testing). The following services are disconnected by default — you can connect them to your own test accounts, but be careful: Jetpack (share buttons, Jetpack Search, Top Posts & Pages), ESPs (Newsletter blocks), Stripe and Woo (Donate block). There is a known Core WordPress issue with legacy widget persistence on at least one clone each round — this can be ignored. Report any issues you find as new Linear issues with clear replication steps and screenshots.

Subtasks:
- Test desktop front-end experience
- Test mobile front-end experience
- Test Campaigns
- Test editing posts and pages
- Test the Customizer and adding/editing widgets
- Test sponsored content
- Test Newspack Listings
- Test Newspack Newsletters | Note: requires setup with a test Mailchimp account
- Test Events Calendar
- Test the Donation Flow | Note: requires setup with a test Stripe account

---

## Core Features Issues (one per plugin/theme)

Due: DUE_RELEASE_MINUS_14
Description: Test [plugin/theme name] on the WordPress VERSION clone sites. If a clone doesn't have this plugin installed, install it to complete testing.

Plugins/themes:
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

## Post-Release Cleanup

### Switch publisher test sites back to current WP
Due: DUE_RELEASE_PLUS_2
Description: Using The Knife, switch the publisher test sites back to the current stable version of WordPress.

### Delete cloned sites
Due: DUE_RELEASE_PLUS_2
Description: Delete the 4 cloned test sites created for WordPress VERSION testing.

### Archive Slack channel
Due: DUE_RELEASE_PLUS_2
Description: Archive the #newspack-wpVERSION-testing Slack channel.

### Close Linear project
Due: DUE_RELEASE_PLUS_2
Description: Mark the WordPress VERSION Testing Linear project as complete/closed.

### Set Slack alert for next WP release beta announcement
Due: DUE_RELEASE_PLUS_2
Description: Set up a Slack reminder or alert so the team knows when to start the testing process for the next WordPress major release. Watch for the beta announcement on make.wordpress.org/core.
```

- [ ] **Step 3: Commit**

```bash
cd ~/.claude/plugins/marketplaces/newspack-devkit
git add plugins/newspack/skills/wp-release-test/linear-issues.md
git commit -m "feat(wp-release-test): add linear issue definitions reference file"
```

---

## Task 3: Write `SKILL.md` — Frontmatter and Overview

**Files:**
- Create: `~/.claude/plugins/marketplaces/newspack-devkit/plugins/newspack/skills/wp-release-test/SKILL.md`

- [ ] **Step 1: Write the frontmatter and overview**

Replace `NEWS_TEAM_ID` with the UUID discovered in Task 1.

```markdown
---
description: Use when starting a new WordPress major release testing cycle to set up the Linear project with all issues and calculated due dates.
argument-hint: "--version <X.X> --rc1 <YYYY-MM-DD> --release <YYYY-MM-DD> [--dry-run]"
allowed-tools:
  - Read
  - Bash(python3 *)
  - linear_createProject
  - linear_createIssue
  - linear_getTeams
  - linear_archiveProject
---

> **Auth reminder:** Before invoking this skill, run `claude mcp auth linear` in your terminal to authenticate the Linear MCP for this session.

# WordPress Major Release Testing Setup

Set up the Linear project for a WordPress major release testing cycle. Calculates all milestone due dates from the RC1 and release dates, generates a full checklist for review, and — after confirmation — creates the project and all issues in Linear with due dates automatically flowing to Slack via the Linear integration.

## Constants

- **Linear team:** NEWS
- **Linear team ID:** `NEWS_TEAM_ID`
- **Linear API endpoint:** `https://api.linear.app/graphql`

## Dynamic Context

- Today's date: !`python3 -c "from datetime import date; print(date.today())"`
```

- [ ] **Step 2: Commit**

```bash
cd ~/.claude/plugins/marketplaces/newspack-devkit
git add plugins/newspack/skills/wp-release-test/SKILL.md
git commit -m "feat(wp-release-test): add skill frontmatter and overview"
```

---

## Task 4: Write `SKILL.md` — Argument Parsing and Date Calculation

- [ ] **Step 1: Append argument parsing section to SKILL.md**

```markdown
## Step 1: Parse Arguments

Parse `$ARGUMENTS` for the following flags:

- `--version X.X` → WP version (e.g. `7.1`)
- `--rc1 YYYY-MM-DD` → RC1 date
- `--release YYYY-MM-DD` → Release date
- `--dry-run` → If present, generate checklist only, do not create in Linear

If any of `--version`, `--rc1`, or `--release` are missing, ask the user for them before proceeding.

Extract the version without the dot for use in URLs and channel names (e.g. `7.1` → `71`).

## Step 2: Calculate Due Dates

Run the following to calculate all milestone dates. Replace `RC1_DATE` and `RELEASE_DATE` with the parsed values.

```bash
python3 -c "
from datetime import datetime, timedelta
rc1 = datetime.strptime('RC1_DATE', '%Y-%m-%d')
rel = datetime.strptime('RELEASE_DATE', '%Y-%m-%d')
fmt = '%Y-%m-%d'
print('DUE_RC1_MINUS_14:', (rc1 - timedelta(days=14)).strftime(fmt))
print('DUE_RC1_MINUS_7:', (rc1 - timedelta(days=7)).strftime(fmt))
print('DUE_RC1:', rc1.strftime(fmt))
print('DUE_RELEASE_MINUS_14:', (rel - timedelta(days=14)).strftime(fmt))
print('DUE_RELEASE_MINUS_10:', (rel - timedelta(days=10)).strftime(fmt))
print('DUE_RELEASE_MINUS_7:', (rel - timedelta(days=7)).strftime(fmt))
print('DUE_RELEASE_MINUS_1:', (rel - timedelta(days=1)).strftime(fmt))
print('DUE_RELEASE_PLUS_2:', (rel + timedelta(days=2)).strftime(fmt))
"
```

Store each date value for use in subsequent steps.

Also check: if today's date is past any of these milestones, flag those tasks as **overdue** in the checklist output.
```

- [ ] **Step 2: Commit**

```bash
cd ~/.claude/plugins/marketplaces/newspack-devkit
git add plugins/newspack/skills/wp-release-test/SKILL.md
git commit -m "feat(wp-release-test): add argument parsing and date calculation"
```

---

## Task 5: Write `SKILL.md` — Checklist Generation

- [ ] **Step 1: Append checklist generation section to SKILL.md**

```markdown
## Step 3: Generate Checklist

Read `linear-issues.md` (in the same directory as this skill). Replace all tokens with calculated values:

| Token | Replace with |
|---|---|
| `VERSION` | WP version (e.g. `7.1`) |
| `VERSION_NODOT` | Version without dot (e.g. `71`) |
| `DUE_RC1_MINUS_14` | Calculated date |
| `DUE_RC1_MINUS_7` | Calculated date |
| `DUE_RC1` | Calculated date |
| `DUE_RELEASE_MINUS_14` | Calculated date |
| `DUE_RELEASE_MINUS_10` | Calculated date |
| `DUE_RELEASE_MINUS_7` | Calculated date |
| `DUE_RELEASE_MINUS_1` | Calculated date |
| `DUE_RELEASE_PLUS_2` | Calculated date |

Output the following checklist to the user:

---
**WordPress VERSION Testing — Linear Project Preview**

📋 **Project:** WordPress VERSION Testing
👥 **Team:** NEWS (Newspack)
🔗 Will be created at: linear.app

**COORDINATOR** (8 issues)
- [ ] Nominate testing lead — due DUE_RC1_MINUS_14 [⚠️ OVERDUE if applicable]
- [ ] Review Source of Truth; draft list of relevant changes — due DUE_RC1_MINUS_14
- [ ] Create Slack channel (#newspack-wpVERSION_NODOT-testing) — due DUE_RC1_MINUS_7
- [ ] Set up clone sites — due DUE_RC1_MINUS_7
  - [ ] Clone 4 publisher sites via The Knife
  - [ ] Update each clone to the latest RC via The Knife
  - [ ] Update the 4 clone testing issues in Linear with the real site URLs
- [ ] Draft + publish internal P2 post — due DUE_RC1_MINUS_7
- [ ] Update publisher test sites to latest WP beta — due DUE_RC1
- [ ] Draft + publish external help.newspack.com post — due DUE_RC1
- [ ] Write publisher-facing release notes for newspack.com — due DUE_RELEASE_MINUS_7
- [ ] Finalize and publish release notes — due DUE_RELEASE_MINUS_1

**ISSUE TRIAGE** (1 issue)
- [ ] Triage reported issues — due DUE_RELEASE_MINUS_10

**CLONE TESTING** (4 parent issues × 10 subtasks = 40 subtasks)
- [ ] wp[VERSION_NODOT]test-[sitename-1].newspackstaging.com — due DUE_RELEASE_MINUS_14
  - [ ] Test desktop front-end experience
  - [ ] Test mobile front-end experience
  - [ ] Test Campaigns
  - [ ] Test editing posts and pages
  - [ ] Test the Customizer and adding/editing widgets
  - [ ] Test sponsored content
  - [ ] Test Newspack Listings
  - [ ] Test Newspack Newsletters (⚠️ needs test Mailchimp account)
  - [ ] Test Events Calendar
  - [ ] Test the Donation Flow (⚠️ needs test Stripe account)
- [ ] wp[VERSION_NODOT]test-[sitename-2].newspackstaging.com — due DUE_RELEASE_MINUS_14
  [same 10 subtasks]
- [ ] wp[VERSION_NODOT]test-[sitename-3].newspackstaging.com — due DUE_RELEASE_MINUS_14
  [same 10 subtasks]
- [ ] wp[VERSION_NODOT]test-[sitename-4].newspackstaging.com — due DUE_RELEASE_MINUS_14
  [same 10 subtasks]

⚠️ **Reminder:** 2 of the 4 clone sites must be networked together (not pointing at live sites).

**CORE FEATURES** (13 issues)
- [ ] newspack-plugin — due DUE_RELEASE_MINUS_14
- [ ] newspack-blocks — due DUE_RELEASE_MINUS_14
- [ ] newspack-popups — due DUE_RELEASE_MINUS_14
- [ ] newspack-newsletters — due DUE_RELEASE_MINUS_14
- [ ] newspack-ads — due DUE_RELEASE_MINUS_14
- [ ] newspack-theme — due DUE_RELEASE_MINUS_14
- [ ] newspack-block-theme — due DUE_RELEASE_MINUS_14
- [ ] newspack-listings — due DUE_RELEASE_MINUS_14
- [ ] newspack-sponsors — due DUE_RELEASE_MINUS_14
- [ ] newspack-network — due DUE_RELEASE_MINUS_14
- [ ] newspack-multibranded-site — due DUE_RELEASE_MINUS_14
- [ ] The Events Calendar — due DUE_RELEASE_MINUS_14
- [ ] Co-Authors Plus — due DUE_RELEASE_MINUS_14

**POST-RELEASE CLEANUP** (5 issues)
- [ ] Switch publisher test sites back to current WP — due DUE_RELEASE_PLUS_2
- [ ] Delete cloned sites — due DUE_RELEASE_PLUS_2
- [ ] Archive Slack channel — due DUE_RELEASE_PLUS_2
- [ ] Close Linear project — due DUE_RELEASE_PLUS_2
- [ ] Set Slack alert for next WP release beta announcement — due DUE_RELEASE_PLUS_2
---

**Total: ~70 issues/subtasks**

If `--dry-run` was passed, stop here. Output: "Dry run complete. No changes made to Linear."

If not `--dry-run`, ask: "Does this look right? Type YES to create the project in Linear, or describe any changes needed."

Do not proceed until the user explicitly types YES.
```

- [ ] **Step 2: Commit**

```bash
cd ~/.claude/plugins/marketplaces/newspack-devkit
git add plugins/newspack/skills/wp-release-test/SKILL.md
git commit -m "feat(wp-release-test): add checklist generation and dry-run"
```

---

## Task 6: Write `SKILL.md` — Linear API Creation Procedure

- [ ] **Step 1: Append the Linear creation procedure to SKILL.md**

```markdown
## Step 4: Create in Linear

### Check for Linear MCP auth

If the Linear MCP tools are not available or return an auth error, stop and tell the user:
"The Linear MCP is not authenticated for this session. Please run `claude mcp auth linear` in your terminal, complete the OAuth flow, then re-invoke this skill."

### Create the project

Call `linear_createProject` with:
- `name`: `"WordPress VERSION Testing"`
- `teamIds`: `["NEWS_TEAM_ID"]`

Save the returned project `id` as `PROJECT_ID`. Show the user the project URL.

If the call fails, report the error and stop.

### Create issues

For each issue in the checklist (in the order listed below), call `linear_createIssue` with:
- `title`: the issue title (with tokens replaced)
- `description`: the issue description from `linear-issues.md` (with tokens replaced)
- `teamId`: `NEWS_TEAM_ID`
- `projectId`: `PROJECT_ID`
- `dueDate`: the calculated date string (`YYYY-MM-DD`)

For sub-tasks, also pass `parentId` (the parent issue's `id`) and omit `dueDate` — sub-tasks inherit from their parent. Always create the parent issue first, save its `id`, then create its sub-tasks.

Creation order:
1. All Coordinator issues (top-level), saving the `id` of "Set up clone sites"
2. Sub-tasks of "Set up clone sites" (3 subtasks, using saved parent ID)
3. Issue Triage issue
4. Clone Testing parent issues (4×), saving each `id`
5. Sub-tasks for each Clone Testing parent (10× per parent, using saved parent IDs)
6. Core Features issues (13×)
7. Post-Release Cleanup issues (5×)

Report progress to the user as groups are created (e.g. "✅ Coordinator issues created (8/8)").

### Report completion

After all issues are created, output:

```
✅ WordPress VERSION Testing project created in Linear.
🔗 PROJECT_URL

Next steps:
1. Share the project link in #newspack-wpVERSION_NODOT-testing when you create the channel.
2. Update the 4 clone testing issues with real site URLs once sites are cloned.
3. Assign issues to team members as testing volunteers sign up.
```
```

- [ ] **Step 2: Commit**

```bash
cd ~/.claude/plugins/marketplaces/newspack-devkit
git add plugins/newspack/skills/wp-release-test/SKILL.md
git commit -m "feat(wp-release-test): add Linear API creation procedure"
```

---

## Task 7: Test Dry Run

Verify the skill generates correct output without touching Linear.

- [ ] **Step 1: Invoke the skill in dry-run mode**

In a Claude Code session:

```
newspack:wp-release-test --version 7.1 --rc1 2026-05-01 --release 2026-05-27 --dry-run
```

- [ ] **Step 2: Verify the output**

Check that:
- All 8 milestone dates are correct (manually calculate a few against the formulas)
- All sections are present: Coordinator, Issue Triage, Clone Testing (×4), Core Features (×13), Post-Release Cleanup (×5)
- Clone placeholder names use `wp71test-[sitename].newspackstaging.com`
- Networking reminder is shown
- "Dry run complete" message appears and nothing is created in Linear

- [ ] **Step 3: Fix any issues found, re-run until clean**

---

## Task 8: Test Live Run with a Throwaway Project

Run the full skill against a real (but clearly labelled throwaway) Linear project, verify structure, then delete it.

- [ ] **Step 1: Invoke the skill for real**

```
newspack:wp-release-test --version 0.0-TEST --rc1 2026-05-01 --release 2026-05-27
```

- [ ] **Step 2: Review the checklist, type YES to confirm**

- [ ] **Step 3: Verify in Linear**

Open the created project and check:
- Project exists under the NEWS team
- All sections of issues are present
- Due dates are correct
- "Set up clone sites" has 3 sub-tasks
- Each clone testing parent has 10 sub-tasks
- Mailchimp and Stripe caveats appear in the correct sub-task descriptions

- [ ] **Step 4: Delete the test project**

Call `linear_archiveProject` (or `linear_deleteProject` if available) with the test project's ID.

- [ ] **Step 5: Fix any issues, re-test until clean**

- [ ] **Step 6: Final commit**

```bash
cd ~/.claude/plugins/marketplaces/newspack-devkit
git add plugins/newspack/skills/wp-release-test/
git commit -m "feat(wp-release-test): finalize skill after end-to-end testing"
```
