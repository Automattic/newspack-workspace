/**
 * Discount rule editor — two-step "who and what, then how much" flow for
 * adding or editing a subscriber product-discount rule. `rule` is `{}` for a new
 * rule or an existing rule object to edit. Mirrors AddSubscriptionFlow's
 * method → details shape via the shared useTwoStep/StepButtons scaffolding.
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	FormTokenField,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { close } from '@wordpress/icons';
import { Button, Modal, SelectControl } from '../../../../packages/components/src';
import { CATEGORIES, PRODUCTS, getProductById } from '../data/mock-catalog';
import { saveDiscount, subscriberPrice, productsForRule } from '../data/mock-discounts';
import { DIGITAL_PLANS, PRINT_PLANS } from '../data/mock-subscribers';
import { TEAM_PLANS } from '../data/mock-groups';
import { GROUP_LABEL } from '../labels';
import { fmtCurrency } from '../format';
import ConfirmFlow from './ConfirmFlow';

// Every subscription, in list order. Group (team) subscriptions are tagged
// "(Group)" — matching how the Subscribers demo marks them — while individual
// ones show a plain name.
const ALL_PLANS = [ ...DIGITAL_PLANS, ...PRINT_PLANS, ...TEAM_PLANS ];
const isGroupPlan = name => TEAM_PLANS.some( p => p.name === name );
const planLabel = name => ( isGroupPlan( name ) ? `${ name } (${ GROUP_LABEL })` : name );

// The saved shape of a rule's targeting/amount, normalized so a mode switch
// before saving doesn't persist stale ids. Shared by the draft and the "has it
// changed?" comparison.
function ruleShape( { audience, targeting, productIds, category, excludedIds, type, amount } ) {
	return {
		audience,
		targeting,
		productIds: targeting === 'products' ? productIds : [],
		category: targeting === 'category' ? category : null,
		excludedIds: targeting === 'products' ? [] : excludedIds,
		type,
		amount,
	};
}

export default function DiscountRuleFlow( { rule, onClose, onSaved } ) {
	const isEdit = Boolean( rule.id );
	// Adding from a subscription's row pre-scopes the audience: lock it in and
	// drop the picker, since the subscription is already chosen.
	const isScopedAdd = ! isEdit && Boolean( rule.audience );

	const [ audience, setAudience ] = useState( rule.audience || ALL_PLANS[ 0 ].name );
	const [ targeting, setTargeting ] = useState( rule.targeting || 'products' );
	const [ productIds, setProductIds ] = useState( rule.productIds || [] );
	const [ category, setCategory ] = useState( rule.category || CATEGORIES[ 0 ] );
	const [ excludedIds, setExcludedIds ] = useState( rule.excludedIds || [] );
	const [ type, setType ] = useState( rule.type || 'fixed' );
	// Amount and subscriber price are linked but each holds its own raw input
	// string, so typing intermediate states ("", "12.") is never rewritten;
	// only the counterpart field recomputes.
	const [ amountInput, setAmountInput ] = useState( rule.amount !== undefined && rule.amount !== null ? String( rule.amount ) : '' );
	const [ busy, setBusy ] = useState( false );
	const [ confirmDiscard, setConfirmDiscard ] = useState( false );

	const onProductsChange = names => {
		setProductIds( names.map( name => PRODUCTS.find( p => p.name === name )?.id ).filter( Boolean ) );
	};

	// Products currently in scope with no exclusions applied yet, so the
	// exclusion picker only ever offers what could actually be excluded.
	const scopeProducts = productsForRule( { targeting, productIds, category, excludedIds: [] } );

	const onExcludedChange = names => {
		setExcludedIds( names.map( name => scopeProducts.find( p => p.name === name )?.id ).filter( Boolean ) );
	};

	// Drop exclusions no longer in scope (e.g. left over after switching targeting
	// or category) so the saved rule and its "N excluded" label never count
	// products the editor isn't showing.
	const scopedExcludedIds = excludedIds.filter( id => scopeProducts.some( p => p.id === id ) );

	const draft = ruleShape( {
		audience,
		targeting,
		productIds,
		category,
		excludedIds: scopedExcludedIds,
		type,
		amount: Number( amountInput ) || 0,
	} );
	const products = productsForRule( draft );

	const onAmountChange = value => setAmountInput( value );

	// What the rule looked like when the editor opened; Save stays disabled until
	// the draft differs from it (and is valid), so a no-op edit can't be saved.
	const initial = ruleShape( {
		audience: rule.audience || ALL_PLANS[ 0 ].name,
		targeting: rule.targeting || 'products',
		productIds: rule.productIds || [],
		category: rule.category || CATEGORIES[ 0 ],
		excludedIds: rule.excludedIds || [],
		type: rule.type || 'fixed',
		amount: rule.amount ?? 0,
	} );
	const isDirty = JSON.stringify( draft ) !== JSON.stringify( initial );
	// A valid amount is finite and positive; a percentage can't exceed 100. Save
	// stays disabled (and saveDiscount never runs) until the amount clears these.
	const amountValue = Number( amountInput );
	const isValid =
		Number.isFinite( amountValue ) &&
		amountValue > 0 &&
		( type !== 'percent' || amountValue <= 100 ) &&
		( targeting !== 'products' || productIds.length > 0 );
	const canSave = isDirty && isValid;

	const onSave = () => {
		setBusy( true );
		setTimeout( () => {
			saveDiscount( { ...rule, ...draft } );
			onSaved( rule.id ? __( 'Discount updated.', 'newspack-plugin' ) : __( 'Discount added.', 'newspack-plugin' ) );
		}, 700 );
	};

	// Guard an unsaved close behind a discard confirmation. Blocked while busy so
	// a discard during the in-flight save's 700ms timeout can't unmount the flow
	// out from under the pending saveDiscount()/onSaved() call.
	const attemptClose = () => {
		if ( busy ) {
			return;
		}
		if ( isDirty ) {
			setConfirmDiscard( true );
		} else {
			onClose();
		}
	};

	const previewRows = products.slice( 0, 8 );
	const extra = products.length - previewRows.length;

	let title = __( 'Add discount', 'newspack-plugin' );
	if ( isEdit ) {
		title = __( 'Edit discount', 'newspack-plugin' );
	} else if ( isScopedAdd ) {
		title = sprintf(
			// translators: %s: subscription name, e.g. "Education Annual (Group)".
			__( 'Add subscriber discount to %s', 'newspack-plugin' ),
			planLabel( rule.audience )
		);
	}

	const content = (
		<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
			{ ! isScopedAdd && (
				<SelectControl
					label={ __( 'Subscription', 'newspack-plugin' ) }
					help={ __( 'Subscribers of this subscription get the discount.', 'newspack-plugin' ) }
					value={ audience }
					options={ ALL_PLANS.map( p => ( { label: planLabel( p.name ), value: p.name } ) ) }
					onChange={ setAudience }
					__next40pxDefaultSize
				/>
			) }
			<RadioControl
				label={ __( 'Applies to', 'newspack-plugin' ) }
				help={ __( 'Choose which store products this discount applies to.', 'newspack-plugin' ) }
				selected={ targeting }
				onChange={ setTargeting }
				options={ [
					{ value: 'products', label: __( 'Specific products', 'newspack-plugin' ) },
					{ value: 'category', label: __( 'Category', 'newspack-plugin' ) },
					{ value: 'all', label: __( 'All products', 'newspack-plugin' ) },
				] }
			/>
			{ targeting === 'products' && (
				<FormTokenField
					label={ __( 'Products', 'newspack-plugin' ) }
					value={ productIds.map( id => getProductById( id )?.name ).filter( Boolean ) }
					suggestions={ PRODUCTS.map( p => p.name ) }
					onChange={ onProductsChange }
					__experimentalExpandOnFocus
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			) }
			{ targeting === 'category' && (
				<SelectControl
					label={ __( 'Category', 'newspack-plugin' ) }
					help={ __( 'Subscribers get the discount on every product in this category.', 'newspack-plugin' ) }
					value={ category }
					options={ CATEGORIES.map( c => ( { label: c, value: c } ) ) }
					onChange={ setCategory }
					__next40pxDefaultSize
				/>
			) }
			{ targeting !== 'products' && (
				<FormTokenField
					label={ __( 'Excluded products', 'newspack-plugin' ) }
					value={ excludedIds.map( id => scopeProducts.find( p => p.id === id )?.name ).filter( Boolean ) }
					suggestions={ scopeProducts.map( p => p.name ) }
					onChange={ onExcludedChange }
					__experimentalExpandOnFocus
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			) }
			<ToggleGroupControl
				label={ __( 'Discount type', 'newspack-plugin' ) }
				value={ type }
				onChange={ setType }
				isBlock
				__nextHasNoMarginBottom
			>
				<ToggleGroupControlOption value="fixed" label={ __( 'Fixed amount', 'newspack-plugin' ) } />
				<ToggleGroupControlOption value="percent" label={ __( 'Percentage', 'newspack-plugin' ) } />
			</ToggleGroupControl>
			<TextControl
				type="number"
				label={ type === 'percent' ? __( 'Percentage off', 'newspack-plugin' ) : __( 'Amount off', 'newspack-plugin' ) }
				help={
					type === 'percent'
						? __( 'The percentage subscribers save on each product.', 'newspack-plugin' )
						: __( 'The amount subscribers save on each product.', 'newspack-plugin' )
				}
				value={ amountInput }
				onChange={ onAmountChange }
			/>
			{ products.length > 0 && (
				<table className="newspack-subscribers-demo__discount-preview">
					<thead>
						<tr>
							<th>{ __( 'Product', 'newspack-plugin' ) }</th>
							<th>{ __( 'Price', 'newspack-plugin' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ previewRows.map( p => {
							const discounted = subscriberPrice( p.price, draft );
							return (
								<tr key={ p.id }>
									<td>{ p.name }</td>
									<td>
										{ discounted < p.price ? (
											<>
												<del>{ fmtCurrency( p.price ) }</del> { fmtCurrency( discounted ) }
											</>
										) : (
											fmtCurrency( p.price )
										) }
									</td>
								</tr>
							);
						} ) }
						{ extra > 0 && (
							<tr className="newspack-subscribers-demo__discount-preview-more">
								<td colSpan={ 2 }>
									{ sprintf(
										// translators: %d: number of additional matching products not shown in the preview.
										_n( '…and %d more product', '…and %d more products', extra, 'newspack-plugin' ),
										extra
									) }
								</td>
							</tr>
						) }
					</tbody>
				</table>
			) }
		</VStack>
	);
	const footer = (
		<HStack spacing={ 2 } justify="flex-end">
			<Button variant="secondary" disabled={ busy } onClick={ attemptClose }>
				{ __( 'Cancel', 'newspack-plugin' ) }
			</Button>
			<Button variant="primary" isBusy={ busy } disabled={ busy || ! canSave } onClick={ onSave }>
				{ __( 'Save', 'newspack-plugin' ) }
			</Button>
		</HStack>
	);

	return (
		<>
			<Modal
				__experimentalHideHeader
				size="small"
				onRequestClose={ attemptClose }
				className="newspack-subscribers-demo__sub-detail-modal newspack-subscribers-demo__sub-detail-modal--form"
				overlayClassName="newspack-subscribers-demo__sub-detail-overlay"
			>
				<HStack className="newspack-subscribers-demo__sub-detail-header" spacing={ 2 } alignment="center">
					<h2 className="newspack-subscribers-demo__sub-detail-title">{ title }</h2>
					<Button icon={ close } size="small" label={ __( 'Close', 'newspack-plugin' ) } disabled={ busy } onClick={ attemptClose } />
				</HStack>
				<div className="newspack-subscribers-demo__sub-detail-content">{ content }</div>
				<div className="newspack-subscribers-demo__sub-detail-footer">{ footer }</div>
			</Modal>
			{ confirmDiscard && (
				<ConfirmFlow
					title={ __( 'Discard changes?', 'newspack-plugin' ) }
					confirmLabel={ __( 'Discard changes', 'newspack-plugin' ) }
					isDestructive
					onCancel={ () => setConfirmDiscard( false ) }
					onConfirm={ onClose }
				>
					{ __( 'You have unsaved changes that will be lost.', 'newspack-plugin' ) }
				</ConfirmFlow>
			) }
		</>
	);
}
