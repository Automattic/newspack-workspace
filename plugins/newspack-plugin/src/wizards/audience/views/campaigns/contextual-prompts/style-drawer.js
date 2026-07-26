/**
 * Contextual Prompts Edit Styles drawer.
 *
 * A right-edge slide-in panel hosting the classic-theme style controls, opened
 * from the wizard header. The parent owns the styles state and the close/save
 * flows: closing with unsaved style edits is confirmed there before the local
 * edits are discarded.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Modal, Notice, Popover, SlotFillProvider, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { close } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button } from '../../../../../../packages/components/src';
import StyleSection from './style-section';

const StyleDrawer = ( { status, styles, error, inFlight, isDirty, onChangeStyles, onRequestClose, onSave } ) => {
	// Veto first: a close the parent answers with a confirm must reach it without
	// the exit animation replaying, so only closes that really unmount go through
	// the Modal's own Escape handler, which is what animates them. The overlay
	// click is the accepted exception: it animates before the confirm appears.
	const requestClose = event => {
		if ( isDirty || inFlight ) {
			onRequestClose();
			return;
		}
		const frame = event.currentTarget.closest( '.components-modal__frame' );
		if ( frame ) {
			frame.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		} else {
			onRequestClose();
		}
	};
	return (
		<Modal
			__experimentalHideHeader
			onRequestClose={ onRequestClose }
			// Escape on a dirty or in-flight drawer is vetoed here, before the
			// Modal's own handler animates the close. Popovers inside the drawer
			// preventDefault their own Escape, so those are left alone.
			onKeyDown={ event => {
				if ( 'Escape' === event.key && ! event.defaultPrevented && ( isDirty || inFlight ) ) {
					event.preventDefault();
					onRequestClose();
				}
			} }
			className="newspack-prompt-style-drawer"
			overlayClassName="newspack-prompt-style-drawer__overlay"
			contentLabel={ __( 'Edit Styles', 'newspack-plugin' ) }
		>
			{ /* Without a slot in the dialog, the controls' popovers (palettes, panel
			     menus) portal to a body-level container the Modal may already hold
			     aria-hidden, dropping them from the accessibility tree. In the slot
			     they stay inside the dialog; being `position: fixed`, they still
			     escape the content area's overflow. */ }
			<SlotFillProvider>
				<HStack className="newspack-prompt-style-drawer__header" spacing={ 2 } alignment="center">
					<h2 className="newspack-prompt-style-drawer__title">{ __( 'Edit Styles', 'newspack-plugin' ) }</h2>
					<Button icon={ close } size="small" label={ __( 'Close', 'newspack-plugin' ) } onClick={ requestClose } disabled={ inFlight } />
				</HStack>
				<div className="newspack-prompt-style-drawer__content">
					<StyleSection status={ status } styles={ styles } inFlight={ inFlight } onChangeStyles={ onChangeStyles } />
				</div>
				{ error && (
					<Notice status="error" isDismissible={ false } className="newspack-prompt-style-drawer__notice">
						{ error.message }
					</Notice>
				) }
				<HStack className="newspack-prompt-style-drawer__footer" spacing={ 2 } justify="flex-end">
					<Button variant="secondary" onClick={ requestClose } disabled={ inFlight }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" onClick={ onSave } disabled={ inFlight || ! isDirty } isBusy={ inFlight }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
				<Popover.Slot />
			</SlotFillProvider>
		</Modal>
	);
};

export default StyleDrawer;
