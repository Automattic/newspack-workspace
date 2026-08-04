/**
 * Promotional URL generator modal (NPPD-1707).
 *
 * Rendered inside the DataViews action modal for a Plans row. Builds a link
 * that opens the checkout over a page containing a compatible block — the
 * checkout template is built for the modal, so there is no standalone variant.
 * Every option comes from the server-side scan of published blocks, so the UI
 * cannot emit a URL that silently fails.
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
import type { PromoTarget, PromoTargetBlockConfig, PromoTargetDonateConfig, PromoTargetsResponse } from './promo-url-options';

const API_BASE = '/newspack/v1/wizard/newspack-audience-subscription-products';

export default function PromoUrlModal( { item, closeModal }: { item: SubscriptionProduct; closeModal?: () => void } ) {
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const kind = item.is_donation ? 'donation' : 'product';

	const [ isLoading, setIsLoading ] = useState( true );
	const [ fetchError, setFetchError ] = useState( false );
	const [ response, setResponse ] = useState< PromoTargetsResponse | null >( null );

	const [ pageId, setPageId ] = useState< number | '' >( '' );
	const [ variationId, setVariationId ] = useState< number | '' >( '' );
	const [ frequency, setFrequency ] = useState< DonateFrequencySlug >( 'month' );
	const [ amount, setAmount ] = useState< number | 'custom' | '' >( '' );
	const [ customAmount, setCustomAmount ] = useState( '' );
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
	const targetBlocks = kind === 'product' && selectedTarget ? ( selectedTarget.blocks as PromoTargetBlockConfig[] ) : null;
	const donateConfig: PromoTargetDonateConfig | null = useMemo( () => {
		if ( kind !== 'donation' || ! selectedTarget ) {
			return null;
		}
		return ( selectedTarget.blocks as PromoTargetDonateConfig[] )[ 0 ] || null;
	}, [ kind, selectedTarget ] );

	const variationChoices = useMemo(
		() => ( kind === 'product' ? getVariationChoices( item, targetBlocks, response?.eligible_children ) : [] ),
		[ kind, item, targetBlocks, response ]
	);
	const frequencyChoices = useMemo( () => getFrequencyChoices( donateConfig ), [ donateConfig ] );
	const amountChoices = useMemo( () => getAmountChoices( donateConfig, frequency ), [ donateConfig, frequency ] );

	// Reset dependent selections when the page they came from changes.
	useEffect( () => {
		setVariationId( '' );
	}, [ pageId ] );
	useEffect( () => {
		if ( donateConfig ) {
			setFrequency( getDefaultFrequency( item, donateConfig ) );
		}
	}, [ donateConfig, item.period ] );
	useEffect( () => {
		setAmount( amountChoices.presets.length ? amountChoices.presets[ 0 ] : 'custom' );
		setCustomAmount( amountChoices.suggested ? String( amountChoices.suggested ) : '' );
	}, [ frequency, donateConfig ] );

	// A specific child is required unless a picker block on the page provides
	// the "reader chooses" option.
	const requiresChild = variationChoices.length > 0 && ! variationChoices.some( choice => choice.value === '' );
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

	const pageProductParams =
		kind === 'product' && targetBlocks?.length
			? resolvePageProductParams( targetBlocks, item.id, variationId )
			: { productId: item.id, variationId: variationId === '' ? null : variationId };

	const selections: PromoUrlSelections = {
		pageUrl: selectedTarget?.url || '',
		productId: kind === 'product' ? pageProductParams.productId : undefined,
		variationId: kind === 'product' ? pageProductParams.variationId : null,
		frequency: kind === 'donation' ? frequency : undefined,
		amount: effectiveAmount,
		otherAmount: effectiveAmount === 'other' ? parseFloat( customAmount ) : undefined,
		layoutParam: donateConfig?.layout_param,
		utmSource,
		utmMedium,
		utmCampaign,
	};

	const validationError: string | null = useMemo(
		() =>
			getValidationError( {
				kind,
				hasTarget: Boolean( selectedTarget ),
				requiresChild,
				variationId,
				donateConfig,
				effectiveAmount,
				customAmount,
				presets: amountChoices.presets,
			} ),
		[ kind, selectedTarget, requiresChild, variationId, donateConfig, effectiveAmount, customAmount, amountChoices.presets ]
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
	if ( fetchError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load link options. Please close and try again.', 'newspack-plugin' ) }
			</Notice>
		);
	}

	const blockLabel = kind === 'product' ? __( 'Checkout Button', 'newspack-plugin' ) : __( 'Donate', 'newspack-plugin' );
	if ( ! response?.targets.length ) {
		return (
			<VStack spacing={ 4 }>
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: block name. */
						__(
							'No published page or post contains a compatible %s block. Add one to a page, then generate the link from here. Only pages and posts are searched.',
							'newspack-plugin'
						),
						blockLabel
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
			{ response?.truncated && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Only the most recently updated pages were checked; older pages may be missing.', 'newspack-plugin' ) }
				</Notice>
			) }
			<SelectControl
				label={ __( 'Target page', 'newspack-plugin' ) }
				help={ __( 'The checkout opens over this page, keeping your pitch in view.', 'newspack-plugin' ) }
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
						onChange={ value => setAmount( value === 'custom' ? 'custom' : parseFloat( value ) ) }
					/>
					{ amount === 'custom' && (
						<TextControl label={ __( 'Custom amount', 'newspack-plugin' ) } type="number" value={ customAmount } onChange={ setCustomAmount } />
					) }
				</HStack>
			) }
			{ kind === 'product' && targetBlocks?.[ 0 ]?.coupon && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: coupon code. */
						__( 'The block on this page applies the coupon "%s" automatically.', 'newspack-plugin' ),
						targetBlocks[ 0 ].coupon
					) }
				</Notice>
			) }
			{ targetBlocks?.[ 0 ]?.after_success && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'After checkout, readers follow the behavior configured on the block on this page.', 'newspack-plugin' ) }
				</Notice>
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
