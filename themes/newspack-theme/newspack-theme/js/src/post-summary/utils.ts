/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';

export const META_FIELD_SUMMARY = 'newspack_article_summary';
export const META_FIELD_TITLE = 'newspack_article_summary_title';

export const connectWithSelect = withSelect( select => {
	// The editor selectors are untyped for string-keyed stores; assert at the store boundary.
	const { getEditedPostAttribute } = select( 'core/editor' ) as {
		getEditedPostAttribute: ( attribute: string ) => Record< string, unknown >;
	};
	const { getEditorMode } = select( 'core/edit-post' ) as {
		getEditorMode: () => string;
	};
	return {
		summary: getEditedPostAttribute( 'meta' )[ META_FIELD_SUMMARY ] as string,
		summaryTitle: getEditedPostAttribute( 'meta' )[ META_FIELD_TITLE ] as string,
		mode: getEditorMode(),
	};
} );
