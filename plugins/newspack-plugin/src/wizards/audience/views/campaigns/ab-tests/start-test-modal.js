/**
 * The Start A/B Test modal, as the tab opens it: for an Incomplete test, with
 * version A's goal and split filled in.
 *
 * A copy of newspack-popups' src/ab-tests/components/StartTestModal.js, which
 * the prompt editor uses; newspack-plugin does not build code from another
 * plugin's source tree. Keep the request and the field rules in step with that
 * file. The two differ on purpose where the editor suggests a goal from the
 * prompt's blocks, which this tab cannot read.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Modal, Notice, RangeControl, SelectControl, TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { REST_BASE, splitSummary } from './utils';

const MIN_SHARE = 20;
const MAX_SHARE = 80;

/**
 * @param {Object}   props
 * @param {number}   props.promptId     Prompt that is version A.
 * @param {string}   props.testName     The test's name.
 * @param {Object}   props.goals        Goal key => label.
 * @param {string}   props.initialGoal  A's goal.
 * @param {number}   props.initialShare A's share of readers.
 * @param {string}   props.intro        Line above the fields.
 * @param {Function} props.onCreated    Called with the route's result.
 * @param {Function} props.onClose      Called on Cancel or close.
 */
const StartTestModal = ( { promptId, testName = '', goals = {}, initialGoal = '', initialShare = 50, intro, onCreated, onClose } ) => {
	const [ name, setName ] = useState( testName );
	const [ goal, setGoal ] = useState( initialGoal );
	const [ controlShare, setControlShare ] = useState( initialShare );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const canCreate = '' !== name.trim() && '' !== goal && ! isBusy;

	const create = async () => {
		if ( ! canCreate ) {
			return;
		}
		setIsBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: REST_BASE,
				method: 'POST',
				data: { prompt_id: promptId, name: name.trim(), goal, control_share: controlShare },
			} );
			onCreated( result );
		} catch ( e ) {
			setError( e?.message || __( 'Could not create version B. Try again.', 'newspack-plugin' ) );
			setIsBusy( false );
		}
	};

	const goalOptions = [
		{ value: '', label: __( 'Select a goal', 'newspack-plugin' ), disabled: true },
		...Object.entries( goals ).map( ( [ value, label ] ) => ( { value, label } ) ),
	];

	return (
		<Modal title={ __( 'Start A/B Test', 'newspack-plugin' ) } size="medium" focusOnMount="firstContentElement" onRequestClose={ onClose }>
			<Stack direction="column" gap="xl">
				<p className="newspack-ab-tests__intro">{ intro }</p>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TextControl
					label={ __( 'Test name', 'newspack-plugin' ) }
					help={ __( 'Shown on the A/B Tests tab. Version B joins this test automatically.', 'newspack-plugin' ) }
					value={ name }
					onChange={ setName }
					disabled={ isBusy }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Goal', 'newspack-plugin' ) }
					help={ __( 'What a reader does that counts as a success.', 'newspack-plugin' ) }
					value={ goal }
					options={ goalOptions }
					onChange={ setGoal }
					disabled={ isBusy }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<RangeControl
					label={ __( 'Readers who see A', 'newspack-plugin' ) }
					value={ controlShare }
					min={ MIN_SHARE }
					max={ MAX_SHARE }
					step={ 1 }
					onChange={ value => setControlShare( value ?? controlShare ) }
					disabled={ isBusy }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<RangeControl
					label={ __( 'Readers who see B', 'newspack-plugin' ) }
					value={ 100 - controlShare }
					min={ MIN_SHARE }
					max={ MAX_SHARE }
					step={ 1 }
					onChange={ value => setControlShare( 100 - ( value ?? 100 - controlShare ) ) }
					disabled={ isBusy }
					help={ splitSummary( controlShare ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<Stack justify="flex-end" align="center" gap="lg">
					<Button variant="secondary" onClick={ onClose } disabled={ isBusy } __next40pxDefaultSize>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ create }
						disabled={ ! canCreate }
						isBusy={ isBusy }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ __( 'Create Version B', 'newspack-plugin' ) }
					</Button>
				</Stack>
			</Stack>
		</Modal>
	);
};

export default StartTestModal;
