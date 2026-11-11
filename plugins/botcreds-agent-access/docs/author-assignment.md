# Co-Authors Plus author assignment (2.4.2)

A human administrator can enable **Allow author assignment** in another user's Agent Access credential settings. Select the applicable WordPress content types as well, then Save. The existing credential works immediately; no password regeneration is needed. This is a per-credential extra permission, not an exclusive author-only mode. It adds no WordPress capabilities. Full access and custom route patterns do not implicitly grant it. Read-only and Yoast-only settings override it. Pro tokens and ordinary cookie sessions are not supported for this endpoint.

Requires active Co-Authors Plus. This release does not provide a core-only fallback. Only existing CAP-enabled REST content that the account can **already edit** is supported. This boundary prevents an agent adding itself to someone else's byline and acquiring new content-editing access. Content scope is checked independently of the route grant. Existing publishing/content permissions are unchanged.

## Client contract

Use Basic Auth with the existing BotCreds Application Password:

1. `GET /wp-json/agent-access/v1/authors/POST_ID` reads the current ordered author-term IDs.
2. `GET /wp-json/agent-access/v1/authors/POST_ID/available?search=NAME&page=1` returns up to 20 canonical choices: `{ "authors": [{"id":123,"name":"News agency"}], "page":1, "has_more":false }`. Paginate while has_more; IDs are CAP author-term IDs, not WP user IDs or guest post IDs. Linking a profile can change its canonical ID; refresh choices before saving.
3. `POST /wp-json/agent-access/v1/authors/POST_ID` with JSON `{"authors":[123,456]}` assigns an ordered byline. At most 20 distinct positive integer IDs. No content, status or other fields; no query/form/file parameters. Unknown/stale IDs fail before writing.

Use the dedicated endpoint, not core `author` or CAP native updates. Lookup reveals names and IDs only, not email or private profile data. Finish content work **before** byline assignment: handing ownership to another account can remove your editing access. The POST reports `can_edit_after`; a subsequent GET may then be denied. Guest-only attribution can legitimately retain the previous underlying `post_author`.

The API uses CAP's native setter and checks saved order and all non-author post columns. Storage/hook failures can partially apply and return `agent_authors_write_unverified`; inspect state before retrying. Successful writes fire `agent_access_audit` and retain the latest 20 old/new byline records in protected `_agent_access_author_history` post metadata. Standard Agent Access write logging also records requests. No atomic transaction or live-theme/newsletter/cache compatibility guarantee is claimed.

Admin-only grant marker: `__assign_authors__`, combined with selected post types (or `*`). No automatic migration of earlier prototype grants. Revoke via the same administrator setting or revoke the credential. Installing the release does not enable any existing credential.
