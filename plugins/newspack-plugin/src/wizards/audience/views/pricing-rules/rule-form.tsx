/**
 * Pricing-rule common-fields editor. Full-page form; Save/Back live in the wizard
 * header. POST creates (simple-only), PUT updates. Advanced bits (multi-step
 * schedule, conditions) live in the classic editor — surfaced read-only on edit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
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
import { Grid, SectionHeader, Divider } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import ScopeTargets from './scope-targets';
import Conditions from './conditions';
import RulePreview from './rule-preview';
import { tsToLocalInput, localInputToTs } from './datetime';
import { RECIPES, pathOptions, applyRecipeConditions, intentLabel, pathDescription, type PricingPath } from './recipes';

const API_PATH = '/wc-dynamic-pricing/v1/rules';

interface RuleFormProps {
	isNew: boolean;
	rule: PricingRuleRow | null;
	vocab: PricingRulesResponse;
	onDone: () => void;
}

interface StepRowState {
	at: string;
	calc_type: string;
	value: string;
	label: string;
}

export default function RuleForm( { isNew, rule, vocab, onDone }: RuleFormProps ) {
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ title, setTitle ] = useState( rule?.title ?? '' );
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
	const [ scopeType, setScopeType ] = useState( rule?.scope_type ?? vocab.scopes[ 0 ]?.id ?? 'all_products' );
	const [ scopeIds, setScopeIds ] = useState< number[] >( rule?.scope_ids ?? [] );
	const [ priority, setPriority ] = useState( String( rule?.priority ?? 100 ) );
	const [ composeMode, setComposeMode ] = useState( rule?.compose_mode ?? 'min' );
	const [ application, setApplication ] = useState( rule?.application === 'locked' ? 'locked' : 'current' );
	const [ cycleAnchor, setCycleAnchor ] = useState( rule?.cycle_anchor === 'rule_application' ? 'rule_application' : 'subscription_start' );
	const [ publicize, setPublicize ] = useState( Boolean( rule?.publicize ) );
	const [ path, setPath ] = useState< string >( rule?.intent ?? ( isNew ? '' : 'custom' ) );
	const [ intentNote, setIntentNote ] = useState( rule?.intent_note ?? '' );
	// On a new rule the goal cards collapse to the chosen one once picked; "picking" reopens the full list.
	const [ picking, setPicking ] = useState( false );
	// The deal name auto-fills from the goal until the publisher types their own.
	const [ titleIsAuto, setTitleIsAuto ] = useState( isNew && ! rule?.title );
	const recipe = path && path in RECIPES ? RECIPES[ path as PricingPath ] : null;

	// Choosing a path applies its recipe: force the lifecycle matcher + application +
	// default scope, and seed the name from the goal. Preserves segmentation; Custom
	// presets nothing but its scope default.
	const choosePath = ( next: string ) => {
		setPath( next );
		setPicking( false );
		if ( ! ( next in RECIPES ) ) {
			return;
		}
		const p = next as PricingPath;
		setConditions( prev => applyRecipeConditions( p, prev ) );
		const app = RECIPES[ p ].application;
		if ( app ) {
			setApplication( app );
		}
		// Seed the cycle anchor from the recipe — retention rebases to first apply.
		setCycleAnchor( RECIPES[ p ].cycleAnchor );
		// Default the deal name to the goal until the publisher types their own.
		if ( titleIsAuto ) {
			setTitle( 'custom' === p ? '' : intentLabel( p ) );
		}
		// Subscription presets target all subscriptions; Custom stays all products.
		const wantScope = RECIPES[ p ].defaultScope;
		if ( vocab.scopes.some( s => s.id === wantScope ) ) {
			setScopeType( wantScope );
			setScopeIds( [] );
		}
	};

	const [ activeFrom, setActiveFrom ] = useState( tsToLocalInput( rule?.active_from ?? null ) );
	const [ activeUntil, setActiveUntil ] = useState( tsToLocalInput( rule?.active_until ?? null ) );
	const [ conditions, setConditions ] = useState< Record< string, boolean | number | null > >( () => ( { ...( rule?.conditions ?? {} ) } ) );
	const [ isSaving, setIsSaving ] = useState( false );

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
				onDone();
			} )
			.catch( ( e: { message?: string } ) =>
				addNotice( {
					message: e?.message || __( 'Failed to save the rule.', 'newspack-plugin' ),
					type: 'error',
					id: 'pricing-rule-save-error',
				} )
			)
			.finally( () => setIsSaving( false ) );
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

	// The create/save action is disabled until the form's hard requirements are met:
	// a name and a chosen goal (mirrors the submit() guards).
	const canSubmit = title.trim() !== '' && path !== '';
	useEffect( () => {
		setHeaderData( {
			backNav: '#/',
			actions: [
				{
					type: 'primary',
					label: isNew ? __( 'Create rule', 'newspack-plugin' ) : __( 'Save changes', 'newspack-plugin' ),
					action: submit,
					disabled: isSaving || ! canSubmit,
				},
			],
		} );
	}, [ setHeaderData, submit, isNew, isSaving, canSubmit ] );

	return (
		<div className="newspack-pricing-rules__form">
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader
					title={ __( 'What are you trying to do?', 'newspack-plugin' ) }
					description={ __(
						'Pick a goal. We preset the matching options and hide the rest; choose Custom for full control.',
						'newspack-plugin'
					) }
				/>
				<VStack spacing={ 4 }>
					{ isNew && ( path === '' || picking ) && (
						<div className="newspack-pricing-rules__goal-grid">
							{ pathOptions().map( opt => {
								const selected = path === opt.value;
								return (
									<button
										key={ opt.value }
										type="button"
										className={ `newspack-pricing-rules__goal-card${ selected ? ' is-selected' : '' }` }
										aria-pressed={ selected }
										onClick={ () => choosePath( opt.value ) }
									>
										<span className="newspack-pricing-rules__goal-card-title">{ opt.label }</span>
										<span className="newspack-pricing-rules__goal-card-desc">{ pathDescription( opt.value ) }</span>
									</button>
								);
							} ) }
						</div>
					) }
					{ isNew && path !== '' && ! picking && (
						<div className="newspack-pricing-rules__goal-grid">
							<div className="newspack-pricing-rules__goal-card is-selected">
								<span className="newspack-pricing-rules__goal-card-title">{ intentLabel( path ) }</span>
								<span className="newspack-pricing-rules__goal-card-desc">{ pathDescription( path as PricingPath ) }</span>
							</div>
							<Button variant="link" onClick={ () => setPicking( true ) }>
								{ __( 'Change', 'newspack-plugin' ) }
							</Button>
						</div>
					) }
					{ ! isNew && (
						<p className="description">
							{ __( 'Goal:', 'newspack-plugin' ) } <strong>{ intentLabel( path ) }</strong>
							<br />
							{ __( 'Set when the rule was created — create a new rule to use a different goal.', 'newspack-plugin' ) }
						</p>
					) }
					{ ! isNew && recipe && (
						<p className="description" style={ { marginTop: 0 } }>
							{ pathDescription( path as PricingPath ) }
						</p>
					) }
					{ recipe?.isCustom && (
						<TextControl
							label={ __( 'Goal note', 'newspack-plugin' ) }
							help={ __( "Optional. Describe this deal's goal in your own words.", 'newspack-plugin' ) }
							value={ intentNote }
							onChange={ setIntentNote }
							__next40pxDefaultSize
						/>
					) }
				</VStack>
			</Grid>

			{ path !== '' && (
				<>
					<Grid columns={ 2 } gutter={ 32 }>
						<SectionHeader
							title={ __( 'Rule details', 'newspack-plugin' ) }
							description={ __( 'Name, status, and which products it applies to.', 'newspack-plugin' ) }
						/>
						<VStack spacing={ 4 }>
							<TextControl
								label={ __( 'Name', 'newspack-plugin' ) }
								value={ title }
								onChange={ v => {
									setTitle( v );
									setTitleIsAuto( false );
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
							title={ __( 'Pricing model', 'newspack-plugin' ) }
							description={ __( 'How matching products are priced.', 'newspack-plugin' ) }
						/>
						<VStack spacing={ 4 }>
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
												label={ __( 'Remove step', 'newspack-plugin' ) }
											/>
										</HStack>
									) ) }
									<div>
										<Button variant="secondary" onClick={ addStep }>
											{ __( '+ Add row', 'newspack-plugin' ) }
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
							title={ __( 'Scheduling & behavior', 'newspack-plugin' ) }
							description={ __( 'When the rule is active, its priority, and how it composes with other rules.', 'newspack-plugin' ) }
						/>
						<VStack spacing={ 4 }>
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
								help={ __( 'Optional. Site timezone. Empty = active immediately.', 'newspack-plugin' ) }
								type="datetime-local"
								value={ activeFrom }
								onChange={ setActiveFrom }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Ends', 'newspack-plugin' ) }
								help={ __( 'Optional. Site timezone. Empty = no end date.', 'newspack-plugin' ) }
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
						/>
						<VStack spacing={ 4 }>
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
							title={ __( 'Impact preview', 'newspack-plugin' ) }
							description={ __(
								'How this rule prices products, composed with your other active rules. Updates as you edit.',
								'newspack-plugin'
							) }
						/>
						<RulePreview body={ previewBody } />
					</div>
				</>
			) }
		</div>
	);
}
