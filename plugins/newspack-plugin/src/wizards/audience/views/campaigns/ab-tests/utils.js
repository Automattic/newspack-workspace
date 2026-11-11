/**
 * WordPress dependencies
 */
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { dateI18n, getSettings } from '@wordpress/date';

export const DASH = '—';

/**
 * Where the tab's requests go: newspack-popups owns the tests and their routes.
 */
export const REST_BASE = '/newspack-popups/v1/ab-tests';

/**
 * Card order, and the Status filter's: what needs the publisher first, ended
 * tests last.
 */
export const STATUS_ORDER = [ 'needs-attention', 'incomplete', 'draft', 'running', 'ended' ];

/**
 * A test's state from its variants' post statuses.
 *
 * @param {Object} test A test from the list route.
 * @return {string} 'running', 'needs-attention', 'draft', 'incomplete' or 'ended'.
 */
export const getTestStatus = test => {
	if ( test?.status === 'ended' ) {
		return 'ended';
	}
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
 * A test's place in the card order. A running test with a winner the card
 * would name goes ahead of the other running tests: it is ready to end.
 *
 * @param {Object} test A test from the list route.
 * @return {number} Rank, lowest first.
 */
const getSortRank = test => {
	const status = getTestStatus( test );
	const rank = STATUS_ORDER.indexOf( status ) * 2;
	// The list's cached results rather than the card's own, so cards never
	// move once their results load. A cache miss ranks a test as plain
	// running until a later load.
	return 'running' === status && getRecommendation( test, test.cached_stats ).winner ? rank - 1 : rank;
};

/**
 * @param {Object[]} tests Tests from the list route.
 * @return {Object[]} The tests in card order.
 */
export const sortTests = tests => [ ...tests ].sort( ( a, b ) => getSortRank( a ) - getSortRank( b ) );

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
		case 'ended':
			return __( 'Ended', 'newspack-plugin' );
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
		case 'ended':
			return 'informational';
		default:
			return 'low';
	}
};

/**
 * A percentage in the page's language, which in wp-admin is the admin's own,
 * so the decimal mark and the percent sign's place match the translated
 * sentence around it: "2,69 %" in French, "%2,69" in Turkish. The language
 * comes from the page, as formatCount() reads it, and the same fallback
 * applies to locale tags Intl rejects.
 *
 * @param {number} percent  Value out of 100.
 * @param {number} decimals Digits after the decimal mark, always shown.
 * @param {Object} options  More Intl.NumberFormat options.
 * @return {string} Formatted.
 */
const formatPercent = ( percent, decimals, options = {} ) => {
	const formatOptions = { style: 'percent', minimumFractionDigits: decimals, maximumFractionDigits: decimals, ...options };
	const value = Number( percent ) / 100;
	const lang = ( typeof document !== 'undefined' && document.documentElement.lang ) || '';
	const subtags = lang.replace( /_/g, '-' ).split( '-' ).filter( Boolean );
	while ( subtags.length ) {
		try {
			return new Intl.NumberFormat( subtags.join( '-' ), formatOptions ).format( value );
		} catch {
			subtags.pop();
		}
	}
	return new Intl.NumberFormat( undefined, formatOptions ).format( value );
};

/**
 * @param {?number} rate Conversions per hundred readers.
 * @return {string} Formatted.
 */
export const formatRate = rate => ( rate === null || rate === undefined ? DASH : formatPercent( rate, 2 ) );

/**
 * @param {?number} lift Percent change against A.
 * @return {string} Formatted, signed unless it rounds to zero.
 */
export const formatLift = lift => ( lift === null || lift === undefined ? DASH : formatPercent( lift, 1, { signDisplay: 'exceptZero' } ) );

/**
 * Confidence split into the percentage and its label.
 *
 * @param {?Object} comparison A comparison from the stats route.
 * @param {Object}  labels     Label key => text.
 * @return {{percent: ?string, label: string}} The percentage, or null without a confidence, and the label.
 */
export const confidenceParts = ( comparison, labels = {} ) => {
	const confidence = comparison?.confidence;
	return {
		percent: confidence === null || confidence === undefined ? null : formatPercent( confidence, 1 ),
		label: ( comparison && labels?.[ comparison.label ] ) || '',
	};
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
	const { percent, label } = confidenceParts( comparison, labels );
	if ( ! percent ) {
		return label || DASH;
	}
	return `${ percent } ${ label }`.trim();
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
 * test, and on a placement a test can use. A prompt's own menu also offers
 * drafts, as the editor's panel does; the picker lists only what is live.
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
 * Wraps a fetcher so that only the latest call's result reaches `onSettle`.
 * Two calls close together can resolve out of order; without this, the earlier
 * response could land last and overwrite the newer one. A failure settles with
 * null, under the same rule.
 *
 * @param {Function} fetcher  Returns a promise.
 * @param {Function} onSettle Called with the latest call's result, or null.
 * @return {Function} Starts a fetch; returns its promise.
 */
export const latestOnly = ( fetcher, onSettle ) => {
	let latest = 0;
	return () => {
		const request = ++latest;
		const settle = value => request === latest && onSettle( value );
		return fetcher().then( settle, () => settle( null ) );
	};
};

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

/**
 * Whether a test can be ended from its card: it is splitting readers now,
 * which is the server's rule too. A Needs Attention test qualifies only while
 * every version is still published.
 *
 * @param {Object} test The test.
 * @return {boolean} Endable.
 */
export const canEndTest = test => {
	const status = getTestStatus( test );
	if ( status !== 'running' && status !== 'needs-attention' ) {
		return false;
	}
	return Object.values( test.variants ).every( variant => variant.status === 'publish' );
};

/**
 * The version a Reliable result names as the winner, however young the test.
 *
 * A Reliable result for a challenger that converts worse than A is A winning,
 * but only once every challenger is Reliable: until then a weaker one could
 * still be the best.
 *
 * @param {Object}  test  The test.
 * @param {?Object} stats Its stats, or null.
 * @return {?string} The winning letter, or null.
 */
const getReliableWinner = ( test, stats ) => {
	if ( ! stats || stats.source?.state === 'unavailable' ) {
		return null;
	}
	const reliable = Object.keys( stats.comparisons || {} ).filter(
		letter => test.variants?.[ letter ] && stats.comparisons[ letter ]?.label === 'reliable' && stats.variants?.[ letter ]
	);
	const better = reliable.filter( letter => ( stats.variants[ letter ].lift ?? 0 ) > 0 );
	if ( better.length > 0 ) {
		return better.reduce( ( top, letter ) => ( stats.variants[ letter ].rate > stats.variants[ top ].rate ? letter : top ) );
	}
	if ( reliable.length > 0 && reliable.length === Object.keys( test.variants ).length - 1 ) {
		return 'a';
	}
	return null;
};

/**
 * Which version the End Test modal recommends keeping, and why.
 *
 * Only a Reliable result names a winner, and only once the test is past the
 * server's young-test age: early results swing. Anything else recommends A,
 * the version readers saw before the test.
 *
 * @param {Object}  test  The test.
 * @param {?Object} stats Its stats, or null.
 * @return {{ letter: string, winner: boolean }} The letter to recommend, and
 *                                               whether a reliable winner backs it.
 */
export const getRecommendation = ( test, stats ) => {
	// The server's young-test flag, so the two never disagree about the age.
	const winner = stats?.flags?.young_test === false ? getReliableWinner( test, stats ) : null;
	return winner ? { letter: winner, winner: true } : { letter: 'a', winner: false };
};

/**
 * The version a verdict sets against another: A, or when it is A, the best of
 * the rest.
 *
 * @param {string}   letter  The version named.
 * @param {string[]} letters The versions with a rate.
 * @param {Object}   rates   Letter => rate.
 * @return {?string} The other letter, or null.
 */
const getCounterpart = ( letter, letters, rates ) => {
	if ( letter !== 'a' && letters.includes( 'a' ) ) {
		return 'a';
	}
	const rest = letters.filter( other => other !== letter );
	return rest.length ? rest.reduce( ( top, other ) => ( rates[ other ] > rates[ top ] ? other : top ) ) : null;
};

/**
 * One bar per version, as a share of the leader's rate.
 *
 * @param {string[]} letters The test's versions.
 * @param {Object}   rates   Letter => rate, for the versions with one.
 * @return {{ placeholder: boolean, rows: Object[] }} Each row's `letter` and `width` in percent.
 */
const getBars = ( letters, rates ) => {
	const top = Math.max( 0, ...Object.values( rates ) );
	if ( top <= 0 ) {
		return { placeholder: true, rows: letters.map( letter => ( { letter, width: 100 } ) ) };
	}
	return {
		placeholder: false,
		rows: letters.map( letter => ( { letter, width: Math.round( ( ( rates[ letter ] ?? 0 ) / top ) * 1000 ) / 10 } ) ),
	};
};

/**
 * What a card's Result section says: a one-line verdict, an optional detail
 * and the bars. Only a Reliable result past the young-test age names a winner,
 * so a publisher is never nudged to end a test early.
 *
 * @param {Object}  test   The test.
 * @param {string}  status From getTestStatus().
 * @param {?Object} stats  Its stats, or null when they are not loaded or not available.
 * @return {?{ answer: string, detail: ?string, bars: ?Object }} Null when the card shows a notice or nothing yet.
 */
export const getVerdict = ( test, status, stats ) => {
	const letters = Object.keys( test.variants || {} ).sort();
	if ( status === 'draft' ) {
		const unpublished = letters.filter( letter => test.variants[ letter ].status !== 'publish' ).map( letter => letter.toUpperCase() );
		return {
			answer: __( 'Not started.', 'newspack-plugin' ),
			detail: sprintf(
				// Translators: %s is a version letter or a list of them, such as "B" or "B and C".
				_n( 'Publish version %s to start the test.', 'Publish versions %s to start the test.', unpublished.length, 'newspack-plugin' ),
				formatList( unpublished )
			),
			bars: null,
		};
	}
	if ( status !== 'running' && status !== 'ended' ) {
		return null;
	}

	const usable = stats && stats.source?.state !== 'unavailable' ? stats : null;
	const counted = letters.filter(
		letter => ( usable?.variants?.[ letter ]?.readers ?? 0 ) > 0 && null !== ( usable.variants[ letter ].rate ?? null )
	);
	const rates = Object.fromEntries( counted.map( letter => [ letter, Number( usable.variants[ letter ].rate ) ] ) );

	if ( status === 'ended' ) {
		const kept = String( test.kept || '' ).toLowerCase();
		const answer = sprintf(
			// Translators: %1$s is the date the test ended, %2$s the letter of the version kept, such as "B".
			__( 'Ended %1$s. Kept %2$s.', 'newspack-plugin' ),
			formatDay( test.ended_at ),
			kept.toUpperCase()
		);
		const other = counted.includes( kept ) ? getCounterpart( kept, counted, rates ) : null;
		if ( ! other ) {
			return { answer, detail: null, bars: null };
		}
		const endedBars = getBars( letters, rates );
		return {
			answer,
			detail: sprintf(
				// Translators: %1$s is the kept version's letter, %2$s its conversion rate, %3$s the other rate, %4$s the other version's letter.
				__( '%1$s converted %2$s of readers, against %3$s for %4$s.', 'newspack-plugin' ),
				kept.toUpperCase(),
				formatRate( rates[ kept ] ),
				formatRate( rates[ other ] ),
				other.toUpperCase()
			),
			// Faint bars mean "no data yet", which an ended test never is.
			bars: endedBars.placeholder ? null : endedBars,
		};
	}

	if ( ! usable ) {
		return null;
	}
	if ( counted.length < 2 ) {
		return {
			answer: __( 'Too early to tell.', 'newspack-plugin' ),
			detail: __( 'The test started today, so there are no results yet.', 'newspack-plugin' ),
			bars: { placeholder: true, rows: letters.map( letter => ( { letter, width: 100 } ) ) },
		};
	}

	const bars = getBars( letters, rates );
	const winner = getReliableWinner( test, usable );
	if ( winner && counted.includes( winner ) ) {
		const other = getCounterpart( winner, counted, rates );
		if ( usable.flags?.young_test === false ) {
			return {
				answer: sprintf(
					// Translators: %1$s is the winning version's letter, %2$s its conversion rate, %3$s the other rate, %4$s the other version's letter.
					__( '%1$s wins. %2$s of readers who saw %1$s converted, against %3$s for %4$s.', 'newspack-plugin' ),
					winner.toUpperCase(),
					formatRate( rates[ winner ] ),
					formatRate( rates[ other ] ),
					other.toUpperCase()
				),
				detail: null,
				bars,
			};
		}
		const days = Math.max( 0, Number( usable.flags?.days_running ) || 0 );
		return {
			answer: sprintf(
				// Translators: %1$s is the leading version's letter, %2$s its conversion rate, %3$s the other version's rate.
				__( '%1$s is ahead, %2$s against %3$s.', 'newspack-plugin' ),
				winner.toUpperCase(),
				formatRate( rates[ winner ] ),
				formatRate( rates[ other ] )
			),
			detail: sprintf(
				// Translators: %d is the number of days the test has run.
				_n(
					'The result looks reliable, but the test is %d day old. Let it run a full two weeks before ending it.',
					'The result looks reliable, but the test is %d days old. Let it run a full two weeks before ending it.',
					days,
					'newspack-plugin'
				),
				days
			),
			bars,
		};
	}

	const leader = counted.reduce( ( top, letter ) => ( rates[ letter ] > rates[ top ] ? letter : top ) );
	const other = getCounterpart( leader, counted, rates );
	const detail = usable.flags?.high_anonymous
		? __( 'More readers are needed. Some may have seen both versions on different devices.', 'newspack-plugin' )
		: __( 'More readers are needed.', 'newspack-plugin' );
	if ( rates[ leader ] === rates[ other ] ) {
		const [ first, second ] = [ leader, other ].sort();
		return {
			answer: sprintf(
				// Translators: %1$s and %2$s are version letters, %3$s their shared conversion rate.
				__( 'No winner yet. %1$s and %2$s are even, at %3$s.', 'newspack-plugin' ),
				first.toUpperCase(),
				second.toUpperCase(),
				formatRate( rates[ leader ] )
			),
			detail,
			bars,
		};
	}
	return {
		answer: sprintf(
			// Translators: %1$s is the leading version's letter, %2$s its conversion rate, %3$s the other version's rate.
			__( 'No winner yet. %1$s is ahead, %2$s against %3$s.', 'newspack-plugin' ),
			leader.toUpperCase(),
			formatRate( rates[ leader ] ),
			formatRate( rates[ other ] )
		),
		detail,
		bars,
	};
};

/**
 * @param {string} iso An ISO 8601 date.
 * @return {string} The date in the site's format, without a time.
 */
export const formatDay = iso => dateI18n( getSettings().formats.date, iso );
