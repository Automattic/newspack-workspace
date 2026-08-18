/**
 * The subscriber discount editor: who gets the discount, what it covers, and
 * how much off — with a live preview of what each covered product will cost.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';
import {
	Notice,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Drawer } from '../../../../../../../packages/components/src';
import SearchTokenField from '../../components/search-token-field';
import TargetingFields from '../../components/targeting-fields';
import { SEARCH_ENDPOINTS, WIZARD_ENDPOINT } from '../../constants';
import { DISCOUNTS_ENDPOINT, PREVIEW_LIMIT } from './constants';
import { formatCurrency, isValidRule, subscriberPrice } from './discount';
import type { DiscountCurrency, DiscountRule, DiscountsPayload } from './types';
import type { ProductSearchItem } from '../../types';

const EMPTY_RULE: Omit< DiscountRule, 'id' | 'created_at' > = {
	subscription_product_ids: [],
	targeting: 'products',
	product_ids: [],
	category_ids: [],
	excluded_product_ids: [],
	discount_type: 'fixed',
	amount: 0,
	active: true,
};

interface EditorProps {
	isOpen: boolean;
	rule: DiscountRule | null;
	currency: DiscountCurrency;
	onSaved: ( payload: DiscountsPayload ) => void;
	onClose: () => void;
}

export default function DiscountEditor( { isOpen, rule, currency, onSaved, onClose }: EditorProps ) {
	const initial = useMemo( () => ( rule ? { ...rule } : { ...EMPTY_RULE } ), [ rule ] );
	const [ draft, setDraft ] = useState< Partial< DiscountRule > >( initial );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ previewProducts, setPreviewProducts ] = useState< ProductSearchItem[] >( [] );
	// The amount is held as the raw input string: deriving it from the numeric
	// value blanks the field the moment it is falsy, which makes any amount
	// below 1 impossible to type ("0" clears, then "." is NaN and clears again).
	const [ amountInput, setAmountInput ] = useState( rule?.amount ? String( rule.amount ) : '' );

	const [ wasOpen, setWasOpen ] = useState( isOpen );

	// The drawer stays mounted, so without this a reopen would show the previous
	// draft. Reset on the way open only: the rule is cleared on close, and
	// resetting then would blank the fields in view for the whole slide-out.
	if ( wasOpen !== isOpen ) {
		setWasOpen( isOpen );
		if ( isOpen ) {
			setDraft( initial );
			setAmountInput( rule?.amount ? String( rule.amount ) : '' );
			setError( '' );
		}
	}

	const update = ( partial: Partial< DiscountRule > ) => setDraft( current => ( { ...current, ...partial } ) );

	const isDirty = JSON.stringify( draft ) !== JSON.stringify( initial );
	const canSave = isDirty && isValidRule( draft ) && ! inFlight;

	// The preview only ever lists hand-picked products: resolving a whole
	// category to its products belongs on the server, and a rule covering the
	// entire store has nothing useful to enumerate.
	const previewIds = useMemo( () => ( 'products' === draft.targeting ? draft.product_ids ?? [] : [] ), [ draft.targeting, draft.product_ids ] );

	useEffect( () => {
		if ( ! previewIds.length ) {
			setPreviewProducts( [] );
			return;
		}
		apiFetch< ProductSearchItem[] >( {
			path: addQueryArgs( `${ WIZARD_ENDPOINT }/${ SEARCH_ENDPOINTS.products }`, { include: previewIds.join( ',' ), per_page: 100 } ),
		} )
			.then( items => setPreviewProducts( items || [] ) )
			.catch( () => setPreviewProducts( [] ) );
	}, [ previewIds ] );

	const save = () => {
		setInFlight( true );
		setError( '' );
		apiFetch< DiscountsPayload >( {
			path: DISCOUNTS_ENDPOINT,
			method: 'POST',
			data: draft,
		} )
			.then( onSaved )
			.catch( ( apiError: { message?: string } ) =>
				setError( apiError?.message || __( 'The discount could not be saved.', 'newspack-plugin' ) )
			)
			.finally( () => setInFlight( false ) );
	};

	const isPercent = 'percent' === draft.discount_type;
	const previewRows = previewProducts.slice( 0, PREVIEW_LIMIT );
	const remaining = previewProducts.length - previewRows.length;

	return (
		<Drawer.Root isOpen={ isOpen } size="medium" isDirty={ isDirty } onRequestClose={ onClose } className="newspack-subscriber-discounts__editor">
			<Drawer.Header>
				<Drawer.Title>{ rule ? __( 'Edit Discount', 'newspack-plugin' ) : __( 'Add Discount', 'newspack-plugin' ) }</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<VStack spacing={ 6 }>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<SearchTokenField
						endpoint={ SEARCH_ENDPOINTS.subscriptions }
						label={ __( 'Subscription', 'newspack-plugin' ) }
						help={ __( 'Subscribers of these subscriptions get the discount.', 'newspack-plugin' ) }
						value={ draft.subscription_product_ids ?? [] }
						onChange={ ids => update( { subscription_product_ids: ids } ) }
						disabled={ inFlight }
					/>
					<TargetingFields
						value={ {
							targeting: draft.targeting ?? 'products',
							product_ids: draft.product_ids ?? [],
							category_ids: draft.category_ids ?? [],
							excluded_product_ids: draft.excluded_product_ids ?? [],
						} }
						onChange={ partial => update( partial ) }
						appliesHelp={ __( 'Choose which products this discount applies to.', 'newspack-plugin' ) }
						disabled={ inFlight }
					/>
					<ToggleGroupControl
						label={ __( 'Discount type', 'newspack-plugin' ) }
						value={ draft.discount_type }
						onChange={ value => update( { discount_type: value as DiscountRule[ 'discount_type' ] } ) }
						isBlock
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="fixed" label={ __( 'Fixed amount', 'newspack-plugin' ) } />
						<ToggleGroupControlOption value="percent" label={ __( 'Percentage', 'newspack-plugin' ) } />
					</ToggleGroupControl>
					<TextControl
						type="number"
						label={ isPercent ? __( 'Percentage off', 'newspack-plugin' ) : __( 'Amount off', 'newspack-plugin' ) }
						help={
							isPercent
								? __( 'The percentage subscribers save on each product.', 'newspack-plugin' )
								: __( 'The amount subscribers save on each product.', 'newspack-plugin' )
						}
						value={ amountInput }
						onChange={ value => {
							setAmountInput( value );
							update( { amount: Number( value ) || 0 } );
						} }
						disabled={ inFlight }
						__nextHasNoMarginBottom
					/>
					{ previewRows.length > 0 && (
						<table className="newspack-subscriber-discounts__preview">
							<thead>
								<tr>
									<th>{ __( 'Product', 'newspack-plugin' ) }</th>
									<th>{ __( 'Price', 'newspack-plugin' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ previewRows.map( product => {
									const basePrice = Number( product.price );
									const discounted = subscriberPrice(
										basePrice,
										{
											discount_type: draft.discount_type ?? 'fixed',
											amount: Number( draft.amount ) || 0,
										},
										currency.decimals
									);
									return (
										<tr key={ product.id }>
											<td>{ decodeEntities( product.name ) }</td>
											<td>
												{ null === discounted ? (
													formatCurrency( basePrice, currency )
												) : (
													<>
														<del>{ formatCurrency( basePrice, currency ) }</del>{ ' ' }
														{ formatCurrency( discounted, currency ) }
													</>
												) }
											</td>
										</tr>
									);
								} ) }
								{ remaining > 0 && (
									<tr className="newspack-subscriber-discounts__preview-more">
										<td colSpan={ 2 }>
											{ sprintf(
												/* translators: %d: number of further products the discount covers. */
												_n( '…and %d more product', '…and %d more products', remaining, 'newspack-plugin' ),
												remaining
											) }
										</td>
									</tr>
								) }
							</tbody>
						</table>
					) }
				</VStack>
			</Drawer.Content>
			<Drawer.Footer>
				<Drawer.Action variant="secondary" disabled={ inFlight } closes>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Drawer.Action>
				<Drawer.Action variant="primary" isBusy={ inFlight } disabled={ ! canSave } onClick={ save }>
					{ __( 'Save', 'newspack-plugin' ) }
				</Drawer.Action>
			</Drawer.Footer>
		</Drawer.Root>
	);
}
