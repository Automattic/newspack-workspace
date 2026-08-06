/**
 * Copies a value to the clipboard and confirms the outcome in a snackbar.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useSocialCards } from '../../context';

interface CopyButtonProps {
	value: string;
	label: string;
	successMessage: string;
	errorMessage: string;
}

export default function CopyButton( { value, label, successMessage, errorMessage }: CopyButtonProps ) {
	const { notify } = useSocialCards();
	const buttonRef = useRef< HTMLButtonElement | null >( null );

	// navigator.clipboard is undefined outside a secure context, e.g. wp-admin over HTTP.
	const legacyCopy = (): boolean => {
		const doc = buttonRef.current?.ownerDocument ?? document;
		const field = doc.createElement( 'textarea' );
		field.value = value;
		field.setAttribute( 'readonly', '' );
		field.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
		doc.body.appendChild( field );
		field.select();
		let copied = false;
		try {
			copied = doc.execCommand( 'copy' );
		} catch {
			copied = false;
		}
		field.remove();
		// select() moved focus to the textarea, which no longer exists.
		buttonRef.current?.focus();
		return copied;
	};

	// Not useCopyToClipboard: it only exposes onSuccess, so failure cannot be reported.
	const copy = async () => {
		let copied = false;
		try {
			await navigator.clipboard.writeText( value );
			copied = true;
		} catch {
			copied = legacyCopy();
		}
		// The snackbar carries the announcement, so nothing calls speak() here.
		notify( copied ? successMessage : errorMessage );
	};

	return (
		<Button ref={ buttonRef } variant="secondary" onClick={ copy } aria-label={ label } __next40pxDefaultSize>
			{ __( 'Copy', 'newspack-plugin' ) }
		</Button>
	);
}
