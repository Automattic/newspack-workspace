/**
 * The Start A/B Test modal, as the wizard opens it: for an Incomplete test, with
 * version A's goal and split filled in; from a prompt's menu in Campaigns; and
 * from the A/B Tests tab, where it adds a "Prompt to test" picker.
 *
 * A copy of newspack-popups' src/ab-tests/components/StartTestModal.js, which
 * the prompt editor uses; newspack-plugin does not build code from another
 * plugin's source tree. Keep the request and the field rules in step with that
 * file. The two differ on purpose where the editor suggests a goal from the
 * prompt's blocks: here the suggestion comes from the server instead.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Notice, RangeControl, SelectControl, TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import Modal from '../../../../../../packages/components/src/modal';
import { GOAL_SUGGESTION_HELP, REST_BASE, splitSummary } from './utils';

const MIN_SHARE = 20;
const MAX_SHARE = 80;

/**
 * @param {Object}   props
 * @param {number}   props.promptId      Prompt that is version A; leave out with `prompts`.
 * @param {string}   props.testName      The test's name, or the name to suggest.
 * @param {Object}   props.goals         Goal key => label.
 * @param {string}   props.initialGoal   A's goal.
 * @param {string}   props.suggestedGoal The goal the prompt's blocks suggest.
 * @param {number}   props.initialShare  A's share of readers.
 * @param {string}   props.intro         Line above the fields.
 * @param {Object[]} props.prompts       Prompts to pick version A from, from the
 *                                       list route; adds the "Prompt to test" field.
 * @param {boolean}  props.hasIneligible Whether the picker left out prompts for their placement.
 * @param {Function} props.onCreated     Called with the route's result.
 * @param {Function} props.onClose       Called on Cancel or close.
 */
const StartTestModal = ( {
	promptId,
	testName = '',
	goals = {},
	initialGoal = '',
	suggestedGoal = '',
	initialShare = 50,
	intro,
	prompts,
	hasIneligible = false,
	onCreated,
	onClose,
} ) => {
	const isPicker = Array.isArray( prompts );
	const [ pickedId, setPickedId ] = useState( '' );
	const [ suggestion, setSuggestion ] = useState( suggestedGoal );
	const [ name, setName ] = useState( testName );
	const [ goal, setGoal ] = useState( initialGoal || suggestedGoal );
	// The name and goal follow the picked prompt until the user sets them.
	const [ isNameEdited, setIsNameEdited ] = useState( false );
	const [ isGoalEdited, setIsGoalEdited ] = useState( false );
	const [ controlShare, setControlShare ] = useState( initialShare );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Nothing to pick: the dialog only says why, since no field could be sent.
	const isEmptyPicker = isPicker && ! prompts.length;
	const emptyId = useInstanceId( StartTestModal, 'newspack-ab-tests-picker-empty' );
	const versionA = isPicker ? Number( pickedId ) : promptId;
	const canCreate = !! versionA && '' !== name.trim() && '' !== goal && ! isBusy;

	const pick = value => {
		setPickedId( value );
		const picked = prompts.find( prompt => String( prompt.id ) === value );
		const pickedSuggestion = picked?.suggested_goal || '';
		setSuggestion( pickedSuggestion );
		if ( ! isNameEdited ) {
			setName( picked ? decodeEntities( picked.title ) : '' );
		}
		if ( ! isGoalEdited ) {
			setGoal( pickedSuggestion );
		}
	};

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
				data: { prompt_id: versionA, name: name.trim(), goal, control_share: controlShare },
			} );
			onCreated( result );
		} catch ( e ) {
			setError( e?.message || __( 'Could not create version B. Try again.', 'newspack-plugin' ) );
			setIsBusy( false );
		}
	};

	const pickerHelp = hasIneligible
		? __( 'Published prompts that aren’t already in a test. Prompts on manual or custom placements can’t be A/B tested.', 'newspack-plugin' )
		: __( 'Published prompts that aren’t already in a test.', 'newspack-plugin' );

	const goalOptions = [
		{ value: '', label: __( 'Select a goal', 'newspack-plugin' ), disabled: true },
		...Object.entries( goals ).map( ( [ value, label ] ) => ( { value, label } ) ),
	];

	return (
		<Modal
			title={ __( 'Start A/B Test', 'newspack-plugin' ) }
			size="medium"
			focusOnMount="firstContentElement"
			aria={ { describedby: isEmptyPicker ? emptyId : undefined } }
			onRequestClose={ onClose }
		>
			<Stack direction="column" gap="xl">
				<p className="newspack-ab-tests__intro">{ intro }</p>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ isEmptyPicker && (
					<p id={ emptyId } className="newspack-ab-tests__picker-empty">
						{ hasIneligible
							? __(
									'No published prompts are free to test yet. Prompts on manual or custom placements can’t be A/B tested.',
									'newspack-plugin'
							  )
							: __( 'No published prompts are free to test yet.', 'newspack-plugin' ) }
					</p>
				) }
				{ isPicker && ! isEmptyPicker && (
					<SelectControl
						label={ __( 'Prompt to test', 'newspack-plugin' ) }
						help={ pickerHelp }
						value={ pickedId }
						options={ [
							{ value: '', label: __( 'Select a prompt', 'newspack-plugin' ), disabled: true },
							...prompts.map( prompt => ( {
								value: String( prompt.id ),
								label: decodeEntities( prompt.title ) || __( '(no title)', 'newspack-plugin' ),
							} ) ),
						] }
						onChange={ pick }
						disabled={ isBusy }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
				{ ! isEmptyPicker && (
					<>
						<TextControl
							label={ __( 'Test name', 'newspack-plugin' ) }
							help={ __( 'Shown on the A/B Tests tab. Version B joins this test automatically.', 'newspack-plugin' ) }
							value={ name }
							onChange={ value => {
								setIsNameEdited( true );
								setName( value );
							} }
							disabled={ isBusy }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<SelectControl
							label={ __( 'Goal', 'newspack-plugin' ) }
							help={
								suggestion && suggestion === goal && GOAL_SUGGESTION_HELP[ goal ]
									? GOAL_SUGGESTION_HELP[ goal ]
									: __( 'What a reader does that counts as a success.', 'newspack-plugin' )
							}
							value={ goal }
							options={ goalOptions }
							onChange={ value => {
								setIsGoalEdited( true );
								setGoal( value );
							} }
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
					</>
				) }
				<Stack justify="flex-end" align="center" gap="lg">
					<Button variant="secondary" onClick={ onClose } disabled={ isBusy } __next40pxDefaultSize>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					{ ! isEmptyPicker && (
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
					) }
				</Stack>
			</Stack>
		</Modal>
	);
};

export default StartTestModal;
