/**
 * WordPress dependencies
 */
import { compose } from '@wordpress/compose';
import { withDispatch } from '@wordpress/data';
import { ComponentType, useState, useEffect } from '@wordpress/element';
import { TextareaControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { connectWithSelect, META_FIELD_NAME } from './utils';

interface SubtitleEditorProps {
	subtitle: string;
	saveSubtitle: ( subtitle: string ) => void;
}

const decorate = compose(
	connectWithSelect,
	withDispatch(
		dispatch =>
			( {
				saveSubtitle: ( subtitle: string ) => {
					( dispatch( 'core/editor' ) as { editPost: ( edits: Record< string, unknown > ) => void } ).editPost( {
						meta: {
							[ META_FIELD_NAME ]: subtitle,
						},
					} );
				},
			} ) as Record< string, ( ...args: unknown[] ) => unknown >
	)
);

const SubtitleEditor = ( { subtitle, saveSubtitle }: SubtitleEditorProps ) => {
	const [ value, setValue ] = useState( subtitle );

	useEffect( () => {
		saveSubtitle( value );
	}, [ value ] );

	return <TextareaControl value={ value } onChange={ setValue } style={ { marginTop: '10px', width: '100%' } } />;
};

export default decorate( SubtitleEditor ) as ComponentType;
