/**
 * Donation frequency slugs, on their own so any bundle can consume them:
 * consts.ts attaches translated labels (pulling in @wordpress/i18n at module
 * scope), which the modal checkout's donate-trigger resolver must not drag
 * into its bundles just to validate trigger params. The PHP renderers
 * hardcode the same set (class-newspack-blocks-donate-renderer-base.php).
 *
 * @type {string[]}
 */
export const FREQUENCY_SLUGS = [ 'once', 'month', 'year' ];
