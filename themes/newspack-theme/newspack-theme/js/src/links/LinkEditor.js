/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { withDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import { TextareaControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import {
	connectWithSelect,
	LINK_FIELD_NAME,
} from './utils';

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

const LinkEditor = ( {
	link,
	saveLink,
} ) => {
	const [ linkValue, setLink ] = useState( link );

	useEffect( () => {
		saveLink( linkValue );
	}, [ linkValue ] );

	return (
		<>
			<TextareaControl
				label={ __( 'External Link', 'newspack-theme' ) }
				value={ linkValue }
				onChange={ setLink }
				rows={2}
				style={ { marginTop: '10px', width: '100%' } }
			/>
		</>
	);
};

export default decorate( LinkEditor );
