/**
 * Shapes for the data `PlacementControl` renders, mirroring the REST API
 * responses documented in `includes/class-providers.php`, `class-bidding.php`
 * and `providers/gam/class-gam-provider.php`.
 */

/** A single sellable ad size, e.g. `[ 300, 250 ]`. */
export type AdSize = [ number, number ];

export interface AdUnit {
	name: string;
	value: string;
	sizes: AdSize[];
}

export interface AdProvider {
	id: string;
	name: string;
	units?: AdUnit[];
}

export interface AdBidder {
	name: string;
	ad_sizes: AdSize[];
}

export interface PlacementValue {
	provider?: string;
	ad_unit?: string;
	bidders_ids?: Record< string, string >;
	[ key: string ]: unknown;
}
