/**
 * WordPress dependencies.
 */
import { CardBody, CardDivider, ToggleControl } from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ActionCard } from '../../../../../../packages/components/src';
import Metering from './metering';

interface RegistrationProps {
	registration: Registration;
	onChange: ( registration: Partial< Registration > ) => void;
	cardProps?: Partial< React.ComponentPropsWithoutRef< typeof ActionCard > >;
	isNewsletter?: boolean;
}

export default function Registration( { registration, onChange, isNewsletter = false }: RegistrationProps ) {
	const handleChange = useCallback(
		( value: Partial< Registration > ) => {
			onChange( {
				active: registration.active,
				metering: registration.metering,
				require_verification: registration.require_verification,
				...value,
			} );
		},
		[ registration, onChange ]
	);
	return (
		<>
			{ ! isNewsletter && (
				<>
					<CardBody size="small">
						<Metering
							description={ __( 'Allow limited free views before requiring login.', 'newspack-plugin' ) }
							metering={ registration.metering }
							onChange={ ( metering: Metering ) => handleChange( { metering } ) }
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
		</>
	);
}
