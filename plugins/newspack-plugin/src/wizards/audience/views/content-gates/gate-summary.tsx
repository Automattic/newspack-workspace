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
 * Build the Content rules / Registered access / Paid access sections for a gate.
 *
 * @param gate         The gate (live edit state or a saved gate).
 * @param isNewsletter Whether this is a premium-newsletter gate (hides registration).
 */
export const getGateSummarySections = ( gate: Gate, isNewsletter = false ): GateSummarySection[] => {
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
				<p>{ __( 'N/A', 'newspack-plugin' ) }</p>
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
							{ sprintf(
								// translators: 1: metering count, 2: metering period
								__( '%1$d free views per %2$s', 'newspack-plugin' ),
								gate.registration.metering.count,
								gate.registration.metering.period
							) }
						</p>
					) }
					{ ! gate.registration?.active && <p>{ __( 'N/A', 'newspack-plugin' ) }</p> }
				</>
			),
		} );
	}

	sections.push( {
		key: 'custom_access',
		label: __( 'Paid Access', 'newspack-plugin' ),
		content: (
			<>
				{ gate.custom_access?.active &&
					gate.custom_access.access_rules.length > 0 &&
					gate.custom_access.access_rules.map( ( ruleGroup, groupIndex ) =>
						ruleGroup.map( rule =>
							availableAccessRules[ rule.slug ]?.name ? (
								<p key={ `${ groupIndex }-${ rule.slug }` }>
									<strong>{ availableAccessRules[ rule.slug ].name }:</strong> { formatAccessRuleValue( rule ) }
								</p>
							) : null
						)
					) }
				{ gate.custom_access?.active && gate.custom_access.metering.enabled && (
					<p>
						<strong>{ __( 'Metered:', 'newspack-plugin' ) } </strong>{ ' ' }
						{ sprintf(
							// translators: 1: metering count, 2: metering period
							__( '%1$d free views per %2$s', 'newspack-plugin' ),
							gate.custom_access.metering.count,
							gate.custom_access.metering.period
						) }
					</p>
				) }
				{ ( ! gate.custom_access?.active || gate.custom_access.access_rules?.length === 0 ) && <p>{ __( 'N/A', 'newspack-plugin' ) }</p> }
			</>
		),
	} );

	return sections;
};
