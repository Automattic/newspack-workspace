/**
 * Restriction editor — drawer flow for adding or editing a subscriber-only
 * products restriction: which subscriptions unlock purchasing of which store
 * products. `rule` is `{}` for a new restriction (optionally with
 * `subscriptions` pre-filled from a subscription row's kebab) or an existing
 * restriction to edit. Mirrors DiscountRuleFlow's drawer scaffolding.
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	FormTokenField,
} from '@wordpress/components';
import { close } from '@wordpress/icons';
import { Button, Modal } from '../../../../packages/components/src';
import { CATEGORIES } from '../data/mock-catalog';
import { productsForRule } from '../data/targeting';
import { saveRestriction } from '../data/mock-restrictions';
import { DIGITAL_PLANS, PRINT_PLANS } from '../data/mock-subscribers';
import { TEAM_PLANS } from '../data/mock-groups';
import { GROUP_LABEL } from '../labels';
import { fmtCurrency } from '../format';
import ConfirmFlow from './ConfirmFlow';
import TargetingFields from './TargetingFields';

// Every subscription, in list order, tagged "(Group)" for team plans — same
// treatment as the discount editor.
const ALL_PLANS = [ ...DIGITAL_PLANS, ...PRINT_PLANS, ...TEAM_PLANS ];
const isGroupPlan = name => TEAM_PLANS.some( p => p.name === name );
const planLabel = name => ( isGroupPlan( name ) ? `${ name } (${ GROUP_LABEL })` : name );
// Tokens display the tagged label, so map labels back to plan names on change.
const LABEL_TO_NAME = {};
ALL_PLANS.forEach( p => {
	LABEL_TO_NAME[ planLabel( p.name ) ] = p.name;
} );

// The saved shape of a restriction, normalized so a mode switch before saving
// doesn't persist stale ids. Shared by the draft and the dirty comparison.
function restrictionShape( { subscriptions, targeting, productIds, category, excludedIds } ) {
	return {
		subscriptions,
		targeting,
		productIds: targeting === 'products' ? productIds : [],
		category: targeting === 'category' ? category : null,
		excludedIds: targeting === 'products' ? [] : excludedIds,
	};
}

export default function RestrictionFlow( { rule, onClose, onSaved } ) {
	const isEdit = Boolean( rule.id );
	// Adding from a subscription's row pre-fills that subscription, but the
	// field stays editable — unlike the discount editor's locked audience —
	// because a restriction can be unlocked by several subscriptions.
	const isScopedAdd = ! isEdit && ( rule.subscriptions || [] ).length > 0;

	const [ subscriptions, setSubscriptions ] = useState( rule.subscriptions || [] );
	const [ targeting, setTargeting ] = useState( rule.targeting || 'products' );
	const [ productIds, setProductIds ] = useState( rule.productIds || [] );
	const [ category, setCategory ] = useState( rule.category || CATEGORIES[ 0 ] );
	const [ excludedIds, setExcludedIds ] = useState( rule.excludedIds || [] );
	const [ busy, setBusy ] = useState( false );
	const [ confirmDiscard, setConfirmDiscard ] = useState( false );

	const onSubscriptionsChange = labels => {
		setSubscriptions( labels.map( label => LABEL_TO_NAME[ label ] ).filter( Boolean ) );
	};

	const onTargetChange = patch => {
		if ( patch.targeting !== undefined ) {
			setTargeting( patch.targeting );
		}
		if ( patch.productIds !== undefined ) {
			setProductIds( patch.productIds );
		}
		if ( patch.category !== undefined ) {
			setCategory( patch.category );
		}
		if ( patch.excludedIds !== undefined ) {
			setExcludedIds( patch.excludedIds );
		}
	};

	// Drop exclusions no longer in scope (e.g. left over after switching
	// targeting or category) so the saved restriction never counts products the
	// editor isn't showing.
	const scopeProducts = productsForRule( { targeting, productIds, category, excludedIds: [] } );
	const scopedExcludedIds = excludedIds.filter( id => scopeProducts.some( p => p.id === id ) );

	const draft = restrictionShape( { subscriptions, targeting, productIds, category, excludedIds: scopedExcludedIds } );
	const products = productsForRule( draft );

	// What the restriction looked like when the editor opened; Save stays
	// disabled until the draft differs from it (and is valid).
	const initial = restrictionShape( {
		subscriptions: rule.subscriptions || [],
		targeting: rule.targeting || 'products',
		productIds: rule.productIds || [],
		category: rule.category || CATEGORIES[ 0 ],
		excludedIds: rule.excludedIds || [],
	} );
	const isDirty = JSON.stringify( draft ) !== JSON.stringify( initial );
	const isValid = subscriptions.length > 0 && ( targeting !== 'products' || productIds.length > 0 );
	const canSave = isDirty && isValid;

	const onSave = () => {
		setBusy( true );
		setTimeout( () => {
			saveRestriction( { ...rule, ...draft } );
			onSaved( rule.id ? __( 'Restriction updated.', 'newspack-plugin' ) : __( 'Restriction added.', 'newspack-plugin' ) );
		}, 700 );
	};

	// Guard an unsaved close behind a discard confirmation. Blocked while busy
	// so a discard during the in-flight save's timeout can't unmount the flow
	// out from under the pending saveRestriction()/onSaved() call.
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

	let title = __( 'Add restriction', 'newspack-plugin' );
	if ( isEdit ) {
		title = __( 'Edit restriction', 'newspack-plugin' );
	} else if ( isScopedAdd ) {
		title = sprintf(
			// translators: %s: subscription name, e.g. "Education Annual (Group)".
			__( 'Add subscriber-only products to %s', 'newspack-plugin' ),
			planLabel( rule.subscriptions[ 0 ] )
		);
	}

	const content = (
		<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
			<VStack spacing={ 2 }>
				<FormTokenField
					label={ __( 'Available to', 'newspack-plugin' ) }
					value={ subscriptions.map( planLabel ) }
					suggestions={ ALL_PLANS.map( p => planLabel( p.name ) ) }
					onChange={ onSubscriptionsChange }
					__experimentalExpandOnFocus
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<span className="newspack-subscribers-demo__muted">
					{ __( 'Subscribers of any of these subscriptions can purchase the products.', 'newspack-plugin' ) }
				</span>
			</VStack>
			<TargetingFields
				value={ { targeting, productIds, category, excludedIds } }
				onChange={ onTargetChange }
				appliesHelp={ __( 'Choose which products are subscriber-only.', 'newspack-plugin' ) }
				categoryHelp={ __( 'Every product in this category becomes subscriber-only.', 'newspack-plugin' ) }
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
						{ previewRows.map( p => (
							<tr key={ p.id }>
								<td>{ p.name }</td>
								<td>{ fmtCurrency( p.price ) }</td>
							</tr>
						) ) }
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
