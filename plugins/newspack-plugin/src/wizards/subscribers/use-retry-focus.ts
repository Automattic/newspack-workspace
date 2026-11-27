/**
 * WordPress dependencies.
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * Keep a failed read's retry affordance reachable from the keyboard.
 *
 * Retrying unmounts the notice, so the affordance that comes back is a fresh node
 * and focus has fallen to the body. It is restored only when it actually landed
 * there, so a reader who tabbed away mid-request is not pulled back.
 *
 * `settled` is whether the read has finished, `failed` whether the finished read
 * failed, and `reload` starts it again. Returns a ref for the affordance and the
 * handler that starts a retry.
 */
export function useRetryFocus( { settled, failed, reload }: { settled: boolean; failed: boolean; reload: () => void } ) {
	const retryRef = useRef< HTMLElement | null >( null );
	const hasRetried = useRef( false );

	const retry = () => {
		hasRetried.current = true;
		reload();
	};

	useEffect( () => {
		const node = retryRef.current;
		if ( settled && failed && hasRetried.current && node && node.ownerDocument.activeElement === node.ownerDocument.body ) {
			node.focus();
		}
	}, [ settled, failed ] );

	return { retryRef, retry };
}
