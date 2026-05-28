/* global newspackAudience */

/**
 * Content Gifting settings page.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	RangeControl,
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
import {
	Divider,
	Grid,
	Notice,
	Router,
	SectionHeader,
	SelectControl,
	TextControl,
	useConfirmDialog,
} from '../../../../../../packages/components/src';
import { useWizardData } from '../../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from '../consts';

const { useHistory } = Router;

const ContentGiftingSettings = () => {
	const history = useHistory();
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as WizardData;
	const { addNotice, resetNotices, setHeaderData, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const { wizardApiFetch, errorMessage, resetError } = useWizardApiFetch( AUDIENCE_CONTENT_GATES_WIZARD_SLUG );
	const [ config, setConfig ] = useState< GateSettings >( wizardData?.config || {} );
	const availableProducts = newspackAudience?.available_products || [];
	const hasMetering = newspackAudience?.content_gifting?.has_metering;
	const giftingErrors = Object.values( newspackAudience?.content_gifting?.can_use_gifting?.errors || {} ).flat() as string[];
	const isDirty = useMemo( () => {
		return (
			config?.content_gifting &&
			wizardData?.config?.content_gifting &&
			JSON.stringify( config?.content_gifting ) !== JSON.stringify( wizardData?.config?.content_gifting )
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

	const handleUpdateConfig = ( newConfig: GateSettings, message: string = __( 'Content gifting settings updated.', 'newspack-plugin' ) ) => {
		isSaving.current = true;
		resetError();
		resetNotices();
		wizardApiFetch(
			{
				path: '/newspack/v1/wizard/newspack-audience-access-control/content-gifting',
				method: 'POST',
				quiet: true,
				data: newConfig?.content_gifting,
			},
			{
				onSuccess( data ) {
					updateWizardSettings( {
						slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
						path: [ 'config' ],
						value: { ...wizardData?.config, content_gifting: data },
					} );
					addNotice( {
						message,
						type: 'success',
						id: 'content-gifting-config-updated',
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
					label: config?.content_gifting?.enabled ? __( 'Disable', 'newspack-plugin' ) : __( 'Enable', 'newspack-plugin' ),
					action: () => {
						const newConfig = {
							...wizardData?.config,
							content_gifting: {
								...wizardData?.config?.content_gifting,
								enabled: ! wizardData?.config?.content_gifting?.enabled,
							},
						};
						const message = sprintf(
							// translators: %s is the status of content gifting.
							__( 'Content gifting %s.', 'newspack-plugin' ),
							config?.content_gifting?.enabled ? __( 'disabled', 'newspack-plugin' ) : __( 'enabled', 'newspack-plugin' )
						);
						requestConfirm( () => handleUpdateConfig( newConfig, message ) );
					},
					type: 'more',
				},
			],
			sectionName: __( 'Content gifting', 'newspack-plugin' ),
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
				id: 'content-gifting-error',
			} );
		}
	}, [ errorMessage ] );

	return (
		<div className="newspack-content-gate__edit">
			{ confirmDialog }
			{ giftingErrors.length > 0 && <Notice noticeText={ giftingErrors.join( ', ' ) } isError /> }
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader heading={ 2 } title={ __( 'General settings', 'newspack-plugin' ) } />
				<VStack spacing={ 4 }>
					<RangeControl
						label={ __( 'Gifting limit', 'newspack-plugin' ) }
						help={ __( 'Maximum number of articles that can be gifted per user for the configured interval.', 'newspack-plugin' ) }
						min={ 1 }
						max={ 20 }
						value={ config?.content_gifting?.limit || 10 }
						onChange={ ( value: number ) => setConfig( { ...config, content_gifting: { ...config?.content_gifting, limit: value } } ) }
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Gifting limit interval', 'newspack-plugin' ) }
						help={ __( 'Interval at which the gifting limit is reset.', 'newspack-plugin' ) }
						value={ config?.content_gifting?.interval || 'month' }
						onChange={ ( value: string ) => setConfig( { ...config, content_gifting: { ...config?.content_gifting, interval: value } } ) }
						options={ [
							{ value: 'day', label: __( 'Day', 'newspack-plugin' ) },
							{ value: 'week', label: __( 'Week', 'newspack-plugin' ) },
							{ value: 'month', label: __( 'Month', 'newspack-plugin' ) },
						] }
						__next40pxDefaultSize
					/>
					<RangeControl
						label={ __( 'Article link expiration time', 'newspack-plugin' ) }
						help={ __( 'Time after which the article link expires.', 'newspack-plugin' ) }
						min={ 1 }
						max={ 60 }
						value={ config?.content_gifting?.expiration_time || 5 }
						onChange={ ( value: number ) =>
							setConfig( { ...config, content_gifting: { ...config?.content_gifting, expiration_time: value } } )
						}
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Article link expiration time unit', 'newspack-plugin' ) }
						help={ __( 'Unit of time for the article link expiration time.', 'newspack-plugin' ) }
						value={ config?.content_gifting?.expiration_time_unit || 'days' }
						onChange={ ( value: string ) =>
							setConfig( { ...config, content_gifting: { ...config?.content_gifting, expiration_time_unit: value } } )
						}
						options={ [
							{ value: 'hours', label: __( 'Hours', 'newspack-plugin' ) },
							{ value: 'days', label: __( 'Days', 'newspack-plugin' ) },
						] }
						__next40pxDefaultSize
					/>
				</VStack>
			</Grid>
			<Divider alignment="full-width" />
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader heading={ 2 } title={ __( 'Recipient banner', 'newspack-plugin' ) } />
				<VStack spacing={ 4 }>
					<TextControl
						label={ __( 'Message', 'newspack-plugin' ) }
						help={ __( 'Text displayed in the banner shown to recipients of gifted articles.', 'newspack-plugin' ) }
						value={ config?.content_gifting?.cta_label || '' }
						onChange={ ( value: string ) =>
							setConfig( { ...config, content_gifting: { ...config?.content_gifting, cta_label: value } } )
						}
						withMargin={ false }
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Subscribe button label', 'newspack-plugin' ) }
						help={ __( 'Text displayed on the subscribe button in the banner.', 'newspack-plugin' ) }
						value={ config?.content_gifting?.button_label || '' }
						onChange={ ( value: string ) =>
							setConfig( { ...config, content_gifting: { ...config?.content_gifting, button_label: value } } )
						}
						withMargin={ false }
						__next40pxDefaultSize
					/>
					<ToggleGroupControl
						label={ __( 'Style', 'newspack-plugin' ) }
						value={ config?.content_gifting?.style || 'light' }
						onChange={ ( value: string ) => setConfig( { ...config, content_gifting: { ...config?.content_gifting, style: value } } ) }
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
						value={ config?.content_gifting?.cta_type || 'product' }
						onChange={ ( value: string ) => setConfig( { ...config, content_gifting: { ...config?.content_gifting, cta_type: value } } ) }
						isBlock
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Product', 'newspack-plugin' ) } value="product" />
						<ToggleGroupControlOption label={ __( 'Landing page', 'newspack-plugin' ) } value="url" />
					</ToggleGroupControl>
					{ config?.content_gifting?.cta_type === 'product' && (
						<SelectControl
							label={ __( 'Subscribe button product', 'newspack-plugin' ) }
							help={ __( 'Product linked to the subscribe button.', 'newspack-plugin' ) }
							options={ [ { label: __( 'Select a product', 'newspack-plugin' ), value: 0, disabled: true }, ...availableProducts ] }
							value={ config?.content_gifting?.cta_product_id || 0 }
							suggestions={ availableProducts.map( o => o.label ) }
							onChange={ ( value: number ) =>
								setConfig( { ...config, content_gifting: { ...config?.content_gifting, cta_product_id: value } } )
							}
							__next40pxDefaultSize
						/>
					) }
					{ config?.content_gifting?.cta_type === 'url' && (
						<TextControl
							label={ __( 'Subscribe button URL', 'newspack-plugin' ) }
							help={ __( 'URL for the landing page to redirect to.', 'newspack-plugin' ) }
							value={ config?.content_gifting?.cta_url || '' }
							onChange={ ( value: string ) =>
								setConfig( { ...config, content_gifting: { ...config?.content_gifting, cta_url: value } } )
							}
							withMargin={ false }
							__next40pxDefaultSize
						/>
					) }
				</VStack>
			</Grid>
			<div style={ { gridColumn: '1 / -1' } }>
				<BaseControl id="newspack-content-gifting-cta-preview" label={ __( 'Preview', 'newspack-plugin' ) }>
					<div className="newspack-content-gifting__cta-preview" inert="true">
						<div className="newspack-ui">
							<div className={ `banner newspack-content-gifting__cta is-style-${ config?.content_gifting?.style || 'light' }` }>
								<div className="wrapper newspack-content-gifting__cta__content">
									<div className="newspack-ui__font--s">
										{ config?.content_gifting?.cta_label ||
											__(
												'This article has been gifted to you by someone who values great journalism.',
												'newspack-plugin'
											) }{ ' ' }
										<div className="newspack-ui__font--xs newspack-content-gifting__cta__content__links">
											{ hasMetering ? (
												<a href="#register_modal">{ __( 'Create an account', 'newspack-plugin' ) }</a>
											) : (
												<a href="#signin_modal">{ __( 'Sign in to an existing account', 'newspack-plugin' ) }</a>
											) }
										</div>
									</div>
									{ ( ( config?.content_gifting?.cta_type === 'product' && config?.content_gifting?.cta_product_id ) ||
										( config?.content_gifting?.cta_type === 'url' && config?.content_gifting?.cta_url ) ) && (
										<button
											className={ `newspack-ui__button newspack-ui__button--x-small ${
												( config?.content_gifting?.style || 'light' ) === 'dark'
													? 'newspack-ui__button--primary-light'
													: 'newspack-ui__button--accent'
											}` }
										>
											{ config?.content_gifting?.button_label || __( 'Subscribe now', 'newspack-plugin' ) }
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

export default ContentGiftingSettings;
