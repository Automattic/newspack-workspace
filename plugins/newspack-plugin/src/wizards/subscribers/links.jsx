/**
 * Click-through targets shared by the subscriber and group lists.
 *
 * The rule both tabs follow: a person's name goes to that person, a plan name
 * goes to that plan. Names are the DataViews title cell, so their target is the
 * list's `onClickItem`; plan names are ordinary cells and carry their own link.
 */

/**
 * Link a plan/subscription name to that subscription's admin edit screen.
 *
 * Renders the name unlinked when the endpoint couldn't resolve an edit URL —
 * which in practice means WooCommerce is absent, since WC_Order's
 * get_edit_order_url() performs no capability check of its own — so the column
 * never shows a dead link. Likewise when the name itself is empty (a
 * subscription whose product has since been deleted), which would otherwise
 * leave an invisible link with no accessible name.
 *
 * The click is stopped from bubbling: both lists delegate row clicks to the
 * person, and a plan name must not resolve to two destinations at once.
 *
 * Keydown needs no equivalent guard, but only because of where this is used:
 * always an ordinary cell, never the DataViews title cell. That cell's
 * ItemClickWrapper fires its onClickItem on Enter/Space without checking that
 * the key originated on the wrapper itself, so a link nested there would
 * navigate to the row's target as well as its own. Keep it out of title cells —
 * see the owner field in GroupList.
 *
 * @param {Object} props          Component props.
 * @param {string} props.href     The subscription edit URL, if any.
 * @param {*}      props.children The plan name.
 */
export const SubscriptionLink = ( { href, children } ) => {
	if ( ! href || ! children ) {
		return children;
	}
	return (
		<a href={ href } onClick={ event => event.stopPropagation() }>
			{ children }
		</a>
	);
};
