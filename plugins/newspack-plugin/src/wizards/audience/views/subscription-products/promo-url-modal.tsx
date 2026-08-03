/**
 * Promotional URL generator modal (NPPD-1707).
 *
 * Rendered inside the DataViews action modal for a Plans row. Offers two
 * destinations: a direct link straight to checkout (works anywhere), or a
 * link that opens the modal over a page containing a compatible block —
 * options derived from the server-side scan so the UI can't emit a URL
 * that silently fails.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	BaseControl,
	Button,
	Notice,
	PanelBody,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { buildPromoUrl } from './promo-url';
import type { DonateFrequencySlug, PromoUrlSelections } from './promo-url';
import {
	getAmountChoices,
	getDefaultFrequency,
	getFrequencyChoices,
	getValidationError,
	getVariationChoices,
	resolvePageProductParams,
} from './promo-url-options';
import type { PromoCouponResponse, PromoTarget, PromoTargetBlockConfig, PromoTargetDonateConfig, PromoTargetsResponse } from './promo-url-options';

const API_BASE = '/newspack/v1/wizard/newspack-audience-subscription-products';

type CouponStatus = { state: 'idle' | 'checking' | 'valid' | 'invalid'; reason?: string };

export default function PromoUrlModal( { item, closeModal }: { item: SubscriptionProduct; closeModal?: () => void } ) {
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const kind = item.is_donation ? 'donation' : 'product';
	// Readers open these links, so build them on the home address: on a
	// subdirectory install the WordPress address carries a path they shouldn't see.
	const siteUrl = window.newspack_urls?.home || window.newspack_urls?.site || window.location.origin;

	const [ isLoading, setIsLoading ] = useState( true );
	const [ fetchError, setFetchError ] = useState( false );
	const [ response, setResponse ] = useState< PromoTargetsResponse | null >( null );

	const [ destination, setDestination ] = useState< 'direct' | 'page' >( 'direct' );
	const [ pageId, setPageId ] = useState< number | '' >( '' );
	const [ variationId, setVariationId ] = useState< number | '' >( '' );
	const [ frequency, setFrequency ] = useState< DonateFrequencySlug >( 'month' );
	const [ amount, setAmount ] = useState< number | 'custom' | '' >( '' );
	const [ customAmount, setCustomAmount ] = useState( '' );
	const [ coupon, setCoupon ] = useState( '' );
	const [ couponStatus, setCouponStatus ] = useState< CouponStatus >( { state: 'idle' } );
	const [ price, setPrice ] = useState( '' );
	const [ afterSuccess, setAfterSuccess ] = useState< '' | 'custom' >( '' );
	const [ afterSuccessUrl, setAfterSuccessUrl ] = useState( '' );
	const [ afterSuccessLabel, setAfterSuccessLabel ] = useState( __( 'Continue', 'newspack-plugin' ) );
	const [ utmSource, setUtmSource ] = useState( '' );
	const [ utmMedium, setUtmMedium ] = useState( '' );
	const [ utmCampaign, setUtmCampaign ] = useState( '' );

	useEffect( () => {
		apiFetch< PromoTargetsResponse >( {
			path: addQueryArgs( `${ API_BASE }/promo-targets`, {
				type: kind === 'product' ? 'checkout_button' : 'donate',
				...( kind === 'product' ? { product_id: item.id } : {} ),
			} ),
		} )
			.then( result => setResponse( result ) )
			.catch( () => setFetchError( true ) )
			.finally( () => setIsLoading( false ) );
	}, [ item.id, kind ] );

	const selectedTarget: PromoTarget | null = useMemo(
		() => response?.targets.find( target => target.id === pageId ) || null,
		[ response, pageId ]
	);
	const targetBlocks =
		kind === 'product' && destination === 'page' && selectedTarget ? ( selectedTarget.blocks as PromoTargetBlockConfig[] ) : null;
	const donateConfig: PromoTargetDonateConfig | null = useMemo( () => {
		if ( kind !== 'donation' ) {
			return null;
		}
		if ( destination === 'page' && selectedTarget ) {
			return ( selectedTarget.blocks as PromoTargetDonateConfig[] )[ 0 ] || null;
		}
		return response?.donation_config || null;
	}, [ kind, destination, selectedTarget, response ] );

	const variationChoices = useMemo(
		() => ( kind === 'product' ? getVariationChoices( item, targetBlocks, response?.eligible_children ) : [] ),
		[ kind, item, targetBlocks, response ]
	);
	const frequencyChoices = useMemo( () => getFrequencyChoices( donateConfig ), [ donateConfig ] );
	const amountChoices = useMemo( () => getAmountChoices( donateConfig, frequency ), [ donateConfig, frequency ] );
	const isNypEligible = Boolean( response?.nyp?.[ variationId === '' ? item.id : variationId ] );
	const couponProductId = variationId === '' ? item.id : variationId;

	// Reset dependent selections when the context they came from changes.
	useEffect( () => {
		setVariationId( '' );
		setPrice( '' );
	}, [ destination, pageId ] );
	useEffect( () => {
		setPrice( '' );
	}, [ variationId ] );
	useEffect( () => {
		if ( donateConfig ) {
			setFrequency( getDefaultFrequency( item, donateConfig ) );
		}
	}, [ donateConfig, item.period ] );
	useEffect( () => {
		setAmount( amountChoices.presets.length ? amountChoices.presets[ 0 ] : 'custom' );
		setCustomAmount( amountChoices.suggested ? String( amountChoices.suggested ) : '' );
	}, [ frequency, donateConfig ] );

	// Debounced coupon validation (direct product path only).
	useEffect( () => {
		if ( ! coupon ) {
			setCouponStatus( { state: 'idle' } );
			return;
		}
		setCouponStatus( { state: 'checking' } );
		let ignore = false;
		const timeout = setTimeout( () => {
			apiFetch< PromoCouponResponse >( {
				path: addQueryArgs( `${ API_BASE }/promo-coupon`, { code: coupon, product_id: couponProductId } ),
			} )
				.then( result => {
					if ( ignore ) {
						return;
					}
					setCouponStatus( result.valid ? { state: 'valid' } : { state: 'invalid', reason: result.reason } );
				} )
				.catch( () => {
					if ( ignore ) {
						return;
					}
					setCouponStatus( { state: 'invalid', reason: __( 'Could not validate the coupon.', 'newspack-plugin' ) } );
				} );
		}, 500 );
		return () => {
			clearTimeout( timeout );
			ignore = true;
		};
	}, [ coupon, couponProductId ] );

	// A specific child is required on the direct path always, and on the page
	// path unless a picker block provides the "reader chooses" option.
	const requiresChild = variationChoices.length > 0 && ( destination === 'direct' || ! variationChoices.some( choice => choice.value === '' ) );
	const isCouponActive = kind === 'product' && destination === 'direct';
	const effectiveAmount: number | 'other' | undefined = useMemo( () => {
		if ( kind !== 'donation' ) {
			return undefined;
		}
		if ( amount === 'custom' ) {
			const parsed = parseFloat( customAmount );
			if ( isNaN( parsed ) ) {
				return undefined;
			}
			// Direct URLs and untiered blocks take the number itself; the
			// frequency-based tier UI takes amount=other&other=N.
			return destination === 'page' && donateConfig?.layout_param === 'frequency' ? 'other' : parsed;
		}
		return typeof amount === 'number' ? amount : undefined;
	}, [ kind, amount, customAmount, destination, donateConfig ] );

	const pageProductParams =
		kind === 'product' && targetBlocks?.length
			? resolvePageProductParams( targetBlocks, item.id, variationId )
			: { productId: item.id, variationId: variationId === '' ? null : variationId };

	const selections: PromoUrlSelections = {
		destination,
		pageUrl: selectedTarget?.url,
		productId: kind === 'product' ? pageProductParams.productId : undefined,
		variationId: kind === 'product' ? pageProductParams.variationId : null,
		frequency: kind === 'donation' ? frequency : undefined,
		amount: effectiveAmount,
		otherAmount: effectiveAmount === 'other' ? parseFloat( customAmount ) : undefined,
		layoutParam: donateConfig?.layout_param,
		coupon: isCouponActive ? coupon || undefined : undefined,
		price:
			kind === 'product' && destination === 'direct' && isNypEligible && price && ! isNaN( parseFloat( price ) )
				? parseFloat( price )
				: undefined,
		afterSuccessBehavior: destination === 'direct' ? afterSuccess : '',
		afterSuccessUrl,
		afterSuccessButtonLabel: afterSuccessLabel,
		utmSource,
		utmMedium,
		utmCampaign,
	};

	const validationError: string | null = useMemo(
		() =>
			getValidationError( {
				kind,
				destination,
				hasTarget: Boolean( selectedTarget ),
				requiresChild,
				variationId,
				donateConfig,
				effectiveAmount,
				customAmount,
				presets: amountChoices.presets,
				isCouponActive,
				couponState: couponStatus.state,
				couponReason: couponStatus.reason,
				afterSuccess,
				afterSuccessUrl,
			} ),
		[
			kind,
			destination,
			selectedTarget,
			requiresChild,
			variationId,
			donateConfig,
			effectiveAmount,
			customAmount,
			amountChoices.presets,
			isCouponActive,
			couponStatus,
			afterSuccess,
			afterSuccessUrl,
		]
	);

	const url = validationError ? '' : buildPromoUrl( { kind, siteUrl, selections } );

	const copyUrl = async () => {
		let copied = false;
		try {
			// `navigator.clipboard` is undefined outside a secure context — a
			// plain-HTTP admin — where this throws synchronously.
			await navigator.clipboard.writeText( url );
			copied = true;
		} catch ( e ) {
			try {
				const textarea = document.createElement( 'textarea' );
				textarea.value = url;
				textarea.setAttribute( 'readonly', '' );
				textarea.style.position = 'fixed';
				textarea.style.opacity = '0';
				document.body.appendChild( textarea );
				textarea.select();
				copied = document.execCommand( 'copy' );
				document.body.removeChild( textarea );
			} catch ( fallbackError ) {
				copied = false;
			}
		}
		if ( copied ) {
			addNotice( {
				message: __( 'Promotional link copied to clipboard.', 'newspack-plugin' ),
				type: 'success',
				id: 'promo-url-copied',
			} );
			closeModal?.();
			return;
		}
		addNotice( {
			message: __( 'Failed to copy the link. Please copy it manually.', 'newspack-plugin' ),
			type: 'error',
			id: 'promo-url-copy-error',
		} );
	};

	if ( isLoading ) {
		return (
			<HStack justify="center">
				<Spinner />
			</HStack>
		);
	}
	if ( fetchError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load link options. Please close and try again.', 'newspack-plugin' ) }
			</Notice>
		);
	}

	const hasPageTargets = ( response?.targets.length || 0 ) > 0;
	const blockLabel = kind === 'product' ? __( 'Checkout Button', 'newspack-plugin' ) : __( 'Donate', 'newspack-plugin' );

	let couponHelp: string | undefined;
	switch ( couponStatus.state ) {
		case 'checking':
			couponHelp = __( 'Checking…', 'newspack-plugin' );
			break;
		case 'valid':
			couponHelp = __( 'Coupon is valid and will be applied automatically.', 'newspack-plugin' );
			break;
		case 'invalid':
			couponHelp = couponStatus.reason;
			break;
		default:
			couponHelp = __( 'Optional. Applied automatically at checkout.', 'newspack-plugin' );
	}

	return (
		<VStack spacing={ 4 }>
			<RadioControl
				label={ __( 'Destination', 'newspack-plugin' ) }
				help={
					destination === 'direct'
						? __(
								'Works from anywhere — emails, social media, or print QR codes. Readers land straight on the checkout.',
								'newspack-plugin'
						  )
						: __( 'The checkout opens over the selected page, keeping your pitch in view.', 'newspack-plugin' )
				}
				selected={ destination }
				options={ [
					{ label: __( 'Straight to checkout', 'newspack-plugin' ), value: 'direct' },
					{
						label: hasPageTargets
							? sprintf(
									/* translators: %d: number of compatible pages. */
									__( 'Over a specific page (%d compatible)', 'newspack-plugin' ),
									response?.targets.length
							  )
							: __( 'Over a specific page (none available)', 'newspack-plugin' ),
						value: 'page',
					},
				] }
				onChange={ value => setDestination( value as 'direct' | 'page' ) }
			/>
			{ ! hasPageTargets && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: block name. */
						__(
							'No published page or post contains a compatible %s block. Add one to a page to enable this option. Only pages and posts are searched.',
							'newspack-plugin'
						),
						blockLabel
					) }
				</Notice>
			) }
			{ response?.truncated && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Only the most recently updated pages were checked; older pages may be missing.', 'newspack-plugin' ) }
				</Notice>
			) }
			{ destination === 'page' && hasPageTargets && (
				<SelectControl
					label={ __( 'Target page', 'newspack-plugin' ) }
					value={ String( pageId ) }
					options={ [
						{ label: __( 'Select a page…', 'newspack-plugin' ), value: '' },
						...( response?.targets || [] ).map( target => ( {
							label: target.title,
							value: String( target.id ),
						} ) ),
					] }
					onChange={ value => setPageId( value ? parseInt( value, 10 ) : '' ) }
				/>
			) }
			{ kind === 'product' && variationChoices.length > 0 && (
				<SelectControl
					label={ __( 'Plan option', 'newspack-plugin' ) }
					value={ String( variationId ) }
					options={ [
						...( requiresChild ? [ { label: __( 'Select…', 'newspack-plugin' ), value: '' } ] : [] ),
						...variationChoices.map( choice => ( { label: choice.label, value: String( choice.value ) } ) ),
					] }
					onChange={ value => setVariationId( value ? parseInt( value, 10 ) : '' ) }
				/>
			) }
			{ kind === 'donation' && donateConfig && (
				<HStack alignment="top">
					<SelectControl
						label={ __( 'Frequency', 'newspack-plugin' ) }
						value={ frequency }
						options={ frequencyChoices.map( choice => ( { label: choice.label, value: choice.value } ) ) }
						onChange={ value => setFrequency( value as DonateFrequencySlug ) }
					/>
					<SelectControl
						label={ __( 'Amount', 'newspack-plugin' ) }
						value={ String( amount ) }
						options={ [
							...amountChoices.presets.map( preset => ( { label: String( preset ), value: String( preset ) } ) ),
							...( amountChoices.supportsCustom ? [ { label: __( 'Custom…', 'newspack-plugin' ), value: 'custom' } ] : [] ),
						] }
						help={
							destination === 'direct' && ! amountChoices.supportsCustom
								? __(
										'Name Your Price is not active, so checkout charges the amount saved in Donations settings. A link cannot pre-fill a different one.',
										'newspack-plugin'
								  )
								: undefined
						}
						onChange={ value => setAmount( value === 'custom' ? 'custom' : parseFloat( value ) ) }
					/>
					{ amount === 'custom' && (
						<TextControl
							label={ __( 'Custom amount', 'newspack-plugin' ) }
							type="number"
							value={ customAmount }
							onChange={ setCustomAmount }
						/>
					) }
				</HStack>
			) }
			<PanelBody title={ __( 'Link options', 'newspack-plugin' ) } initialOpen={ false }>
				<VStack spacing={ 3 }>
					{ kind === 'product' && destination === 'direct' && (
						<TextControl label={ __( 'Coupon code', 'newspack-plugin' ) } value={ coupon } onChange={ setCoupon } help={ couponHelp } />
					) }
					{ kind === 'product' && destination === 'page' && targetBlocks?.[ 0 ]?.coupon && (
						<Notice status="info" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: coupon code. */
								__( 'The block on this page applies the coupon "%s" automatically.', 'newspack-plugin' ),
								targetBlocks[ 0 ].coupon
							) }
						</Notice>
					) }
					{ destination === 'page' && targetBlocks?.[ 0 ]?.after_success && (
						<Notice status="info" isDismissible={ false }>
							{ __( 'After checkout, readers follow the behavior configured on the block on this page.', 'newspack-plugin' ) }
						</Notice>
					) }
					{ kind === 'product' && destination === 'direct' && isNypEligible && (
						<TextControl
							label={ __( 'Suggested price', 'newspack-plugin' ) }
							type="number"
							value={ price }
							onChange={ setPrice }
							help={ __( 'Optional. Pre-fills the name-your-price amount.', 'newspack-plugin' ) }
						/>
					) }
					{ destination === 'direct' && (
						<>
							<SelectControl
								label={ __( 'After checkout', 'newspack-plugin' ) }
								value={ afterSuccess }
								options={ [
									{ label: __( 'Show the thank-you screen', 'newspack-plugin' ), value: '' },
									{ label: __( 'Continue to a custom URL', 'newspack-plugin' ), value: 'custom' },
								] }
								onChange={ value => setAfterSuccess( value as '' | 'custom' ) }
							/>
							{ afterSuccess === 'custom' && (
								<>
									<TextControl
										label={ __( 'Custom URL', 'newspack-plugin' ) }
										value={ afterSuccessUrl }
										onChange={ setAfterSuccessUrl }
										placeholder="https://example.com"
									/>
									<TextControl
										label={ __( 'Button label', 'newspack-plugin' ) }
										value={ afterSuccessLabel }
										onChange={ setAfterSuccessLabel }
									/>
								</>
							) }
						</>
					) }
					<HStack alignment="top">
						<TextControl label="utm_source" value={ utmSource } onChange={ setUtmSource } />
						<TextControl label="utm_medium" value={ utmMedium } onChange={ setUtmMedium } />
						<TextControl label="utm_campaign" value={ utmCampaign } onChange={ setUtmCampaign } />
					</HStack>
				</VStack>
			</PanelBody>
			{ validationError ? (
				<Notice status="warning" isDismissible={ false }>
					{ validationError }
				</Notice>
			) : (
				<BaseControl id="newspack-promo-url-preview" label={ __( 'Promotional link', 'newspack-plugin' ) }>
					<TextareaControl id="newspack-promo-url-preview" value={ url } readOnly rows={ 3 } onChange={ () => {} } />
				</BaseControl>
			) }
			<HStack justify="flex-end">
				<Button variant="tertiary" onClick={ () => closeModal?.() }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button variant="secondary" href={ url || undefined } target="_blank" rel="noopener noreferrer" disabled={ ! url }>
					{ __( 'Test link', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" onClick={ copyUrl } disabled={ ! url }>
					{ __( 'Copy link', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
