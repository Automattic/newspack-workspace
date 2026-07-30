/**
 * Ambient module declaration for @wordpress/block-editor, which ships no types
 * of its own (unlike most @wordpress/* packages). Everything imported from it
 * is `any`. Do not add a shim like this for packages that publish their own
 * types, and do not use stale @types/wordpress__* packages.
 */
declare module '@wordpress/block-editor';
