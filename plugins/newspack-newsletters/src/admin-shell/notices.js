// <ShellNotices> in app.js only renders `type: 'snackbar'` entries — plain
// dispatches are silently dropped. Both kinds auto-dismiss with no close
// button; callers wanting a persistent error pass `explicitDismiss: true`.
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

export function notifySuccess( message, options = {} ) {
	dispatch( noticesStore ).createSuccessNotice( message, { ...options, type: 'snackbar' } );
}

export function notifyError( message, options = {} ) {
	dispatch( noticesStore ).createErrorNotice( message, { ...options, type: 'snackbar' } );
}
