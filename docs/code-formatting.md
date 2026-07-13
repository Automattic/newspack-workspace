# Code formatting (Prettier) — canonical setup

This document is the single source of truth for how JavaScript/TypeScript/SCSS
formatting works in the workspace, and how to make an editor agree with CI. If
an editor formats a file one way and `pnpm lint` (CI) wants another, something
below is misconfigured.

## The canonical engine: `wp-prettier`

Newspack code is written in the WordPress house style, which includes
**paren spacing** — `( value )`, `{ foo }`, `setConfig( { ... } )`. That spacing
is implemented only by [`wp-prettier`](https://www.npmjs.com/package/wp-prettier)
(the WordPress fork of Prettier), via its `parenSpacing` option. Stock `prettier`
does not understand `parenSpacing`: it warns `Ignored unknown option { parenSpacing: true }`
and strips the spaces.

`newspack-scripts` already pins the fork (`"prettier": "npm:wp-prettier@^3.0.3"`),
and the ESLint `prettier/prettier` rule resolves it. To guarantee that **every**
consumer — `node_modules/.bin/prettier`, `npx prettier`, the editor's Prettier
extension, and `eslint-plugin-prettier` — uses the same engine, the root
`package.json` does two things:

```jsonc
// package.json
"devDependencies": {
	"prettier": "npm:wp-prettier@^3.0.3"   // root .bin/prettier is wp-prettier
},
"pnpm": {
	"overrides": {
		"prettier": "npm:wp-prettier@^3.0.3" // collapse ALL prettier specifiers,
	}                                         // including transitive ones, to the fork
}
```

Without the override, stock `prettier` (e.g. as a transitive or root dependency)
coexists with `wp-prettier` in the tree. Different tools then resolve different
engines and disagree on `parenSpacing` — formatting in the editor with one and
linting in CI with the other.

> **Do not** reformat the codebase to stock Prettier. That would mean dropping
> `parenSpacing` and rewriting every file in every plugin/theme — a workspace-wide
> change, not a config tweak.

## The canonical config

`packages/scripts/config/prettier.config.js` (published as `newspack-scripts`):

```js
const wpConfig = require( '@wordpress/prettier-config' );
module.exports = {
	...wpConfig,            // useTabs, tabWidth 4, singleQuote, parenSpacing, ...
	arrowParens: 'avoid',  // value => …   (not ( value ) => …)
	printWidth: 150,       // wider than the WP default of 80
};
```

Each plugin/theme re-exports it from its own `.prettierrc.js`:

```js
const baseConfig = require( 'newspack-scripts/config/prettier.config.js' );
module.exports = { ...baseConfig };
```

### Why a bare specifier, not `./node_modules/...`

The `require()` above uses the **bare specifier** `newspack-scripts/config/...`,
not a hardcoded `./node_modules/newspack-scripts/config/...`. Under pnpm's strict,
symlinked `node_modules` layout, a package is not guaranteed to be symlinked into
*every* directory's local `node_modules`; the hardcoded relative path only
resolves when that exact symlink exists in the config file's own folder. A bare
specifier lets Node walk up the tree and find `newspack-scripts` wherever pnpm
placed it, so it resolves from any working directory or hoisting layout.

When the hardcoded path fails (e.g. a contributor's pnpm layout, or opening a
sub-folder in the editor), the editor's Prettier extension can't load
`.prettierrc.js`. It then either does nothing (with `prettier.requireConfig: true`,
recommended below) or formats with Prettier's own defaults — `printWidth: 80`,
`arrowParens: 'always'`, no `parenSpacing` — none of which match the canonical
config (`printWidth: 150`, `arrowParens: 'avoid'`), so CI rejects the result.

> **ESLint/Stylelint `extends` are different.** `.eslintrc.js` and
> `.stylelintrc.js` keep the explicit `extends: [ './node_modules/newspack-scripts/...' ]`
> path on purpose: ESLint's config resolver does **not** accept a bare
> `newspack-scripts/.eslintrc.js` specifier (unlike Node's `require`), so the
> explicit path is required there. Don't "fix" those to a bare specifier.

## Required editor settings

### VS Code

Recommended settings are committed in [`.vscode/settings.json`](../.vscode/settings.json)
and the [Prettier](https://marketplace.visualstudio.com/items?itemName=esbenp.prettier-vscode)
+ [ESLint](https://marketplace.visualstudio.com/items?itemName=dbaeumer.vscode-eslint)
extensions are recommended in [`.vscode/extensions.json`](../.vscode/extensions.json).
The two settings that matter:

- `"editor.defaultFormatter": "esbenp.prettier-vscode"` + `"editor.formatOnSave": true`
- `"prettier.requireConfig": true` — only format when a Prettier config resolves.
  If it doesn't resolve, the extension does nothing instead of formatting with
  stock defaults, so a misconfiguration surfaces as "format-on-save stopped
  working" rather than as silently-wrong formatting that fails CI.

The Prettier extension auto-resolves the nearest local `node_modules/prettier`
(now `wp-prettier`), so no `prettier.prettierPath` is needed. **Do not** set a
custom `prettier.configPath` pointing at `@wordpress/prettier-config` — that
bypasses the `printWidth: 150` / `arrowParens: 'avoid'` overrides.

Run `pnpm install` at the workspace root before relying on format-on-save, so that
`newspack-scripts` is installed and the config `require()` can resolve.

### Other editors

Any editor integration must (1) use the workspace `node_modules/.bin/prettier`
(wp-prettier), not a globally-installed or bundled stock Prettier, and (2) let
Prettier discover the nearest `.prettierrc.js` rather than pointing at a fixed
config.

## Verifying editor and CI agree

A file formatted by the editor must pass lint, and vice versa:

```sh
# Format a file the way the editor does, then lint it — expect 0 prettier/prettier errors:
node_modules/.bin/prettier --write plugins/newspack-plugin/src/some-file.tsx
pnpm --filter newspack run lint:js
```

The `prettier/prettier` ESLint rule and `prettier --write` now use the same
engine and the same resolved config, so their output is identical by construction.
