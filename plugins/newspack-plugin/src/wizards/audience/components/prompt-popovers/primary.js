/**
 * Primary PromptActionCard Popover.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import { MenuItem } from '@wordpress/components';
import { ESCAPE } from '@wordpress/keycodes';

/**
 * Internal dependencies.
 */
import { Popover } from '../../../../../packages/components/src';
import './style.scss';

const PrimaryPromptPopover = ( {
	deletePopup,
	restorePopup,
	onFocusOutside,
	prompt,
	previewPopup,
	publishPopup,
	setIsDuplicatePromptModalVisible,
	unpublishPopup,
} ) => {
	const { id, edit_link: editLink, status } = prompt;
	const isPublished = 'publish' === status;
	const isTrash = status === 'trash';

	return (
		<Popover
			position="bottom left"
			onFocusOutside={ onFocusOutside }
			onKeyDown={ event => ESCAPE === event.keyCode && onFocusOutside() }
			className="newspack-popover__campaigns__primary-popover"
		>
			<MenuItem onClick={ () => onFocusOutside() } className="screen-reader-text">
				{ __( 'Close Popover', 'newspack-plugin' ) }
			</MenuItem>
			{ isTrash ? (
				<>
					<MenuItem onClick={ () => restorePopup( id ) } className="newspack-button">
						{ __( 'Restore', 'newspack-plugin' ) }
					</MenuItem>
					<MenuItem onClick={ () => deletePopup( id ) } className="newspack-button">
						{ __( 'Delete permanently', 'newspack-plugin' ) }
					</MenuItem>
				</>
			) : (
				<>
					<MenuItem
						onClick={ () => {
							onFocusOutside();
							previewPopup( prompt );
						} }
						className="newspack-button"
					>
						{ __( 'Preview', 'newspack-plugin' ) }
					</MenuItem>
					<MenuItem href={ decodeEntities( editLink ) } className="newspack-button" isLink>
						{ __( 'Edit', 'newspack-plugin' ) }
					</MenuItem>
					<MenuItem onClick={ () => setIsDuplicatePromptModalVisible( true ) } className="newspack-button">
						{ __( 'Duplicate', 'newspack-plugin' ) }
					</MenuItem>
					<MenuItem
						onClick={ () => {
							onFocusOutside();
							( isPublished ? unpublishPopup : publishPopup )( id );
						} }
						className="newspack-button"
					>
						{ isPublished ? __( 'Deactivate', 'newspack-plugin' ) : __( 'Activate', 'newspack-plugin' ) }
					</MenuItem>
					<MenuItem onClick={ () => deletePopup( id ) } className="newspack-button">
						{ __( 'Delete', 'newspack-plugin' ) }
					</MenuItem>
				</>
			) }
			<div className="newspack-popover__campaigns__info">
				{ __( 'ID:', 'newspack-plugin' ) } { id }
			</div>
		</Popover>
	);
};
export default PrimaryPromptPopover;
