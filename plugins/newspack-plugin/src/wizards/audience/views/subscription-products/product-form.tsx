/**
 * Full-page create/edit form for a plan, rendered as a routed wizard section (mirroring the
 * institutions editor). Save / back live in the wizard header; the body is a 2-column Grid
 * (SectionHeader left, fields right). Covers simple, variable (plan repeater), grouped
 * (bundle picker), and one-time, plus category + availability + group subscription. POSTs to
 * create or PUTs to update, then returns to the list. Type is locked when editing.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	TextControl,
	SelectControl,
	CheckboxControl,
	FormTokenField,
	Notice,
	Flex,
	FlexBlock,
	FlexItem,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Badge, Grid, SectionHeader, Divider } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { PolicyChips, EffectivePrice } from './policy-cells';

const BASE_PATH = '/newspack/v1/wizard/newspack-audience-subscription-products/products';
const CONVENTION_SLUGS = [ 'private-subscriptions', 'free-subscriptions' ];

const PERIOD_OPTIONS = [
	{ label: __( 'day', 'newspack-plugin' ), value: 'day' },
	{ label: __( 'week', 'newspack-plugin' ), value: 'week' },
	{ label: __( 'month', 'newspack-plugin' ), value: 'month' },
	{ label: __( 'year', 'newspack-plugin' ), value: 'year' },
];

type ProductType = 'subscription' | 'variable-subscription' | 'grouped' | 'simple';
type PlanDraft = { id?: number; label: string; price: string; period: string; interval: string; existing: boolean };

const newPlan = ( label = '', period = 'month' ): PlanDraft => ( { label, price: '', period, interval: '1', existing: false } );

export default function ProductForm( {
	mode,
	initial,
	categories,
	bundleOptions,
	currency,
	onDone,
}: {
	mode: 'create' | 'edit';
	initial?: SubscriptionProduct;
	categories: { id: number; label: string }[];
	bundleOptions: { id: number; label: string }[];
	currency: SubscriptionProductsCurrency;
	onDone: () => void;
} ) {
	const isEdit = mode === 'edit' && !! initial;
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ name, setName ] = useState( initial?.name ?? '' );
	const [ type, setType ] = useState< ProductType >( ( initial?.type as ProductType ) ?? 'subscription' );
	const [ status, setStatus ] = useState( initial?.status === 'draft' ? 'draft' : 'publish' );
	const [ isDonation, setIsDonation ] = useState( initial?.is_donation ?? false );
	const [ availability, setAvailability ] = useState( initial?.availability ?? 'public' );

	// Categories as names (mapped to IDs on submit); convention categories are managed by
	// the availability picker, so they're excluded here.
	const [ categoryNames, setCategoryNames ] = useState< string[] >(
		( initial?.categories ?? [] ).filter( cat => ! CONVENTION_SLUGS.includes( cat.slug ) ).map( cat => cat.name )
	);

	// Simple fields.
	const [ price, setPrice ] = useState(
		initial && ( initial.type === 'subscription' || initial.type === 'simple' ) && initial.base_price !== null ? String( initial.base_price ) : ''
	);
	const [ period, setPeriod ] = useState( initial?.period || 'month' );
	const [ interval, setInterval ] = useState( initial?.interval ? String( initial.interval ) : '1' );

	// Variable plans.
	const [ plans, setPlans ] = useState< PlanDraft[] >(
		initial && initial.type === 'variable-subscription'
			? initial.variations.map( variation => ( {
					id: variation.id,
					label: variation.plan_label || variation.name,
					price: variation.base_price !== null ? String( variation.base_price ) : '',
					period: variation.period || 'month',
					interval: String( variation.interval || 1 ),
					existing: true,
			  } ) )
			: [ newPlan( __( 'Monthly', 'newspack-plugin' ), 'month' ), newPlan( __( 'Annual', 'newspack-plugin' ), 'year' ) ]
	);

	// Group subscription (applies to product / all plans).
	const [ groupEnabled, setGroupEnabled ] = useState( initial?.is_group_subscription ?? false );
	const [ groupLimit, setGroupLimit ] = useState( initial && initial.group_member_limit > 0 ? String( initial.group_member_limit ) : '0' );

	// Grouped bundle.
	const [ bundled, setBundled ] = useState< number[] >( initial?.bundled_products?.map( b => b.id ) ?? [] );

	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const updatePlan = ( index: number, key: keyof PlanDraft, value: string ) =>
		setPlans( current => current.map( ( plan, i ) => ( i === index ? { ...plan, [ key ]: value } : plan ) ) );
	const addPlan = () => setPlans( current => [ ...current, newPlan() ] );
	const removePlan = ( index: number ) => setPlans( current => current.filter( ( _, i ) => i !== index ) );
	const toggleBundled = ( id: number, checked: boolean ) =>
		setBundled( current => ( checked ? [ ...current, id ] : current.filter( existing => existing !== id ) ) );

	const submit = useCallback( () => {
		setError( '' );
		if ( ! name.trim() ) {
			setError( __( 'Name is required.', 'newspack-plugin' ) );
			return;
		}

		const categoryIds = categoryNames
			.map( label => categories.find( cat => cat.label === label )?.id )
			.filter( ( id ): id is number => typeof id === 'number' );

		const groupFields = { is_group_subscription: groupEnabled, group_member_limit: Number( groupLimit ) || 0 };
		const payload: Record< string, unknown > = {
			name: name.trim(),
			type,
			status,
			is_donation: isDonation,
			availability,
			category_ids: categoryIds,
		};

		if ( type === 'grouped' ) {
			if ( ! bundled.length ) {
				setError( __( 'Select at least one subscription to bundle.', 'newspack-plugin' ) );
				return;
			}
			payload.bundled_product_ids = bundled;
		} else if ( type === 'variable-subscription' ) {
			if ( plans.some( plan => ( ! plan.existing && ! plan.label.trim() ) || plan.price === '' ) ) {
				setError( __( 'Each plan needs a label and a price.', 'newspack-plugin' ) );
				return;
			}
			payload.variations = plans.map( plan => ( {
				...( plan.id ? { id: plan.id } : {} ),
				label: plan.label.trim(),
				price: Number( plan.price ),
				period: plan.period,
				interval: Number( plan.interval ) || 1,
				...groupFields,
			} ) );
		} else if ( type === 'simple' ) {
			if ( price === '' ) {
				setError( __( 'A price is required.', 'newspack-plugin' ) );
				return;
			}
			payload.price = Number( price );
		} else {
			if ( price === '' ) {
				setError( __( 'A price is required.', 'newspack-plugin' ) );
				return;
			}
			payload.price = Number( price );
			payload.period = period;
			payload.interval = Number( interval ) || 1;
			Object.assign( payload, groupFields );
		}

		setIsSaving( true );
		apiFetch< { id: number; name: string } >( {
			path: isEdit ? `${ BASE_PATH }/${ initial?.id }` : BASE_PATH,
			method: isEdit ? 'PUT' : 'POST',
			data: payload,
		} )
			.then( response => {
				addNotice( {
					/* translators: %s is the plan name. */
					message: sprintf( __( '“%s” saved.', 'newspack-plugin' ), response.name || name.trim() ),
					type: 'success',
					id: 'subscription-product-saved',
				} );
				onDone();
			} )
			.catch( ( e: { message?: string } ) => {
				setError( e.message || __( 'Failed to save changes.', 'newspack-plugin' ) );
				setIsSaving( false );
			} );
	}, [
		name,
		type,
		status,
		isDonation,
		availability,
		categoryNames,
		categories,
		groupEnabled,
		groupLimit,
		bundled,
		plans,
		price,
		period,
		interval,
		isEdit,
		initial,
		onDone,
		addNotice,
	] );

	// Drive the wizard header: back-nav to the list, breadcrumb, and the Save action.
	useEffect( () => {
		setHeaderData( {
			backNav: '#/',
			sectionName: isEdit ? __( 'Edit plan', 'newspack-plugin' ) : __( 'Add plan', 'newspack-plugin' ),
			actions: [
				{
					type: 'primary',
					label: isEdit ? __( 'Save changes', 'newspack-plugin' ) : __( 'Create plan', 'newspack-plugin' ),
					icon: null,
					action: submit,
					disabled: isSaving,
				},
			],
		} );
	}, [ isEdit, isSaving, submit, setHeaderData ] );

	return (
		<div className="newspack-subscription-products__edit">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader
					title={ __( 'Plan details', 'newspack-plugin' ) }
					description={ __( 'The name, type, and publish status of this plan.', 'newspack-plugin' ) }
				/>
				<VStack spacing={ 4 }>
					<TextControl label={ __( 'Name', 'newspack-plugin' ) } value={ name } onChange={ setName } __next40pxDefaultSize />
					<HStack alignment="flex-start" spacing={ 4 }>
						<FlexBlock>
							<SelectControl
								label={ __( 'Type', 'newspack-plugin' ) }
								value={ type }
								options={
									type === 'simple'
										? [ { label: __( 'One-time', 'newspack-plugin' ), value: 'simple' } ]
										: [
												{ label: __( 'Simple subscription', 'newspack-plugin' ), value: 'subscription' },
												{ label: __( 'Variable subscription', 'newspack-plugin' ), value: 'variable-subscription' },
												{ label: __( 'Plan bundle (switching)', 'newspack-plugin' ), value: 'grouped' },
										  ]
								}
								onChange={ value => setType( value as ProductType ) }
								disabled={ isEdit }
								help={ isEdit ? __( 'Type can’t be changed after creation.', 'newspack-plugin' ) : undefined }
								__next40pxDefaultSize
							/>
						</FlexBlock>
						<FlexBlock>
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
						</FlexBlock>
					</HStack>
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Pricing', 'newspack-plugin' ) }
					description={
						type === 'grouped'
							? __( 'The subscriptions readers can switch between in this bundle.', 'newspack-plugin' )
							: __( 'How much this plan costs and how often it bills.', 'newspack-plugin' )
					}
				/>
				<VStack spacing={ 4 }>
					{ type === 'subscription' && (
						<HStack alignment="flex-start" spacing={ 4 }>
							<FlexBlock>
								<TextControl
									label={ __( 'Price', 'newspack-plugin' ) }
									type="number"
									min="0"
									value={ price }
									onChange={ setPrice }
									__next40pxDefaultSize
								/>
							</FlexBlock>
							<FlexBlock>
								<TextControl
									label={ __( 'Bill every', 'newspack-plugin' ) }
									type="number"
									min="1"
									max="6"
									value={ interval }
									onChange={ setInterval }
									__next40pxDefaultSize
								/>
							</FlexBlock>
							<FlexBlock>
								<SelectControl
									label={ __( 'Period', 'newspack-plugin' ) }
									value={ period }
									options={ PERIOD_OPTIONS }
									onChange={ setPeriod }
									__next40pxDefaultSize
								/>
							</FlexBlock>
						</HStack>
					) }

					{ type === 'simple' && (
						<TextControl
							label={ __( 'Price (one-time)', 'newspack-plugin' ) }
							type="number"
							min="0"
							value={ price }
							onChange={ setPrice }
							__next40pxDefaultSize
						/>
					) }

					{ type === 'variable-subscription' && (
						<>
							{ plans.map( ( plan, index ) => (
								<VStack key={ plan.id ?? `new-${ index }` } className="newspack-subscription-products__plan" spacing={ 2 }>
									<Flex align="flex-end" gap={ 2 }>
										<FlexBlock>
											<TextControl
												label={ __( 'Plan label', 'newspack-plugin' ) }
												value={ plan.label }
												onChange={ value => updatePlan( index, 'label', value ) }
												disabled={ plan.existing }
												help={ plan.existing ? __( 'Existing plan', 'newspack-plugin' ) : undefined }
												__next40pxDefaultSize
											/>
										</FlexBlock>
										<FlexItem>
											<Button
												variant="tertiary"
												isDestructive
												onClick={ () => removePlan( index ) }
												disabled={ plans.length <= 1 }
											>
												{ __( 'Remove', 'newspack-plugin' ) }
											</Button>
										</FlexItem>
									</Flex>
									<HStack alignment="flex-start" spacing={ 2 }>
										<FlexBlock>
											<TextControl
												label={ __( 'Price', 'newspack-plugin' ) }
												type="number"
												min="0"
												value={ plan.price }
												onChange={ value => updatePlan( index, 'price', value ) }
												__next40pxDefaultSize
											/>
										</FlexBlock>
										<FlexBlock>
											<TextControl
												label={ __( 'Every', 'newspack-plugin' ) }
												type="number"
												min="1"
												max="6"
												value={ plan.interval }
												onChange={ value => updatePlan( index, 'interval', value ) }
												__next40pxDefaultSize
											/>
										</FlexBlock>
										<FlexBlock>
											<SelectControl
												label={ __( 'Period', 'newspack-plugin' ) }
												value={ plan.period }
												options={ PERIOD_OPTIONS }
												onChange={ value => updatePlan( index, 'period', value ) }
												__next40pxDefaultSize
											/>
										</FlexBlock>
									</HStack>
								</VStack>
							) ) }
							<div>
								<Button variant="secondary" onClick={ addPlan }>
									{ __( 'Add plan', 'newspack-plugin' ) }
								</Button>
							</div>
						</>
					) }

					{ type === 'grouped' && (
						<div className="newspack-subscription-products__bundle-picker">
							{ bundleOptions.length ? (
								bundleOptions.map( option => (
									<CheckboxControl
										key={ option.id }
										label={ option.label }
										checked={ bundled.includes( option.id ) }
										onChange={ checked => toggleBundled( option.id, checked ) }
									/>
								) )
							) : (
								<span className="newspack-subscription-products__muted">
									{ __( 'No subscriptions to bundle yet.', 'newspack-plugin' ) }
								</span>
							) }
						</div>
					) }
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Availability & categories', 'newspack-plugin' ) }
					description={ __( 'Where and to whom this plan is offered.', 'newspack-plugin' ) }
				/>
				<VStack spacing={ 4 }>
					<SelectControl
						label={ __( 'Availability', 'newspack-plugin' ) }
						value={ availability }
						options={ [
							{ label: __( 'Public', 'newspack-plugin' ), value: 'public' },
							{ label: __( 'Private', 'newspack-plugin' ), value: 'private' },
							{ label: __( 'Free', 'newspack-plugin' ), value: 'free' },
						] }
						onChange={ value => setAvailability( value as SubscriptionProduct[ 'availability' ] ) }
						help={ __( 'Private/Free file the plan under the matching category.', 'newspack-plugin' ) }
						__next40pxDefaultSize
					/>
					<FormTokenField
						label={ __( 'Categories', 'newspack-plugin' ) }
						value={ categoryNames }
						suggestions={ categories.map( cat => cat.label ) }
						onChange={ tokens => setCategoryNames( tokens as string[] ) }
						__experimentalExpandOnFocus
						__next40pxDefaultSize
					/>
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Options', 'newspack-plugin' ) }
					description={ __( 'Multi-seat access and donation handling.', 'newspack-plugin' ) }
				/>
				<VStack spacing={ 4 }>
					{ ( type === 'subscription' || type === 'variable-subscription' ) && (
						<VStack spacing={ 2 }>
							<CheckboxControl
								label={ __( 'Group subscription (multi-seat)', 'newspack-plugin' ) }
								help={ __( 'Let one purchase grant access to multiple members.', 'newspack-plugin' ) }
								checked={ groupEnabled }
								onChange={ setGroupEnabled }
							/>
							{ groupEnabled && (
								<TextControl
									label={ __( 'Member limit (0 = unlimited)', 'newspack-plugin' ) }
									type="number"
									min="0"
									value={ groupLimit }
									onChange={ setGroupLimit }
									__next40pxDefaultSize
								/>
							) }
						</VStack>
					) }
					<CheckboxControl label={ __( 'This is a donation', 'newspack-plugin' ) } checked={ isDonation } onChange={ setIsDonation } />
				</VStack>
			</Grid>

			{ isEdit && initial && ( initial.unlocks.length > 0 || initial.policy.policies.length > 0 ) && (
				<>
					<Divider alignment="full-width" variant="tertiary" />
					<Grid columns={ 2 } gutter={ 32 } noMargin>
						<SectionHeader
							title={ __( 'Policies & access', 'newspack-plugin' ) }
							description={ __( 'Read-only: pricing policies applied to this plan and the content it unlocks.', 'newspack-plugin' ) }
						/>
						<VStack spacing={ 4 }>
							{ initial.policy.policies.length > 0 && (
								<div>
									<span className="newspack-subscription-products__modal-label">
										{ __( 'Applied policies', 'newspack-plugin' ) }
									</span>
									<HStack alignment="center" spacing={ 3 } justify="flex-start">
										<PolicyChips policy={ initial.policy } />
										<EffectivePrice policy={ initial.policy } currency={ currency } />
									</HStack>
								</div>
							) }
							{ initial.unlocks.length > 0 && (
								<div>
									<span className="newspack-subscription-products__modal-label">{ __( 'Unlocks', 'newspack-plugin' ) }</span>
									<div className="newspack-subscription-products__unlocks">
										{ initial.unlocks.map( gate => (
											<Badge key={ gate.id } level="default" text={ gate.title } />
										) ) }
									</div>
								</div>
							) }
						</VStack>
					</Grid>
				</>
			) }
		</div>
	);
}
