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
import type { IntegrationRunActionResponse, RunActionNotice } from './constants';

const DEFAULT_COMPLETE_NOTICE: RunActionNotice = { message: __( 'Action completed.', 'newspack-plugin' ), type: 'success' };

type UseRunActionOptions = {
	onSettled?: () => void;
	completeNotice?: RunActionNotice;
};

/**
 * Run a pending scheduled action now and report how it went.
 *
 * Both Logs tabs offer this: the scheduled actions list for any pending
 * action, and the sync activity for a push that is waiting on its retry.
 *
 * @param integrationId            The integration the action belongs to.
 * @param options                  Options.
 * @param [options.onSettled]      Called once the request ends, to refresh the list.
 * @param [options.completeNotice] The `{ message, type }` shown when the action ran to the end.
 * @return The runner and the actions it is running.
 */
export function useRunAction( integrationId: string, { onSettled, completeNotice = DEFAULT_COMPLETE_NOTICE }: UseRunActionOptions = {} ) {
	const { addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ runningActionIds, setRunningActionIds ] = useState< Set< string | number > >( () => new Set() );

	// The run outlives the render that started it. Held in a ref, the refresh
	// belongs to the view the reader is on when the request ends, so a view
	// changed mid-request is not filled with the previous one's rows.
	const onSettledRef = useRef( onSettled );
	useEffect( () => {
		onSettledRef.current = onSettled;
	} );

	const runAction = useCallback(
		( actionId: string | number ) => {
			setRunningActionIds( prev => new Set( prev ).add( actionId ) );
			// addNotice appends without deduping by id, so the final notice
			// removes this one first to replace it in place.
			const noticeId = `integration-action-run-${ actionId }`;
			addNotice( {
				message: __( 'Running action…', 'newspack-plugin' ),
				type: 'info',
				id: noticeId,
			} );
			return apiFetch< IntegrationRunActionResponse >( {
				path: `${ API_BASE }/${ integrationId }/logs/${ actionId }/run`,
				method: 'POST',
			} )
				.then( response => {
					let notice: RunActionNotice;
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
