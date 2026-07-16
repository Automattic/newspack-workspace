/**
 * WordPress dependencies
 */
import { compose } from '@wordpress/compose';
import { withDispatch } from '@wordpress/data';
import { ComponentType, useState, useEffect } from '@wordpress/element';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { connectWithSelect, META_FIELD_TITLE } from './utils';

interface SummaryTitleEditorProps {
	summaryTitle: string;
	saveSummaryTitle: ( summaryTitle: string ) => void;
}

const decorateTitle = compose(
	connectWithSelect,
	withDispatch(
		dispatch =>
			( {
				saveSummaryTitle: ( summaryTitle: string ) => {
					( dispatch( 'core/editor' ) as { editPost: ( edits: Record< string, unknown > ) => void } ).editPost( {
						meta: {
							[ META_FIELD_TITLE ]: summaryTitle,
						},
					} );
				},
			} ) as Record< string, ( ...args: unknown[] ) => unknown >
	)
);

const SummaryTitleEditor = ( { summaryTitle, saveSummaryTitle }: SummaryTitleEditorProps ) => {
	const [ value, setValue ] = useState( summaryTitle );

	useEffect( () => {
		saveSummaryTitle( value );
	}, [ value ] );

	return <TextControl label={ __( 'Title:', 'newspack-theme' ) } value={ value } onChange={ setValue } style={ { width: '100%' } } />;
};

export default decorateTitle( SummaryTitleEditor ) as ComponentType;
