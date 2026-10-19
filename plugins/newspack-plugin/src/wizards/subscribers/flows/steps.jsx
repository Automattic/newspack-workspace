/**
 * Two-step scaffolding for the wizard's stepped flows: the method → details
 * view state and the footer button rows.
 */

/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button } from '../../../../packages/components/src';

/**
 * A two-view (method → details) state machine shared by the stepped flows.
 *
 * @return {{ view: string, isMethod: boolean, toDetails: Function, toMethod: Function }} The step state.
 */
export function useTwoStep() {
	const [ view, setView ] = useState( 'method' );
	return {
		view,
		isMethod: view === 'method',
		toDetails: () => setView( 'details' ),
		toMethod: () => setView( 'method' ),
	};
}

/**
 * A step's footer: a tertiary action on the left (Cancel / Back) and a primary
 * action on the right (Continue / Confirm).
 *
 * @param {Object}   props            Component props.
 * @param {string}   props.leftLabel  Label for the tertiary action.
 * @param {Function} props.onLeft     Tertiary action handler.
 * @param {string}   props.rightLabel Label for the primary action.
 * @param {Function} props.onRight    Primary action handler.
 * @param {boolean}  [props.busy]     Render the primary action busy.
 * @param {boolean}  [props.disabled] Disable both actions.
 */
export function StepButtons( { leftLabel, onLeft, rightLabel, onRight, busy = false, disabled = false } ) {
	return (
		<HStack spacing={ 2 } justify="flex-end">
			<Button variant="tertiary" size="compact" disabled={ disabled } onClick={ onLeft }>
				{ leftLabel }
			</Button>
			<Button variant="primary" size="compact" isBusy={ busy } disabled={ disabled } onClick={ onRight }>
				{ rightLabel }
			</Button>
		</HStack>
	);
}
