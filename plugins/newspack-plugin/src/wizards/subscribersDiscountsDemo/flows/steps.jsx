/**
 * Shared two-step scaffolding for the add / reactivate flows: the method ->
 * details view state and the footer button rows. Keeping these here means the
 * step framework stays identical across flows.
 */

import { useState } from '@wordpress/element';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button } from '../../../../packages/components/src';

// A two-view (method -> details) state machine shared by the stepped flows.
export function useTwoStep() {
	const [ view, setView ] = useState( 'method' );
	return {
		view,
		isMethod: view === 'method',
		toDetails: () => setView( 'details' ),
		toMethod: () => setView( 'method' ),
	};
}

// A step's footer: a tertiary action on the left (Cancel / Back) and a primary
// action on the right (Continue / Confirm).
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
