/**
 * The A/B Test document panel: the four test fields, the rules that gate them,
 * and the other prompts sharing the Test ID.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { BaseControl, TextControl, SelectControl, RangeControl, Notice, ExternalLink } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { useSelect } from '@wordpress/data';
import { useEffect, useRef, useState, useCallback } from '@wordpress/element';
import { useDebounce } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { slugifyTestId, isEligiblePlacement, splitSummary, promptLacksGoalBlock, missingGoalBlockMessage, testPromptWarnings } from './ab-utils';

const META_TEST_ID = 'newspack_popups_ab_test_id';
const META_VARIANT = 'newspack_popups_ab_variant';
const META_GOAL = 'newspack_popups_ab_test_goal';
const META_CONTROL_SHARE = 'newspack_popups_ab_control_share';

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
		variants = {},
		ineligible_placements: ineligible = [],
		results_url: resultsUrl = '',
	} = window?.newspack_popups_data?.ab_testing || {};
	const eligible = isEligiblePlacement( placement, ineligible );

	// What the author typed, kept apart from the stored slug so a trailing space
	// or hyphen survives until the next keystroke instead of being trimmed away
	// mid-word. The meta always holds the slug.
	const [ draft, setDraft ] = useState( testId );
	useEffect( () => {
		if ( slugifyTestId( draft ) !== testId ) {
			setDraft( testId );
		}
	}, [ testId ] );

	const blockNames = useSelect( select => {
		const { getClientIdsWithDescendants, getBlockName } = select( 'core/block-editor' );
		return getClientIdsWithDescendants().map( getBlockName );
	}, [] );

	const [ prompts, setPrompts ] = useState( [] );
	const latestTestId = useRef( testId );
	latestTestId.current = testId;
	const lookUpPrompts = useDebounce(
		useCallback( id => {
			if ( ! id ) {
				setPrompts( [] );
				return;
			}
			apiFetch( { path: `/newspack-popups/v1/ab-tests/${ id }` } )
				.then( result => {
					// A reply to an earlier Test ID must not describe the current one.
					if ( id === latestTestId.current ) {
						setPrompts( Array.isArray( result ) ? result : [] );
					}
				} )
				.catch( () => setPrompts( [] ) );
		}, [] ),
		500
	);
	useEffect( () => {
		lookUpPrompts( testId );
	}, [ testId ] );

	const onTestIdChange = value => {
		setDraft( value );
		const slug = slugifyTestId( value );
		if ( '' === slug ) {
			onMetaFieldChange( { [ META_TEST_ID ]: '', [ META_VARIANT ]: '', [ META_GOAL ]: '' } );
			return;
		}
		const update = { [ META_TEST_ID ]: slug };
		if ( ! variant ) {
			update[ META_VARIANT ] = 'a';
		}
		onMetaFieldChange( update );
	};

	const variantOptions = Object.entries( variants ).map( ( [ value, label ] ) => ( { value, label } ) );
	if ( ! variants[ variant ] ) {
		// A stored value this editor doesn't offer: a variant from another source
		// (c, d), or none at all, which leaves the prompt out of the test. Show the
		// stored state rather than letting the select fall back to "A (control)",
		// and don't rewrite it.
		variantOptions.unshift( {
			value: variant,
			label: variant
				? // Translators: %s is the variant letter.
				  sprintf( __( 'Unsupported version (%s)', 'newspack-popups' ), variant.toUpperCase() )
				: __( 'Select a version', 'newspack-popups' ),
			disabled: true,
		} );
	}

	const goalOptions = [
		{ value: '', label: __( 'Select a goal', 'newspack-popups' ) },
		...Object.entries( goals ).map( ( [ value, label ] ) => ( { value, label } ) ),
	];

	const others = prompts.filter( prompt => prompt.id !== postId );
	const control = 'a' !== variant ? others.find( prompt => 'a' === prompt.variant ) : null;
	const effectiveGoal = control ? control.goal || '' : goal;
	const warnings = testPromptWarnings( { postId, variant, prompts } );

	// The notices describe standing state rather than the result of an action, so
	// none is announced on mount (the panel re-mounts them on every expand).
	return (
		<Stack direction="column" gap="lg">
			{ ! canManage && (
				<p className="newspack-popups-ab-test__note">
					{ __( "You can see this test's settings but can't change them.", 'newspack-popups' ) }
				</p>
			) }
			{ ! eligible && (
				<Notice status={ testId ? 'error' : 'warning' } spokenMessage="" isDismissible={ false }>
					{ __( 'A/B tests only work on prompts that Newspack places for you.', 'newspack-popups' ) }
					{ testId ? ' ' + __( 'Clear the test name or choose another placement to save.', 'newspack-popups' ) : '' }
				</Notice>
			) }
			<TextControl
				label={ __( 'Test name', 'newspack-popups' ) }
				value={ draft }
				onChange={ onTestIdChange }
				onBlur={ () => setDraft( testId ) }
				// On an ineligible placement a new test can't start, but a stored Test
				// ID stays editable so it can be cleared, which the notice asks for.
				disabled={ ! canManage || ( ! eligible && ! testId ) }
				help={ __( 'Prompts with the same test name are compared with each other.', 'newspack-popups' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ testId && (
				<>
					<SelectControl
						label={ __( 'Version', 'newspack-popups' ) }
						value={ variant }
						options={ variantOptions }
						onChange={ value => onMetaFieldChange( { [ META_VARIANT ]: value } ) }
						disabled={ ! canManage }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ warnings.map( warning => (
						<Notice key={ warning } status="warning" spokenMessage="" isDismissible={ false }>
							{ warning }
						</Notice>
					) ) }
					{ 'a' === variant && (
						<RangeControl
							label={ __( 'Readers who see A', 'newspack-popups' ) }
							value={ controlShare }
							min={ 20 }
							max={ 80 }
							step={ 1 }
							onChange={ value => onMetaFieldChange( { [ META_CONTROL_SHARE ]: value } ) }
							disabled={ ! canManage }
							help={ splitSummary( controlShare ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					{ control ? (
						<p className="newspack-popups-ab-test__note">
							{ sprintf(
								// Translators: %1$s is the test's goal, %2$s the traffic split, such as "60% see A, 40% see B".
								__( 'Goal: %1$s. %2$s. Set on version A.', 'newspack-popups' ),
								goals[ effectiveGoal ] || __( 'none', 'newspack-popups' ),
								splitSummary( control.control_share ?? 50 )
							) }
						</p>
					) : (
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
					) }
					{ promptLacksGoalBlock( effectiveGoal, blockNames ) && (
						<Notice status="warning" spokenMessage="" isDismissible={ false }>
							{ missingGoalBlockMessage( effectiveGoal ) }
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
										// Translators: %1$s is the prompt's version, %2$s its post status label.
										__( '%1$s · %2$s', 'newspack-popups' ),
										variants[ prompt.variant ] ||
											( prompt.variant ? String( prompt.variant ).toUpperCase() : __( 'No version', 'newspack-popups' ) ),
										prompt.status_label || prompt.status
									);
									return (
										<li key={ prompt.id }>
											{ prompt.edit_link ? (
												<ExternalLink href={ prompt.edit_link }>{ prompt.title }</ExternalLink>
											) : (
												prompt.title
											) }
											<span className="newspack-popups-ab-test__prompt-meta">{ meta }</span>
										</li>
									);
								} ) }
							</ul>
						</div>
					) }
					{ resultsUrl && <ExternalLink href={ resultsUrl }>{ __( 'View test results', 'newspack-popups' ) }</ExternalLink> }
				</>
			) }
		</Stack>
	);
};

export default ABTestPanel;
