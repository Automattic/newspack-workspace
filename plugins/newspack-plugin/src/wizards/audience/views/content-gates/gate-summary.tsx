/**
 * Shared gate summary sections, rendered identically by the Access control
 * list card and the pre-save panel.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ContentRuleControl from './edit/content-rule-control';

const availableAccessRules = window.newspackAudienceContentGates.available_access_rules || {};

const noOp = () => {};

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
		label: __( 'Content rules', 'newspack-plugin' ),
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
			label: __( 'Registered access', 'newspack-plugin' ),
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
		label: __( 'Paid access', 'newspack-plugin' ),
		content: (
			<>
				{ gate.custom_access?.active &&
					gate.custom_access.access_rules.length > 0 &&
					gate.custom_access.access_rules.map( ( ruleGroup, groupIndex ) =>
						ruleGroup.map( rule =>
							availableAccessRules[ rule.slug ]?.name ? (
								<p key={ `${ groupIndex }-${ rule.slug }` }>
									<strong>{ availableAccessRules[ rule.slug ].name }:</strong>{ ' ' }
									{ Array.isArray( rule.value ) && availableAccessRules[ rule.slug ]?.options
										? rule.value
												.map(
													value =>
														availableAccessRules[ rule.slug ].options?.find( option => option.value === value )?.label
												)
												.join( ', ' )
										: rule.value }
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
