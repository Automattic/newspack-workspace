/**
 * WordPress dependencies.
 */
import { CardBody, CardDivider, ToggleControl } from '@wordpress/components';
import { Fragment, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ActionCard } from '../../../../../../packages/components/src';
import AccessRule from './access-rule';
import Metering from './metering';

interface RegistrationProps {
	registration: Registration;
	onChange: ( registration: Registration ) => void;
	cardProps?: Partial< React.ComponentPropsWithoutRef< typeof ActionCard > >;
	isNewsletter?: boolean;
	siteMeter?: SiteMeterConfig;
}

export default function Registration( { registration, onChange, isNewsletter = false, siteMeter }: RegistrationProps ) {
	const handleChange = useCallback(
		( value: Partial< Registration > ) => {
			// Spread the full object so fields this screen doesn't manage
			// (e.g. gate_layout_id) survive the update and the next save.
			onChange( {
				...registration,
				...value,
			} );
		},
		[ registration, onChange ]
	);

	// Only a rule that can judge a signed-out visitor can let one skip registration;
	// the server refuses any other (Content_Gate_API::sanitize_registration_access_rules()).
	const availableAccessRules: AccessRules = window.newspackAudienceContentGates?.available_access_rules ?? {};
	const anonymousRuleSlugs = Object.keys( availableAccessRules ).filter( slug => availableAccessRules[ slug ].supports_anonymous );
	// One rule per group, so matching any of them is enough.
	const currentRules = ( registration.access_rules ?? [] ).map( group => group[ 0 ] ).filter( Boolean );
	const setRules = ( rules: GateAccessRule[] ) => handleChange( { access_rules: rules.map( rule => [ rule ] ) } );
	const toggleRule = ( slug: string ) =>
		setRules(
			currentRules.some( rule => rule.slug === slug )
				? currentRules.filter( rule => rule.slug !== slug )
				: [ ...currentRules, { slug, value: availableAccessRules[ slug ].default } ]
		);
	const changeRuleValue = ( slug: string ) => ( value: GateAccessRuleValue ) =>
		setRules( currentRules.map( rule => ( rule.slug === slug ? { ...rule, value } : rule ) ) );

	return (
		<>
			{ ! isNewsletter && (
				<>
					<CardBody size="small">
						<Metering
							description={ __( 'Allow limited free views before requiring login.', 'newspack-plugin' ) }
							metering={ registration.metering }
							onChange={ ( metering: Metering ) => handleChange( { metering } ) }
							siteCount={ siteMeter?.anonymous_count }
							sitePeriod={ siteMeter?.period }
						/>
					</CardBody>
					<CardDivider />
				</>
			) }
			<CardBody size="small">
				<ToggleControl
					label={ __( 'Require verification', 'newspack-plugin' ) }
					help={ __( 'Readers must verify their account to access.', 'newspack-plugin' ) }
					checked={ registration.require_verification }
					onChange={ () => handleChange( { require_verification: ! registration.require_verification } ) }
				/>
			</CardBody>
			{ ! isNewsletter &&
				anonymousRuleSlugs.map( slug => (
					<Fragment key={ slug }>
						<CardDivider />
						<AccessRule
							config={ {
								...availableAccessRules[ slug ],
								description:
									'institution' === slug
										? __(
												'Visitors from the selected institutions can read without registering. If paid access is on, they still need to meet it.',
												'newspack-plugin'
										  )
										: availableAccessRules[ slug ].description,
							} }
							enabled={ currentRules.some( rule => rule.slug === slug ) }
							rule={ currentRules.find( rule => rule.slug === slug ) }
							slug={ slug }
							onChange={ changeRuleValue( slug ) }
							onToggle={ toggleRule }
						/>
					</Fragment>
				) ) }
		</>
	);
}
