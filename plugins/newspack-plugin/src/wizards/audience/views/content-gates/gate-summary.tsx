/**
 * Shared gate summary sections, rendered identically by the Access control
 * list card and the pre-save panel.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ContentRuleControl from './edit/content-rule-control';
import { getMeteringCount } from './utils';
import { normalizeOneTimePurchaseValue } from '../../../../content-gate/components/one-time-purchase-rule-control';

const availableAccessRules = window.newspackAudienceContentGates.available_access_rules || {};

const noOp = () => {};

/**
 * Map option values to labels, falling back to the raw value.
 */
const getOptionLabels = ( values: Array< string | number >, options: { value: string | number; label: string }[] = [] ) =>
	values.map( value => options.find( option => String( option.value ) === String( value ) )?.label ?? String( value ) ).join( ', ' );

/**
 * Human-readable summary for an access rule value.
 */
const formatAccessRuleValue = ( rule: GateAccessRule ): string => {
	const config = availableAccessRules[ rule.slug ];
	if ( 'one_time_purchase' === rule.slug ) {
		const { product_ids: productIds, duration_value: durationValue, duration_unit: durationUnit } = normalizeOneTimePurchaseValue( rule.value );
		const products = getOptionLabels( productIds, config?.options );
		if ( 'forever' === durationUnit ) {
			return sprintf(
				// translators: %s: list of product names.
				__( '%s (forever)', 'newspack-plugin' ),
				products
			);
		}
		if ( 'days' === durationUnit ) {
			return sprintf(
				// translators: 1: list of product names, 2: number of days.
				_n( '%1$s (%2$d day from purchase)', '%1$s (%2$d days from purchase)', durationValue, 'newspack-plugin' ),
				products,
				durationValue
			);
		}
		if ( 'months' === durationUnit ) {
			return sprintf(
				// translators: 1: list of product names, 2: number of months.
				_n( '%1$s (%2$d month from purchase)', '%1$s (%2$d months from purchase)', durationValue, 'newspack-plugin' ),
				products,
				durationValue
			);
		}
		return sprintf(
			// translators: %s: list of product names. Shown when the stored duration is unrecognized; the rule then never grants access.
			__( '%s (invalid duration, grants no access)', 'newspack-plugin' ),
			products
		);
	}
	if ( Array.isArray( rule.value ) && config?.options ) {
		return getOptionLabels( rule.value, config.options );
	}
	// Boolean rules carry no displayable value (mirrors the pre-formatter
	// rendering, where React printed nothing for a boolean child).
	if ( 'boolean' === typeof rule.value ) {
		return '';
	}
	return String( rule.value );
};

export type GateSummarySection = {
	key: string;
	label: string;
	content: React.ReactNode;
};

/**
 * Summarise one audience path's allowance, naming the site meter when the count
 * is shared. Without that, several gates showing the same number read as several
 * separate allowances, which is the confusion the shared meter exists to remove.
 *
 * @param metering   The path's metering settings.
 * @param siteCount  The site meter count governing this path.
 * @param sitePeriod The site meter reset period.
 * @param hasRegwall Whether the gate offers registration before this path. Without
 *                   one, running out of free views is the end of the road, so the
 *                   summary says so rather than leaving the publisher to infer it.
 */
const formatMetering = ( metering: Metering, siteCount?: number, sitePeriod?: Metering[ 'period' ], hasRegwall = true ) => {
	const isSiteScoped = metering.scope !== 'gate';
	const count = getMeteringCount( metering, siteCount );
	const period = isSiteScoped ? sitePeriod ?? 'month' : metering.period;
	const periodLabel = period === 'week' ? __( 'week', 'newspack-plugin' ) : __( 'month', 'newspack-plugin' );
	const allowance = hasRegwall
		? sprintf(
				// translators: 1: metering count, 2: metering period
				_n( '%1$d free view per %2$s', '%1$d free views per %2$s', count, 'newspack-plugin' ),
				count,
				periodLabel
		  )
		: sprintf(
				// translators: 1: metering count, 2: metering period
				_n(
					'%1$d free view per %2$s before content is restricted',
					'%1$d free views per %2$s before content is restricted',
					count,
					'newspack-plugin'
				),
				count,
				periodLabel
		  );
	return isSiteScoped
		? sprintf(
				// translators: %s is the allowance, e.g. "3 free views per month".
				__( '%s (site meter)', 'newspack-plugin' ),
				allowance
		  )
		: sprintf(
				// translators: %s is the allowance, e.g. "3 free views per month".
				__( '%s (this gate only)', 'newspack-plugin' ),
				allowance
		  );
};

/**
 * Build the Content rules / Registered access / Paid access sections for a gate.
 *
 * @param gate         The gate (live edit state or a saved gate).
 * @param isNewsletter Whether this is a premium-newsletter gate (hides registration).
 * @param siteMeter    The shared allowance, so a summary can name where its count comes from.
 */
export const getGateSummarySections = ( gate: Gate, isNewsletter = false, siteMeter?: SiteMeterConfig ): GateSummarySection[] => {
	const sections: GateSummarySection[] = [];

	sections.push( {
		key: 'content_rules',
		label: __( 'Content Rules', 'newspack-plugin' ),
		content:
			gate.content_rules.length > 0 ? (
				gate.content_rules.map( rule => (
					<ContentRuleControl
						key={ rule.slug }
						slug={ rule.slug }
						value={ rule.value }
						exclusion={ rule.exclusion }
						onChange={ noOp }
						onChangeExclusion={ noOp }
						isStatic
					/>
				) )
			) : (
				<p>
					<strong>{ __( 'N/A', 'newspack-plugin' ) }</strong>
				</p>
			),
	} );

	if ( ! isNewsletter ) {
		sections.push( {
			key: 'registration',
			label: __( 'Registered Access', 'newspack-plugin' ),
			content: (
				<>
					{ gate.registration?.active && (
						<p>
							<strong>{ __( 'Require verification:', 'newspack-plugin' ) } </strong>{ ' ' }
							{ gate.registration.require_verification ? __( 'Yes', 'newspack-plugin' ) : __( 'No', 'newspack-plugin' ) }
						</p>
					) }
					{ gate.registration?.active && gate.registration.metering.enabled && (
						<p>
							<strong>{ __( 'Metered:', 'newspack-plugin' ) } </strong>{ ' ' }
							{ formatMetering( gate.registration.metering, siteMeter?.anonymous_count, siteMeter?.period ) }
						</p>
					) }
					{ ! gate.registration?.active && (
						<p>
							<strong>{ __( 'N/A', 'newspack-plugin' ) }</strong>
						</p>
					) }
				</>
			),
		} );
	}

	const showsAccessRules = Boolean( gate.custom_access?.active && gate.custom_access.access_rules.length > 0 );
	const showsPaidMetering = Boolean( gate.custom_access?.active && gate.custom_access.metering.enabled );
	const hasRegwall = Boolean( ! isNewsletter && gate.registration?.active );

	sections.push( {
		key: 'custom_access',
		label: __( 'Paid Access', 'newspack-plugin' ),
		content: (
			<>
				{ showsAccessRules &&
					gate.custom_access.access_rules.map( ( ruleGroup, groupIndex ) =>
						ruleGroup.map( rule =>
							availableAccessRules[ rule.slug ]?.name ? (
								<p key={ `${ groupIndex }-${ rule.slug }` }>
									<strong>{ availableAccessRules[ rule.slug ].name }:</strong> { formatAccessRuleValue( rule ) }
								</p>
							) : null
						)
					) }
				{ showsPaidMetering && (
					<p>
						<strong>{ __( 'Metered:', 'newspack-plugin' ) } </strong>{ ' ' }
						{ formatMetering( gate.custom_access.metering, siteMeter?.registered_count, siteMeter?.period, hasRegwall ) }
					</p>
				) }
				{ /* Only when the column has nothing else: N/A beside a metering line reads as a contradiction. */ }
				{ ! showsAccessRules && ! showsPaidMetering && (
					<p>
						<strong>{ __( 'N/A', 'newspack-plugin' ) }</strong>
					</p>
				) }
			</>
		),
	} );

	return sections;
};
