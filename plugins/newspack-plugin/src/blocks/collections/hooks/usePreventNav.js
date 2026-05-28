import { useCallback } from '@wordpress/element';

/**
 * Hook returning a stable handler that prevents default navigation.
 * Use for dummy anchor tags in editor preview.
 *
 * @return {(e: Event) => void} Stable callback preventing default.
 */
const usePreventNav = () => {
	return useCallback( e => {
		e.preventDefault();
	}, [] );
};

export default usePreventNav;
