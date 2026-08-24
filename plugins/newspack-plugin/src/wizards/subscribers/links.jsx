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

/**
 * Where a group's name goes.
 *
 * The in-wizard group detail screen is not registered yet, and the wizard
 * redirects an unmatched route back to the subscriber list — so pointing a group
 * at `#/groups/<id>` today would silently strand the user rather than 404. Until
 * that screen lands the target stays the group's own subscription edit screen,
 * which is what the group list already links to.
 *
 * SINGLE SWITCH: when the group detail route is registered, change this to
 * return `#/groups/${ id }` and every group affordance in the wizard follows.
 *
 * @param {Object} group A group entry carrying `id` and `editUrl`.
 * @return {string} The href, or '' when there is nothing to open.
 */
export const groupDetailHref = group => group?.editUrl || '';

/**
 * Whether a value is a safe in-wizard hash route (`#/…`).
 *
 * Guards the person profile's `?from=` origin, which is read straight from the
 * URL and placed into anchor `href`s (the back chevron, the breadcrumb, the
 * not-found button). Without this an attacker-supplied `from=javascript:…` would
 * render a live `javascript:` URL in an authenticated `manage_options` origin,
 * and `from=https://evil.example` would be a silent off-site redirect. Only a
 * value beginning `#/` — the wizard's own HashRouter paths — is accepted, and a
 * protocol-relative `#//host` (which a browser can treat as off-site) is rejected
 * by requiring a non-slash immediately after `#/`.
 *
 * @param {*} value The candidate hash path.
 * @return {boolean} Whether it is an internal wizard route.
 */
export const isInternalHashPath = value => typeof value === 'string' && /^#\/(?!\/)/.test( value );
