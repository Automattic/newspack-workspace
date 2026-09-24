/**
 * WordPress dependencies
 */
import { atSymbol } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import activeCampaign from './active-campaign';
import beehiiv from './beehiiv';
import constantContact from './constant-contact';
import mailchimp from './mailchimp';
import salesforce from './salesforce';

export { default as activeCampaign } from './active-campaign';
export { default as beehiiv } from './beehiiv';
export { default as constantContact } from './constant-contact';
export { default as fundraiseUp } from './fundraise-up';
export { default as mailchimp } from './mailchimp';
export { default as salesforce } from './salesforce';
export { default as wisepops } from './wisepops';

// Brand marks keyed by the ESP slug the backend reports
// (`Newspack_Newsletters::service_provider()`) or by integration ID. Rendered
// via IntegrationIcon.
export const espProviderIcons = {
	active_campaign: activeCampaign,
	mailchimp,
	constant_contact: constantContact,
	manual: atSymbol,
	salesforce,
	beehiiv,
};

export const espProviderOrder = [ 'active_campaign', 'mailchimp', 'constant_contact', 'manual' ];
