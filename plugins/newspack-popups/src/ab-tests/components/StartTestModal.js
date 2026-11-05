/**
 * The Start A/B Test modal: names the test, picks its goal and split, and
 * creates version B as a draft copy of the prompt. Shared so the Campaigns
 * screens can open the same modal.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Modal, Notice, SelectControl, TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import SplitControls from './SplitControls';
import { splitSummary, suggestGoal } from '../../editor/ab-utils';
import './style.scss';

/**
 * @param {Object}   props
 * @param {number}   props.promptId     Prompt that becomes version A.
 * @param {string}   props.promptTitle  The suggested test name: the prompt's title, or the test's name when resuming.
 * @param {string[]} props.blockNames   Names of the blocks in it, for the goal suggestion.
 * @param {string}   props.content      Its content, for the goal suggestion.
 * @param {Object}   props.goals        Goal key => label.
 * @param {string}   props.initialGoal  Optional; a goal to start from instead of the suggestion.
 * @param {number}   props.initialShare Optional; the share of readers for A to start from.
 * @param {string}   props.intro        Line above the fields.
 * @param {Function} props.beforeCreate Optional; awaited before the request, e.g. to save the prompt.
 * @param {Function} props.onCreated    Called with the route's result.
 * @param {Function} props.onClose      Called on Cancel or close.
 */
const StartTestModal = ( {
	promptId,
	promptTitle = '',
	blockNames = [],
	content = '',
	goals = {},
	initialGoal = '',
	initialShare = 50,
	intro,
	beforeCreate,
	onCreated,
	onClose,
} ) => {
	const suggestion = suggestGoal( blockNames, content );
	const [ name, setName ] = useState( promptTitle );
	const [ goal, setGoal ] = useState( initialGoal || suggestion?.goal || '' );
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
			if ( beforeCreate ) {
				await beforeCreate();
			}
			const result = await apiFetch( {
				path: '/newspack-popups/v1/ab-tests',
				method: 'POST',
				data: { prompt_id: promptId, name: name.trim(), goal, control_share: controlShare },
			} );
			onCreated( result );
		} catch ( e ) {
			setError( e?.message || __( 'Could not create version B. Try again.', 'newspack-popups' ) );
			setIsBusy( false );
		}
	};

	const goalOptions = [
		{ value: '', label: __( 'Select a goal', 'newspack-popups' ), disabled: true },
		...Object.entries( goals ).map( ( [ value, label ] ) => ( { value, label } ) ),
	];

	return (
		<Modal title={ __( 'Start A/B Test', 'newspack-popups' ) } size="medium" focusOnMount="firstContentElement" onRequestClose={ onClose }>
			<Stack direction="column" gap="xl">
				<p className="newspack-popups-ab-test__intro">{ intro }</p>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TextControl
					label={ __( 'Test name', 'newspack-popups' ) }
					help={ __( 'Shown on the A/B Tests tab. Version B joins this test automatically.', 'newspack-popups' ) }
					value={ name }
					onChange={ setName }
					disabled={ isBusy }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Goal', 'newspack-popups' ) }
					help={
						suggestion && suggestion.goal === goal
							? suggestion.help
							: __( 'What a reader does that counts as a success.', 'newspack-popups' )
					}
					value={ goal }
					options={ goalOptions }
					onChange={ setGoal }
					disabled={ isBusy }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SplitControls controlShare={ controlShare } onChange={ setControlShare } disabled={ isBusy } help={ splitSummary( controlShare ) } />
				<Stack justify="flex-end" align="center" gap="lg">
					<Button variant="secondary" onClick={ onClose } disabled={ isBusy } __next40pxDefaultSize>
						{ __( 'Cancel', 'newspack-popups' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ create }
						disabled={ ! canCreate }
						isBusy={ isBusy }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ __( 'Create Version B', 'newspack-popups' ) }
					</Button>
				</Stack>
			</Stack>
		</Modal>
	);
};

export default StartTestModal;
