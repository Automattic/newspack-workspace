/**
 * External dependencies
 */
import { uniqBy } from 'lodash';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { STORE_NAMESPACE } from '../../../newsletter-editor/store';

/* globals newspack_email_editor_data */

const EMPTY_MERGE_FIELDS = [];

const stripDiacritics = str => str.normalize( 'NFD' ).replace( /\p{Diacritic}/gu, '' );
const normalise = str => stripDiacritics( str ).toLowerCase();

export const TRIGGER = '{}';

export const getStaticTags = () => newspack_email_editor_data?.merge_tags?.tags || [];
export const getLabel = () => newspack_email_editor_data?.merge_tags?.label || __( 'merge tag', 'newspack-newsletters' );
// Optional muscle-memory trigger (e.g. "*|" for Mailchimp) that opens the same picker as TRIGGER. Empty when none.
export const getLegacyTrigger = () => newspack_email_editor_data?.merge_tags?.trigger_prefix || '';

const buildOptions = listMergeFields =>
	uniqBy(
		[
			...listMergeFields.map( mergeField => ( {
				tag: `*|${ mergeField.tag }|*`,
				label: mergeField.name,
				keywords: [ 'list', 'audience', ...mergeField.name.split( ' ' ) ],
			} ) ),
			...getStaticTags(),
		],
		'tag'
	);

const getOptionLabelNode = ( { tag, label } ) => (
	<div className="newspack-completer-merge-tags">
		<code>{ tag }</code>
		<p>{ label }</p>
	</div>
);

const getOptionKeywords = ( { tag, keywords } ) => [ tag, ...( keywords || [] ) ];

export const useMergeTagItems = filterValue => {
	const listMergeFields = useSelect( select => select( STORE_NAMESPACE )?.getData?.()?.merge_fields ?? EMPTY_MERGE_FIELDS, [] );
	const keyed = useMemo(
		() =>
			buildOptions( listMergeFields ).map( ( opt, i ) => ( {
				key: `merge-tags-${ i }`,
				value: opt,
				label: getOptionLabelNode( opt ),
				keywords: getOptionKeywords( opt ),
			} ) ),
		[ listMergeFields ]
	);
	return useMemo( () => {
		if ( ! filterValue ) {
			return keyed;
		}
		const needle = normalise( filterValue );
		return keyed.filter( item => item.keywords.some( k => normalise( k ).includes( needle ) ) );
	}, [ filterValue, keyed ] );
};
