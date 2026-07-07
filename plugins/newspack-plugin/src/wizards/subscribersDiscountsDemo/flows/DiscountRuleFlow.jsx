/**
 * Discount rule editor — two-step "who and what, then how much" flow for
 * adding or editing a member product-discount rule. `rule` is `{}` for a new
 * rule or an existing rule object to edit. Mirrors AddSubscriptionFlow's
 * method → details shape via the shared useTwoStep/StepButtons scaffolding.
 */

import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	FormTokenField,
	TextControl,
} from '@wordpress/components';
import { Modal, SelectControl } from '../../../../packages/components/src';
import { CATEGORIES, PRODUCTS, getProductById } from '../data/mock-catalog';
import { saveDiscount, memberPrice, productsForRule } from '../data/mock-discounts';
import { DIGITAL_PLANS, PRINT_PLANS } from '../data/mock-subscribers';
import { TEAM_PLANS } from '../data/mock-groups';
import { fmtCurrency } from '../format';
import { StepButtons, useTwoStep } from './steps';

// Same three-family grouping as AddSubscriptionFlow's CATALOG, so "Who gets
// it" reads the same way across flows.
const CATALOG = [
	{ label: __( 'Digital', 'newspack-plugin' ), plans: DIGITAL_PLANS },
	{ label: __( 'Print', 'newspack-plugin' ), plans: PRINT_PLANS },
	{ label: __( 'Team', 'newspack-plugin' ), plans: TEAM_PLANS },
];
const ALL_CATALOG_PLANS = CATALOG.flatMap( g => g.plans );

const round2 = n => Math.round( n * 100 ) / 100;

// The price a "set the member price" edit anchors against: the most common
// price among the matched products, first product wins a tie.
function anchorPrice( products ) {
	if ( ! products.length ) {
		return null;
	}
	const counts = new Map();
	products.forEach( p => counts.set( p.price, ( counts.get( p.price ) || 0 ) + 1 ) );
	let best = products[ 0 ].price;
	let bestCount = 0;
	products.forEach( p => {
		const count = counts.get( p.price );
		if ( count > bestCount ) {
			bestCount = count;
			best = p.price;
		}
	} );
	return best;
}

export default function DiscountRuleFlow( { rule, onClose, onSaved } ) {
	const isEdit = Boolean( rule.id );
	const step = useTwoStep();

	const [ audience, setAudience ] = useState( rule.audience || ALL_CATALOG_PLANS[ 0 ].name );
	const [ targeting, setTargeting ] = useState( rule.targeting || 'products' );
	const [ productIds, setProductIds ] = useState( rule.productIds || [] );
	const [ category, setCategory ] = useState( rule.category || CATEGORIES[ 0 ] );
	const [ excludedIds, setExcludedIds ] = useState( rule.excludedIds || [] );
	const [ type, setType ] = useState( rule.type || 'fixed' );
	// Amount and member price are linked but each holds its own raw input
	// string, so typing intermediate states ("", "12.") is never rewritten;
	// only the counterpart field recomputes.
	const [ amountInput, setAmountInput ] = useState( rule.amount !== undefined && rule.amount !== null ? String( rule.amount ) : '' );
	const [ memberPriceInput, setMemberPriceInput ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const onProductsChange = names => {
		setProductIds( names.map( name => PRODUCTS.find( p => p.name === name )?.id ).filter( Boolean ) );
	};

	// Products currently in scope with no exclusions applied yet, so the
	// exclusion picker only ever offers what could actually be excluded.
	const scopeProducts = productsForRule( { targeting, productIds, category, excludedIds: [] } );

	const onExcludedChange = names => {
		setExcludedIds( names.map( name => scopeProducts.find( p => p.name === name )?.id ).filter( Boolean ) );
	};

	// The saved shape only keeps the data its targeting mode uses, so a mode
	// switch before saving doesn't persist stale ids.
	const draft = {
		targeting,
		productIds: targeting === 'products' ? productIds : [],
		category: targeting === 'category' ? category : null,
		excludedIds: targeting === 'products' ? [] : excludedIds,
		type,
		amount: Number( amountInput ) || 0,
	};
	const products = productsForRule( draft );
	const anchor = anchorPrice( products );

	const onAmountChange = value => {
		setAmountInput( value );
		if ( anchor !== null ) {
			setMemberPriceInput( String( round2( Math.max( 0, anchor - ( Number( value ) || 0 ) ) ) ) );
		}
	};

	const onMemberPriceChange = value => {
		setMemberPriceInput( value );
		if ( anchor !== null ) {
			setAmountInput( String( round2( Math.max( 0, anchor - ( Number( value ) || 0 ) ) ) ) );
		}
	};

	// Re-anchor the member price whenever the matched products change (and on
	// mount, prefilling it for edits). The amount is the value being kept.
	useEffect( () => {
		if ( anchor !== null ) {
			setMemberPriceInput( String( round2( Math.max( 0, anchor - ( Number( amountInput ) || 0 ) ) ) ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ anchor ] );

	const onSave = () => {
		setBusy( true );
		setTimeout( () => {
			saveDiscount( { ...rule, audience, ...draft } );
			onSaved( rule.id ? __( 'Discount updated.', 'newspack-plugin' ) : __( 'Discount added.', 'newspack-plugin' ) );
		}, 700 );
	};

	let body;
	if ( step.isMethod ) {
		body = (
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
				<SelectControl
					label={ __( 'Who gets it', 'newspack-plugin' ) }
					value={ audience }
					options={ CATALOG.flatMap( g => g.plans.map( p => ( { label: `${ g.label } — ${ p.name }`, value: p.name } ) ) ) }
					onChange={ setAudience }
				/>
				<ToggleGroupControl
					label={ __( 'Applies to', 'newspack-plugin' ) }
					value={ targeting }
					onChange={ setTargeting }
					isBlock
					__nextHasNoMarginBottom
				>
					<ToggleGroupControlOption value="products" label={ __( 'Specific products', 'newspack-plugin' ) } />
					<ToggleGroupControlOption value="category" label={ __( 'Category', 'newspack-plugin' ) } />
					<ToggleGroupControlOption value="all" label={ __( 'All products', 'newspack-plugin' ) } />
				</ToggleGroupControl>
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
						value={ category }
						options={ CATEGORIES.map( c => ( { label: c, value: c } ) ) }
						onChange={ setCategory }
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
				<StepButtons
					leftLabel={ __( 'Cancel', 'newspack-plugin' ) }
					onLeft={ onClose }
					rightLabel={ __( 'Continue', 'newspack-plugin' ) }
					onRight={ step.toDetails }
					disabled={ targeting === 'products' && ! productIds.length }
				/>
			</VStack>
		);
	} else {
		const previewRows = products.slice( 0, 8 );
		const extra = products.length - previewRows.length;
		const canSave = draft.amount > 0 && ( targeting !== 'products' || productIds.length > 0 );

		body = (
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
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
					value={ amountInput }
					onChange={ onAmountChange }
				/>
				{ type === 'fixed' && anchor !== null && (
					<TextControl
						type="number"
						label={ __( 'Or set the member price', 'newspack-plugin' ) }
						value={ memberPriceInput }
						onChange={ onMemberPriceChange }
					/>
				) }
				{ products.length > 0 && (
					<table className="newspack-subscribers-demo__discount-preview">
						<thead>
							<tr>
								<th>{ __( 'Product', 'newspack-plugin' ) }</th>
								<th>{ __( 'Price', 'newspack-plugin' ) }</th>
								<th>{ __( 'Member price', 'newspack-plugin' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ previewRows.map( p => (
								<tr key={ p.id }>
									<td>{ p.name }</td>
									<td>{ fmtCurrency( p.price ) }</td>
									<td>{ fmtCurrency( memberPrice( p.price, draft ) ) }</td>
								</tr>
							) ) }
							{ extra > 0 && (
								<tr className="newspack-subscribers-demo__discount-preview-more">
									<td colSpan={ 3 }>
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
				<StepButtons
					leftLabel={ __( 'Back', 'newspack-plugin' ) }
					onLeft={ step.toMethod }
					rightLabel={ __( 'Save', 'newspack-plugin' ) }
					onRight={ onSave }
					busy={ busy }
					disabled={ busy || ! canSave }
				/>
			</VStack>
		);
	}

	return (
		<Modal
			title={ isEdit ? __( 'Edit discount', 'newspack-plugin' ) : __( 'Add discount', 'newspack-plugin' ) }
			onRequestClose={ onClose }
			size="small"
		>
			{ body }
		</Modal>
	);
}
