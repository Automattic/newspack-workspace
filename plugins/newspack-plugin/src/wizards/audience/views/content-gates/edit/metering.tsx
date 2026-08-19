/**
 * WordPress dependencies.
 */
import {
	ExternalLink,
	Notice,
	__experimentalNumberControl as NumberControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis,
	ToggleControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */

interface MeteringProps {
	description?: string;
	metering: Metering;
	onChange: React.Dispatch< React.SetStateAction< Metering > > | ( ( metering: Metering ) => void );
	/** The site meter allowance governing this audience path, when one is loaded. */
	siteCount?: number;
	sitePeriod?: Metering[ 'period' ];
}

/**
 * Describe the shared allowance in the gate editor, so a publisher can see what
 * "Site-wide" grants without leaving the gate to find out.
 *
 * @param count  Free views the site meter grants this audience path.
 * @param period How often the allowance resets.
 */
function getSiteMeterSummary( count: number, period: Metering[ 'period' ] ) {
	if ( period === 'week' ) {
		return sprintf(
			// translators: %d is the number of free views the site meter grants each week.
			_n(
				'%d free view a week, shared with every other gate.',
				'%d free views a week, shared with every other gate.',
				count,
				'newspack-plugin'
			),
			count
		);
	}
	return sprintf(
		// translators: %d is the number of free views the site meter grants each month.
		_n( '%d free view a month, shared with every other gate.', '%d free views a month, shared with every other gate.', count, 'newspack-plugin' ),
		count
	);
}

export default function Metering( { description, metering, onChange, siteCount, sitePeriod }: MeteringProps ) {
	const count = typeof metering.count === 'number' ? metering.count : parseInt( String( metering.count ), 10 );
	// Gates saved before the site meter existed carry no scope, and share by default.
	const scope: MeteringScope = metering.scope === 'gate' ? 'gate' : 'site';
	const isSiteScoped = scope === 'site';
	const effectiveCount = isSiteScoped ? siteCount : count;
	const isCountZero = typeof effectiveCount === 'number' && ! isNaN( effectiveCount ) && effectiveCount === 0;

	return (
		<>
			<ToggleControl
				label={ __( 'Metering', 'newspack-plugin' ) }
				help={ description || __( 'Allow limited free views before access conditions apply.', 'newspack-plugin' ) }
				checked={ metering.enabled }
				onChange={ () => onChange( { ...metering, enabled: ! metering.enabled } ) }
			/>
			{ metering.enabled && (
				<>
					{ isCountZero && (
						<Notice status="warning" isDismissible={ false }>
							{ isSiteScoped
								? __(
										'The site meter grants 0 free views, so no reader gets a free view and content is gated for everyone, the same as turning Metering off. Raise the site meter, or give this gate its own allowance.',
										'newspack-plugin'
								  )
								: __(
										'Free views is set to 0, so no reader gets a free view and content is gated for everyone — the same behavior as turning Metering off. Set 1 or more free views to meter access.',
										'newspack-plugin'
								  ) }
						</Notice>
					) }
					<ToggleGroupControl
						label={ __( 'Meter', 'newspack-plugin' ) }
						help={
							isSiteScoped && typeof siteCount === 'number' && sitePeriod ? (
								<>
									{ getSiteMeterSummary( siteCount, sitePeriod ) }{ ' ' }
									{ /* A new tab, so opening the site meter cannot discard unsaved gate edits. */ }
									<ExternalLink href={ `${ window.location.pathname }${ window.location.search }#/settings/metering` }>
										{ __( 'Edit site meter', 'newspack-plugin' ) }
									</ExternalLink>
								</>
							) : (
								__( 'Whether this gate draws on the site-wide allowance or keeps its own.', 'newspack-plugin' )
							)
						}
						value={ scope }
						onChange={ v => onChange( { ...metering, scope: v as MeteringScope } ) }
						isBlock
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Site-wide', 'newspack-plugin' ) } value="site" />
						<ToggleGroupControlOption label={ __( 'This gate only', 'newspack-plugin' ) } value="gate" />
					</ToggleGroupControl>
					{ ! isSiteScoped && (
						<>
							<NumberControl
								label={ __( 'Free views', 'newspack-plugin' ) }
								help={ __( 'Free views before the gate appears.', 'newspack-plugin' ) }
								min={ 0 }
								value={ count }
								// Floor and round here rather than relying on `min`/`step`, which the control only
								// enforces when it commits (blur/Enter): a raw keystroke would otherwise put a
								// negative or fractional count into gate state.
								onChange={ v => onChange( { ...metering, count: Math.max( 0, Math.round( Number( v ) || 0 ) ) } ) }
								__next40pxDefaultSize
							/>
							<ToggleGroupControl
								label={ __( 'Reset period', 'newspack-plugin' ) }
								help={ __( 'How often free views reset.', 'newspack-plugin' ) }
								value={ metering.period }
								onChange={ v => onChange( { ...metering, period: v as Metering[ 'period' ] } ) }
								isBlock
								__next40pxDefaultSize
							>
								<ToggleGroupControlOption label={ __( 'Monthly', 'newspack-plugin' ) } value="month" />
								<ToggleGroupControlOption label={ __( 'Weekly', 'newspack-plugin' ) } value="week" />
							</ToggleGroupControl>
						</>
					) }
				</>
			) }
		</>
	);
}
