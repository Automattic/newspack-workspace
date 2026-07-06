/**
 * WordPress dependencies
 */
import { compose } from '@wordpress/compose';
import { withDispatch } from '@wordpress/data';
import { ComponentType, useState, useEffect } from '@wordpress/element';
import { TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { connectWithSelect, META_FIELD_SUMMARY } from './utils';

interface SummaryEditorProps {
	summary: string;
	saveSummary: ( summary: string ) => void;
}

const decorateSummary = compose(
	connectWithSelect,
	withDispatch(
		dispatch =>
			( {
				saveSummary: ( summary: string ) => {
					( dispatch( 'core/editor' ) as { editPost: ( edits: Record< string, unknown > ) => void } ).editPost( {
						meta: {
							[ META_FIELD_SUMMARY ]: summary,
						},
					} );
				},
			} ) as Record< string, ( ...args: unknown[] ) => unknown >
	)
);

const SummaryEditor = ( { summary, saveSummary }: SummaryEditorProps ) => {
	const [ value, setValue ] = useState( summary );

	useEffect( () => {
		saveSummary( value );
	}, [ value ] );

	return <TextareaControl label={ __( 'Body:', 'newspack-theme' ) } value={ value } onChange={ setValue } style={ { width: '100%' } } />;
};

export default decorateSummary( SummaryEditor ) as ComponentType;
