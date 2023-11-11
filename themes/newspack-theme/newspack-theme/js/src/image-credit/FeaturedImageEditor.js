/**
 * WordPress dependencies
 */
import { compose } from '@wordpress/compose';
import { withDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import { TextareaControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { connectWithSelect, CAPTION_FIELD_NAME, CREDIT_FIELD_NAME } from './utils';

const decorate = compose(
	connectWithSelect,
	withDispatch( dispatch => ( {
		saveCaption: caption => {
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ CAPTION_FIELD_NAME ]: caption,
				},
			} );
		},
		saveCredit: credit => {
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ CREDIT_FIELD_NAME ]: credit,
				},
			} );
		},
	} ) )
);

const FeaturedImageEditor = ( { caption, credit, saveCaption, saveCredit } ) => {
	const [ caption, setCaption ] = useState( caption );
	const [ credit, setCredit ] = useState( credit );

	useEffect( () => {
		saveCaption( caption );
	}, [ caption ] );

	useEffect( () => {
		saveCredit( credit );
	}, [ credit ] );

	return (
		<>
		<TextareaControl
			value={ credit }
			onChange={ setCredit }
			style={ { marginTop: '10px', width: '100%' } }
		/>
		<TextareaControl
			value={ caption }
			onChange={ setCaption }
			style={ { marginTop: '10px', width: '100%' } }
		/>
		</>
	);
};

export default decorate( FeaturedImageEditor );
