/**
 * WordPress dependencies
 */
import { atSymbol } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import activeCampaign from './active-campaign';
import constantContact from './constant-contact';
import mailchimp from './mailchimp';

export { default as activeCampaign } from './active-campaign';
export { default as constantContact } from './constant-contact';
export { default as fundraiseUp } from './fundraise-up';
export { default as mailchimp } from './mailchimp';
export { default as salesforce } from './salesforce';
export { default as wisepops } from './wisepops';

// ESP provider brand marks keyed by the slug the backend reports
// (`Newspack_Newsletters::service_provider()`). Rendered via IntegrationIcon.
export const espProviderIcons = {
	active_campaign: activeCampaign,
	mailchimp,
	constant_contact: constantContact,
	manual: atSymbol,
};

export const espProviderOrder = [ 'active_campaign', 'mailchimp', 'constant_contact', 'manual' ];
