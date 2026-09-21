/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { API_BASE } from './constants';

const DEFAULT_COMPLETE_NOTICE = { message: __( 'Action completed.', 'newspack-plugin' ), type: 'success' };

/**
 * Run a pending scheduled action now and report how it went.
 *
 * Both Logs tabs offer this: the scheduled actions list for any pending
 * action, and the sync activity for a push that is waiting on its retry.
 *
 * @param {string}   integrationId            The integration the action belongs to.
 * @param {Object}   options                  Options.
 * @param {Function} [options.onSettled]      Called once the request ends, to refresh the list.
 * @param {Object}   [options.completeNotice] The `{ message, type }` shown when the action ran to the end.
 * @return {{ runAction: Function, runningActionIds: Set }} The runner and the actions it is running.
 */
export function useRunAction( integrationId, { onSettled, completeNotice = DEFAULT_COMPLETE_NOTICE } = {} ) {
	const { addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ runningActionIds, setRunningActionIds ] = useState( () => new Set() );

	// The run outlives the render that started it. Held in a ref, the refresh
	// belongs to the view the reader is on when the request ends, so a view
	// changed mid-request is not filled with the previous one's rows.
	const onSettledRef = useRef( onSettled );
	useEffect( () => {
		onSettledRef.current = onSettled;
	} );

	const runAction = useCallback(
		actionId => {
			setRunningActionIds( prev => new Set( prev ).add( actionId ) );
			// addNotice appends without deduping by id, so the final notice
			// removes this one first to replace it in place.
			const noticeId = `integration-action-run-${ actionId }`;
			addNotice( {
				message: __( 'Running action…', 'newspack-plugin' ),
				type: 'info',
				id: noticeId,
			} );
			return apiFetch( {
				path: `${ API_BASE }/${ integrationId }/logs/${ actionId }/run`,
				method: 'POST',
			} )
				.then( response => {
					let notice;
					if ( response.status === 'complete' ) {
						notice = completeNotice;
					} else if ( response.status === 'failed' ) {
						notice = { message: response.message || __( 'Action failed.', 'newspack-plugin' ), type: 'error' };
					} else if ( response.status === 'pending' ) {
						// Still pending after the attempt means nothing ran, and the
						// message asks to try again: that is not a result to confirm.
						notice = { message: response.message || __( 'Could not run action.', 'newspack-plugin' ), type: 'error' };
					} else {
						notice = { message: response.message || __( 'Action processed.', 'newspack-plugin' ), type: 'success' };
					}
					removeNotice( noticeId );
					addNotice( { ...notice, id: noticeId } );
				} )
				.catch( err => {
					removeNotice( noticeId );
					addNotice( {
						message: err && err.message ? err.message : __( 'Could not run action.', 'newspack-plugin' ),
						type: 'error',
						id: noticeId,
					} );
				} )
				.finally( () => {
					setRunningActionIds( prev => {
						const next = new Set( prev );
						next.delete( actionId );
						return next;
					} );
					if ( onSettledRef.current ) {
						onSettledRef.current();
					}
				} );
		},
		[ integrationId, addNotice, removeNotice, completeNotice ]
	);

	return { runAction, runningActionIds };
}
