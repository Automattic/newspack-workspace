/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import registerToolbarButton from './toolbar-button';
import { TRIGGER, getLabel, getStaticTags } from './utils';
import './style.scss';

export default () => {
	const tags = getStaticTags();
	if ( ! tags.length ) {
		return;
	}

	const label = getLabel();

	const updateParagraphPlaceholder = ( settings, name ) => {
		if ( name === 'core/paragraph' ) {
			settings.attributes.placeholder.default = sprintf(
				/* translators: 1: picker trigger (e.g. "{}"), 2: ESP-native singular noun (e.g. "merge tag" or "personalization tag"). */
				__( 'Type / to choose a block, or %1$s to add a %2$s', 'newspack-newsletters' ),
				TRIGGER,
				label
			);
		}
		return settings;
	};

	addFilter( 'blocks.registerBlockType', 'newspack-newsletters/merge-tags-placeholder', updateParagraphPlaceholder );
	registerToolbarButton();
};
