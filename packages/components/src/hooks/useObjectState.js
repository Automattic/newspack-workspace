/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

import isArray from 'lodash/isArray';
import mergeWith from 'lodash/mergeWith';

/**
 * @typedef {Object} StateObjectUpdate
 * @property {Object.<string, any>} [update] The update object with key-value pairs to merge into the state.
 */

const mergeCustomizer = ( objValue, srcValue ) => {
	if ( isArray( objValue ) ) {
		// If it's an array, replace it (instead of concatenating).
		return srcValue;
	}
};

/**
 * A useState for an object.
 * Nested objects will be nested, but arrays replaced.
 *
 * @template T
 * @param {T} initial Initial state object.
 * @return {[T, (keyOrUpdate: string | Partial<T>) => (value?: any) => void]} The state object and a function to update it.
 */
export default ( initial = {} ) => {
	const [ stateObject, setStateObject ] = useState( initial );

	const runUpdate = update => setStateObject( _stateObject => mergeWith( {}, _stateObject, update, mergeCustomizer ) );

	const updateStateObject = keyOrUpdate => {
		if ( typeof keyOrUpdate === 'string' ) {
			return value => runUpdate( { [ keyOrUpdate ]: value } );
		}
		runUpdate( keyOrUpdate );
	};
	return [ stateObject, updateStateObject ];
};
