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
 * Renders the name unlinked when the endpoint couldn't resolve an edit URL (no
 * WooCommerce, or an admin without the capability), so the column never shows a
 * dead link — and likewise when the name itself is empty (a subscription whose
 * product has since been deleted), which would otherwise leave an invisible link
 * with no accessible name. The click is stopped from bubbling: both lists
 * delegate row clicks to the person, and a plan name must not resolve to two
 * destinations at once.
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
