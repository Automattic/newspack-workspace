# Verified credential identity, independent of client headers — rc.11

BotCreds authorization must resolve the authenticated Application Password UUID against the current user's stored credential and its exact BotCreds name. Never route authorization through audit-source guesses based on User-Agent, URL query parameters or other client-controlled attribution hints.

`Agent_Access_Activity_Log::get_authenticated_botcreds_uuid()` performs that verified lookup. Scope resolves directly from it; audit source prioritizes it before MCP User-Agent / Jetpack hints, so rate-limit and content-policy recognition also remain attached to the verified credential. Ordinary human sessions and unrelated credentials keep their existing behavior.

## Confirmed regression

On disposable QA, rc.10 with an admin-assigned cross-author SEO-only scope:

1. A normal User-Agent reads the dedicated SEO endpoint successfully.
2. `wordpress.com-mcp/1.0` changes the same credential's attribution to MCP before BotCreds is checked: the dedicated SEO endpoint returns 403 because its scope cannot be resolved.
3. The same header also bypasses the core write-scope gate. A deliberately disposable agent-owned post accepted a content update. No publisher content was touched; this synthetic post is deleted during cleanup.

This confirms a code defect, not the precise cause of any live client's failure. A live client's actual request headers, runtime PHP version and read capability still need verification if the corrected candidate fails.

## Verification

- 41 hosted assertions exercise verified identity, attribution, SEO scope, strict rate-limit recognition and draft-policy application across normal, MCP-like and Jetpack-like User-Agents, including synthetic query/signature headers. Unrelated credentials, unknown UUIDs, anonymous identity and ordinary human sessions are separate negative cases. Credential identity is process-local simulated; storage/capability/policy code is real WordPress.
- 65 real HTTPS assertions use actual BotCreds Application Passwords and admin cookies with official Yoast28.8 on WordPress7.1.4/PHP8.4. Cross-author SEO, administrator assignment, self-grant denial, revocation and Yoast public output remain verified. Five MCP/Jetpack-like User-Agents each pass SEO GET/POST and reject native writes to both owned and other-author posts, including Jetpack query hints.
- Existing hosted regressions: 141 SEO/storage + 61 site-management + 93 scope assertions pass. All 21 native PHP files lint. The UI assets and controls are byte-identical to rc.10; no new browser coverage is claimed.
- Exact final ZIP/source/installed SHA256 manifest and complete Plugin Check comparison are in the review bundle. 0 errors, 21 existing warnings, no new signatures against original2.3.2.
- Dedicated QA original plugin restored byte-identical to2.3.2, active-plugin/table inventories unchanged. Synthetic identities, passwords/sessions, both posts, logs, counters, scope metadata and private fixture file removed; temporary Yoast options/cron restored, six newly introduced tables removed and plugin directory moved to private backup. No full DB restore.

No publisher staging install, credential rotation, shared-role changes, Git push or SVN publication. QA plugin-file undo: Desktop/undo-agent-access-auth.sh; backup: /tmp/agent-access-auth-20261106 on the dedicated QA host.

## Live checks if needed

Keep the verified scope on the exact authenticated UUID; password creation time does not affect scope updates. Repeat a read with `User-Agent: AgentAccessScopeCheck/1.0` to separate header-dependent behavior from other causes. Do not infer running PHP from a public JS asset match. A server operator can inspect runtime facts without exposing credentials:

```bash
wp eval 'echo wp_json_encode(array("version"=>AGENT_ACCESS_VERSION,"seo_scope"=>Agent_Access_Yoast_SEO::SCOPE,"read"=>user_can(7,"read")));'
```

Bind any user ID to the actual authenticated identity before using that example. Do not post passwords, cookies or complete credential responses in diagnostics.
