# User-directory access

A human administrator with `manage_options`, `promote_users`, and authority to edit the target account can enable **List users** under **Additional permissions** on another user's managed credential. It is off by default. The same collapsed section groups author assignment (credential-specific) and site management (account-wide, explicitly disclosed).

## Client requests

Use `GET /wp-json/wp/v2/users?context=view&per_page=100&page=1`, then paginate according to `X-WP-TotalPages`. `X-WP-Total` is the matching account count, not just the returned page size. Native search/filter parameters apply. With this grant, accounts without published posts are included. View context returns native profile fields; this grant does not authorize private edit-context profiles for other accounts. Native edit-context collection requests can omit accounts the caller cannot edit even when their totals include those accounts, so do not use edit context for a complete directory.

This is not user management. No `edit_users`, `create_users`, `delete_users`, `promote_users`, or `edit_user` grant is added. Existing permissions from other sources remain unchanged.

## Authorization boundary

- Protected scope marker: `__list_users__`, stored with the selected managed Application Password UUID.
- Only native verified BotCreds Application Passwords qualify; not browser cookies, manually named credentials, ungranted managed passwords, spoofable audit headers, or Pro tokens.
- Adds `list_users` only during GET/HEAD callbacks for `/wp/v2/users`, `/wp/v2/users/{id}`, and `/wp/v2/users/me` on the active site.
- A callback request stack bounds the temporary grant and restores outer context after nested dispatch. No role or stored user capability changes occur.
- Read-only and Yoast-only retain the independent read grant without relaxing write enforcement. Full write scope does not imply directory access.
- A marker without selected write access defaults to read-only; the marker never becomes a write route or taxonomy grant.
- Uncheck and save to revoke immediately without replacing the password. Older forms preserve an existing grant rather than silently removing it. Explicit grant/revocation input requires administrator authorization.

## QA

`tests/list-users-fixture-wordpress.php`, `tests/list-users-wordpress.php`, and `tests/list-users-http.py` use synthetic accounts on the dedicated QA site. HTTP checks use real Basic Auth, separate ungranted passwords, and administrator-cookie AJAX saves with nonce checks. `tests/list-users-browser.cjs` exercises actual WordPress-rendered scope controls and production assets, with only its AJAX transport mocked. These scripts are excluded from release ZIPs.
