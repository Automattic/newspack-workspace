import { __ } from '@wordpress/i18n';

const PROVIDER_CREDENTIAL_SCHEMAS = {
	mailchimp: [
		{
			key: 'api_key',
			label: __( 'Mailchimp API Key', 'newspack-newsletters' ),
			help: __( 'Find or generate your API key', 'newspack-newsletters' ),
			helpURL: 'https://mailchimp.com/help/about-api-keys/#Find_or_generate_your_API_key',
			placeholder: '123457103961b1f4dc0b2b2fd59c137b-us1',
		},
	],
	constant_contact: [
		{
			key: 'api_key',
			label: __( 'Constant Contact API Key', 'newspack-newsletters' ),
		},
		{
			key: 'api_secret',
			label: __( 'Constant Contact API Secret', 'newspack-newsletters' ),
		},
	],
	active_campaign: [
		{
			key: 'url',
			label: __( 'ActiveCampaign API URL', 'newspack-newsletters' ),
		},
		{
			key: 'key',
			label: __( 'ActiveCampaign API Key', 'newspack-newsletters' ),
		},
	],
};

export function getProviderCredentialFields( slug ) {
	return PROVIDER_CREDENTIAL_SCHEMAS[ slug ] || [];
}
