/**
 * Newspack > Settings > Social: shared card state.
 */

/**
 * WordPress dependencies
 */
import { createContext, createPortal, useCallback, useContext, useMemo, useState } from '@wordpress/element';
import { Snackbar } from '@wordpress/components';

type SocialCardsContextValue = {
	notify: ( message: string ) => void;
};

const SocialCardsContext = createContext< SocialCardsContextValue >( { notify: () => {} } );

export const useSocialCards = () => useContext( SocialCardsContext );

export const SocialCardsProvider = ( { children }: { children: React.ReactNode } ) => {
	const [ notice, setNotice ] = useState< { id: number; content: string } | null >( null );

	const notify = useCallback( ( message: string ) => {
		setNotice( { id: Date.now(), content: message } );
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
