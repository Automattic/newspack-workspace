/**
 * Refresh registry — lets the active tab's hook register its refetch callback
 * so the header LastUpdated kebab can invoke it without prop-drilling.
 */

/**
 * WordPress dependencies
 */
import { createContext, useCallback, useContext, useEffect, useRef } from '@wordpress/element';

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

type RefreshFn = () => void;

interface RegistryShape {
	register: ( tab: string, fn: RefreshFn ) => () => void;
	invoke: ( tab: string ) => void;
}

const Ctx = createContext< RegistryShape | null >( null );

export const RefreshRegistryProvider = ( { children }: { children: ReactNode } ) => {
	const fns = useRef( new Map< string, RefreshFn >() );

	const register = useCallback( ( tab: string, fn: RefreshFn ) => {
		fns.current.set( tab, fn );
		return () => {
			if ( fns.current.get( tab ) === fn ) {
				fns.current.delete( tab );
			}
		};
	}, [] );

	const invoke = useCallback( ( tab: string ) => {
		fns.current.get( tab )?.();
	}, [] );

	return <Ctx.Provider value={ { register, invoke } }>{ children }</Ctx.Provider>;
};

/**
 * Tab hooks call this with their current refetch.
 */
export const useRegisterRefresh = ( tab: string, refetch: RefreshFn ): void => {
	const ctx = useContext( Ctx );
	useEffect( () => {
		if ( ! ctx ) {
			return;
		}
		return ctx.register( tab, refetch );
	}, [ ctx, tab, refetch ] );
};

/** Header uses this to fire the active tab's refetch on kebab click. */
export const useInvokeRefresh = (): ( ( tab: string ) => void ) => {
	const ctx = useContext( Ctx );
	return useCallback(
		( tab: string ) => {
			ctx?.invoke( tab );
		},
		[ ctx ]
	);
};
