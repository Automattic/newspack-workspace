/**
 * External dependencies
 */
import { uniqBy } from 'lodash';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getServiceProvider } from '../../../service-providers';
import { STORE_NAMESPACE } from '../../../newsletter-editor/store';
import tags from './merge-tags';
import './style.scss';

/**
 * Merge tags completer configuration.
 *
 * @return {Object} Completer configuration.
 */
const getCompleter = () => {
	return {
		name: 'Mailchimp Merge Tags',
		triggerPrefix: '*|',
		options: () => {
			const { getData } = wp.data.select( STORE_NAMESPACE );
			const newsletterData = getData();
			const listMergeFields = newsletterData?.merge_fields || [];
			return uniqBy(
				[
					...listMergeFields.map( mergeField => ( {
						tag: `*|${ mergeField.tag }|*`,
						label: mergeField.name,
						keywords: [ 'list', 'audience', ...mergeField.name.split( ' ' ) ],
					} ) ),
					...tags,
				],
				'tag'
			);
		},
		getOptionLabel: ( { tag, label } ) => (
			<div className="newspack-completer-mc-merge-tags">
				<code>{ tag }</code>
				<p>{ label }</p>
			</div>
		),
		getOptionKeywords: ( { tag, keywords } ) => [ tag, ...( keywords || [] ) ],
		getOptionCompletion: ( { tag } ) => tag,
	};
};

export default () => {
	const { name: serviceProviderName } = getServiceProvider();
	const updateParagraphPlaceholder = ( settings, name ) => {
		if ( name === 'core/paragraph' ) {
			settings.attributes.placeholder.default = __( 'Type / to choose a block, or *| to add a merge tag', 'newspack-newsletters' );
		}
		return settings;
	};
	const addMergeTagsCompleter = ( completers, blockName ) => {
		return blockName === 'core/paragraph' ? [ ...completers, getCompleter() ] : completers;
	};
	if ( serviceProviderName === 'mailchimp' ) {
		wp.hooks.addFilter( 'blocks.registerBlockType', 'newspack-newsletters/mailchimp-merge-tags-placeholder', updateParagraphPlaceholder );
		wp.hooks.addFilter( 'editor.Autocomplete.completers', 'newspack-newsletters/autocompleters/mailchimp-merge-tags', addMergeTagsCompleter );
	}
};
