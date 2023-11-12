/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';

export const LINK_FIELD_NAME = 'link';
export const LINK_ORG_FIELD_NAME = 'link_org';
export const LINK_SPONSORED_FIELD_NAME = 'link_sponsored';

export const connectWithSelect = withSelect( ( select ) => ( {
	link: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[
		LINK_FIELD_NAME
	],
	org: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[
		LINK_ORG_FIELD_NAME
	],
	sponsored: select( 'core/editor' ).getEditedPostAttribute( 'meta' )[
		LINK_SPONSORED_FIELD_NAME
	],
	mode: select( 'core/edit-post' ).getEditorMode(),
} ) );
