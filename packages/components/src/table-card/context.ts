/**
 * WordPress dependencies
 */
import { createContext } from '@wordpress/element';

/**
 * Whether the content is inside a `TableCard`, so a list can match the card's
 * padding.
 */
export const TableCardContext = createContext( false );
