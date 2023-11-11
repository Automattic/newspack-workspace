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
	CAPTION_FIELD_NAME,
	CREDIT_FIELD_NAME,
} from './utils';

const decorate = compose(
	connectWithSelect,
	withDispatch( ( dispatch ) => ( {
		saveCaption: ( caption ) => {
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ CAPTION_FIELD_NAME ]: caption,
				},
			} );
		},
		saveCredit: ( credit ) => {
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ CREDIT_FIELD_NAME ]: credit,
				},
			} );
		},
	} ) )
);

const FeaturedImageEditor = ( {
	caption,
	credit,
	saveCaption,
	saveCredit,
} ) => {
	const [ captionValue, setCaption ] = useState( caption );
	const [ creditValue, setCredit ] = useState( credit );

	useEffect( () => {
		saveCaption( captionValue );
	}, [ captionValue ] );

	useEffect( () => {
		saveCredit( creditValue );
	}, [ creditValue ] );

	return (
		<>
			<TextareaControl
				label={ __( 'Featured Image Caption', 'newspack-theme' ) }
				value={ captionValue }
				onChange={ setCaption }
				style={ { marginTop: '10px', width: '100%' } }
			/>
			<TextareaControl
				label={ __( 'Featured Image Credit', 'newspack-theme' ) }
				value={ creditValue }
				onChange={ setCredit }
				rows={2}
				style={ { marginTop: '10px', width: '100%' } }
			/>
		</>
	);
};

export default decorate( FeaturedImageEditor );
