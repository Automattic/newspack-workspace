/**
 * WordPress dependencies.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';

/**
 * Carry a reader to the notice reporting a failed submit.
 *
 * For a form whose button sits far below its notice. Focus doubles as the
 * announcement here, which is why the notice goes silent once a submit has happened;
 * where focus would interrupt someone who carried on while the request was in flight
 * it is declined and the message is spoken instead, so nobody is left with neither.
 *
 * @param errorMessage The current failure message, or null when there is none.
 * @param label        Accessible name for the notice container.
 * @return Props for the notice wrapper, a submit handler to register, and the notice's `spokenMessage`.
 */
export function useErrorNoticeFocus( errorMessage: string | null | undefined, label: string ) {
	const noticeRef = useRef< HTMLDivElement | null >( null );
	const submittedFrom = useRef< Element | null >( null );
	const [ submitCount, setSubmitCount ] = useState( 0 );

	const registerSubmit = ( event?: { currentTarget: Element | null } ) => {
		submittedFrom.current = event?.currentTarget ?? null;
		setSubmitCount( count => count + 1 );
	};

	useEffect( () => {
		if ( ! errorMessage || ! submitCount ) {
			return;
		}
		const notice = noticeRef.current;
		if ( ! notice ) {
			speak( errorMessage, 'polite' );
			return;
		}
		const { activeElement, body } = notice.ownerDocument;
		const hasMovedOn = submittedFrom.current && activeElement !== submittedFrom.current && activeElement !== body;
		if ( hasMovedOn ) {
			speak( errorMessage, 'polite' );
			return;
		}
		// Focus brings the notice into view by itself. A separate smooth `scrollTo` was
		// tried and does not survive: moving focus cancels it, in either order and across
		// a frame's delay, so the animation never ran and only the jump was ever seen.
		notice.focus();
	}, [ errorMessage, submitCount ] );

	return {
		wrapperProps: {
			ref: noticeRef,
			tabIndex: -1,
			role: 'group',
			'aria-label': label,
		},
		registerSubmit,
		spokenMessage: submitCount ? '' : errorMessage ?? '',
	};
}

export default useErrorNoticeFocus;
