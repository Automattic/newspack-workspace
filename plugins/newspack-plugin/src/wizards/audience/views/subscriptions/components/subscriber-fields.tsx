/**
 * Shared audience fields for subscriber-commerce rule editors: the radio that
 * picks between naming subscriptions and reaching every active subscriber, plus
 * the subscription picker the first mode needs.
 *
 * Controlled through a single `value` object; `onChange` receives a partial with
 * only the changed keys, matching TargetingFields. Which subscriptions the rule
 * names is kept while "all subscriptions" is selected, so a publisher who
 * switches modes to compare does not lose the list — the server clears it on
 * save, which is what stops a stored rule matching on ids nobody can see.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { RadioControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import SearchTokenField from './search-token-field';
import { allSubscriptionsLabel, SEARCH_ENDPOINTS } from '../constants';
import type { SubscriberAudience, SubscriberTargeting } from '../types';

interface SubscriberFieldsProps {
	value: SubscriberAudience;
	onChange: ( partial: Partial< SubscriberAudience > ) => void;
	/** Label for the mode radio, e.g. "Subscribers". */
	label: string;
	/** Help shown while the rule names its subscriptions. */
	specificHelp: string;
	/** Help shown while the rule reaches every subscriber. */
	allHelp: string;
	disabled?: boolean;
}

export default function SubscriberFields( { value, onChange, label, specificHelp, allHelp, disabled }: SubscriberFieldsProps ) {
	const { subscription_targeting: subscriberTargeting, subscription_product_ids: subscriptionIds } = value;
	const isAll = 'all' === subscriberTargeting;

	return (
		<>
			<RadioControl
				label={ label }
				help={ isAll ? allHelp : specificHelp }
				selected={ subscriberTargeting }
				onChange={ ( next: string ) => {
					// RadioControl puts `help` in the fieldset's description, which a
					// screen reader announces once, on entry. Selecting the other
					// option rewrites that string with nothing to re-read it, so the
					// reader would never hear what the mode they just chose means —
					// and for this field the help text is the whole meaning.
					speak( 'all' === next ? allHelp : specificHelp, 'polite' );
					onChange( { subscription_targeting: next as SubscriberTargeting } );
				} }
				options={ [
					{ value: 'subscriptions', label: __( 'Specific subscriptions', 'newspack-plugin' ) },
					{ value: 'all', label: allSubscriptionsLabel() },
				] }
				disabled={ disabled }
			/>
			{ ! isAll && (
				<SearchTokenField
					endpoint={ SEARCH_ENDPOINTS.subscriptions }
					label={ __( 'Subscriptions', 'newspack-plugin' ) }
					value={ subscriptionIds }
					onChange={ ids => onChange( { subscription_product_ids: ids } ) }
					disabled={ disabled }
				/>
			) }
		</>
	);
}
