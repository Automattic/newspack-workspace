/**
 * Pricing-rule common-fields editor. Full-page form; Save/Back live in the wizard
 * header. POST creates (simple-only), PUT updates. Advanced bits (multi-step
 * schedule, conditions) live in the classic editor — surfaced read-only on edit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import {
	BaseControl,
	Button,
	Modal,
	TextControl,
	SelectControl,
	ToggleControl,
	FlexBlock,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Grid, Router, SectionHeader, Divider } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import ScopeTargets from './scope-targets';
import Conditions, { type ConditionsMap } from './conditions';
import RulePreview from './rule-preview';
import { tsToLocalInput, localInputToTs } from './datetime';
import { RECIPES, applyRecipeConditions, isConditionVisible, intentLabel, pathDescription, type PricingPath } from './recipes';
import GoalCards from './goal-cards';
import { RULES_API_PATH as API_PATH } from './constants';

const { useHistory, useLocation } = Router;

interface StepRowState {
	at: string;
	calc_type: string;
	value: string;
	label: string;
}

const GOAL_HELP_ID = 'newspack-pricing-rule-goal__help';
const GOAL_MODAL_DESCRIPTION_ID = 'newspack-pricing-rule-goal-modal__description';

interface RuleFormProps {
	isNew: boolean;
	/** The goal chosen at #/new. Null when editing. */
	initialPath?: PricingPath | null;
	rule: PricingRuleRow | null;
	vocab: PricingRulesResponse;
	onDone: () => void;
}

/**
 * Drop conditions the target goal cannot show. Without this a Custom detour leaves a
 * named goal carrying a gate it never displays and the publisher cannot clear.
 */
function conditionsVisibleUnder( path: PricingPath, conditions: ConditionsMap, vocab: PricingRuleConditionVocab[] = [] ): ConditionsMap {
	const next: ConditionsMap = {};
	for ( const [ id, val ] of Object.entries( conditions ) ) {
		const matcher = vocab.find( m => m.id === id );
		if ( matcher && isConditionVisible( path, matcher.field_type ) ) {
			next[ id ] = val;
		}
	}
	return next;
}

/**
 * Seed condition state from a saved rule, coercing array-valued conditions (e.g.
 * `reader_segment`) to numeric IDs. A legacy rule can persist segment IDs as
 * strings (`[ "20" ]`); the segment token field matches by numeric identity
 * (`ids.includes( option.value )`), so string IDs would render no tokens on edit.
 * Coercing here heals those rows and decouples the form from the server value type.
 */
function seedConditions( raw: ConditionsMap | undefined ): ConditionsMap {
	const seeded: ConditionsMap = {};
	for ( const [ id, val ] of Object.entries( raw ?? {} ) ) {
		seeded[ id ] = Array.isArray( val ) ? val.map( Number ).filter( n => ! Number.isNaN( n ) ) : val;
	}
	return seeded;
}

export default function RuleForm( { isNew, initialPath = null, rule, vocab, onDone }: RuleFormProps ) {
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const history = useHistory();
	const { pathname } = useLocation();

	const seedPath = isNew ? initialPath : null;
	const seedTitle = seedPath && ! RECIPES[ seedPath ].isCustom ? intentLabel( seedPath ) : '';
	const seedApplication = seedPath ? RECIPES[ seedPath ].application : null;
	const seedCycleAnchor = seedPath ? RECIPES[ seedPath ].cycleAnchor : 'subscription_start';
	const seedScope = seedPath && vocab.scopes.some( s => s.id === RECIPES[ seedPath ].defaultScope ) ? RECIPES[ seedPath ].defaultScope : null;

	const [ title, setTitle ] = useState( rule?.title ?? seedTitle );
	// The name follows the goal until the publisher types their own.
	const [ titleIsAuto, setTitleIsAuto ] = useState( isNew && ! rule?.title );
	const [ status, setStatus ] = useState( rule?.status === 'publish' ? 'publish' : 'draft' );
	const [ calcType, setCalcType ] = useState( rule?.simple?.calc_type ?? vocab.calc_types[ 0 ]?.value ?? 'fixed_price' );
	const [ value, setValue ] = useState( String( rule?.simple?.value ?? '' ) );
	const [ cyclesLimit, setCyclesLimit ] = useState( String( rule?.simple?.cycles_limit ?? 0 ) );
	const [ simpleLabel, setSimpleLabel ] = useState( rule?.simple?.label ?? '' );
	const [ strategyId, setStrategyId ] = useState( rule?.strategy_id ?? vocab.strategies[ 0 ]?.id ?? 'simple_price' );
	const defaultCalc = vocab.calc_types[ 0 ]?.value ?? 'fixed_price';
	const [ steps, setSteps ] = useState< StepRowState[] >(
		rule?.steps?.length
			? rule.steps.map( s => ( { at: String( s.at ), calc_type: s.calc_type, value: String( s.value ), label: s.label } ) )
			: [ { at: '1', calc_type: defaultCalc, value: '', label: '' } ]
	);
	const isSchedule = strategyId === 'stepped_by_cycle';
	// A stepped schedule, or a flat rule capped to N cycles, has a cycle dimension —
	// the only case where the cycle anchor is consequential.
	const hasCycleDimension = isSchedule || ( ! isSchedule && Number( cyclesLimit ) > 0 );
	const updateStep = ( i: number, key: keyof StepRowState, val: string ) =>
		setSteps( prev => prev.map( ( s, idx ) => ( idx === i ? { ...s, [ key ]: val } : s ) ) );
	const addStep = () =>
		setSteps( prev => [
			...prev,
			{ at: String( ( Number( prev[ prev.length - 1 ]?.at ) || prev.length ) + 1 ), calc_type: defaultCalc, value: '', label: '' },
		] );
	const removeStep = ( i: number ) => setSteps( prev => prev.filter( ( _, idx ) => idx !== i ) );
	const [ scopeType, setScopeType ] = useState( rule?.scope_type ?? seedScope ?? vocab.scopes[ 0 ]?.id ?? 'all_products' );
	const [ scopeIds, setScopeIds ] = useState< number[] >( rule?.scope_ids ?? [] );
	const [ priority, setPriority ] = useState( String( rule?.priority ?? 100 ) );
	const [ composeMode, setComposeMode ] = useState( rule?.compose_mode ?? 'min' );
	const [ application, setApplication ] = useState( rule?.application === 'locked' ? 'locked' : seedApplication ?? 'current' );
	const [ cycleAnchor, setCycleAnchor ] = useState( rule?.cycle_anchor === 'rule_application' ? 'rule_application' : seedCycleAnchor );
	const [ publicize, setPublicize ] = useState( Boolean( rule?.publicize ) );
	const [ intentNote, setIntentNote ] = useState( rule?.intent_note ?? '' );
	const [ path, setPath ] = useState< string >( rule?.intent || initialPath || ( isNew ? '' : 'custom' ) );
	const needsGoal = isNew && ! path;
	const [ isChangingGoal, setIsChangingGoal ] = useState( needsGoal );
	const [ pendingGoal, setPendingGoal ] = useState< PricingPath | null >( null );
	const recipe = Object.prototype.hasOwnProperty.call( RECIPES, path ) ? RECIPES[ path as PricingPath ] : null;

	/** Apply a goal's recipe to the fields that goal owns. Everything typed is left alone. */
	const choosePath = ( next: PricingPath ) => {
		setPath( next );
		// `replace`, so Back leaves the flow rather than stepping through goals.
		if ( isNew ) {
			history.replace( `/new/${ next }` );
		}
		const nextRecipe = RECIPES[ next ];
		setConditions( prev => applyRecipeConditions( next, conditionsVisibleUnder( next, prev, vocab.conditions ) ) );
		if ( nextRecipe.application ) {
			setApplication( nextRecipe.application );
		}
		setCycleAnchor( nextRecipe.cycleAnchor );
		if ( titleIsAuto ) {
			setTitle( nextRecipe.isCustom ? '' : intentLabel( next ) );
		}
		if ( vocab.scopes.some( s => s.id === nextRecipe.defaultScope ) ) {
			setScopeType( nextRecipe.defaultScope );
			setScopeIds( [] );
		}
		// Custom-only controls; a named goal would carry them hidden and unremovable.
		if ( ! nextRecipe.isCustom ) {
			setPriority( '100' );
			setComposeMode( 'min' );
		}
	};

	// A goal changed outside the form arrives as a prop, never as a remount. A URL
	// that has dropped the goal is canonicalised back to the one on screen: clearing
	// `path` instead would discard the recipe and everything typed, and would not
	// reopen the picker, leaving a form that can never be saved.
	useEffect( () => {
		if ( ! isNew ) {
			return;
		}
		if ( initialPath && initialPath !== path ) {
			choosePath( initialPath );
		} else if ( ! initialPath && path ) {
			history.replace( `/new/${ path }` );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ initialPath ] );

	const confirmGoal = () => {
		if ( pendingGoal ) {
			choosePath( pendingGoal );
		}
		setIsChangingGoal( false );
	};

	// WP would return focus to the document body after the first-run modal, so land
	// the publisher on the Change button instead.
	const changeGoalRef = useRef< HTMLButtonElement >( null );
	const isFirstRun = useRef( needsGoal );
	useEffect( () => {
		if ( ! isChangingGoal && isFirstRun.current ) {
			isFirstRun.current = false;
			changeGoalRef.current?.focus();
		}
	}, [ isChangingGoal ] );

	const [ activeFrom, setActiveFrom ] = useState( tsToLocalInput( rule?.active_from ?? null ) );
	const [ activeUntil, setActiveUntil ] = useState( tsToLocalInput( rule?.active_until ?? null ) );
	const [ conditions, setConditions ] = useState< ConditionsMap >( () =>
		seedPath ? applyRecipeConditions( seedPath, {} ) : seedConditions( rule?.conditions )
	);
	const [ isSaving, setIsSaving ] = useState( false );

	// A save can outlive the form; its callbacks must not then navigate.
	const isMounted = useRef( true );
	useEffect( () => {
		isMounted.current = true;
		return () => {
			isMounted.current = false;
		};
	}, [] );

	const previewBody = useMemo( () => {
		const b: Record< string, unknown > = {
			id: rule?.id,
			scope_type: scopeType,
			scope_ids: scopeIds,
			conditions,
			application,
			compose_mode: composeMode,
			priority: Number( priority ) || 0,
			active_from: localInputToTs( activeFrom ),
			active_until: localInputToTs( activeUntil ),
		};
		if ( isSchedule ) {
			b.strategy_id = 'stepped_by_cycle';
			b.steps = steps
				.filter( s => String( s.value ).trim() !== '' )
				.map( s => ( { at: Number( s.at ) || 1, calc_type: s.calc_type, value: Number( s.value ) || 0, label: s.label } ) );
		} else {
			b.strategy_id = 'simple_price';
			b.simple = {
				calc_type: calcType,
				value: Number( value ) || 0,
				cycles_limit: Number( cyclesLimit ) || 0,
				label: simpleLabel,
			};
		}
		return b;
	}, [
		rule,
		scopeType,
		scopeIds,
		conditions,
		application,
		composeMode,
		priority,
		activeFrom,
		activeUntil,
		isSchedule,
		steps,
		calcType,
		value,
		cyclesLimit,
		simpleLabel,
	] );

	const submit = useCallback( () => {
		if ( ! title.trim() ) {
			addNotice( { message: __( 'A name is required.', 'newspack-plugin' ), type: 'error', id: 'pricing-rule-name' } );
			return;
		}
		if ( path === '' ) {
			addNotice( { message: __( 'Choose a goal for this rule.', 'newspack-plugin' ), type: 'error', id: 'pricing-rule-path' } );
			return;
		}
		// A blank flat value is "not set" — distinct from a deliberate 0. The
		// schedule model already drops empty steps and refuses to save with none;
		// mirror that here instead of silently coercing blank to $0 (NPPD-1854). A
		// typed 0 is still allowed (an intentional free price).
		if ( ! isSchedule && String( value ).trim() === '' ) {
			addNotice( {
				message: __( 'Enter a price for this rule.', 'newspack-plugin' ),
				type: 'error',
				id: 'pricing-rule-value',
			} );
			return;
		}
		// A non-empty start/end that doesn't parse would otherwise be silently dropped
		// to "no date" on save; surface it instead of discarding the operator's input.
		if ( activeFrom.trim() !== '' && localInputToTs( activeFrom ) === null ) {
			addNotice( {
				message: __( 'Enter a valid start date, or clear it.', 'newspack-plugin' ),
				type: 'error',
				id: 'pricing-rule-active-from',
			} );
			return;
		}
		if ( activeUntil.trim() !== '' && localInputToTs( activeUntil ) === null ) {
			addNotice( {
				message: __( 'Enter a valid end date, or clear it.', 'newspack-plugin' ),
				type: 'error',
				id: 'pricing-rule-active-until',
			} );
			return;
		}
		setIsSaving( true );
		const body: Record< string, unknown > = {
			title,
			status,
			scope_type: scopeType,
			scope_ids: scopeIds,
			priority: Number( priority ) || 0,
			compose_mode: composeMode,
			application,
			cycle_anchor: cycleAnchor,
			publicize,
			intent: path,
			intent_note: path === 'custom' ? intentNote : '',
			active_from: localInputToTs( activeFrom ),
			active_until: localInputToTs( activeUntil ),
			conditions,
		};
		if ( isSchedule ) {
			const cleanSteps = steps
				.filter( s => String( s.value ).trim() !== '' )
				.map( s => ( { at: Number( s.at ) || 1, calc_type: s.calc_type, value: Number( s.value ) || 0, label: s.label } ) );
			if ( ! cleanSteps.length ) {
				addNotice( {
					message: __( 'Add at least one schedule step with a value.', 'newspack-plugin' ),
					type: 'error',
					id: 'pricing-rule-steps',
				} );
				setIsSaving( false );
				return;
			}
			body.strategy_id = 'stepped_by_cycle';
			body.steps = cleanSteps;
		} else {
			body.strategy_id = 'simple_price';
			body.simple = {
				calc_type: calcType,
				value: Number( value ) || 0,
				cycles_limit: Number( cyclesLimit ) || 0,
				label: simpleLabel,
			};
		}
		const apiPath = isNew ? API_PATH : `${ API_PATH }/${ rule!.id }`;
		apiFetch( { path: apiPath, method: isNew ? 'POST' : 'PUT', data: body } )
			.then( () => {
				addNotice( {
					message: isNew ? __( 'Rule created.', 'newspack-plugin' ) : __( 'Rule saved.', 'newspack-plugin' ),
					type: 'success',
					id: 'pricing-rule-saved',
				} );
				if ( isMounted.current ) {
					onDone();
				}
			} )
			.catch( ( e: { message?: string } ) =>
				addNotice( {
					message: e?.message || __( 'Failed to save the rule.', 'newspack-plugin' ),
					type: 'error',
					id: 'pricing-rule-save-error',
				} )
			)
			.finally( () => {
				if ( isMounted.current ) {
					setIsSaving( false );
				}
			} );
	}, [
		title,
		status,
		scopeType,
		scopeIds,
		priority,
		composeMode,
		application,
		cycleAnchor,
		publicize,
		path,
		intentNote,
		activeFrom,
		activeUntil,
		conditions,
		isSchedule,
		steps,
		calcType,
		value,
		cyclesLimit,
		simpleLabel,
		isNew,
		rule,
		addNotice,
		onDone,
	] );

	// Disabled until the form's hard requirement is met: a name (mirrors submit()).
	const canSubmit = title.trim() !== '' && ! needsGoal;
	useEffect( () => {
		setHeaderData( {
			backNav: '#/',
			sectionName: isNew ? __( 'Add Rule', 'newspack-plugin' ) : __( 'Edit Rule', 'newspack-plugin' ),
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: submit,
					disabled: isSaving || ! canSubmit,
				},
			],
		} );
		// `pathname`: the wizard blanks the header on every route change, and the form
		// outlives them, so each one has to republish it.
	}, [ setHeaderData, submit, isNew, isSaving, canSubmit, pathname ] );

	const goalHelp = [
		pathDescription( path as PricingPath ),
		! isNew && __( 'Set when the rule was created; create a new rule to use a different goal.', 'newspack-plugin' ),
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className="newspack-pricing-rules__form">
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader
					title={ __( 'Rule Details', 'newspack-plugin' ) }
					description={ __( 'The goal it is built around, its name and status, and which products it applies to.', 'newspack-plugin' ) }
					noMargin
				/>
				<VStack spacing={ 6 } className="newspack-pricing-rules__details">
					<BaseControl id="newspack-pricing-rule-goal" label={ __( 'Goal', 'newspack-plugin' ) } help={ goalHelp } __nextHasNoMarginBottom>
						<HStack className="newspack-pricing-rules__goal" alignment="center" spacing={ 2 }>
							<FlexBlock>
								<input
									id="newspack-pricing-rule-goal"
									className="components-text-control__input"
									type="text"
									value={ intentLabel( path ) }
									placeholder={ __( 'No goal chosen yet', 'newspack-plugin' ) }
									aria-describedby={ goalHelp ? GOAL_HELP_ID : undefined }
									readOnly
								/>
							</FlexBlock>
							{ isNew && (
								<Button
									ref={ changeGoalRef }
									variant="secondary"
									onClick={ () => {
										setPendingGoal( path as PricingPath );
										setIsChangingGoal( true );
									} }
									aria-label={ __( 'Change goal', 'newspack-plugin' ) }
									disabled={ isSaving }
									__next40pxDefaultSize
								>
									{ __( 'Change', 'newspack-plugin' ) }
								</Button>
							) }
						</HStack>
					</BaseControl>
					{ recipe?.isCustom && (
						<TextControl
							label={ __( 'Goal note', 'newspack-plugin' ) }
							help={ __( "Optional. Describe this deal's goal in your own words.", 'newspack-plugin' ) }
							value={ intentNote }
							onChange={ setIntentNote }
							__next40pxDefaultSize
						/>
					) }
					<TextControl
						label={ __( 'Name', 'newspack-plugin' ) }
						value={ title }
						onChange={ v => {
							setTitle( v );
							setTitleIsAuto( v.trim() === '' );
						} }
						__next40pxDefaultSize
					/>
					{ ! isNew && rule && (
						<p className="description">
							{ __( 'Deal ID:', 'newspack-plugin' ) } <code>{ rule.deal_key }</code>
							<br />
							{ __( 'Use this ID to find the deal in your analytics. It never changes.', 'newspack-plugin' ) }
						</p>
					) }
					<SelectControl
						label={ __( 'Status', 'newspack-plugin' ) }
						value={ status }
						options={ [
							{ label: __( 'Published', 'newspack-plugin' ), value: 'publish' },
							{ label: __( 'Draft', 'newspack-plugin' ), value: 'draft' },
						] }
						onChange={ setStatus }
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Applies to', 'newspack-plugin' ) }
						help={ __( 'Which products this rule targets.', 'newspack-plugin' ) }
						value={ scopeType }
						options={ vocab.scopes.map( s => ( { label: s.label, value: s.id } ) ) }
						onChange={ st => {
							setScopeType( st );
							// Category and product ids are different namespaces — clear on switch.
							setScopeIds( [] );
						} }
						__next40pxDefaultSize
					/>
					<ScopeTargets scopeType={ scopeType } value={ scopeIds } onChange={ setScopeIds } />
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Pricing Model', 'newspack-plugin' ) }
					description={ __( 'How matching products are priced.', 'newspack-plugin' ) }
					noMargin
				/>
				<VStack spacing={ 6 }>
					{ isNew ? (
						<SelectControl
							label={ __( 'Pricing model', 'newspack-plugin' ) }
							value={ strategyId }
							options={ vocab.strategies.map( s => ( { label: s.label, value: s.id } ) ) }
							onChange={ setStrategyId }
							__next40pxDefaultSize
						/>
					) : (
						<p className="description">
							{ __( 'Pricing model:', 'newspack-plugin' ) }{ ' ' }
							<strong>{ vocab.strategies.find( s => s.id === strategyId )?.label ?? strategyId }</strong>
						</p>
					) }

					{ isSchedule ? (
						<VStack spacing={ 3 }>
							<p className="description">
								{ __(
									'Each row sets the price from a given cycle onward, until a later row takes over. Cycle 1 is the initial purchase; cycle 2 is the first renewal.',
									'newspack-plugin'
								) }
							</p>
							{ steps.map( ( step, i ) => (
								<HStack key={ i } alignment="flex-end" spacing={ 2 }>
									<FlexBlock>
										<TextControl
											label={ __( 'From cycle #', 'newspack-plugin' ) }
											hideLabelFromVision={ i > 0 }
											type="number"
											min={ 1 }
											value={ step.at }
											onChange={ v => updateStep( i, 'at', v ) }
											__next40pxDefaultSize
										/>
									</FlexBlock>
									<FlexBlock>
										<SelectControl
											label={ __( 'Pricing', 'newspack-plugin' ) }
											hideLabelFromVision={ i > 0 }
											value={ step.calc_type }
											options={ vocab.calc_types.map( c => ( { label: c.label, value: c.value } ) ) }
											onChange={ v => updateStep( i, 'calc_type', v ) }
											__next40pxDefaultSize
										/>
									</FlexBlock>
									<FlexBlock>
										<TextControl
											label={ __( 'Value', 'newspack-plugin' ) }
											hideLabelFromVision={ i > 0 }
											type="number"
											value={ step.value }
											onChange={ v => updateStep( i, 'value', v ) }
											__next40pxDefaultSize
										/>
									</FlexBlock>
									<FlexBlock>
										<TextControl
											label={ __( 'Name shown to reader', 'newspack-plugin' ) }
											hideLabelFromVision={ i > 0 }
											value={ step.label }
											onChange={ v => updateStep( i, 'label', v ) }
											__next40pxDefaultSize
										/>
									</FlexBlock>
									<Button
										icon={ trash }
										isDestructive
										variant="tertiary"
										disabled={ steps.length <= 1 }
										onClick={ () => removeStep( i ) }
										label={ __( 'Remove Step', 'newspack-plugin' ) }
									/>
								</HStack>
							) ) }
							<div>
								<Button variant="secondary" onClick={ addStep }>
									{ __( '+ Add Row', 'newspack-plugin' ) }
								</Button>
							</div>
						</VStack>
					) : (
						<>
							<SelectControl
								label={ __( 'Pricing', 'newspack-plugin' ) }
								value={ calcType }
								options={ vocab.calc_types.map( c => ( { label: c.label, value: c.value } ) ) }
								onChange={ setCalcType }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Value', 'newspack-plugin' ) }
								type="number"
								value={ value }
								onChange={ setValue }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Name shown to reader', 'newspack-plugin' ) }
								help={ __( 'Optional. Shown to readers when "Show pricing details" is on.', 'newspack-plugin' ) }
								value={ simpleLabel }
								onChange={ setSimpleLabel }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Apply for first N cycles', 'newspack-plugin' ) }
								help={ __(
									'0 = unlimited (every cycle). For subscriptions only — covers the purchase plus the next N-1 renewals. No effect on one-time products.',
									'newspack-plugin'
								) }
								type="number"
								value={ cyclesLimit }
								onChange={ setCyclesLimit }
								__next40pxDefaultSize
							/>
						</>
					) }
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Scheduling & Behavior', 'newspack-plugin' ) }
					description={ __( 'When the rule is active, its priority, and how it composes with other rules.', 'newspack-plugin' ) }
					noMargin
				/>
				<VStack spacing={ 6 }>
					{ recipe?.isCustom && (
						<TextControl
							label={ __( 'Priority', 'newspack-plugin' ) }
							help={ __( 'Lower numbers are considered first when multiple rules match.', 'newspack-plugin' ) }
							type="number"
							value={ priority }
							onChange={ setPriority }
							__next40pxDefaultSize
						/>
					) }
					{ recipe?.isCustom && (
						<SelectControl
							label={ __( 'When multiple rules match', 'newspack-plugin' ) }
							value={ composeMode }
							options={ [
								{ label: __( 'Best price wins (default)', 'newspack-plugin' ), value: 'min' },
								{ label: __( 'This rule only (stop checking others)', 'newspack-plugin' ), value: 'priority_exclusive' },
							] }
							onChange={ setComposeMode }
							__next40pxDefaultSize
						/>
					) }
					<TextControl
						label={ __( 'Starts', 'newspack-plugin' ) }
						help={ __( 'Optional. Times are in your local timezone. Empty = active immediately.', 'newspack-plugin' ) }
						type="datetime-local"
						value={ activeFrom }
						onChange={ setActiveFrom }
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Ends', 'newspack-plugin' ) }
						help={ __( 'Optional. Times are in your local timezone. Empty = no end date.', 'newspack-plugin' ) }
						type="datetime-local"
						value={ activeUntil }
						onChange={ setActiveUntil }
						__next40pxDefaultSize
					/>
					{ recipe?.isCustom && (
						<ToggleControl
							label={ __( 'Lock pricing at purchase', 'newspack-plugin' ) }
							help={ __(
								'On: subscribers keep the price they bought at — the deal only applies to new sign-ups. Off: the deal applies to every matching subscriber at each renewal.',
								'newspack-plugin'
							) }
							checked={ 'locked' === application }
							onChange={ checked => setApplication( checked ? 'locked' : 'current' ) }
							__nextHasNoMarginBottom
						/>
					) }
					{ application === 'current' && hasCycleDimension && (
						<SelectControl
							label={ __( 'Count cycles from', 'newspack-plugin' ) }
							value={ cycleAnchor }
							options={ [
								{
									label: __( 'When this rule first applies to a subscriber', 'newspack-plugin' ),
									value: 'rule_application',
								},
								{ label: __( 'Subscription start', 'newspack-plugin' ), value: 'subscription_start' },
							] }
							onChange={ setCycleAnchor }
							help={ __(
								'Anchors a stepped or cycle-limited schedule. “First applies” starts the schedule when the subscriber becomes eligible; “Subscription start” counts from their original signup.',
								'newspack-plugin'
							) }
							__next40pxDefaultSize
						/>
					) }
					<ToggleControl
						label={ __( 'Show pricing details', 'newspack-plugin' ) }
						help={ __(
							'Tell readers about this rule wherever the product appears — its name and the regular-vs-adjusted comparison show on the product page, cart, and checkout. When off, the adjusted price applies silently.',
							'newspack-plugin'
						) }
						checked={ publicize }
						onChange={ setPublicize }
						__nextHasNoMarginBottom
					/>
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Eligibility', 'newspack-plugin' ) }
					description={ __(
						'Gate whether this rule applies to a given purchase. All set conditions must pass; empty = no restrictions.',
						'newspack-plugin'
					) }
					noMargin
				/>
				<VStack spacing={ 6 }>
					<Conditions
						vocab={ vocab.conditions }
						value={ conditions }
						publishedAt={ rule?.published_at ?? null }
						isNew={ isNew }
						onChange={ setConditions }
						path={ path }
					/>
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<div className="newspack-pricing-rules__preview-section">
				<SectionHeader
					title={ __( 'Impact Preview', 'newspack-plugin' ) }
					description={ __(
						'How this rule prices products, composed with your other active rules. Updates as you edit.',
						'newspack-plugin'
					) }
					noMargin
				/>
				{ ! isSchedule && String( value ).trim() === '' ? (
					<p className="newspack-pricing-rules__muted">{ __( 'Enter a price to see the impact preview.', 'newspack-plugin' ) }</p>
				) : (
					<RulePreview body={ previewBody } />
				) }
			</div>
			{ isChangingGoal && (
				<Modal
					title={ needsGoal ? __( 'Choose a Goal', 'newspack-plugin' ) : __( 'Change Goal', 'newspack-plugin' ) }
					onRequestClose={ () => setIsChangingGoal( false ) }
					isDismissible={ ! needsGoal }
					shouldCloseOnEsc={ ! needsGoal }
					shouldCloseOnClickOutside={ ! needsGoal }
					size="large"
					className="newspack-pricing-rules__goal-modal"
					aria={ { describedby: GOAL_MODAL_DESCRIPTION_ID } }
				>
					<VStack spacing={ 6 }>
						<p className="newspack-pricing-rules__muted" id={ GOAL_MODAL_DESCRIPTION_ID }>
							{ needsGoal
								? __(
										'Pick a goal and we preset the options that match it: who qualifies, whether the price locks in at purchase, and which products it covers. You fill in the pricing. Choose Custom to set everything yourself.',
										'newspack-plugin'
								  )
								: __(
										'A new goal presets its own eligibility, price locking, products, and how cycles are counted. Your pricing, steps and dates stay as they are, and the name follows the goal until you write your own.',
										'newspack-plugin'
								  ) }
						</p>
						<GoalCards selected={ pendingGoal } onSelect={ setPendingGoal } />
						<HStack justify="flex-end" spacing={ 2 }>
							{ needsGoal ? (
								<Button variant="tertiary" href="#/" __next40pxDefaultSize>
									{ __( 'Back', 'newspack-plugin' ) }
								</Button>
							) : (
								<Button variant="tertiary" onClick={ () => setIsChangingGoal( false ) } __next40pxDefaultSize>
									{ __( 'Cancel', 'newspack-plugin' ) }
								</Button>
							) }
							<Button
								variant="primary"
								onClick={ confirmGoal }
								disabled={ ! pendingGoal || pendingGoal === path }
								accessibleWhenDisabled
								__next40pxDefaultSize
							>
								{ needsGoal ? __( 'Select Goal', 'newspack-plugin' ) : __( 'Update Goal', 'newspack-plugin' ) }
							</Button>
						</HStack>
					</VStack>
				</Modal>
			) }
		</div>
	);
}
