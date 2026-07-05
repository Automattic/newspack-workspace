/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

import isArray from 'lodash/isArray';
import mergeWith from 'lodash/mergeWith';

/**
 * A recursive partial of the state object: nested objects may be partial,
 * but arrays are always provided (and replaced) wholesale.
 */
export type ObjectStateUpdate< T > = T extends readonly unknown[]
	? T
	: T extends object
	? { [ K in keyof T ]?: ObjectStateUpdate< T[ K ] > }
	: T;

export type ObjectStateSetter< T > = {
	< K extends keyof T >( key: K ): ( value: T[ K ] ) => void;
	( update: ObjectStateUpdate< T > ): void;
};

const mergeCustomizer = ( objValue: unknown, srcValue: unknown ) => {
	if ( isArray( objValue ) ) {
		// If it's an array, replace it (instead of concatenating).
		return srcValue;
	}
};

/**
 * A useState for an object.
 * Nested objects will be nested, but arrays replaced.
 *
 * @param initial Initial state object.
 * @return The state object and a function to update it.
 */
export default < T extends object >( initial: T = {} as T ): [ T, ObjectStateSetter< T > ] => {
	const [ stateObject, setStateObject ] = useState< T >( initial );

	const runUpdate = ( update: ObjectStateUpdate< T > | Record< string, unknown > ) =>
		setStateObject( _stateObject => mergeWith( {}, _stateObject, update, mergeCustomizer ) );

	function updateStateObject< K extends keyof T >( key: K ): ( value: T[ K ] ) => void;
	function updateStateObject( update: ObjectStateUpdate< T > ): void;
	function updateStateObject( keyOrUpdate: keyof T | ObjectStateUpdate< T > ) {
		if ( typeof keyOrUpdate === 'string' ) {
			return ( value: unknown ) => runUpdate( { [ keyOrUpdate ]: value } );
		}
		runUpdate( keyOrUpdate as ObjectStateUpdate< T > );
	}
	return [ stateObject, updateStateObject ];
};
