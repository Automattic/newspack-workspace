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
 * Whether a prompt with this placement can be part of a test.
 *
 * @param {string}   placement  Placement value.
 * @param {string[]} ineligible Placements the server refuses.
 * @return {boolean} Eligible.
 */
export const isEligiblePlacement = ( placement, ineligible ) => ! ( ineligible || [] ).includes( placement );

/**
 * The goal to suggest for a new test, from the blocks in the prompt, and the
 * help line that says where it came from. The most specific goal wins:
 * GOAL_BLOCKS can't be read in order for this, because it lists the Checkout
 * Button under form submissions too.
 *
 * @param {string[]} blockNames Names of the blocks in the prompt.
 * @param {string}   content    The prompt's content, for links outside a button block.
 * @return {{goal: string, help: string}|null} Suggestion, or null when nothing in the prompt names a goal.
 */
export const suggestGoal = ( blockNames, content = '' ) => {
	const has = name => ( blockNames || [] ).includes( name );
	if ( has( 'newspack-blocks/checkout-button' ) ) {
		return {
			goal: 'subscription',
			help: __( 'What a reader does that counts as a success. Suggested from the Checkout Button in this prompt.', 'newspack-popups' ),
		};
	}
	if ( has( 'newspack-blocks/donate' ) ) {
		return {
			goal: 'donation',
			help: __( 'What a reader does that counts as a success. Suggested from the Donate block in this prompt.', 'newspack-popups' ),
		};
	}
	if ( has( 'newspack/reader-registration' ) || has( 'newspack-newsletters/subscribe' ) ) {
		return {
			goal: 'form_submission',
			help: __( 'What a reader does that counts as a success. Suggested from the sign-up form in this prompt.', 'newspack-popups' ),
		};
	}
	if ( has( 'core/button' ) || /<a\s[^>]*href=/i.test( content || '' ) ) {
		return {
			goal: 'link_click',
			help: __( 'What a reader does that counts as a success. Suggested from the link in this prompt.', 'newspack-popups' ),
		};
	}
	return null;
};

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
 * @param {string}  goal        Goal key.
 * @param {boolean} fromControl Whether the goal comes from version A, so it can't be changed on this prompt.
 * @return {string} Message.
 */
export const missingGoalBlockMessage = ( goal, fromControl = false ) => {
	switch ( goal ) {
		case 'donation':
			return fromControl
				? __( "Readers can't donate from this prompt. Add a Donate block, or change the goal on version A.", 'newspack-popups' )
				: __( "Readers can't donate from this prompt. Add a Donate block, or choose another goal.", 'newspack-popups' );
		case 'subscription':
			return fromControl
				? __( "Readers can't subscribe from this prompt. Add a Checkout Button block, or change the goal on version A.", 'newspack-popups' )
				: __( "Readers can't subscribe from this prompt. Add a Checkout Button block, or choose another goal.", 'newspack-popups' );
		default:
			return fromControl
				? __(
						"Readers can't fill in a form from this prompt. Add a sign-up, registration, donate or checkout block, or change the goal on version A.",
						'newspack-popups'
				  )
				: __(
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
				__( 'Another prompt in this test is already version %s. Unpublish one of them.', 'newspack-popups' ),
				String( variant ).toUpperCase()
			)
		);
	}
	return warnings;
};
