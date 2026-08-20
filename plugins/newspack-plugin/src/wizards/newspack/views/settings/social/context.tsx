/**
 * Newspack > Settings > Social: shared card state.
 */

/**
 * WordPress dependencies
 */
import { createContext, createPortal, useCallback, useContext, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Snackbar } from '@wordpress/components';
import { speak } from '@wordpress/a11y';

type SocialCardsContextValue = {
	notify: ( message: string ) => void;
};

const SocialCardsContext = createContext< SocialCardsContextValue >( { notify: () => {} } );

export const useSocialCards = () => useContext( SocialCardsContext );

/**
 * Announce an error as it appears. The notices that carry it are not live
 * regions, so a screen reader user would otherwise get no signal at all —
 * unlike the success path, which the snackbar announces.
 *
 * `nonce` is what makes a repeat announceable: a retry that fails the same way
 * produces an identical message, and message identity alone would swallow it.
 * Callers bump it once per failed attempt.
 *
 * @param message Current error message, or null when there is none.
 * @param nonce   Bumped by the caller on every failed attempt.
 */
export const useErrorAnnouncement = ( message: string | null, nonce: number = 0 ) => {
	useEffect( () => {
		if ( message ) {
			speak( message, 'assertive' );
		}
	}, [ message, nonce ] );
};

export const SocialCardsProvider = ( { children }: { children: React.ReactNode } ) => {
	const [ notice, setNotice ] = useState< { id: number; content: string } | null >( null );
	// Doubles as the Snackbar's key, which is what remounts it and re-triggers
	// its announcement. A timestamp collides for two messages in one millisecond.
	const nextNoticeId = useRef( 0 );

	const notify = useCallback( ( message: string ) => {
		nextNoticeId.current += 1;
		setNotice( { id: nextNoticeId.current, content: message } );
	}, [] );

	const value = useMemo( () => ( { notify } ), [ notify ] );

	return (
		<SocialCardsContext.Provider value={ value }>
			{ children }
			{ notice &&
				createPortal(
					<div className="newspack-social-settings__snackbar">
						<Snackbar key={ notice.id } onRemove={ () => setNotice( null ) }>
							{ notice.content }
						</Snackbar>
					</div>,
					document.getElementById( 'wpbody' ) ?? document.body
				) }
		</SocialCardsContext.Provider>
	);
};

export default SocialCardsProvider;
