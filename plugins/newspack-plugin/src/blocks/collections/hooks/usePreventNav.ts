import { useCallback } from '@wordpress/element';

/**
 * External dependencies
 */
import type { MouseEvent as ReactMouseEvent } from 'react';

/**
 * Hook returning a stable handler that prevents default navigation.
 * Use for dummy anchor tags in editor preview.
 *
 * @return Stable callback preventing default.
 */
const usePreventNav = () => {
	return useCallback( ( e: ReactMouseEvent ) => {
		e.preventDefault();
	}, [] );
};

export default usePreventNav;
