/* global newspackStoryBudgetNetwork */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Icon } from '@wordpress/components';
import { cloudDownload } from '@wordpress/icons';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import PullStory from './components/pull-story';

// Set the list of availables sites
addFilter(
	'newspack-story-budget.sites',
	'newspack-network/story-budget',
	() => newspackStoryBudgetNetwork.sites
);

// Add "Pull" to the Story Budget actions.
addFilter(
	'newspack-story-budget.actions',
	'newspack-network/story-budget',
	( actions ) => [
		...actions,
		{
			id: 'pull',
			label: __( 'Pull Story', 'newspack-network' ),
			isEligible: ( item ) =>
				item.metadata &&
				item.metadata?.can_pull &&
				! item.metadata?.is_pulled,
			isPrimary: true,
			supportsBulk: true,
			icon: <Icon icon={ cloudDownload } />,
			hideModalHeader: true,
			RenderModal: PullStory,
		},
	]
);
