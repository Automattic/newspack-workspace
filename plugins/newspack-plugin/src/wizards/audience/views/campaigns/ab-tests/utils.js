/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

export const DASH = '—';

/**
 * Where the tab's requests go: newspack-popups owns the tests and their routes.
 */
export const REST_BASE = '/newspack-popups/v1/ab-tests';

/**
 * Card order: running tests, then those needing attention, drafts, and
 * tests still missing a version.
 */
export const STATUS_ORDER = [ 'running', 'needs-attention', 'draft', 'incomplete' ];

/**
 * A test's state from its variants' post statuses.
 *
 * @param {Object} test A test from the list route.
 * @return {string} 'running', 'needs-attention', 'draft' or 'incomplete'.
 */
export const getTestStatus = test => {
	const variants = ( test && test.variants ) || {};
	const letters = Object.keys( variants );
	const challengers = letters.filter( letter => letter !== 'a' );
	if ( ! variants.a || challengers.length === 0 ) {
		return 'incomplete';
	}
	if ( letters.some( letter => false === variants[ letter ].eligible ) ) {
		return 'needs-attention';
	}
	return letters.every( letter => variants[ letter ].status === 'publish' ) ? 'running' : 'draft';
};

/**
 * @param {Object[]} tests Tests from the list route.
 * @return {Object[]} The tests in card order.
 */
export const sortTests = tests =>
	[ ...tests ].sort( ( a, b ) => STATUS_ORDER.indexOf( getTestStatus( a ) ) - STATUS_ORDER.indexOf( getTestStatus( b ) ) );

/**
 * @param {string} status From getTestStatus().
 * @return {string} Label.
 */
export const getStatusLabel = status => {
	switch ( status ) {
		case 'running':
			return __( 'Running', 'newspack-plugin' );
		case 'needs-attention':
			return __( 'Needs attention', 'newspack-plugin' );
		case 'draft':
			return __( 'Draft', 'newspack-plugin' );
		default:
			return __( 'Incomplete', 'newspack-plugin' );
	}
};

/**
 * @param {string} status From getTestStatus().
 * @return {string} A `Badge` intent.
 */
export const getStatusIntent = status => {
	switch ( status ) {
		case 'running':
			return 'stable';
		case 'needs-attention':
			return 'high';
		case 'draft':
			return 'draft';
		default:
			return 'low';
	}
};

/**
 * @param {?number} rate Conversions per hundred readers.
 * @return {string} Formatted.
 */
export const formatRate = rate => ( rate === null || rate === undefined ? DASH : `${ Number( rate ).toFixed( 2 ) }%` );

/**
 * @param {?number} lift Percent change against A.
 * @return {string} Formatted, always signed.
 */
export const formatLift = lift => {
	if ( lift === null || lift === undefined ) {
		return DASH;
	}
	const value = Number( lift );
	return `${ value < 0 ? '-' : '+' }${ Math.abs( value ).toFixed( 1 ) }%`;
};

/**
 * Confidence as text: the percentage then the label, or the label alone.
 *
 * @param {?Object} comparison A comparison from the stats route.
 * @param {Object}  labels     Label key => text.
 * @return {string} Formatted.
 */
export const formatConfidence = ( comparison, labels = {} ) => {
	if ( ! comparison ) {
		return DASH;
	}
	const label = labels[ comparison.label ] || '';
	if ( comparison.confidence === null || comparison.confidence === undefined ) {
		return label || DASH;
	}
	return `${ Number( comparison.confidence ).toFixed( 1 ) }% ${ label }`.trim();
};

/**
 * @param {string} unit 'readers' or 'events'.
 * @return {string} The count column's heading.
 */
export const getCountLabel = unit => ( unit === 'events' ? __( 'Views', 'newspack-plugin' ) : __( 'Readers', 'newspack-plugin' ) );

/**
 * A keeps its share and the other versions divide the rest evenly, which is
 * how readers are assigned.
 *
 * @param {number}   controlShare Percent of readers who see A.
 * @param {string[]} letters      The test's version letters.
 * @return {string} The split, such as "50% see A, 50% see B."
 */
export const splitSummary = ( controlShare, letters = [ 'a', 'b' ] ) => {
	const challengers = letters.filter( letter => letter !== 'a' ).sort();
	if ( challengers.length === 0 ) {
		challengers.push( 'b' );
	}
	if ( challengers.length === 1 ) {
		return sprintf(
			// Translators: %1$d is the share of readers who see version A, %2$d the share who see the other version, %3$s that version's letter such as "B".
			__( '%1$d%% see A, %2$d%% see %3$s.', 'newspack-plugin' ),
			controlShare,
			100 - controlShare,
			challengers[ 0 ].toUpperCase()
		);
	}
	// Rounding each share on its own can total 101; the last version takes the
	// remainder so the line always adds up to 100.
	const rest = 100 - controlShare;
	const challengerShare = Math.round( rest / challengers.length );
	const lastShare = rest - challengerShare * ( challengers.length - 1 );
	const parts = [ 'a', ...challengers ].map( ( letter, index ) => {
		let share = challengerShare;
		if ( letter === 'a' ) {
			share = controlShare;
		} else if ( index === challengers.length ) {
			share = lastShare;
		}
		return sprintf(
			// Translators: %1$d is a share of readers, %2$s a version letter such as "C".
			__( '%1$d%% see %2$s', 'newspack-plugin' ),
			share,
			letter.toUpperCase()
		);
	} );
	return sprintf(
		// Translators: %s is a list such as "60% see A, 20% see B and 20% see C".
		_x( '%s.', 'list of version shares', 'newspack-plugin' ),
		formatList( parts )
	);
};

/**
 * A list in the page's language, such as "A and B".
 *
 * @param {string[]} items Items.
 * @return {string} Joined list.
 */
export const formatList = items => {
	// WordPress locales such as pt_PT_ao90 become tags Intl rejects, and a throw
	// here would unmount the tab.
	try {
		return new Intl.ListFormat( document.documentElement.lang || 'en', { type: 'conjunction' } ).format( items );
	} catch ( e ) {
		return items.join( ', ' );
	}
};

/**
 * What the Start A/B Test modal says about a suggested goal, by goal. The
 * suggestion itself comes from the server, which reads the prompt's blocks the
 * way the editor's suggestGoal() does; these lines follow its wording.
 */
export const GOAL_SUGGESTION_HELP = {
	subscription: __( 'What a reader does that counts as a success. Suggested from the Checkout Button in this prompt.', 'newspack-plugin' ),
	donation: __( 'What a reader does that counts as a success. Suggested from the Donate block in this prompt.', 'newspack-plugin' ),
	form_submission: __( 'What a reader does that counts as a success. Suggested from the sign-up form in this prompt.', 'newspack-plugin' ),
	link_click: __( 'What a reader does that counts as a success. Suggested from the link in this prompt.', 'newspack-plugin' ),
};

/**
 * Whether a prompt can start a test from the tab's picker: published, in no
 * test, and on a placement a test can use.
 *
 * @param {Object} prompt A prompt from the list route's `prompts`.
 * @return {boolean} Startable.
 */
export const canStartFromPicker = prompt => 'publish' === prompt.status && ! prompt.test_id && ! prompt.ineligible_reason;

/**
 * The prompts the tab's picker offers, and whether any published prompt was
 * left out for its placement alone, so the picker can say why.
 *
 * @param {Object[]} prompts The list route's `prompts`.
 * @return {{ startable: Object[], hasIneligible: boolean }} Picker contents.
 */
export const getPickerPrompts = ( prompts = [] ) => ( {
	startable: prompts.filter( canStartFromPicker ),
	hasIneligible: prompts.some( prompt => 'publish' === prompt.status && ! prompt.test_id && prompt.ineligible_reason ),
} );

/**
 * The link that opens one test's card on the A/B Tests tab.
 *
 * @param {string} testId Test ID.
 * @return {string} Hash route.
 */
export const testCardHash = testId => `#/ab-tests?test=${ encodeURIComponent( testId ) }`;

/**
 * Where to go once version B exists: its editor, which says the test was created.
 *
 * @param {Object} result The start route's result.
 */
export const openVersionB = result => {
	if ( result?.version_b?.edit_link ) {
		window.location.assign( addQueryArgs( result.version_b.edit_link, { 'newspack-ab-test': 'created' } ) );
	} else {
		window.location.reload();
	}
};
