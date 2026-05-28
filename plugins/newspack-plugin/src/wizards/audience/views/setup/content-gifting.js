/* global newspackAudience */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	RangeControl,
	BaseControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { ActionCard, Button, Grid, Notice, SelectControl, TextControl } from '../../../../../packages/components/src';

export default function ContentGifting( { config, setConfig, updateConfig, noBorder = false } ) {
	const giftingErrors = Object.values( newspackAudience?.content_gifting?.can_use_gifting?.errors || {} ).flat();
	const availableProducts = newspackAudience?.available_products || [];
	const hasMetering = newspackAudience?.content_gifting?.has_metering;

	return (
		<ActionCard
			title={ __( 'Content Gifting', 'newspack-plugin' ) }
			heading={ noBorder ? 1 : 2 }
			description={ __( 'Allow members to gift articles up to the configured limit.', 'newspack-plugin' ) }
			toggleOnChange={ value => updateConfig( { content_gifting: { enabled: value } } ) }
			toggleChecked={ config.content_gifting?.enabled }
			hasGreyHeader={ ! noBorder && config.content_gifting?.enabled }
			togglePosition="trailing"
			noBorder={ noBorder }
		>
			{ config.content_gifting?.enabled && (
				<>
					{ giftingErrors.length > 0 && <Notice noticeText={ giftingErrors.join( ', ' ) } isError /> }
					<Grid columns={ 2 } rowGap={ 16 }>
						<Heading level={ 4 } style={ { gridColumn: '1 / -1' } }>
							{ __( 'General Settings', 'newspack-plugin' ) }
						</Heading>
						<RangeControl
							label={ __( 'Gifting limit', 'newspack-plugin' ) }
							help={ __( 'Maximum number of articles that can be gifted per user for the configured interval.', 'newspack-plugin' ) }
							min={ 1 }
							max={ 20 }
							value={ config.content_gifting.limit }
							onChange={ value => setConfig( { ...config, content_gifting: { ...config.content_gifting, limit: value } } ) }
							__next40pxDefaultSize
						/>
						<SelectControl
							label={ __( 'Gifting limit interval', 'newspack-plugin' ) }
							help={ __( 'Interval at which the gifting limit is reset.', 'newspack-plugin' ) }
							value={ config.content_gifting.interval }
							onChange={ value => setConfig( { ...config, content_gifting: { ...config.content_gifting, interval: value } } ) }
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
							value={ config.content_gifting.expiration_time || 5 }
							onChange={ value => setConfig( { ...config, content_gifting: { ...config.content_gifting, expiration_time: value } } ) }
							__next40pxDefaultSize
						/>
						<SelectControl
							label={ __( 'Article link expiration time unit', 'newspack-plugin' ) }
							help={ __( 'Unit of time for the article link expiration time.', 'newspack-plugin' ) }
							value={ config.content_gifting.expiration_time_unit || 'days' }
							onChange={ value =>
								setConfig( { ...config, content_gifting: { ...config.content_gifting, expiration_time_unit: value } } )
							}
							options={ [
								{ value: 'hours', label: __( 'Hours', 'newspack-plugin' ) },
								{ value: 'days', label: __( 'Days', 'newspack-plugin' ) },
							] }
							__next40pxDefaultSize
						/>
					</Grid>
					<Grid columns={ 1 }>
						<hr />
					</Grid>
					<Grid columns={ 2 } rowGap={ 16 }>
						<Heading level={ 4 } style={ { gridColumn: '1 / -1' } }>
							{ __( 'Recipient Banner', 'newspack-plugin' ) }
						</Heading>
						<TextControl
							label={ __( 'Message', 'newspack-plugin' ) }
							help={ __( 'Text displayed in the banner shown to recipients of gifted articles.', 'newspack-plugin' ) }
							value={ config.content_gifting.cta_label }
							onChange={ value => setConfig( { ...config, content_gifting: { ...config.content_gifting, cta_label: value } } ) }
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __( 'Subscribe button label', 'newspack-plugin' ) }
							help={ __( 'Text displayed on the subscribe button in the banner.', 'newspack-plugin' ) }
							value={ config.content_gifting.button_label }
							onChange={ value => setConfig( { ...config, content_gifting: { ...config.content_gifting, button_label: value } } ) }
							__next40pxDefaultSize
						/>
					</Grid>
					<Grid columns={ 3 } rowGap={ 16 }>
						<ToggleGroupControl
							label={ __( 'Style', 'newspack-plugin' ) }
							value={ config.content_gifting.style || 'light' }
							onChange={ value => setConfig( { ...config, content_gifting: { ...config.content_gifting, style: value } } ) }
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
							value={ config.content_gifting.cta_type || 'product' }
							onChange={ value => setConfig( { ...config, content_gifting: { ...config.content_gifting, cta_type: value } } ) }
							isBlock
							__next40pxDefaultSize
						>
							<ToggleGroupControlOption label={ __( 'Product', 'newspack-plugin' ) } value="product" />
							<ToggleGroupControlOption label={ __( 'Landing page', 'newspack-plugin' ) } value="url" />
						</ToggleGroupControl>
						{ config.content_gifting.cta_type === 'product' && (
							<SelectControl
								label={ __( 'Subscribe button product', 'newspack-plugin' ) }
								help={ __( 'Product linked to the subscribe button.', 'newspack-plugin' ) }
								options={ [ { label: __( 'Select a product', 'newspack-plugin' ), value: 0, disabled: true }, ...availableProducts ] }
								value={ config.content_gifting.cta_product_id }
								suggestions={ availableProducts.map( o => o.label ) }
								onChange={ value =>
									setConfig( { ...config, content_gifting: { ...config.content_gifting, cta_product_id: value } } )
								}
								__next40pxDefaultSize
							/>
						) }
						{ config.content_gifting.cta_type === 'url' && (
							<TextControl
								label={ __( 'Subscribe button URL', 'newspack-plugin' ) }
								help={ __( 'URL for the landing page to redirect to.', 'newspack-plugin' ) }
								value={ config.content_gifting.cta_url }
								onChange={ value => setConfig( { ...config, content_gifting: { ...config.content_gifting, cta_url: value } } ) }
								withMargin={ false }
								__next40pxDefaultSize
							/>
						) }
						<div style={ { gridColumn: '1 / -1' } }>
							<BaseControl id="newspack-content-gifting-cta-preview" label={ __( 'Preview', 'newspack-plugin' ) }>
								<div className="newspack-content-gifting__cta-preview" inert="true">
									<div className="newspack-ui">
										<div
											className={ `banner newspack-content-gifting__cta is-style-${ config.content_gifting.style || 'light' }` }
										>
											<div className="wrapper newspack-content-gifting__cta__content">
												<div className="newspack-ui__font--s">
													{ config.content_gifting.cta_label ||
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
												{ ( ( config.content_gifting.cta_type === 'product' && config.content_gifting.cta_product_id ) ||
													( config.content_gifting.cta_type === 'url' && config.content_gifting.cta_url ) ) && (
													<button
														className={ `newspack-ui__button newspack-ui__button--x-small ${
															( config.content_gifting.style || 'light' ) === 'dark'
																? 'newspack-ui__button--primary-light'
																: 'newspack-ui__button--accent'
														}` }
													>
														{ config.content_gifting.button_label || __( 'Subscribe now', 'newspack-plugin' ) }
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
						<Button isPrimary onClick={ () => updateConfig( { content_gifting: config.content_gifting } ) }>
							{ __( 'Save Settings', 'newspack-plugin' ) }
						</Button>
					</div>
				</>
			) }
		</ActionCard>
	);
}
