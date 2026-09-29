/**
 * Countdown banner section of the Metering settings page.
 *
 * The banner counts down the metered allowance, so it has nowhere to live apart
 * from the meter that produces the number it shows.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Grid, SectionHeader, SelectControl, TextControl } from '../../../../../../packages/components/src';

interface CountdownBannerProps {
	countdown: MeteringCountdownConfig;
	onChange: ( countdown: MeteringCountdownConfig ) => void;
	/** Whether any gate currently meters, so the banner has something to count down. */
	hasMetering: boolean;
	/** The allowance the banner counts down, as currently edited. */
	meterCount: number;
	/** The reset period the allowance runs on, as currently edited. */
	meterPeriod: SiteMeterConfig[ 'period' ];
	/** Which readers the previewed allowance belongs to. */
	meterAudience: 'anonymous' | 'registered';
	/** Whether the audience not previewed also gets free views, and so also sees the banner. */
	otherAudienceMeters: boolean;
}

/**
 * The line the banner shows a reader, with one view already spent.
 *
 * Built from the allowance being edited on this page rather than a fixed example, so
 * the preview cannot promise a different number from the controls beside it.
 *
 * @param count  The allowance.
 * @param period The reset period it runs on.
 */
const getCountdownLabel = ( count: number, period: SiteMeterConfig[ 'period' ] ) => {
	if ( period === 'week' ) {
		return sprintf(
			// translators: %d is the number of free articles the allowance grants.
			_n( '1/%d free article this week', '1/%d free articles this week', count, 'newspack-plugin' ),
			count
		);
	}
	return sprintf(
		// translators: %d is the number of free articles the allowance grants.
		_n( '1/%d free article this month', '1/%d free articles this month', count, 'newspack-plugin' ),
		count
	);
};

/**
 * What the preview stands in for, and what the other audience sees.
 *
 * The banner shows one reader one number, so the preview has to pick an audience. It
 * previews whichever one these settings actually grant views to, and speaks only for
 * these settings: a gate keeping its own allowance can still meter the other audience,
 * and the Free Views section above names those gates.
 *
 * @param audience            The audience being previewed.
 * @param otherAudienceMeters Whether the other audience also gets free views.
 */
const getPreviewHelp = ( audience: 'anonymous' | 'registered', otherAudienceMeters: boolean ) => {
	if ( audience === 'registered' ) {
		return otherAudienceMeters
			? __(
					'Shown as a signed-in reader sees it. Signed-out readers get the same banner counting down their own allowance.',
					'newspack-plugin'
			  )
			: __( 'Shown as a signed-in reader sees it. These settings give signed-out readers no free views.', 'newspack-plugin' );
	}
	return __( 'Shown as a signed-out reader sees it. These settings give signed-in readers no free views.', 'newspack-plugin' );
};

export default function CountdownBanner( {
	countdown,
	onChange,
	hasMetering,
	meterCount,
	meterPeriod,
	meterAudience,
	otherAudienceMeters,
}: CountdownBannerProps ) {
	const availableProducts = window.newspackAudience?.available_products || [];
	const update = ( value: Partial< MeteringCountdownConfig > ) => onChange( { ...countdown, ...value } );
	// Sanitized to an int by REST, so a bare `enabled && ...` would render a literal 0.
	const isEnabled = !! countdown.enabled;

	return (
		<VStack spacing={ 6 }>
			<Grid columns={ 2 } noMargin>
				<VStack spacing={ 6 } justify="flex-start">
					<SectionHeader
						heading={ 2 }
						title={ __( 'Countdown Banner', 'newspack-plugin' ) }
						description={ __( 'Tell readers how many free views they have left before a gate applies.', 'newspack-plugin' ) }
					/>
					{ isEnabled && ! hasMetering && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'No gate meters yet, so there is nothing to count down and the banner will not appear. Turn on metering for a gate to show it.',
								'newspack-plugin'
							) }
						</Notice>
					) }
				</VStack>
				<VStack spacing={ 6 } justify="flex-start">
					<ToggleGroupControl
						label={ __( 'Banner', 'newspack-plugin' ) }
						value={ isEnabled ? 'enabled' : 'disabled' }
						onChange={ value => update( { enabled: value === 'enabled' ? 1 : 0 } ) }
						isBlock
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Enabled', 'newspack-plugin' ) } value="enabled" />
						<ToggleGroupControlOption label={ __( 'Disabled', 'newspack-plugin' ) } value="disabled" />
					</ToggleGroupControl>
					<fieldset className="newspack-countdown-banner__fields" disabled={ ! isEnabled }>
						<legend className="screen-reader-text">{ __( 'Countdown banner settings', 'newspack-plugin' ) }</legend>
						<VStack spacing={ 6 } justify="flex-start">
							<TextControl
								label={ __( 'Message', 'newspack-plugin' ) }
								help={ __( 'Text displayed in the countdown banner.', 'newspack-plugin' ) }
								value={ countdown.cta_label || '' }
								onChange={ ( value: string ) => update( { cta_label: value } ) }
								withMargin={ false }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Subscribe button label', 'newspack-plugin' ) }
								help={ __( 'Text displayed on the subscribe button in the banner.', 'newspack-plugin' ) }
								value={ countdown.button_label || '' }
								onChange={ ( value: string ) => update( { button_label: value } ) }
								withMargin={ false }
								__next40pxDefaultSize
							/>
							<ToggleGroupControl
								label={ __( 'Style', 'newspack-plugin' ) }
								value={ countdown.style || 'light' }
								onChange={ value => update( { style: value as string } ) }
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
								value={ countdown.cta_type || 'product' }
								onChange={ value => update( { cta_type: value as string } ) }
								isBlock
								__next40pxDefaultSize
							>
								<ToggleGroupControlOption label={ __( 'Product', 'newspack-plugin' ) } value="product" />
								<ToggleGroupControlOption label={ __( 'Landing page', 'newspack-plugin' ) } value="url" />
							</ToggleGroupControl>
							{ countdown.cta_type === 'product' && (
								<SelectControl
									label={ __( 'Subscribe button product', 'newspack-plugin' ) }
									help={ __( 'Product linked to the subscribe button.', 'newspack-plugin' ) }
									options={ [
										{ label: __( 'Select a product', 'newspack-plugin' ), value: 0, disabled: true },
										...availableProducts,
									] }
									value={ countdown.cta_product_id || 0 }
									suggestions={ availableProducts.map( o => o.label ) }
									onChange={ ( value: number ) => update( { cta_product_id: value } ) }
									__next40pxDefaultSize
								/>
							) }
							{ countdown.cta_type === 'url' && (
								<TextControl
									label={ __( 'Subscribe button URL', 'newspack-plugin' ) }
									help={ __( 'URL for the landing page to redirect to.', 'newspack-plugin' ) }
									value={ countdown.cta_url || '' }
									onChange={ ( value: string ) => update( { cta_url: value } ) }
									withMargin={ false }
									__next40pxDefaultSize
								/>
							) }
						</VStack>
					</fieldset>
				</VStack>
			</Grid>
			<div
				className={ classnames( 'newspack-countdown-banner__preview', {
					'newspack-countdown-banner__preview--disabled': ! isEnabled,
				} ) }
			>
				<BaseControl
					id="newspack-countdown-banner-cta-preview"
					label={ __( 'Preview', 'newspack-plugin' ) }
					help={ getPreviewHelp( meterAudience, otherAudienceMeters ) }
				>
					<div className="newspack-countdown-banner__cta-preview" inert="true">
						<div className="newspack-ui">
							<div className={ `banner newspack-countdown-banner__cta is-style-${ countdown.style || 'light' }` }>
								<div className="wrapper newspack-countdown-banner__cta__content">
									<div className="newspack-countdown-banner__cta__content__wrapper">
										<span className="newspack-countdown-banner__cta__content__countdown newspack-ui__font--s">
											<strong>{ getCountdownLabel( meterCount, meterPeriod ) }</strong>
										</span>
										<span className="newspack-countdown-banner__cta__content__message newspack-ui__font--xs">
											{ countdown.cta_label || __( 'Subscribe now and get unlimited access.', 'newspack-plugin' ) }{ ' ' }
											<a href="#signin_modal">{ __( 'Sign in to an existing account', 'newspack-plugin' ) }</a>.
										</span>
									</div>
									{ ( ( countdown.cta_type === 'product' && countdown.cta_product_id ) ||
										( countdown.cta_type === 'url' && countdown.cta_url ) ) && (
										<button
											className={ `newspack-ui__button newspack-ui__button--x-small ${
												( countdown.style || 'light' ) === 'dark'
													? 'newspack-ui__button--primary-light'
													: 'newspack-ui__button--accent'
											}` }
										>
											{ countdown.button_label || __( 'Subscribe now', 'newspack-plugin' ) }
										</button>
									) }
								</div>
							</div>
						</div>
					</div>
				</BaseControl>
			</div>
		</VStack>
	);
}
