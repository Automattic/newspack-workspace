/**
 * WordPress dependencies
 */
import { useRef } from '@wordpress/element';

import usePrompt from './usePrompt';
import useObjectState from './useObjectState';
import useOnClickOutside from './useOnClickOutside';
import useConfirmDialog from './use-confirm-dialog';

const useUniqueId = ( prefix: string ) => {
	const id = useRef( `${ prefix }-${ Math.round( Math.random() * 99999 ) }` );
	return id.current;
};

export default { usePrompt, useObjectState, useOnClickOutside, useUniqueId, useConfirmDialog };
