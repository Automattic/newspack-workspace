/**
 * Ambient declarations for globals consumed by the My Account v1 scripts
 * under src/my-account/v1/. This file is a global script: no top-level
 * imports, so every declaration lands in the global scope.
 */

/**
 * Data localized as `newspackMyAccountV1` by
 * My_Account_UI_V1::enqueue_assets() for both the `my-account-v1` and
 * `account-frontend` scripts.
 */
declare const newspackMyAccountV1: {
	myAccountUrl: string;
	labels: {
		resubscribe_title: string;
		renewal_early_title: string;
		change_payment_method_title: string;
		switch_subscription_title: string;
		invite_link_copied: string;
		invite_link_regenerated: string;
		invite_link_copy_failed: string;
		invite_link_disabled: string;
	};
	rest: {
		base_url: string;
		nonce: string;
		namespaces: {
			group: string;
		};
	};
};

/**
 * The `newspack-ui` public API accessed as a bare global. The Window member
 * is declared in src/newspack-ui/js/globals.d.ts; the `newspack-ui` script
 * is a dependency of the `my-account-v1` script.
 */
declare const newspackUI: Window[ 'newspackUI' ];

/**
 * Order details dispatched with the `checkout_completed` activity by the
 * newspack-blocks modal checkout and passed to `onCheckoutComplete`
 * callbacks. Only the members consumed by these scripts are declared; the
 * payload is assembled server-side, hence all members optional.
 */
interface NewspackModalCheckoutOrderDetails {
	order_id?: number;
	product_id?: number;
	subscription_ids?: number[];
	/** Subscription ID when the checkout was a subscription renewal. */
	subscription_renewal?: number;
	[ key: string ]: unknown;
}

interface Window {
	/**
	 * Opens the modal checkout. Defined by newspack-blocks' modal-checkout
	 * script, present whenever these entries render checkout buttons.
	 */
	newspackOpenModalCheckout: ( options: {
		url?: string | null;
		title?: string | null;
		actionType?: string | null;
		afterSuccess?: { url?: string; behavior?: string; buttonLabel?: string };
		onCheckoutComplete?: ( ( data: NewspackModalCheckoutOrderDetails ) => void ) | null;
		onClose?: ( () => void ) | null;
	} ) => void;
}
