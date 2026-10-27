/**
 * Pure helpers for the A/B Test panel: nothing here reads an editor store, so
 * the rules can be tested without one.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Blocks through which a reader can meet each goal from inside the prompt. The
 * panel warns when a prompt's chosen goal has none of these, because a reader
 * who sees that prompt has no way to complete the goal. link_click has no list:
 * any link or button will do, so there is nothing to check.
 */
export const GOAL_BLOCKS = {
	form_submission: [
		'newspack/reader-registration',
		'newspack-newsletters/subscribe',
		'newspack-blocks/checkout-button',
		'newspack-blocks/donate',
	],
	donation: [ 'newspack-blocks/donate' ],
	subscription: [ 'newspack-blocks/checkout-button' ],
	link_click: [],
};

/**
 * Normalize a typed Test ID the way the server's sanitize_test_id() stores it, so
 * two prompts typed "Spring Drive 2026" and "spring-drive-2026" share one test.
 *
 * @param {string} value Typed value.
 * @return {string} Slug.
 */
export const slugifyTestId = value =>
	String( value ?? '' )
		.toLowerCase()
		.trim()
		.replace( /[\s_]+/g, '-' )
		.replace( /[^a-z0-9-]/g, '' )
		.replace( /-+/g, '-' )
		.replace( /^-|-$/g, '' );

/**
 * Whether a prompt with this placement can be part of a test.
 *
 * @param {string}   placement  Placement value.
 * @param {string[]} ineligible Placements the server refuses.
 * @return {boolean} Eligible.
 */
export const isEligiblePlacement = ( placement, ineligible ) => ! ( ineligible || [] ).includes( placement );

/**
 * One line describing the traffic split.
 *
 * @param {number} controlShare Percentage of readers who see the control.
 * @return {string} Summary.
 */
export const splitSummary = controlShare =>
	sprintf(
		// Translators: %1$d is the control's share of readers, %2$d the challenger's.
		__( '%1$d%% control, %2$d%% challenger', 'newspack-popups' ),
		controlShare,
		100 - controlShare
	);

/**
 * Whether the prompt has none of the blocks the goal is measured through.
 *
 * @param {string}   goal       Goal key.
 * @param {string[]} blockNames Names of the blocks in the prompt.
 * @return {boolean} True when the goal expects a block the prompt lacks.
 */
export const promptLacksGoalBlock = ( goal, blockNames ) => {
	const expected = GOAL_BLOCKS[ goal ] || [];
	return expected.length > 0 && ! expected.some( name => ( blockNames || [] ).includes( name ) );
};

/**
 * Warnings about the other prompts in the test, for the panel's list.
 *
 * @param {Object}   args
 * @param {number}   args.postId  This prompt's ID.
 * @param {string}   args.variant This prompt's variant.
 * @param {Object[]} args.prompts The prompts in the test, as the lookup route returns them.
 * @return {string[]} Messages.
 */
export const testPromptWarnings = ( { postId, variant, prompts } ) => {
	const others = ( prompts || [] ).filter( prompt => prompt.id !== postId );
	const warnings = [];
	if ( variant && others.some( prompt => prompt.variant === variant ) ) {
		warnings.push(
			'a' === variant
				? __( 'Another prompt is already the control of this test.', 'newspack-popups' )
				: // Translators: %s is the variant letter.
				  sprintf( __( 'Another prompt is already variant %s of this test.', 'newspack-popups' ), String( variant ).toUpperCase() )
		);
	}
	return warnings;
};
