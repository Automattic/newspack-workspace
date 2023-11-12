/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { withDispatch } from '@wordpress/data';
import { TextControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { connectWithSelect, LINK_FIELD_NAME } from './utils';

const decorate = compose(
	connectWithSelect,
	withDispatch( ( dispatch ) => ( {
		saveLink: ( link ) => {
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ LINK_FIELD_NAME ]: link,
				},
			} );
		},
	} ) )
);

const LinkEditor = ( { link, saveLink } ) => {
	return (
		<TextControl
			label={ __( 'External Link', 'newspack-theme' ) }
			value={ link }
			onChange={ saveLink }
			type={ 'url' }
			style={ { marginTop: '10px', width: '100%' } }
		/>
	);
};

export default decorate( LinkEditor );
