/**
 * @wordpress/edit-post ships no TypeScript types (unlike most @wordpress/*
 * packages), so everything imported from it is `any` via this shorthand
 * declaration — same approach as newspack-plugin's src/bylines/globals.d.ts
 * uses for its untyped imports.
 *
 * This is a global script (no top-level imports/exports); inline `import()`
 * types only.
 */
declare module '@wordpress/edit-post';
