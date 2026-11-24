# Co-Authors Plus author-only investigation

This is a QA spike, **not an installable release**. Nothing in this directory is loaded by Agent Access or included by `build.sh`. The experimental MU plugin must never be deployed on a publisher site.

## Conclusion

Use a dedicated, explicitly administrator-authorized author-assignment controller. Call CAP's author setter; do not grant `edit_others_posts`, promote the account to Editor, or globally enable `coauthors_plus_edit_authors`.

For the reported workflow, limit assignment to existing content the credential's account **can already edit**. Also require that object's post type in the credential's scope. An author-assignment grant must not implicitly permit pages when only posts were selected. Read-only and SEO-only restrictions retain precedence.

This is an additional **byline-only permission**, not a promise that an otherwise content-enabled credential can only change bylines. Its existing ability to edit its own content remains unchanged. A separate whole-credential author-only mode would need its own design.

### Why the existing editing boundary matters

CAP intentionally grants co-authors editing rights. A separate administrator added our synthetic agent as a co-author of another author's published fixture. The agent then successfully sent a core post edit with an unchanged content value, even though its `edit_others_posts` flag stayed false. Removing it restored denial. A naive all-post byline endpoint could therefore escalate access by letting an agent add itself to unrelated posts. A restrictive scope on that one credential does not protect other passwords or browser sessions belonging to the same account.

Our prototype rejects this request before mutation. It does not touch CAP's global capability filters.

### CAP is not just `post_author`

- Byline membership and order live in CAP's `author` taxonomy.
- A guest author is not a WordPress user. Passing its post ID as a core author user ID is wrong.
- Guest-only bylines can legitimately leave the existing core `post_author` unchanged. The CAP setter may return false after successfully changing guest terms: verify the actual ordered author list instead of treating that boolean as the final result.
- Linking a user to a guest profile can change the canonical author term. A fresh CAP-aware lookup must return the current identifier. The prototype rejects a stale, noncanonical selection before writing.
- Use CAP's current term resolver where available, with the same nicename resolver used by CAP 3.x's own template tags as a fallback. Require a canonical term round-trip.
- When reassignment transfers a post to another WordPress account and removes the agent from the byline, the agent can lose editing access. Clients should finish their content changes before handing off attribution. Guest-only attribution may retain the original account's underlying ownership.

## Prototype contract

Experimental route: `GET|POST /wp-json/agent-access-probe/v1/authors/{post_id}`.

POST body: `{"authors":[123,456]}` where IDs are current CAP author-term IDs, not WordPress user IDs or guest-post IDs. Preserve the submitted order. Validate the entire list before calling the setter. Empty lists, duplicate/invalid IDs, unknown fields and query/form/file pollution are rejected. IDs come only from the matched route.

The controller requires the native authenticated **BotCreds Application Password UUID**, a separate protected per-UUID fixture grant, applicable content-type scope and existing `edit_post` permission. A second password on the same account does not inherit the grant. Unauthenticated and unmanaged passwords cannot use it. No role capabilities are added.

After writing, independently resolve the saved author terms and compare every post column except the deliberately permitted `post_author` change. Storage/hook failures return an explicit unverified-write error; this spike does **not** claim transactional rollback.

## Real QA evidence

Host: `bobswpdevsite.wpcomstaging.com`, WordPress 7.1.4, Agent Access 2.4.1. CAP was tested at official versions 4.2.2 and 3.6.6. Publisher versions/configurations remain unknown.

- 29 real HTTPS cases on the initial 4.2.2 prototype.
- 29 on the compatibility adapter with CAP 3.6.6.
- Final adapter: 11 focused cases on 4.2.2, including linked guests, stale term rejection and post/page scope; 3 on 3.6.6 covering final read/order/type-scope behavior.
- Separate real-HTTP administrator/agent control demonstrated the existing CAP ownership effect and restored the original byline.
- Dependency-disabled probes returned 503 from author assignment and 403 from an unrelated core edit; CAP was restored afterward.

The matrices verify real Application Password authentication without administrator-cookie contamination. Snapshots and byline template-helper output come from separate WordPress processes. They cover owned drafts/published content, rejection of unrelated published content, user/guest/ordered bylines, idempotence, malformed/mixed input, wrong UUID, revocation and restrictive scopes. General edit flags remain unchanged. Template-helper verification is not live-theme or newsletter-sync acceptance.

Linked-profile exploration initially failed two test-fixture assumptions: a guest need not retain the user's login, and a prior author term need not remain canonical. The final harness refreshes selections from CAP; stale selections are now an explicit negative test. These were not silently treated as successful author writes.

Evidence: workspace `reports/agent-access-coauthors-20261110/author-prototype-*.json`, `cap-existing-ownership-effect.json`, and `prototype-dependency-disabled.json`. Initial matrices precede final canonical/type-scope hardening; final-focused results are reported separately rather than relabeled as full final-build coverage.

## Remaining production work

1. Add a default-off, per-credential administrator control, protected by actual human-admin cookie/nonce/target-user authorization. Fixture grants were provisioned directly in QA; the real assignment UI and self-escalation defenses have not been implemented or tested.
2. Add minimal, permission-gated author discovery for regular/guest/linked profiles without exposing emails or private profile fields. Return current CAP identifiers; do not reuse generic WP user IDs.
3. Integrate route authorization with existing scope controls and policy feedback, and audit the target plus old/new bylines.
4. Advertise the new author tool/endpoint in credential instructions. Existing clients must use it: their generic core `author` writes will still be denied.
5. Complete UI, migration, Pro/token, broader header, linked-profile/version, live-theme/cache/newsletter-sync, storage-failure and no-CAP/core-author-fallback coverage before shipping. Run Plugin Check on the production implementation; this throwaway MU spike is not that release.

## Reproduction and cleanup

Scripts are QA-only and assume the workspace's saved-credential SSH helper `/tmp/aa-qa-remote.py`. Preserve backups before installing the MU prototype. `setup.php` provisions synthetic users, terms, content and private temporary credentials; `control.php` changes only those fixture grants/scopes. `http-matrix.py` runs baseline cases. `linked-fixtures.php` adds linked/profile/page fixtures and refreshes canonical IDs before `final-current-matrix.py`; `final-legacy-matrix.py` checks the final adapter on 3.x. `ownership-control.py` is the separate administrator control. Inspection and cleanup scripts touch only recorded fixture IDs.

Private credentials and full database backups must remain outside reports/repositories. Cleanup removes synthetic users, posts/revisions, guests, terms, passwords, grants, logs, snapshots and rate counters, then removes the experimental MU file. Restore CAP 4.2.2 byte-for-byte and leave it active as requested. Preserve unrelated data; do not restore a whole database over subsequent changes.

No public release, publisher deployment or production authorization change is part of this investigation.
