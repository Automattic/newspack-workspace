/**
 * Campaign management PopOver.
 */

/**
 * WordPress dependencies.
 */
import { MenuItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ESCAPE } from '@wordpress/keycodes';

/**
 * Internal dependencies
 */
import { Popover } from '../../../../../packages/components/src';

const CampaignManagementPopover = ( {
	dismiss,
	hasPrompts,
	hasPublished,
	isArchive,
	onActivate,
	onArchive,
	onDeactivate,
	onDelete,
	onDuplicate,
	onRename,
	onUnarchive,
} ) => (
	<Popover position="bottom right" onFocusOutside={ () => dismiss() } onKeyDown={ event => ESCAPE === event.keyCode && dismiss() }>
		<MenuItem onClick={ () => dismiss() } className="screen-reader-text">
			{ __( 'Close Popover', 'newspack-plugin' ) }
		</MenuItem>

		{ hasPrompts && (
			<MenuItem
				onClick={ () => {
					dismiss();
					onActivate();
				} }
				className="newspack-button"
			>
				{ __( 'Activate all prompts', 'newspack-plugin' ) }
			</MenuItem>
		) }
		{ hasPublished && (
			<MenuItem
				onClick={ () => {
					dismiss();
					onDeactivate();
				} }
				className="newspack-button"
			>
				{ __( 'Deactivate all prompts', 'newspack-plugin' ) }
			</MenuItem>
		) }
		<MenuItem
			onClick={ () => {
				dismiss();
				onDuplicate();
			} }
			className="newspack-button"
		>
			{ __( 'Duplicate', 'newspack-plugin' ) }
		</MenuItem>
		<MenuItem
			onClick={ () => {
				dismiss();
				onRename();
			} }
			className="newspack-button"
		>
			{ __( 'Rename', 'newspack-plugin' ) }
		</MenuItem>
		{ ! isArchive && (
			<MenuItem
				onClick={ () => {
					dismiss();
					onArchive();
				} }
				className="newspack-button"
			>
				{ __( 'Archive', 'newspack-plugin' ) }
			</MenuItem>
		) }
		{ isArchive && (
			<MenuItem
				onClick={ () => {
					dismiss();
					onUnarchive();
				} }
				className="newspack-button"
			>
				{ __( 'Unarchive', 'newspack-plugin' ) }
			</MenuItem>
		) }
		<MenuItem
			onClick={ () => {
				dismiss();
				onDelete();
			} }
			className="newspack-button"
		>
			{ __( 'Delete', 'newspack-plugin' ) }
		</MenuItem>
	</Popover>
);
export default CampaignManagementPopover;
