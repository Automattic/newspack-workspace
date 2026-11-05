/**
 * The A/B Test document panel. A prompt not in a test can start one, which
 * creates its version B; a prompt in a test shows its version, the test's goal
 * and split (editable on A until the test runs), and the other versions.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { BaseControl, Button, SelectControl, Notice, ExternalLink } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { select, subscribe, useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef, useState, useCallback } from '@wordpress/element';
import { useDebounce } from '@wordpress/compose';
import { addQueryArgs } from '@wordpress/url';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { isEligiblePlacement, splitSummary, promptLacksGoalBlock, missingGoalBlockMessage, testPromptWarnings } from './ab-utils';
import SplitControls from '../ab-tests/components/SplitControls';
import StartTestModal from '../ab-tests/components/StartTestModal';
import '../ab-tests/components/style.scss';

const META_GOAL = 'newspack_popups_ab_test_goal';
const META_CONTROL_SHARE = 'newspack_popups_ab_control_share';

/**
 * The query arg B's editor is opened with after Create Version B, so the panel
 * toggle takes focus there.
 */
export const CREATED_QUERY_ARG = 'newspack-ab-test';

const ABTestPanel = ( {
	newspack_popups_ab_test_id: testId = '',
	newspack_popups_ab_variant: variant = '',
	newspack_popups_ab_test_goal: goal = '',
	newspack_popups_ab_control_share: controlShare = 50,
	placement,
	postId,
	onMetaFieldChange,
} ) => {
	const {
		can_manage_tests: canManage = false,
		goals = {},
		ineligible_placements: ineligible = [],
		results_url: resultsUrl = '',
		test_name: testName = '',
	} = window?.newspack_popups_data?.ab_testing || {};
	const eligible = isEligiblePlacement( placement, ineligible );

	const blockNames = useSelect( registrySelect => {
		const { getClientIdsWithDescendants, getBlockName } = registrySelect( 'core/block-editor' );
		return getClientIdsWithDescendants().map( getBlockName );
	}, [] );
	const status = useSelect( registrySelect => registrySelect( 'core/editor' ).getCurrentPostAttribute( 'status' ), [] );
	const { savePost } = useDispatch( 'core/editor' );
	const [ isModalOpen, setIsModalOpen ] = useState( false );

	const [ prompts, setPrompts ] = useState( [] );
	const [ lookedUpFor, setLookedUpFor ] = useState( null );
	const latestTestId = useRef( testId );
	latestTestId.current = testId;
	const lookUpPrompts = useDebounce(
		useCallback( id => {
			if ( ! id ) {
				setPrompts( [] );
				setLookedUpFor( '' );
				return;
			}
			apiFetch( { path: `/newspack-popups/v1/ab-tests/${ id }` } )
				.then( result => {
					// A reply to an earlier Test ID must not describe the current one.
					if ( id === latestTestId.current ) {
						setPrompts( Array.isArray( result ) ? result : [] );
						setLookedUpFor( id );
					}
				} )
				.catch( () => {
					if ( id === latestTestId.current ) {
						setPrompts( [] );
						setLookedUpFor( id );
					}
				} );
		}, [] ),
		500
	);
	useEffect( () => {
		setPrompts( [] );
		lookUpPrompts( testId );
	}, [ testId ] );

	// The route copies the prompt as stored, so unsaved edits are saved first.
	// A save already running makes savePost() return without saving or failing,
	// so wait it out. On a published prompt a save is an update readers see, so
	// that is left to the publisher rather than done as a side effect.
	const saveFirst = async () => {
		await new Promise( resolve => {
			if ( ! select( 'core/editor' ).isSavingPost() ) {
				resolve();
				return;
			}
			const unsubscribe = subscribe( () => {
				if ( ! select( 'core/editor' ).isSavingPost() ) {
					unsubscribe();
					resolve();
				}
			} );
		} );
		const editor = select( 'core/editor' );
		const currentStatus = editor.getCurrentPostAttribute( 'status' );
		if ( 'publish' === currentStatus && editor.isEditedPostDirty() ) {
			throw new Error( __( 'Save your changes to this prompt first, so version B starts from what readers see.', 'newspack-popups' ) );
		}
		if ( editor.isEditedPostDirty() || 'auto-draft' === currentStatus ) {
			await savePost();
			if ( select( 'core/editor' ).didPostSaveRequestFail() ) {
				throw new Error( __( 'Save this prompt, then try again.', 'newspack-popups' ) );
			}
		}
	};
	const onCreated = result => {
		const editLink = result?.version_b?.edit_link;
		if ( editLink ) {
			window.location.assign( addQueryArgs( editLink, { [ CREATED_QUERY_ARG ]: 'created' } ) );
		} else {
			window.location.reload();
		}
	};
	const modal = isModalOpen && (
		<StartTestModal
			promptId={ postId }
			promptTitle={ testId ? testName || testId : select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '' }
			blockNames={ blockNames }
			content={ select( 'core/editor' ).getEditedPostContent() }
			goals={ goals }
			initialGoal={ testId ? goal : '' }
			initialShare={ testId ? controlShare : 50 }
			intro={ __(
				'Version B starts as a draft copy of this prompt with the same placement. Change what you want to compare, then publish it to start the test.',
				'newspack-popups'
			) }
			beforeCreate={ saveFirst }
			onCreated={ onCreated }
			onClose={ () => setIsModalOpen( false ) }
		/>
	);

	if ( ! testId ) {
		// The notices describe standing state rather than the result of an
		// action, so none is announced on mount (the panel re-mounts them on
		// every expand).
		return (
			<Stack direction="column" gap="lg">
				<p className="newspack-popups-ab-test__intro">
					{ __( 'Compare this prompt with a second version to see which gets more readers to act.', 'newspack-popups' ) }
				</p>
				{ ! canManage && (
					<p className="newspack-popups-ab-test__note">
						{ __( "You don't have permission to start a test on this prompt.", 'newspack-popups' ) }
					</p>
				) }
				{ ! eligible && (
					<Notice status="warning" spokenMessage="" isDismissible={ false }>
						{ __( 'Prompts on manual or custom placements can’t be A/B tested.', 'newspack-popups' ) }
					</Notice>
				) }
				{ canManage && (
					<div>
						<Button
							variant="secondary"
							onClick={ () => setIsModalOpen( true ) }
							disabled={ ! eligible }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Start A/B Test', 'newspack-popups' ) }
						</Button>
					</div>
				) }
				{ modal }
			</Stack>
		);
	}

	const isControl = 'a' === variant;
	const letter = variant ? String( variant ).toUpperCase() : '';
	const others = prompts.filter( prompt => prompt.id !== postId );
	const control = ! isControl ? others.find( prompt => 'a' === prompt.variant ) : null;
	const effectiveGoal = control ? control.goal || '' : goal;
	const warnings = testPromptWarnings( { postId, variant, prompts } );
	const lookupPending = lookedUpFor !== testId;
	const hasChallenger = others.some( prompt => prompt.variant && 'a' !== prompt.variant );
	// Unpublished versions keep the test from running. On B, A's status comes
	// from the lookup, so the notice naming them waits for it.
	const toPublish = [
		control && 'publish' !== control.status ? 'A' : null,
		'publish' !== status ? letter || __( 'this prompt', 'newspack-popups' ) : null,
	].filter( Boolean );
	// Running is the dashboard's rule: every version published, with at least a
	// B. Until then the goal and split can still change without mixing results.
	const isRunning = 'publish' === status && ( isControl ? hasChallenger : !! control ) && others.every( prompt => 'publish' === prompt.status );
	const goalLabel = goals[ effectiveGoal ] || __( 'none', 'newspack-popups' );
	const goalOptions = [
		{ value: '', label: __( 'Select a goal', 'newspack-popups' ), disabled: true },
		...Object.entries( goals ).map( ( [ value, label ] ) => ( { value, label } ) ),
	];

	return (
		<Stack direction="column" gap="lg">
			<p className="newspack-popups-ab-test__intro">
				{ letter
					? sprintf(
							// Translators: %1$s is the version letter, %2$s the test's name.
							__( 'Version %1$s in the %2$s test.', 'newspack-popups' ),
							letter,
							testName || testId
					  )
					: sprintf(
							// Translators: %s is the test's name.
							__( 'This prompt is in the %s test.', 'newspack-popups' ),
							testName || testId
					  ) }
			</p>
			{ ! canManage && (
				<p className="newspack-popups-ab-test__note">
					{ __( "You can see this test's settings but can't change them.", 'newspack-popups' ) }
				</p>
			) }
			{ ! eligible && (
				<Notice status="warning" spokenMessage="" isDismissible={ false }>
					{ sprintf(
						// Translators: %s is a version letter such as "A", or the phrase "this prompt".
						__(
							'Prompts on manual or custom placements can’t be A/B tested. Choose another placement to count %s’s results.',
							'newspack-popups'
						),
						letter || __( 'this prompt', 'newspack-popups' )
					) }
				</Notice>
			) }
			{ warnings.map( warning => (
				<Notice key={ warning } status="error" spokenMessage="" isDismissible={ false }>
					{ warning }
				</Notice>
			) ) }
			{ isControl && ! isRunning && (
				<>
					<SelectControl
						label={ __( 'Goal', 'newspack-popups' ) }
						help={ __( 'What a reader does that counts as a success.', 'newspack-popups' ) }
						value={ goal }
						options={ goalOptions }
						onChange={ value => onMetaFieldChange( { [ META_GOAL ]: value } ) }
						disabled={ ! canManage }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SplitControls
						controlShare={ controlShare }
						onChange={ value => onMetaFieldChange( { [ META_CONTROL_SHARE ]: value } ) }
						disabled={ ! canManage }
						help={ splitSummary( controlShare ) }
					/>
				</>
			) }
			{ /* A start refuses a test with any other version in it, a second A or one with no version included. */ }
			{ isControl && canManage && eligible && ! lookupPending && 0 === others.length && (
				<div>
					<Button variant="secondary" onClick={ () => setIsModalOpen( true ) } __next40pxDefaultSize>
						{ __( 'Create Version B', 'newspack-popups' ) }
					</Button>
				</div>
			) }
			{ modal }
			{ isControl && isRunning && (
				<>
					<p className="newspack-popups-ab-test__intro">
						{ sprintf(
							// Translators: %1$s is the test's goal, %2$s the traffic split, such as "60% see A, 40% see B".
							__( 'Goal: %1$s. %2$s.', 'newspack-popups' ),
							goalLabel,
							splitSummary( controlShare )
						) }
					</p>
					<p className="newspack-popups-ab-test__note">{ __( 'End the test to change the goal or split.', 'newspack-popups' ) }</p>
				</>
			) }
			{ ! isControl &&
				! lookupPending &&
				( control ? (
					<p className="newspack-popups-ab-test__intro">
						{ sprintf(
							// Translators: %1$s is the test's goal, %2$s the traffic split, such as "60% see A, 40% see B".
							__( 'Goal: %1$s. %2$s. Set on version A.', 'newspack-popups' ),
							goalLabel,
							splitSummary( control.control_share ?? 50 )
						) }
					</p>
				) : (
					// A test made before version B was created from A can lack an A;
					// keep the goal editable here rather than show one that doesn't exist.
					<SelectControl
						label={ __( 'Goal', 'newspack-popups' ) }
						help={ __( 'What a reader does that counts as a success.', 'newspack-popups' ) }
						value={ goal }
						options={ goalOptions }
						onChange={ value => onMetaFieldChange( { [ META_GOAL ]: value } ) }
						disabled={ ! canManage }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) ) }
			{ ! isControl && control && 'publish' !== status && control.edit_link && (
				<div>
					<Button variant="link" href={ control.edit_link }>
						{ __( 'Change these on version A', 'newspack-popups' ) }
					</Button>
				</div>
			) }
			{ ( isControl || ! lookupPending ) && promptLacksGoalBlock( effectiveGoal, blockNames ) && (
				<Notice status="warning" spokenMessage="" isDismissible={ false }>
					{ missingGoalBlockMessage( effectiveGoal, !! control ) }
				</Notice>
			) }
			{ ! isControl && ! lookupPending && toPublish.length > 0 && (
				<Notice status="info" spokenMessage="" isDismissible={ false }>
					{ 2 === toPublish.length
						? sprintf(
								// Translators: %1$s is the version letter "A"; %2$s is a version letter such as "B", or the phrase "this prompt".
								__( 'Publish %1$s and %2$s to start the test.', 'newspack-popups' ),
								...toPublish
						  )
						: sprintf(
								// Translators: %s is a version letter such as "A", or the phrase "this prompt".
								__( 'Publish %s to start the test.', 'newspack-popups' ),
								toPublish[ 0 ]
						  ) }
				</Notice>
			) }
			{ others.length > 0 && (
				<div className="newspack-popups-ab-test__prompts">
					<BaseControl.VisualLabel id="newspack-popups-ab-test-others">
						{ __( 'Other versions in this test', 'newspack-popups' ) }
					</BaseControl.VisualLabel>
					<ul aria-labelledby="newspack-popups-ab-test-others">
						{ others.map( prompt => {
							const meta = sprintf(
								// Translators: %1$s is the prompt's version, such as "Version B", %2$s its post status label.
								__( '%1$s · %2$s', 'newspack-popups' ),
								prompt.variant
									? sprintf(
											// Translators: %s is the version letter.
											__( 'Version %s', 'newspack-popups' ),
											String( prompt.variant ).toUpperCase()
									  )
									: __( 'No version', 'newspack-popups' ),
								prompt.status_label || prompt.status
							);
							return (
								<li key={ prompt.id }>
									{ prompt.edit_link ? <ExternalLink href={ prompt.edit_link }>{ prompt.title }</ExternalLink> : prompt.title }
									<span className="newspack-popups-ab-test__prompt-meta">{ meta }</span>
								</li>
							);
						} ) }
					</ul>
				</div>
			) }
			{ resultsUrl && ( isControl || 'publish' === status ) && (
				<ExternalLink href={ resultsUrl }>{ __( 'View results', 'newspack-popups' ) }</ExternalLink>
			) }
		</Stack>
	);
};

export default ABTestPanel;
