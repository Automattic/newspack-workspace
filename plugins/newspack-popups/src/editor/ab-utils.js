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
 * Periods and slashes become hyphens because core's sanitize_title() turns them
 * into hyphens before stripping; non-ASCII letters are the known divergence
 * (the server transliterates them, this drops them).
 *
 * @param {string} value Typed value.
 * @return {string} Slug.
 */
export const slugifyTestId = value =>
	String( value ?? '' )
		.toLowerCase()
		.trim()
		.replace( /[\s_./]+/g, '-' )
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
		// Translators: %1$d is the share of readers who see version A, %2$d the share who see version B.
		__( '%1$d%% see A, %2$d%% see B', 'newspack-popups' ),
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
 * What to tell an author whose prompt has no block for its goal.
 *
 * @param {string} goal Goal key.
 * @return {string} Message.
 */
export const missingGoalBlockMessage = goal => {
	switch ( goal ) {
		case 'donation':
			return __( "Readers can't donate from this prompt. Add a Donate block, or choose another goal.", 'newspack-popups' );
		case 'subscription':
			return __( "Readers can't subscribe from this prompt. Add a Checkout Button block, or choose another goal.", 'newspack-popups' );
		default:
			return __(
				"Readers can't fill in a form from this prompt. Add a sign-up, registration, donate or checkout block, or choose another goal.",
				'newspack-popups'
			);
	}
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
			sprintf(
				// Translators: %s is the version letter.
				__( 'Another prompt in this test is already version %s. Choose a different version.', 'newspack-popups' ),
				String( variant ).toUpperCase()
			)
		);
	}
	return warnings;
};
