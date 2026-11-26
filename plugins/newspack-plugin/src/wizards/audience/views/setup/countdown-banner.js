/* global newspackAudience */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	BaseControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

import { ActionCard, Button, Grid, SelectControl, TextControl } from '../../../../../packages/components/src';
import BannerStyleControl, { getBannerPreviewProps } from '../../components/banner-style';

export default function CountdownBanner( { config, setConfig, updateConfig, noBorder = false } ) {
	const availableProducts = newspackAudience?.available_products || [];
	const preview = getBannerPreviewProps( config.countdown_banner || {} );
	return (
		<ActionCard
			title={ __( 'Metered Countdown', 'newspack-plugin' ) }
			heading={ noBorder ? 1 : 2 }
			description={ __( 'Show a countdown banner before content is restricted by a metered content gate.', 'newspack-plugin' ) }
			toggleOnChange={ value => updateConfig( { countdown_banner: { enabled: value } } ) }
			toggleChecked={ config.countdown_banner?.enabled }
			hasGreyHeader={ ! noBorder && config.countdown_banner?.enabled }
			togglePosition="trailing"
			noBorder={ noBorder }
		>
			{ config.countdown_banner?.enabled && (
				<>
					<Grid columns={ 2 } rowGap={ 16 }>
						<TextControl
							label={ __( 'Message', 'newspack-plugin' ) }
							help={ __( 'Text displayed in the countdown banner.', 'newspack-plugin' ) }
							value={ config.countdown_banner.cta_label }
							onChange={ value => setConfig( { ...config, countdown_banner: { ...config.countdown_banner, cta_label: value } } ) }
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __( 'Subscribe button label', 'newspack-plugin' ) }
							help={ __( 'Text displayed on the subscribe button in the banner.', 'newspack-plugin' ) }
							value={ config.countdown_banner.button_label }
							onChange={ value => setConfig( { ...config, countdown_banner: { ...config.countdown_banner, button_label: value } } ) }
							__next40pxDefaultSize
						/>
					</Grid>
					<Grid columns={ 2 } rowGap={ 16 }>
						<ToggleGroupControl
							label={ __( 'Subscribe button action', 'newspack-plugin' ) }
							help={ __(
								'Whether the subscribe button should start a product checkout or redirect to a landing page.',
								'newspack-plugin'
							) }
							value={ config.countdown_banner.cta_type || 'product' }
							onChange={ value => setConfig( { ...config, countdown_banner: { ...config.countdown_banner, cta_type: value } } ) }
							isBlock
							__next40pxDefaultSize
						>
							<ToggleGroupControlOption label={ __( 'Product', 'newspack-plugin' ) } value="product" />
							<ToggleGroupControlOption label={ __( 'Landing page', 'newspack-plugin' ) } value="url" />
						</ToggleGroupControl>
						{ config.countdown_banner.cta_type === 'product' && (
							<SelectControl
								label={ __( 'Subscribe button product', 'newspack-plugin' ) }
								help={ __( 'Product linked to the subscribe button.', 'newspack-plugin' ) }
								options={ [ { label: __( 'Select a product', 'newspack-plugin' ), value: 0, disabled: true }, ...availableProducts ] }
								value={ config.countdown_banner.cta_product_id }
								suggestions={ availableProducts.map( o => o.label ) }
								onChange={ value =>
									setConfig( { ...config, countdown_banner: { ...config.countdown_banner, cta_product_id: value } } )
								}
								__next40pxDefaultSize
							/>
						) }
						{ config.countdown_banner.cta_type === 'url' && (
							<TextControl
								label={ __( 'Subscribe button URL', 'newspack-plugin' ) }
								help={ __( 'URL for the landing page to redirect to.', 'newspack-plugin' ) }
								value={ config.countdown_banner.cta_url }
								onChange={ value => setConfig( { ...config, countdown_banner: { ...config.countdown_banner, cta_url: value } } ) }
								withMargin={ false }
								__next40pxDefaultSize
							/>
						) }
						<BannerStyleControl
							gridCells
							settings={ config.countdown_banner }
							onChange={ value => setConfig( { ...config, countdown_banner: { ...config.countdown_banner, ...value } } ) }
						/>
						<div style={ { gridColumn: '1 / -1' } }>
							<BaseControl id="newspack-countdown-banner-cta-preview" label={ __( 'Preview', 'newspack-plugin' ) }>
								<div className="newspack-countdown-banner__cta-preview" inert="true">
									<div className="newspack-ui">
										<div
											className={ `banner newspack-countdown-banner__cta ${ preview.bannerClassName }` }
											style={ preview.bannerStyle }
										>
											<div className="wrapper newspack-countdown-banner__cta__content">
												<div className="newspack-countdown-banner__cta__content__wrapper">
													<span className="newspack-countdown-banner__cta__content__countdown newspack-ui__font--s">
														<strong>{ __( '1/10 free articles this month', 'newspack-plugin' ) }</strong>
													</span>
													<span className="newspack-countdown-banner__cta__content__message newspack-ui__font--xs">
														{ config.countdown_banner.cta_label ||
															__( 'Subscribe now and get unlimited access.', 'newspack-plugin' ) }{ ' ' }
														<a href="#signin_modal">{ __( 'Sign in to an existing account', 'newspack-plugin' ) }</a>.
													</span>
												</div>
												{ ( ( config.countdown_banner.cta_type === 'product' && config.countdown_banner.cta_product_id ) ||
													( config.countdown_banner.cta_type === 'url' && config.countdown_banner.cta_url ) ) && (
													<button className={ `newspack-ui__button ${ preview.buttonClassName }` }>
														{ config.countdown_banner.button_label || __( 'Subscribe now', 'newspack-plugin' ) }
													</button>
												) }
											</div>
										</div>
									</div>
								</div>
							</BaseControl>
						</div>
					</Grid>
					<div className="newspack-buttons-card" style={ { margin: '32px 0 0 0' } }>
						<Button isPrimary onClick={ () => updateConfig( { countdown_banner: config.countdown_banner } ) }>
							{ __( 'Save Settings', 'newspack-plugin' ) }
						</Button>
					</div>
				</>
			) }
		</ActionCard>
	);
}
