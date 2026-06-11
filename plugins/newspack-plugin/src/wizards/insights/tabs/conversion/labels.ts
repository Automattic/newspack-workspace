/**
 * Shared display labels for Tab 3 (Conversion Journey).
 *
 * Maps the machine source keys (`gate` / `prompt` / `direct`) used by the
 * Section 3 PieCharts and the Section 4 multi-series distributions to their
 * translated display labels, so the sections stay declarative and the copy
 * lives in one place.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export type ConversionSource = 'gate' | 'prompt' | 'direct';

export const sourceLabel = ( source: ConversionSource ): string => {
	switch ( source ) {
		case 'gate':
			return __( 'Gate', 'newspack-plugin' );
		case 'prompt':
			return __( 'Prompt', 'newspack-plugin' );
		case 'direct':
		default:
			return __( 'Direct', 'newspack-plugin' );
	}
};
