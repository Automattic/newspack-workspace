/**
 * Workspace-root Prettier config.
 *
 * Re-exports the shared Newspack config (printWidth 150, arrowParens 'avoid',
 * wp-prettier parenSpacing) so tools that resolve Prettier config relative to
 * the *repo root* pick up the custom rules — most importantly the husky
 * pre-commit hook, which runs ESLint with cwd = workspace root.
 *
 * Without this file, `@wordpress/eslint-plugin` bakes Prettier options into its
 * `prettier/prettier` rule via a cwd-based `cosmiconfig( 'prettier' ).search()`
 * at config-load time. Run from the repo root that search finds no config and
 * silently falls back to the upstream `@wordpress/prettier-config` defaults
 * (printWidth 80), so the hook demands argument wrapping that the real
 * 150-width config allows. Each package's own `.prettierrc.js` re-exports the
 * same shared config; this makes the root behave identically. See NPPM-290.
 */
module.exports = require( 'newspack-scripts/config/prettier.config.js' );
