/**
 * Promotional URL generator modal (NPPD-1707).
 *
 * Rendered inside the DataViews action modal for a Plans row. Builds a link
 * that opens the modal checkout over a page — the checkout template is built
 * for the modal, so there is no standalone variant.
 *
 * Product links work over any page (Homepage by default): newspack-blocks
 * renders the checkout button for the requested product when the link's
 * trigger params are present, so no pre-existing block is required. Donation
 * links still target a page carrying a Donate block, because the link's
 * frequency/amount/layout params only mean something against that block's
 * configuration.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	BaseControl,
	Button,
	ComboboxControl,
	Notice,
	PanelBody,
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
	getPlanChoices,
	getValidationError,
	resolveProductParams,
} from './promo-url-options';
import type { DonateTargetsResponse, ProductPromoContext, PromoCouponResponse, PromoPageChoice, PromoTargetDonateConfig } from './promo-url-options';

const API_BASE = '/newspack/v1/wizard/newspack-audience-subscription-products';
const HOMEPAGE_VALUE = 'home';

type CouponStatus = { state: 'idle' | 'checking' | 'valid' | 'invalid'; reason?: string };

export default function PromoUrlModal( { item, closeModal }: { item: SubscriptionProduct; closeModal?: () => void } ) {
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const kind = item.is_donation ? 'donation' : 'product';

	const [ isLoading, setIsLoading ] = useState( true );
	const [ fetchError, setFetchError ] = useState( false );
	const [ productContext, setProductContext ] = useState< ProductPromoContext | null >( null );
	const [ donateResponse, setDonateResponse ] = useState< DonateTargetsResponse | null >( null );

	// Product path: any page, Homepage by default; search adds more choices.
	const [ pageValue, setPageValue ] = useState< string | null | undefined >( HOMEPAGE_VALUE );
	const [ searchChoices, setSearchChoices ] = useState< PromoPageChoice[] >( [] );
	const searchTimeout = useRef< ReturnType< typeof setTimeout > >();
	// Donation path: one of the scanned Donate pages.
	const [ donatePageId, setDonatePageId ] = useState< number | '' >( '' );

	const [ variationId, setVariationId ] = useState< number | '' >( '' );
	const [ frequency, setFrequency ] = useState< DonateFrequencySlug >( 'month' );
	const [ amount, setAmount ] = useState< number | 'custom' | '' >( '' );
	const [ customAmount, setCustomAmount ] = useState( '' );
	const [ coupon, setCoupon ] = useState( '' );
	const [ couponStatus, setCouponStatus ] = useState< CouponStatus >( { state: 'idle' } );
	const [ afterSuccess, setAfterSuccess ] = useState< '' | 'custom' >( '' );
	const [ afterSuccessUrl, setAfterSuccessUrl ] = useState( '' );
	const [ afterSuccessLabel, setAfterSuccessLabel ] = useState( '' );
	const [ utmSource, setUtmSource ] = useState( '' );
	const [ utmMedium, setUtmMedium ] = useState( '' );
	const [ utmCampaign, setUtmCampaign ] = useState( '' );

	useEffect( () => {
		apiFetch< ProductPromoContext | DonateTargetsResponse >( {
			path: addQueryArgs( `${ API_BASE }/promo-targets`, {
				type: kind === 'product' ? 'checkout_button' : 'donate',
				...( kind === 'product' ? { product_id: item.id } : {} ),
			} ),
		} )
			.then( result => {
				if ( kind === 'product' ) {
					setProductContext( result as ProductPromoContext );
				} else {
					setDonateResponse( result as DonateTargetsResponse );
				}
			} )
			.catch( () => setFetchError( true ) )
			.finally( () => setIsLoading( false ) );
	}, [ item.id, kind ] );

	const homepageChoice: PromoPageChoice | null = productContext
		? { value: HOMEPAGE_VALUE, label: productContext.homepage.title, url: productContext.homepage.url }
		: null;
	const pageChoices: PromoPageChoice[] = useMemo( () => {
		if ( ! homepageChoice ) {
			return [];
		}
		return [ homepageChoice, ...searchChoices.filter( choice => choice.url !== homepageChoice.url ) ];
	}, [ productContext, searchChoices ] );
	const selectedPage = pageChoices.find( choice => choice.value === pageValue ) || null;

	const searchPages = ( search: string ) => {
		clearTimeout( searchTimeout.current );
		if ( ! search ) {
			return;
		}
		searchTimeout.current = setTimeout( () => {
			apiFetch< { id: number; title: string; url: string }[] >( {
				path: addQueryArgs( '/wp/v2/search', {
					search,
					type: 'post',
					subtype: 'page,post',
					per_page: 20,
				} ),
			} )
				.then( results => {
					setSearchChoices( results.map( result => ( { value: String( result.id ), label: result.title, url: result.url } ) ) );
				} )
				.catch( () => {} );
		}, 300 );
	};

	const donateTarget = useMemo(
		() => donateResponse?.targets.find( target => target.id === donatePageId ) || null,
		[ donateResponse, donatePageId ]
	);
	const donateConfig: PromoTargetDonateConfig | null = donateTarget ? donateTarget.blocks[ 0 ] || null : null;

	const planChoices = useMemo(
		() => ( kind === 'product' ? getPlanChoices( item, productContext?.eligible_children || [] ) : [] ),
		[ kind, item, productContext ]
	);
	const frequencyChoices = useMemo( () => getFrequencyChoices( donateConfig ), [ donateConfig ] );
	const amountChoices = useMemo( () => getAmountChoices( donateConfig, frequency ), [ donateConfig, frequency ] );

	// Reset dependent selections when the config they came from changes.
	useEffect( () => {
		if ( donateConfig ) {
			setFrequency( getDefaultFrequency( item, donateConfig ) );
		}
	}, [ donateConfig, item.period ] );
	useEffect( () => {
		setAmount( amountChoices.presets.length ? amountChoices.presets[ 0 ] : 'custom' );
		setCustomAmount( amountChoices.suggested ? String( amountChoices.suggested ) : '' );
	}, [ frequency, donateConfig ] );

	const couponProductId = variationId === '' ? item.id : variationId;
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
					if ( ! ignore ) {
						setCouponStatus( result.valid ? { state: 'valid' } : { state: 'invalid', reason: result.reason } );
					}
				} )
				.catch( () => {
					if ( ! ignore ) {
						setCouponStatus( { state: 'invalid', reason: __( 'Could not validate the coupon.', 'newspack-plugin' ) } );
					}
				} );
		}, 500 );
		return () => {
			clearTimeout( timeout );
			ignore = true;
		};
	}, [ coupon, couponProductId ] );

	// A specific child is required unless the plan's picker provides the
	// "reader chooses" option.
	const requiresChild = planChoices.length > 0 && ! planChoices.some( choice => choice.value === '' );
	const effectiveAmount: number | 'other' | undefined = useMemo( () => {
		if ( kind !== 'donation' ) {
			return undefined;
		}
		if ( amount === 'custom' ) {
			const parsed = parseFloat( customAmount );
			if ( isNaN( parsed ) ) {
				return undefined;
			}
			// Untiered blocks take the number itself; the frequency-based tier UI
			// takes amount=other&other=N.
			return donateConfig?.layout_param === 'frequency' ? 'other' : parsed;
		}
		return typeof amount === 'number' ? amount : undefined;
	}, [ kind, amount, customAmount, donateConfig ] );

	const productParams = resolveProductParams( item, variationId );
	const pageUrl = kind === 'product' ? selectedPage?.url || '' : donateTarget?.url || '';

	const selections: PromoUrlSelections = {
		pageUrl,
		productId: kind === 'product' ? productParams.productId : undefined,
		variationId: kind === 'product' ? productParams.variationId : null,
		frequency: kind === 'donation' ? frequency : undefined,
		amount: effectiveAmount,
		otherAmount: effectiveAmount === 'other' ? parseFloat( customAmount ) : undefined,
		layoutParam: donateConfig?.layout_param,
		coupon: kind === 'product' ? coupon || undefined : undefined,
		afterSuccessBehavior: kind === 'product' ? afterSuccess : '',
		afterSuccessUrl,
		afterSuccessButtonLabel: afterSuccessLabel || undefined,
		utmSource,
		utmMedium,
		utmCampaign,
	};

	const validationError: string | null = useMemo(
		() =>
			getValidationError( {
				kind,
				hasTarget: Boolean( pageUrl ),
				requiresChild,
				variationId,
				donateConfig,
				effectiveAmount,
				customAmount,
				presets: amountChoices.presets,
				couponState: kind === 'product' ? couponStatus.state : undefined,
				couponReason: couponStatus.reason,
				afterSuccess: kind === 'product' ? afterSuccess : '',
				afterSuccessUrl,
				siteOrigin: productContext?.homepage.url,
			} ),
		[
			kind,
			pageUrl,
			requiresChild,
			variationId,
			donateConfig,
			effectiveAmount,
			customAmount,
			amountChoices.presets,
			couponStatus,
			afterSuccess,
			afterSuccessUrl,
		]
	);

	const url = validationError ? '' : buildPromoUrl( { kind, selections } );

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
	if ( fetchError || ( kind === 'product' && ! productContext ) ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load link options. Please close and try again.', 'newspack-plugin' ) }
			</Notice>
		);
	}

	let couponHelp: string | undefined;
	switch ( couponStatus.state ) {
		case 'checking':
			couponHelp = __( 'Checking…', 'newspack-plugin' );
			break;
		case 'valid':
			couponHelp = __( 'Coupon is valid and will be applied automatically at checkout.', 'newspack-plugin' );
			break;
		case 'invalid':
			couponHelp = couponStatus.reason;
			break;
		default:
			couponHelp = __( 'Optional. Applied automatically at checkout.', 'newspack-plugin' );
	}

	if ( kind === 'donation' && ! donateResponse?.targets.length ) {
		return (
			<VStack spacing={ 4 }>
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No published page or post contains a Donate block using the modal checkout. Add one to a page, then generate the link from here. Only pages and posts are searched.',
						'newspack-plugin'
					) }
				</Notice>
				<HStack justify="flex-end">
					<Button variant="tertiary" onClick={ () => closeModal?.() }>
						{ __( 'Close', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		);
	}

	return (
		<VStack spacing={ 4 }>
			{ kind === 'product' ? (
				<ComboboxControl
					label={ __( 'Target page', 'newspack-plugin' ) }
					help={ __(
						'The checkout opens over this page, keeping it in view. Any page works; search to pick a different one.',
						'newspack-plugin'
					) }
					value={ pageValue }
					options={ pageChoices.map( ( { value, label } ) => ( { value, label } ) ) }
					onChange={ value => setPageValue( value ) }
					onFilterValueChange={ searchPages }
					allowReset={ false }
				/>
			) : (
				<>
					{ donateResponse?.truncated && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Only the most recently updated pages were checked; older pages may be missing.', 'newspack-plugin' ) }
						</Notice>
					) }
					<SelectControl
						label={ __( 'Target page', 'newspack-plugin' ) }
						help={ __( 'The checkout opens over this page. Donation links need a page with a Donate block.', 'newspack-plugin' ) }
						value={ String( donatePageId ) }
						options={ [
							{ label: __( 'Select a page…', 'newspack-plugin' ), value: '' },
							...( donateResponse?.targets || [] ).map( target => ( {
								label: target.title,
								value: String( target.id ),
							} ) ),
						] }
						onChange={ value => setDonatePageId( value ? parseInt( value, 10 ) : '' ) }
					/>
				</>
			) }
			{ kind === 'product' && planChoices.length > 0 && (
				<SelectControl
					label={ __( 'Plan option', 'newspack-plugin' ) }
					value={ String( variationId ) }
					options={ [
						...( requiresChild ? [ { label: __( 'Select…', 'newspack-plugin' ), value: '' } ] : [] ),
						...planChoices.map( choice => ( { label: choice.label, value: String( choice.value ) } ) ),
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
			{ kind === 'product' && (
				<TextControl label={ __( 'Coupon code', 'newspack-plugin' ) } value={ coupon } onChange={ setCoupon } help={ couponHelp } />
			) }
			{ kind === 'product' && (
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
								placeholder={ productContext?.homepage.url || 'https://example.com' }
								help={ __( 'Must be a page on this site.', 'newspack-plugin' ) }
							/>
							<TextControl
								label={ __( 'Button label', 'newspack-plugin' ) }
								value={ afterSuccessLabel }
								onChange={ setAfterSuccessLabel }
								help={ __( 'Optional. Defaults to the site\u2019s continue label.', 'newspack-plugin' ) }
							/>
						</>
					) }
				</>
			) }
			<PanelBody title={ __( 'Campaign tracking', 'newspack-plugin' ) } initialOpen={ false }>
				<HStack alignment="top">
					<TextControl label="utm_source" value={ utmSource } onChange={ setUtmSource } />
					<TextControl label="utm_medium" value={ utmMedium } onChange={ setUtmMedium } />
					<TextControl label="utm_campaign" value={ utmCampaign } onChange={ setUtmCampaign } />
				</HStack>
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
