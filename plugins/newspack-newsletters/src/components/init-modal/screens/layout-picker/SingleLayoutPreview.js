/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { withDispatch } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import { useState, useMemo } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';
import { ENTER, SPACE } from '@wordpress/keycodes';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { LAYOUT_CPT_SLUG } from '../../../../utils/consts';
import { setPreventDeduplicationForPostsInserter } from '../../../../editor/blocks/posts-inserter/utils';
import NewsletterPreview from '../../../newsletter-preview';

const SingleLayoutPreview = ( {
	isEditable,
	deleteHandler,
	saveLayout,
	selectedLayoutId,
	setSelectedLayoutId,
	ID,
	post_title: title,
	post_content: content,
	meta,
} ) => {
	const handleDelete = () => {
		// eslint-disable-next-line no-alert
		if ( confirm( __( 'Are you sure you want to delete this layout?', 'newspack-newsletters' ) ) ) {
			deleteHandler( ID );
		}
	};

	const [ layoutName, setLayoutName ] = useState( title );
	const [ isSaving, setIsSaving ] = useState( false );

	const handleLayoutNameChange = () => {
		if ( layoutName !== title ) {
			setIsSaving( true );
			saveLayout( { title: layoutName } ).then( () => {
				setIsSaving( false );
			} );
		}
	};

	const blockPreviewBlocks = useMemo( () => setPreventDeduplicationForPostsInserter( parse( content ) ), [ content ] );

	return (
		<div
			key={ ID }
			className={ classnames( 'newspack-newsletters-layouts__item', {
				'is-active': selectedLayoutId === ID,
			} ) }
		>
			<div
				className="newspack-newsletters-layouts__item-preview"
				onClick={ () => setSelectedLayoutId( ID ) }
				onKeyDown={ event => {
					if ( ENTER === event.keyCode || SPACE === event.keyCode ) {
						event.preventDefault();
						setSelectedLayoutId( ID );
					}
				} }
				role="button"
				tabIndex="0"
				aria-label={ title }
			>
				{ '' === content ? null : <NewsletterPreview layoutId={ ID } meta={ meta } blocks={ blockPreviewBlocks } viewportWidth={ 848 } /> }
			</div>
			{ isEditable ? (
				<TextControl
					className="newspack-newsletters-layouts__item-label"
					value={ layoutName }
					onChange={ setLayoutName }
					onBlur={ handleLayoutNameChange }
					disabled={ isSaving }
					onKeyDown={ event => {
						if ( ENTER === event.keyCode ) {
							handleLayoutNameChange();
						}
					} }
				/>
			) : (
				<div className="newspack-newsletters-layouts__item-label">{ title }</div>
			) }
			{ isEditable && (
				<Button isDestructive isLink onClick={ handleDelete } disabled={ isSaving }>
					{ __( 'Delete', 'newspack-newsletters' ) }
				</Button>
			) }
		</div>
	);
};

export default withDispatch( ( dispatch, { ID } ) => {
	const { saveEntityRecord } = dispatch( 'core' );
	return {
		saveLayout: payload =>
			saveEntityRecord( 'postType', LAYOUT_CPT_SLUG, {
				status: 'publish',
				id: ID,
				...payload,
			} ),
	};
} )( SingleLayoutPreview );
