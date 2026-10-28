/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonationFrequencySlug, DonateBlockAttributes } from './types';
import { FREQUENCY_SLUGS as DONATE_FREQUENCY_SLUGS } from './frequency-slugs';

export const FREQUENCIES: Record< DonationFrequencySlug, string > = {
	once: __( 'One-time', 'newspack-plugin' ),
	month: __( 'Monthly', 'newspack-plugin' ),
	year: __( 'Annually', 'newspack-plugin' ),
};

export const FREQUENCY_SLUGS = DONATE_FREQUENCY_SLUGS as DonationFrequencySlug[];

export const LAYOUT_OPTIONS: { label: string; key: DonateBlockAttributes[ 'layoutOption' ] }[] = [
	{ label: __( 'Frequency', 'newspack-plugin' ), key: 'frequency' },
	{ label: __( 'Tiers', 'newspack-plugin' ), key: 'tiers' },
];

// The item at fourth position is the "other" value, it is not a tier.
export const DISABLED_IN_TIERS_BASED_LAYOUT_TIER_INDEX = 3;
