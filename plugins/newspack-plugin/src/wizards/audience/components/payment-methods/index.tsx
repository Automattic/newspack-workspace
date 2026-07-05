/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Stripe } from './stripe';
import type { StripeGatewayData } from './stripe';
import { Notice } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import WizardsSection from '../../../wizards-section';
import { PaymentGateway } from './payment-gateway';
import type { PaymentGatewayData } from './payment-gateway';
import './style.scss';

/**
 * The payment wizard data consumed by this component.
 */
type PaymentWizardData = {
	payment_gateways?: Record< string, PaymentGatewayData & StripeGatewayData >;
	is_ssl?: boolean;
	errors?: Array< { message?: string } >;
	plugin_status?: boolean;
	platform_data?: { platform?: string };
};

const PaymentGateways = () => {
	const {
		payment_gateways: paymentGateways = {},
		is_ssl,
		errors = [],
		plugin_status,
		platform_data = {},
	} = useWizardData< PaymentWizardData >( 'newspack-audience/payment' );
	if ( false === plugin_status || 'wc' !== platform_data?.platform ) {
		return null;
	}

	return (
		<WizardsSection
			title={ __( 'Payment Gateways', 'newspack-plugin' ) }
			description={ () => (
				<>
					{ __(
						'Configure Newspack-supported payment gateways for WooCommerce. Payment gateways allow you to accept various payment methods from your readers. ',
						'newspack-plugin'
					) }
					<ExternalLink href="https://woocommerce.com/document/premium-payment-gateway-extensions/">
						{ __( 'Learn more', 'newspack-plugin' ) }
					</ExternalLink>
				</>
			) }
		>
			{ errors.length > 0 && errors.map( ( error, index ) => <Notice isError key={ index } noticeText={ <span>{ error.message }</span> } /> ) }
			{ is_ssl === false && (
				<Notice
					isWarning
					noticeText={
						<>
							{ __(
								'Missing or invalid SSL configuration detected. To collect payments, the site must be secured with SSL. ',
								'newspack-plugin'
							) }
							<ExternalLink href="https://stripe.com/docs/security/guide">{ __( 'Learn more', 'newspack-plugin' ) }</ExternalLink>
						</>
					}
				/>
			) }
			{ Object.keys( paymentGateways ).map( gateway => {
				// Stripe has unique connection status and badge level logic.
				if ( 'stripe' === gateway ) {
					return <Stripe key={ gateway } stripe={ paymentGateways[ gateway ] } />;
				}
				return <PaymentGateway key={ gateway } gateway={ paymentGateways[ gateway ] } />;
			} ) }
		</WizardsSection>
	);
};

export default PaymentGateways;
