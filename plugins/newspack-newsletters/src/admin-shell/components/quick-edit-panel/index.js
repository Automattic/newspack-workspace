/**
 * Side-mounted Quick Edit panel — mirrors Core's
 * `dataviews-action-modal__quick-edit` from the Site Editor's Pages
 * route. Standard `<Modal>` anchored to the right edge via overlay
 * styles, with a sticky footer for Cancel / Save.
 */

import {
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalTruncate as Truncate, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	Button,
	Icon,
	Modal,
} from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { close } from '@wordpress/icons';

export default function QuickEditPanel( {
	title,
	icon,
	subjectTitle,
	isDirty = false,
	onClose,
	onSave,
	isBusy = false,
	canSave = true,
	saveLabel,
	className,
	children,
} ) {
	const requestClose = useCallback( () => {
		if ( isBusy ) {
			return;
		}
		onClose();
	}, [ isBusy, onClose ] );

	// Esc is a common reflex; confirm before dropping unsaved edits.
	const handleEscape = useCallback( () => {
		if ( isBusy ) {
			return;
		}
		// eslint-disable-next-line no-alert
		if ( isDirty && ! window.confirm( __( 'Discard unsaved changes?', 'newspack-newsletters' ) ) ) {
			return;
		}
		onClose();
	}, [ isBusy, isDirty, onClose ] );

	const handleSubmit = event => {
		event.preventDefault();
		if ( isBusy || ! canSave ) {
			return;
		}
		onSave();
	};

	const frameClassName = [ 'newspack-newsletters-quick-edit-modal', className ].filter( Boolean ).join( ' ' );

	return (
		<Modal
			title={ title }
			contentLabel={ subjectTitle ? `${ title }: ${ subjectTitle }` : title }
			__experimentalHideHeader
			onRequestClose={ handleEscape }
			shouldCloseOnEsc={ ! isBusy }
			shouldCloseOnClickOutside={ ! isBusy && ! isDirty }
			className={ frameClassName }
			overlayClassName="newspack-newsletters-quick-edit-modal__overlay"
		>
			<HStack className="newspack-newsletters-quick-edit-modal__header" spacing={ 2 } alignment="center">
				{ icon && <Icon className="newspack-newsletters-quick-edit-modal__icon" icon={ icon } size={ 24 } /> }
				<h2 className="newspack-newsletters-quick-edit-modal__title">
					<Truncate>{ subjectTitle || title }</Truncate>
				</h2>
				<Button
					className="newspack-newsletters-quick-edit-modal__close"
					icon={ close }
					size="small"
					label={ __( 'Close', 'newspack-newsletters' ) }
					onClick={ requestClose }
					disabled={ isBusy }
				/>
			</HStack>
			<form className="newspack-newsletters-quick-edit-modal__form" onSubmit={ handleSubmit }>
				<div className="newspack-newsletters-quick-edit-modal__content">
					<VStack spacing={ 4 }>{ children }</VStack>
				</div>
				<HStack className="newspack-newsletters-quick-edit-modal__footer" justify="flex-end" spacing={ 2 }>
					<Button variant="secondary" onClick={ requestClose } disabled={ isBusy }>
						{ __( 'Cancel', 'newspack-newsletters' ) }
					</Button>
					<Button variant="primary" type="submit" isBusy={ isBusy } disabled={ isBusy || ! canSave }>
						{ saveLabel || __( 'Save', 'newspack-newsletters' ) }
					</Button>
				</HStack>
			</form>
		</Modal>
	);
}
