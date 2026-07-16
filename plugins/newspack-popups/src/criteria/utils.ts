import matchingFunctions from './matching-functions';

window.newspackPopupsCriteria = window.newspackPopupsCriteria || { criteria: {} };
window.newspackPopupsCriteria.criteria = window.newspackPopupsCriteria.criteria || {};

/**
 * A segment's criteria config, as matched against a registered `Criteria`
 * (e.g. `{ value: 'subscribers' }` or `{ value: { min: 1, max: 10 } }`).
 */
export type SegmentConfig = { value?: unknown; [ key: string ]: unknown };

/**
 * Resolves the current value for a criteria's matching attribute, either from
 * the reader data library store or a custom getter function.
 */
export type MatchingAttributeGetter = ( ras?: NewspackReaderActivation ) => unknown;

/**
 * The reader-activation client as seen by a `MatchingFunction`: only
 * `store.get()` is needed by any built-in or custom matching function, so
 * that's all a matching function is required to declare (implementations,
 * e.g. `matchDonation()`, and test stubs can use this narrower shape instead
 * of implementing all of `NewspackReaderActivation`).
 */
export type MatchingRas = { store: Pick< NewspackReaderActivationStore, 'get' > };

/**
 * Decides whether a criteria matches a segment's config. Called with the
 * segment config, the reader activation client (if loaded), and the criteria
 * itself (so custom functions can read e.g. `optionParams`).
 */
export type MatchingFunction = ( config: SegmentConfig, ras?: MatchingRas, criteria?: Criteria ) => unknown;

/**
 * Configuration accepted by `registerCriteria()`/`setMatchingAttribute()`/
 * `setMatchingFunction()`. Extra keys (e.g. `optionParams`) are passed through
 * to the registered `Criteria` as-is.
 */
export interface CriteriaConfig {
	matchingFunction?: string | MatchingFunction;
	matchingAttribute?: string | MatchingAttributeGetter;
	optionParams?: Record< string, unknown >;
	[ key: string ]: unknown;
}

/**
 * A registered criteria, as stored on `window.newspackPopupsCriteria.criteria`.
 */
export interface Criteria extends CriteriaConfig {
	id: string;
	matchingFunction: string | MatchingFunction;
	value?: unknown;
	_configured?: boolean;
	/** Matched-result cache, keyed by `JSON.stringify( segmentConfig )`. */
	_matched: Record< string, unknown >;
	getValue: MatchingAttributeGetter;
	matches: ( segmentConfig: SegmentConfig ) => unknown;
}

const pendingConfig: Record< string, CriteriaConfig > = {};

/**
 * Registers a criteria.
 *
 * @param {string}          id                       The criteria ID. (required)
 * @param {Object}          config                   Criteria matching configuration.
 * @param {string|Function} config.matchingFunction  Function to use for matching. Defaults to 'default'.
 * @param {string|Function} config.matchingAttribute Either the attribute name to match from the reader data library
 *                                                   store or a function that returns the value. Defaults to the ID.
 *
 * @return {Object} The criteria object.
 *
 * @throws {Error} If the criteria ID is not provided.
 */
export function registerCriteria( id: string, config: CriteriaConfig = {} ): Criteria {
	if ( ! id ) {
		throw new Error( 'Criteria must have an ID.' );
	}
	const criteria: Criteria = {
		id,
		matchingFunction: 'default',
		...config,
		...pendingConfig[ id ],
		// Placeholders, overwritten below once `setup` is in scope. Never observed
		// with these values since nothing reads them before that happens.
		_matched: {},
		getValue: () => undefined,
		matches: () => undefined,
	};

	/**
	 * Setup matching requirements for the criteria.
	 */
	const setup = ( ras?: NewspackReaderActivation ) => {
		// Run setup only once.
		if ( criteria._configured ) {
			return;
		}
		criteria._configured = true;

		// Default attribute to the criteria ID.
		if ( ! criteria.matchingAttribute ) {
			criteria.matchingAttribute = criteria.id;
		}

		// Configure matching function.
		if ( typeof criteria.matchingFunction === 'string' && matchingFunctions[ criteria.matchingFunction ] ) {
			criteria.matchingFunction = matchingFunctions[ criteria.matchingFunction ].bind( null, criteria );
		}

		// Bail if unable to configure matching function.
		if ( typeof criteria.matchingFunction !== 'function' ) {
			console.warn( `Unable to configure matching function for criteria ${ criteria.id }.` ); // eslint-disable-line no-console
			return;
		}

		// Clear matched cache when the reader data library store changes.
		if ( typeof ras?.on === 'function' ) {
			ras.on( 'data', () => {
				criteria._matched = {};
			} );
		}

		// Set criteria value.
		criteria.value = criteria.getValue( ras );
	};

	criteria.getValue = ras => {
		if ( typeof criteria.matchingAttribute === 'function' ) {
			return criteria.matchingAttribute( ras );
		}
		if ( typeof criteria.matchingAttribute === 'string' ) {
			if ( typeof ras?.store?.get === 'function' ) {
				return ras.store.get( criteria.matchingAttribute );
			}
			// eslint-disable-next-line no-console
			console.warn( `Reader data library not loaded. Unable to fetch value for '${ criteria.id }'` );
		}
		return criteria.value;
	};

	// Check if the criteria matches the segment config.
	criteria._matched = {}; // Cache results.
	criteria.matches = segmentConfig => {
		const configString = JSON.stringify( segmentConfig );
		if ( criteria._matched[ configString ] !== undefined ) {
			return criteria._matched[ configString ];
		}
		const ras = window.newspackReaderActivation;
		if ( ! ras ) {
			console.warn( 'Reader activation script not loaded.' ); // eslint-disable-line no-console
		}
		setup( ras );
		// NOTE: pre-existing behavior, not introduced by this migration: `ras` may be
		// `undefined` here (see the warning above), yet is still passed through to
		// custom matching functions that destructure it (e.g. `matchDonation`,
		// `matchNewsletter`), which would throw if actually called with `ras` unset.
		// `matchingFunction` is only ever a `string` before `setup()` runs; `setup()`
		// resolves it to a real function (or bails with a console warning), a
		// contract `matches()` relies on but that TS can't see across the call.
		criteria._matched[ configString ] = ( criteria.matchingFunction as MatchingFunction )( segmentConfig, ras, criteria );
		return criteria._matched[ configString ];
	};
	if ( ! window.newspackPopupsCriteria.criteria ) {
		window.newspackPopupsCriteria.criteria = {};
	}
	window.newspackPopupsCriteria.criteria[ id ] = criteria;

	return criteria;
}

/**
 * Get all registered criteria or a specific by ID.
 *
 * @param {string} id The criteria ID.
 *
 * @return {Object|undefined} The criteria object or an object of all criteria.
 *                            undefined if the criteria ID is not found.
 */
export function getCriteria( id: string ): Criteria | undefined;
export function getCriteria(): Record< string, Criteria >;
export function getCriteria( id?: string ): Criteria | Record< string, Criteria > | undefined {
	if ( id ) {
		return window.newspackPopupsCriteria.criteria[ id ];
	}
	return window.newspackPopupsCriteria.criteria;
}

/**
 * Set the criteria matching attribute.
 *
 * @param {string}          id                The criteria ID.
 * @param {string|Function} matchingAttribute Either the attribute name to match from the reader data library store or
 *                                            a function that returns the value.
 *
 * @throws {Error} If the criteria ID is not found.
 */
export function setMatchingAttribute( id: string, matchingAttribute: string | MatchingAttributeGetter ): void {
	let criteria: Criteria | CriteriaConfig | undefined = getCriteria( id );
	if ( ! criteria ) {
		pendingConfig[ id ] = pendingConfig[ id ] || {};
		criteria = pendingConfig[ id ];
	}
	criteria._matched = {}; // Clear matched cache.
	criteria.matchingAttribute = matchingAttribute;
}

/**
 * Set the criteria matching function.
 *
 * @param {string}          id               The criteria ID.
 * @param {string|Function} matchingFunction Function to use for matching
 *
 * @throws {Error} If the criteria ID is not found.
 */
export function setMatchingFunction( id: string, matchingFunction: string | MatchingFunction ): void {
	let criteria: Criteria | CriteriaConfig | undefined = getCriteria( id );
	if ( ! criteria ) {
		pendingConfig[ id ] = pendingConfig[ id ] || {};
		criteria = pendingConfig[ id ];
	}
	criteria._matched = {}; // Clear matched cache.
	criteria.matchingFunction = matchingFunction;
}
