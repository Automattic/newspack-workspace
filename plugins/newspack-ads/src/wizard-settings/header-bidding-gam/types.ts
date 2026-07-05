/**
 * Shapes for the GAM header-bidding REST endpoints, mirroring
 * `includes/integrations/class-bidding-gam.php` (`get_advertiser_orders()`,
 * `get_order()`, `store_order()`) and `includes/class-bidding.php`
 * (`get_bidders()`).
 */

/**
 * A GAM order, merging the raw GAM order fields (`id`, `name`, `status`,
 * `is_archived`) with the locally-stored order config (`order_id`,
 * `order_name`, `revenue_share`, `bidders`, ...). Which fields are present
 * depends on the endpoint (list vs. single-order fetch/create/update).
 */
export interface GamOrder {
	id?: number;
	name?: string;
	status?: string;
	is_archived?: boolean;
	order_id?: number;
	order_name?: string;
	revenue_share?: number;
	bidders?: string[];
	line_item_ids?: number[];
	lica_batch_count?: number;
}

/**
 * A GAM order as returned by the orders-list endpoint (`GET .../orders`),
 * where `get_advertiser_orders()` always merges in the raw GAM fields.
 */
export interface GamOrderListItem extends GamOrder {
	id: number;
	name: string;
	status: string;
	is_archived: boolean;
}

export interface GamBidder {
	name: string;
	ad_sizes: [ number, number ][];
}

/** The shape of errors thrown by `@wordpress/api-fetch` on a failed request. */
export interface ApiError {
	message: string;
	data?: { status?: string };
}
