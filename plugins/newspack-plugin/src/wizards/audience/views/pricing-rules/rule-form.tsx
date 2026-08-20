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
	Button,
	TextControl,
	SelectControl,
	ToggleControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Grid, Router, SectionHeader, Divider, useConfirmDialog } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import ScopeTargets from './scope-targets';
import Conditions, { type ConditionsMap } from './conditions';
import SchedulePrices from './schedule-prices';
import RulePreview from './rule-preview';
import ReadonlyField from './readonly-field';
import DateTimeField from './datetime-field';
import { tsToLocalInput, localInputToTs } from './datetime';
import { RECIPES, applyRecipeConditions, isConditionVisible, isPricingPath, intentLabel, type PricingPath } from './recipes';
import GoalCards from './goal-cards';
import { calcTypeHelp, valueLabel, valueHelp } from './calc-copy';
import { cycleMarkerNote } from './impact-format';
import { byCycle } from './schedule-format';
import { RULES_API_PATH as API_PATH } from './constants';

const { useHistory, useLocation } = Router;

function publicizeHelp( publicize: boolean ): string {
	return publicize
		? __( "The rule's name and the regular-vs-adjusted comparison appear on the product page, cart, and checkout.", 'newspack-plugin' )
		: __( 'The adjusted price applies with no explanation to the reader.', 'newspack-plugin' );
}

/**
 * Both strings repeat that the choice is permanent: help is a single prop, so the
 * warning has to sit under whichever model is selected.
 */
function strategyHelp( id: string, fallback: string ): string {
	if ( 'simple_price' === id ) {
		return __( 'One price for matching products. Fixed once the rule is created.', 'newspack-plugin' );
	}
	if ( 'stepped_by_cycle' === id ) {
		return __( 'Different prices for the purchase and renewals. Fixed once the rule is created.', 'newspack-plugin' );
	}
	return fallback;
}

/**
 * The server's labels carry a description too long for a toggle, so the toggle
 * shows the name alone and the description moves into strategyHelp.
 */
function strategyShortLabel( id: string, fallback: string ): string {
	if ( 'simple_price' === id ) {
		return __( 'Flat Adjustment', 'newspack-plugin' );
	}
	if ( 'stepped_by_cycle' === id ) {
		return __( 'Price Schedule', 'newspack-plugin' );
	}
	return fallback;
}

const SCHEDULE_HELP_ID = 'newspack-pricing-rule-schedule__help';
const COPY_NOTICE_ID = 'pricing-rule-copy';

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

function CopyDealId( { dealKey }: { dealKey: string } ) {
	const { addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const buttonRef = useRef< HTMLButtonElement | null >( null );

	// navigator.clipboard is undefined outside a secure context, e.g. wp-admin over HTTP.
	const legacyCopy = (): boolean => {
		const doc = buttonRef.current?.ownerDocument ?? document;
		const field = doc.createElement( 'textarea' );
		field.value = dealKey;
		field.setAttribute( 'readonly', '' );
		field.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
		doc.body.appendChild( field );
		field.select();
		let copied = false;
		try {
			copied = doc.execCommand( 'copy' );
		} catch {
			copied = false;
		}
		field.remove();
		// select() moved focus to the textarea, which no longer exists.
		buttonRef.current?.focus();
		return copied;
	};

	// Not useCopyToClipboard: it only exposes onSuccess, so failure cannot be reported.
	const copy = async () => {
		let copied = false;
		try {
			await navigator.clipboard.writeText( dealKey );
			copied = true;
		} catch {
			copied = legacyCopy();
		}
		// Notices append without deduping, so a second copy would stack a toast sharing
		// the first one's React key.
		removeNotice( COPY_NOTICE_ID );
		addNotice(
			copied
				? { id: COPY_NOTICE_ID, type: 'success', message: __( 'Deal ID copied to clipboard.', 'newspack-plugin' ) }
				: { id: COPY_NOTICE_ID, type: 'error', message: __( 'Could not copy the Deal ID.', 'newspack-plugin' ) }
		);
	};

	return (
		<Button ref={ buttonRef } variant="secondary" onClick={ copy } aria-label={ __( 'Copy Deal ID', 'newspack-plugin' ) } __next40pxDefaultSize>
			{ __( 'Copy', 'newspack-plugin' ) }
		</Button>
	);
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
	const [ status, setStatus ] = useState< 'publish' | 'draft' >( rule?.status === 'publish' ? 'publish' : 'draft' );
	const [ calcType, setCalcType ] = useState( rule?.simple?.calc_type ?? vocab.calc_types[ 0 ]?.value ?? 'fixed_price' );
	const [ value, setValue ] = useState( String( rule?.simple?.value ?? '' ) );
	const [ cyclesLimit, setCyclesLimit ] = useState( String( rule?.simple?.cycles_limit ?? 0 ) );
	const [ simpleLabel, setSimpleLabel ] = useState( rule?.simple?.label ?? '' );
	const [ strategyId, setStrategyId ] = useState( rule?.strategy_id ?? vocab.strategies[ 0 ]?.id ?? 'simple_price' );
	// Ordered on the way in, so what the table ranges, the preview asks about and the
	// save posts are one and the same list even before anything is touched.
	const [ steps, setSteps ] = useState< SchedulePriceInput[] >( () =>
		( rule?.steps ?? [] )
			.map( s => ( { at: String( s.at ), calc_type: s.calc_type, value: String( s.value ), label: s.label ?? '' } ) )
			.sort( byCycle )
	);
	const isSchedule = strategyId === 'stepped_by_cycle';
	// The subscriptions bootstrap registers the schedule strategy, so a site without
	// WooCommerce Subscriptions has one model and nothing to choose.
	const hasModelChoice = vocab.strategies.length > 1;
	// A stepped schedule, or a flat rule capped to N cycles, has a cycle dimension —
	// the only case where the cycle anchor is consequential.
	const hasCycleDimension = isSchedule || ( ! isSchedule && Number( cyclesLimit ) > 0 );
	// Markers only render when a projection spans cycles, which a one-price
	// schedule never does; the composed preview covers other rules' cycles itself.
	const hasCycleMarkers = isSchedule ? steps.length > 1 : Number( cyclesLimit ) > 0;
	const hasPrice = isSchedule ? steps.length > 0 : String( value ).trim() !== '';
	const [ scopeType, setScopeType ] = useState( rule?.scope_type ?? seedScope ?? vocab.scopes[ 0 ]?.id ?? 'all_products' );
	const [ scopeIds, setScopeIds ] = useState< number[] >( rule?.scope_ids ?? [] );
	const [ priority, setPriority ] = useState( String( rule?.priority ?? 100 ) );
	// The rule schema leaves compose_mode open, and the select only offers the two
	// modes below. Hold whatever the server sent so a value this UI doesn't know
	// round-trips on save instead of being rewritten to 'min'.
	const [ composeMode, setComposeMode ] = useState< PricingRuleRow[ 'compose_mode' ] >( rule?.compose_mode ?? 'min' );
	const [ application, setApplication ] = useState( rule?.application === 'locked' ? 'locked' : seedApplication ?? 'current' );
	const [ cycleAnchor, setCycleAnchor ] = useState( rule?.cycle_anchor === 'rule_application' ? 'rule_application' : seedCycleAnchor );
	const [ publicize, setPublicize ] = useState( Boolean( rule?.publicize ) );
	const [ intentNote, setIntentNote ] = useState( rule?.intent_note ?? '' );
	const [ path, setPath ] = useState< string >( rule?.intent || initialPath || ( isNew ? '' : 'custom' ) );
	const needsGoal = isNew && ! path;
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

	// A goal-less URL is canonicalised back to the goal on screen; clearing `path`
	// instead would discard the recipe and everything typed.
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

	const [ activeFrom, setActiveFrom ] = useState( tsToLocalInput( rule?.active_from ?? null ) );
	const [ activeUntil, setActiveUntil ] = useState( tsToLocalInput( rule?.active_until ?? null ) );
	const [ conditions, setConditions ] = useState< ConditionsMap >( () =>
		seedPath ? applyRecipeConditions( seedPath, {} ) : seedConditions( rule?.conditions )
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ dateModes, setDateModes ] = useState< Record< string, string > >( {} );

	/**
	 * What a switch to a named goal would discard. Priority, compose mode and the
	 * date gates render only under Custom, so leaving Custom is the one switch that
	 * loses anything. Boolean matchers are excluded: a named goal owning the
	 * lifecycle gate is the point of picking one, not a side effect. A date gate
	 * counts only in `custom` mode — new rules auto-apply the publish date, and
	 * warning about a default nobody chose would put a dialog on every switch.
	 */
	const goalChangeLosses = useMemo( () => {
		if ( ! recipe?.isCustom ) {
			return [] as string[];
		}
		const lost: string[] = [];
		if ( '100' !== priority ) {
			lost.push( __( 'Priority', 'newspack-plugin' ) );
		}
		if ( 'min' !== composeMode ) {
			lost.push( __( 'When multiple rules match', 'newspack-plugin' ) );
		}
		( vocab.conditions ?? [] ).forEach( matcher => {
			if ( 'datetime' === matcher.field_type && 'custom' === dateModes[ matcher.id ] ) {
				lost.push( matcher.label );
			}
		} );
		return lost;
	}, [ recipe, priority, composeMode, dateModes, vocab.conditions ] );

	const { confirmDialog: goalDialog, requestConfirm: requestGoalChange } = useConfirmDialog( {
		when: goalChangeLosses.length > 0,
		title: __( 'Change goal?', 'newspack-plugin' ),
		confirmButtonText: __( 'Change Goal', 'newspack-plugin' ),
		message: (
			<>
				<p>
					{ __(
						'A named goal presets its own eligibility, price locking, products and cycle counting. These Custom settings do not carry over:',
						'newspack-plugin'
					) }
				</p>
				<ul>
					{ goalChangeLosses.map( label => (
						<li key={ label }>{ label }</li>
					) ) }
				</ul>
				<p>
					{ __(
						'Your pricing, steps and dates stay as they are, and the name follows the goal until you write your own.',
						'newspack-plugin'
					) }
				</p>
			</>
		),
	} );

	const requestGoal = ( next: PricingPath ) => {
		if ( next === path ) {
			return;
		}
		requestGoalChange( () => choosePath( next ) );
	};

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
			b.steps = steps.map( s => ( { at: Number( s.at ) || 1, calc_type: s.calc_type, value: Number( s.value ) || 0, label: s.label } ) );
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
		// schedule model rejects a price with no value and refuses to save with none;
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
		if ( isSchedule && ! steps.length ) {
			addNotice( {
				message: __( 'Add at least one price.', 'newspack-plugin' ),
				type: 'error',
				id: 'pricing-rule-steps',
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
			body.strategy_id = 'stepped_by_cycle';
			body.steps = steps.map( s => ( {
				at: Number( s.at ) || 1,
				calc_type: s.calc_type,
				value: Number( s.value ) || 0,
				label: s.label,
			} ) );
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

	const goalDescription = [
		__( 'The goal presets who qualifies, whether the price locks in at purchase, and which products the rule covers.', 'newspack-plugin' ),
		! isNew && __( 'It is set when the rule is created; create a new rule to use a different one.', 'newspack-plugin' ),
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className="newspack-pricing-rules__form">
			<div className="newspack-pricing-rules__goal-section">
				<SectionHeader title={ __( 'Goal', 'newspack-plugin' ) } description={ goalDescription } noMargin />
				<GoalCards selected={ isPricingPath( path ) ? path : null } onSelect={ requestGoal } disabled={ ! isNew || isSaving } />
			</div>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Rule Details', 'newspack-plugin' ) }
					description={ __( 'Its name and status, and which products it applies to.', 'newspack-plugin' ) }
					noMargin
				/>
				<VStack spacing={ 6 } className="newspack-pricing-rules__details">
					{ recipe?.isCustom && (
						<TextControl
							label={ __( 'Goal note', 'newspack-plugin' ) }
							help={ __( "Optional. Describe this rule's goal in your own words.", 'newspack-plugin' ) }
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
					<ToggleGroupControl
						label={ __( 'Status', 'newspack-plugin' ) }
						value={ status }
						onChange={ v => setStatus( v === 'publish' ? 'publish' : 'draft' ) }
						isBlock
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="publish" label={ __( 'Published', 'newspack-plugin' ) } />
						<ToggleGroupControlOption value="draft" label={ __( 'Draft', 'newspack-plugin' ) } />
					</ToggleGroupControl>
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
					{ ! isNew && rule && (
						<ReadonlyField
							id="newspack-pricing-rule-deal-id"
							label={ __( 'Deal ID', 'newspack-plugin' ) }
							help={ __( 'Use this ID to find the deal in your analytics. It never changes.', 'newspack-plugin' ) }
							value={ rule.deal_key }
							isMonospace
						>
							<CopyDealId dealKey={ rule.deal_key } />
						</ReadonlyField>
					) }
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
						onDateModeChange={ ( id, mode ) => setDateModes( prev => ( { ...prev, [ id ]: mode } ) ) }
						path={ path }
					/>
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
							options={
								[
									{ label: __( 'Best price wins (default)', 'newspack-plugin' ), value: 'min' },
									{ label: __( 'This rule only (stop checking others)', 'newspack-plugin' ), value: 'priority_exclusive' },
								] as { label: string; value: PricingRuleRow[ 'compose_mode' ] }[]
							}
							onChange={ setComposeMode }
							__next40pxDefaultSize
						/>
					) }
					<VStack spacing={ 2 }>
						<Grid columns={ 2 } gutter={ 16 } noMargin>
							<DateTimeField
								id="newspack-pricing-rule-active-from"
								label={ __( 'Starts', 'newspack-plugin' ) }
								describedBy={ SCHEDULE_HELP_ID }
								value={ activeFrom }
								placeholder={ __( 'Active immediately', 'newspack-plugin' ) }
								disabled={ isSaving }
								onChange={ setActiveFrom }
							/>
							<DateTimeField
								id="newspack-pricing-rule-active-until"
								label={ __( 'Ends', 'newspack-plugin' ) }
								describedBy={ SCHEDULE_HELP_ID }
								value={ activeUntil }
								placeholder={ __( 'No end date', 'newspack-plugin' ) }
								disabled={ isSaving }
								onChange={ setActiveUntil }
							/>
						</Grid>
						<p className="newspack-pricing-rules__muted newspack-pricing-rules__muted--help" id={ SCHEDULE_HELP_ID }>
							{ __( 'Times are in your local timezone.', 'newspack-plugin' ) }
						</p>
					</VStack>
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<VStack spacing={ 6 }>
				<Grid columns={ 2 } gutter={ 32 } noMargin>
					<SectionHeader
						title={ __( 'Pricing', 'newspack-plugin' ) }
						description={ __(
							'What matching products cost under this rule, how long that price holds, and whether readers are told why.',
							'newspack-plugin'
						) }
						noMargin
					/>
					<VStack spacing={ 6 }>
						{ hasModelChoice && (
							<ToggleGroupControl
								label={ __( 'Pricing model', 'newspack-plugin' ) }
								help={ strategyHelp( strategyId, vocab.strategies.find( s => s.id === strategyId )?.label ?? '' ) }
								value={ strategyId }
								onChange={ v => {
									// The server keeps the saved strategy on update; do not let the form drift from it.
									if ( isNew ) {
										setStrategyId( String( v ) );
									}
								} }
								isBlock
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							>
								{ vocab.strategies.map( s => (
									<ToggleGroupControlOption
										key={ s.id }
										value={ s.id }
										label={ strategyShortLabel( s.id, s.label ) }
										disabled={ ! isNew }
									/>
								) ) }
							</ToggleGroupControl>
						) }

						<ToggleGroupControl
							label={ __( 'Pricing details', 'newspack-plugin' ) }
							help={ publicizeHelp( publicize ) }
							value={ publicize ? 'shown' : 'hidden' }
							onChange={ v => setPublicize( 'shown' === v ) }
							isBlock
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						>
							<ToggleGroupControlOption value="shown" label={ __( 'Shown', 'newspack-plugin' ) } />
							<ToggleGroupControlOption value="hidden" label={ __( 'Hidden', 'newspack-plugin' ) } />
						</ToggleGroupControl>

						{ ! isSchedule && (
							<>
								<SelectControl
									label={ __( 'Calculation', 'newspack-plugin' ) }
									help={ calcTypeHelp( calcType, vocab.calc_types.find( c => c.value === calcType )?.label ?? '' ) }
									value={ calcType }
									// A saved rule can name a calculation the vocabulary no longer
									// offers; listing it keeps the control showing what the rule is.
									options={ [
										...vocab.calc_types.map( c => ( { label: c.label, value: c.value } ) ),
										...( calcType && ! vocab.calc_types.some( c => c.value === calcType )
											? [ { label: calcType, value: calcType } ]
											: [] ),
									] }
									onChange={ setCalcType }
									__next40pxDefaultSize
								/>
								<TextControl
									label={ valueLabel( calcType, vocab.currency.symbol ) }
									help={ valueHelp( calcType ) }
									type="number"
									value={ value }
									onChange={ setValue }
									__next40pxDefaultSize
								/>
								{ publicize && (
									<TextControl
										label={ __( 'Name shown to reader', 'newspack-plugin' ) }
										help={ __( 'Optional. Shown on the product page, cart, and checkout.', 'newspack-plugin' ) }
										value={ simpleLabel }
										onChange={ setSimpleLabel }
										__next40pxDefaultSize
									/>
								) }
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
						{ recipe?.isCustom && (
							<ToggleControl
								label={ __( 'Lock pricing at purchase', 'newspack-plugin' ) }
								help={ __(
									'On: subscribers keep the price they bought at — the rule only applies to new sign-ups. Off: the rule applies to every matching subscriber at each renewal.',
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
					</VStack>
				</Grid>
				{ isSchedule && (
					<SchedulePrices
						steps={ steps }
						onChange={ setSteps }
						publicize={ publicize }
						calcTypes={ vocab.calc_types }
						currency={ vocab.currency }
					/>
				) }
			</VStack>

			{ hasPrice && (
				<>
					<Divider alignment="full-width" variant="tertiary" />

					<div>
						<VStack spacing={ 0 }>
							<SectionHeader
								title={ __( 'Impact Preview', 'newspack-plugin' ) }
								description={ __(
									'What a reader would actually pay, with your other active rules taken into account. Where several rules match, the lowest price wins.',
									'newspack-plugin'
								) }
								noMargin
							/>
							{ hasCycleMarkers && <p className="newspack-pricing-rules__muted">{ cycleMarkerNote() }</p> }
						</VStack>
						<RulePreview body={ previewBody } showCycleNote={ ! hasCycleMarkers } />
					</div>
				</>
			) }
			{ goalDialog }
		</div>
	);
}
