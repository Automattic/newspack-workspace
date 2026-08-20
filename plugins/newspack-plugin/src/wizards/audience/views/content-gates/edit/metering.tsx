/**
 * WordPress dependencies.
 */
import {
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
	/**
	 * The signed-out allowance, set only when this path governs signed-out readers as
	 * well as signed-in ones. A gate stores one count per path, so opting such a path
	 * out collapses two shared allowances into one.
	 */
	signedOutSiteCount?: number;
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

export default function Metering( { description, metering, onChange, siteCount, sitePeriod, signedOutSiteCount }: MeteringProps ) {
	const count = typeof metering.count === 'number' ? metering.count : parseInt( String( metering.count ), 10 );
	// Gates saved before the site meter existed carry no scope, and share by default.
	const scope: MeteringScope = metering.scope === 'gate' ? 'gate' : 'site';
	const isSiteScoped = scope === 'site';
	const effectiveCount = isSiteScoped ? siteCount : count;
	const isCountZero = typeof effectiveCount === 'number' && ! isNaN( effectiveCount ) && effectiveCount === 0;
	// One path, two audiences, two allowances: whichever the gate keeps, the other changes.
	const servesTwoAllowances = typeof signedOutSiteCount === 'number' && typeof siteCount === 'number' && signedOutSiteCount !== siteCount;

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
										'The site meter grants these readers 0 free views, so they are gated on their first view, the same as turning Metering off. Raise the site meter, or give this gate its own allowance.',
										'newspack-plugin'
								  )
								: __(
										'Free views is set to 0, so no reader gets a free view and content is gated for everyone — the same behavior as turning Metering off. Set 1 or more free views to meter access.',
										'newspack-plugin'
								  ) }
						</Notice>
					) }
					{ isSiteScoped && servesTwoAllowances && (
						<Notice status="info" isDismissible={ false }>
							{ sprintf(
								// translators: %1$d is the signed-out allowance, %2$d the signed-in allowance.
								__(
									'With no registration wall, this meters signed-out readers too: %1$d free views site-wide, against %2$d for signed-in readers. A gate keeps one allowance, so opting out gives both the lower number.',
									'newspack-plugin'
								),
								signedOutSiteCount,
								siteCount
							) }
						</Notice>
					) }
					<ToggleGroupControl
						label={ __( 'Meter', 'newspack-plugin' ) }
						help={
							isSiteScoped && typeof siteCount === 'number' && sitePeriod
								? getSiteMeterSummary( siteCount, sitePeriod )
								: __( 'Whether this gate draws on the site-wide allowance or keeps its own.', 'newspack-plugin' )
						}
						value={ scope }
						onChange={ v => {
							// Coerced, not cast: undefined is dropped by REST and refilled from the
							// stored value, leaving a pinned gate pinned while this renders "Site-wide".
							const nextScope: MeteringScope = 'gate' === v ? 'gate' : 'site';
							// Carry the shared allowance across, so opting out means "stop sharing this"
							// rather than silently dropping to whatever count the gate last stored.
							if ( 'gate' === nextScope && isSiteScoped ) {
								// The lower of the two: one count cannot preserve both, and the larger
								// would widen the signed-out allowance rather than narrow a signed-in one.
								const carried = servesTwoAllowances ? Math.min( siteCount as number, signedOutSiteCount as number ) : siteCount;
								onChange( {
									...metering,
									scope: nextScope,
									count: typeof carried === 'number' ? carried : metering.count,
									period: sitePeriod ?? metering.period,
								} );
								return;
							}
							onChange( { ...metering, scope: nextScope } );
						} }
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
								// `min`/`step` are only enforced on commit, so a raw keystroke would otherwise
								// put a negative or fractional count into gate state.
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
