/* global newspackAudience */

/**
 * Metered Countdown settings page.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Grid, Router, SectionHeader, SelectControl, TextControl, useConfirmDialog } from '../../../../../../packages/components/src';
import { useWizardData } from '../../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from '../consts';

const { useHistory } = Router;

const CountdownBannerSettings = () => {
	const history = useHistory();
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as WizardData;
	const { addNotice, resetNotices, setHeaderData, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const { wizardApiFetch, errorMessage, resetError } = useWizardApiFetch( AUDIENCE_CONTENT_GATES_WIZARD_SLUG );
	const [ config, setConfig ] = useState< GateSettings >( wizardData?.config || {} );
	const availableProducts = newspackAudience?.available_products || [];
	const isDirty = useMemo( () => {
		return (
			config?.countdown_banner &&
			wizardData?.config?.countdown_banner &&
			JSON.stringify( config?.countdown_banner ) !== JSON.stringify( wizardData?.config?.countdown_banner )
		);
	}, [ config, wizardData?.config ] );
	const isSaving = useRef( false );
	const { confirmDialog, requestConfirm } = useConfirmDialog( {
		when: !! ( isDirty && ! isSaving.current ),
		message: __( 'You have unsaved changes that will be lost. Discard changes?', 'newspack-plugin' ),
		confirmButtonText: __( 'Discard changes', 'newspack-plugin' ),
		isDestructive: true,
		hideTitle: true,
	} );

	const handleUpdateConfig = ( newConfig: GateSettings, message: string = __( 'Metered countdown settings updated.', 'newspack-plugin' ) ) => {
		isSaving.current = true;
		resetError();
		resetNotices();
		wizardApiFetch(
			{
				path: '/newspack/v1/wizard/newspack-audience-access-control/countdown-banner',
				method: 'POST',
				quiet: true,
				data: newConfig?.countdown_banner,
			},
			{
				onSuccess( data ) {
					updateWizardSettings( {
						slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
						path: [ 'config' ],
						value: { ...wizardData?.config, countdown_banner: data },
					} );
					addNotice( {
						message,
						type: 'success',
						id: 'countdown-banner-config-updated',
					} );
					history.push( '/content-gates' );
				},
				onFinally: () => {
					isSaving.current = false;
				},
			}
		);
	};

	useEffect( () => {
		setHeaderData( {
			actions: [
				{
					label: __( 'Save', 'newspack-plugin' ),
					action: () => handleUpdateConfig( config ),
					disabled: ! isDirty,
					type: 'primary',
				},
				{
					label: config?.countdown_banner?.enabled ? __( 'Disable', 'newspack-plugin' ) : __( 'Enable', 'newspack-plugin' ),
					action: () => {
						const newConfig = {
							...wizardData?.config,
							countdown_banner: {
								...wizardData?.config?.countdown_banner,
								enabled: ! wizardData?.config?.countdown_banner?.enabled,
							},
						};
						const message = sprintf(
							// translators: %s is the status of the countdown banner.
							__( 'Countdown banner %s.', 'newspack-plugin' ),
							config?.countdown_banner?.enabled ? __( 'disabled', 'newspack-plugin' ) : __( 'enabled', 'newspack-plugin' )
						);
						requestConfirm( () => handleUpdateConfig( newConfig, message ) );
					},
					type: 'more',
				},
			],
			sectionName: __( 'Metered countdown', 'newspack-plugin' ),
		} );
	}, [ config, isDirty, setHeaderData ] );

	useEffect( () => {
		setConfig( wizardData?.config || {} );
	}, [ wizardData?.config ] );

	useEffect( () => {
		if ( errorMessage ) {
			addNotice( {
				message: errorMessage,
				type: 'error',
				id: 'countdown-banner-error',
			} );
		}
	}, [ errorMessage ] );

	return (
		<div className="newspack-content-gate__edit">
			{ confirmDialog }
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader heading={ 2 } title={ __( 'Countdown banner', 'newspack-plugin' ) } />
				<VStack spacing={ 4 }>
					<TextControl
						label={ __( 'Message', 'newspack-plugin' ) }
						help={ __( 'Text displayed in the countdown banner.', 'newspack-plugin' ) }
						value={ config?.countdown_banner?.cta_label || '' }
						onChange={ ( value: string ) =>
							setConfig( { ...config, countdown_banner: { ...config?.countdown_banner, cta_label: value } } )
						}
						withMargin={ false }
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Subscribe button label', 'newspack-plugin' ) }
						help={ __( 'Text displayed on the subscribe button in the banner.', 'newspack-plugin' ) }
						value={ config?.countdown_banner?.button_label || '' }
						onChange={ ( value: string ) =>
							setConfig( { ...config, countdown_banner: { ...config?.countdown_banner, button_label: value } } )
						}
						withMargin={ false }
						__next40pxDefaultSize
					/>
					<ToggleGroupControl
						label={ __( 'Style', 'newspack-plugin' ) }
						value={ config?.countdown_banner?.style || 'light' }
						onChange={ ( value: string ) => setConfig( { ...config, countdown_banner: { ...config?.countdown_banner, style: value } } ) }
						isBlock
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Light', 'newspack-plugin' ) } value="light" />
						<ToggleGroupControlOption label={ __( 'Dark', 'newspack-plugin' ) } value="dark" />
					</ToggleGroupControl>
					<ToggleGroupControl
						label={ __( 'Subscribe button action', 'newspack-plugin' ) }
						help={ __(
							'Whether the subscribe button should start a product checkout or redirect to a landing page.',
							'newspack-plugin'
						) }
						value={ config?.countdown_banner?.cta_type || 'product' }
						onChange={ ( value: string ) =>
							setConfig( { ...config, countdown_banner: { ...config?.countdown_banner, cta_type: value } } )
						}
						isBlock
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Product', 'newspack-plugin' ) } value="product" />
						<ToggleGroupControlOption label={ __( 'Landing page', 'newspack-plugin' ) } value="url" />
					</ToggleGroupControl>
					{ config?.countdown_banner?.cta_type === 'product' && (
						<SelectControl
							label={ __( 'Subscribe button product', 'newspack-plugin' ) }
							help={ __( 'Product linked to the subscribe button.', 'newspack-plugin' ) }
							options={ [ { label: __( 'Select a product', 'newspack-plugin' ), value: 0, disabled: true }, ...availableProducts ] }
							value={ config?.countdown_banner?.cta_product_id || 0 }
							suggestions={ availableProducts.map( o => o.label ) }
							onChange={ ( value: number ) =>
								setConfig( { ...config, countdown_banner: { ...config?.countdown_banner, cta_product_id: value } } )
							}
							__next40pxDefaultSize
						/>
					) }
					{ config?.countdown_banner?.cta_type === 'url' && (
						<TextControl
							label={ __( 'Subscribe button URL', 'newspack-plugin' ) }
							help={ __( 'URL for the landing page to redirect to.', 'newspack-plugin' ) }
							value={ config?.countdown_banner?.cta_url || '' }
							onChange={ ( value: string ) =>
								setConfig( { ...config, countdown_banner: { ...config?.countdown_banner, cta_url: value } } )
							}
							withMargin={ false }
							__next40pxDefaultSize
						/>
					) }
				</VStack>
			</Grid>
			<div style={ { gridColumn: '1 / -1' } }>
				<BaseControl id="newspack-countdown-banner-cta-preview" label={ __( 'Preview', 'newspack-plugin' ) }>
					<div className="newspack-countdown-banner__cta-preview" inert="true">
						<div className="newspack-ui">
							<div className={ `banner newspack-countdown-banner__cta is-style-${ config?.countdown_banner?.style || 'light' }` }>
								<div className="wrapper newspack-countdown-banner__cta__content">
									<div className="newspack-countdown-banner__cta__content__wrapper">
										<span className="newspack-countdown-banner__cta__content__countdown newspack-ui__font--s">
											<strong>{ __( '1/10 free articles this month', 'newspack-plugin' ) }</strong>
										</span>
										<span className="newspack-countdown-banner__cta__content__message newspack-ui__font--xs">
											{ config?.countdown_banner?.cta_label ||
												__( 'Subscribe now and get unlimited access.', 'newspack-plugin' ) }{ ' ' }
											<a href="#signin_modal">{ __( 'Sign in to an existing account', 'newspack-plugin' ) }</a>.
										</span>
									</div>
									{ ( ( config?.countdown_banner?.cta_type === 'product' && config?.countdown_banner?.cta_product_id ) ||
										( config?.countdown_banner?.cta_type === 'url' && config?.countdown_banner?.cta_url ) ) && (
										<button
											className={ `newspack-ui__button newspack-ui__button--x-small ${
												( config?.countdown_banner?.style || 'light' ) === 'dark'
													? 'newspack-ui__button--primary-light'
													: 'newspack-ui__button--accent'
											}` }
										>
											{ config?.countdown_banner?.button_label || __( 'Subscribe now', 'newspack-plugin' ) }
										</button>
									) }
								</div>
							</div>
						</div>
					</div>
				</BaseControl>
			</div>
		</div>
	);
};

export default CountdownBannerSettings;
