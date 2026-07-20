/**
 * Ambient declarations for the bylines editor unit. Global-script form
 * (no top-level imports/exports).
 */

/**
 * @wordpress/edit-post ships no TypeScript types (unlike most @wordpress/*
 * packages), so everything imported from it is `any` via this shorthand
 * declaration — same approach as src/content-gate/editor/index.d.ts uses
 * for its untyped imports.
 */
declare module '@wordpress/edit-post';

/**
 * Config localized on the bylines editor script by Bylines::enqueue_editor_assets()
 * (includes/bylines/class-bylines.php).
 */
declare const newspackBylines: {
	metaKeyActive: string;
	metaKeyByline: string;
	siteUrl: string;
};
