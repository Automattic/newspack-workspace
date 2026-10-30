/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { HeadingLevel } from './types';

export const TitleLevelContext = createContext< HeadingLevel >( 2 );

export const useTitleLevel = (): HeadingLevel => useContext( TitleLevelContext );
