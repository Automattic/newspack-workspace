import matchingFunctions from './matching-functions';

window.newspackPopupsCriteria = window.newspackPopupsCriteria || { criteria: {} };
window.newspackPopupsCriteria.criteria = window.newspackPopupsCriteria.criteria || {};

const pendingConfig = {};

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
export function registerCriteria( id, config = {} ) {
	if ( ! id ) {
		throw new Error( 'Criteria must have an ID.' );
	}
	const criteria = {
		id,
		matchingFunction: 'default',
		...config,
		...pendingConfig[ id ],
	};

	/**
	 * Setup matching requirements for the criteria.
	 */
	const setup = ras => {
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
		criteria._matched[ configString ] = criteria.matchingFunction( segmentConfig, ras, criteria );
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
export function getCriteria( id ) {
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
export function setMatchingAttribute( id, matchingAttribute ) {
	let criteria = getCriteria( id );
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
export function setMatchingFunction( id, matchingFunction ) {
	let criteria = getCriteria( id );
	if ( ! criteria ) {
		pendingConfig[ id ] = pendingConfig[ id ] || {};
		criteria = pendingConfig[ id ];
	}
	criteria._matched = {}; // Clear matched cache.
	criteria.matchingFunction = matchingFunction;
}
