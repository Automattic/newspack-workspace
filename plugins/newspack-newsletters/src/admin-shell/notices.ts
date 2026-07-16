// <ShellNotices> in app.tsx only renders `type: 'snackbar'` entries — plain
// dispatches are silently dropped. Both kinds auto-dismiss with no close
// button; callers wanting a persistent error pass `explicitDismiss: true`.
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

type NoticeOptions = Record< string, unknown >;

export function notifySuccess( message: string, options: NoticeOptions = {} ): void {
	dispatch( noticesStore ).createSuccessNotice( message, { ...options, type: 'snackbar' } );
}

export function notifyError( message: string, options: NoticeOptions = {} ): void {
	dispatch( noticesStore ).createErrorNotice( message, { ...options, type: 'snackbar' } );
}
