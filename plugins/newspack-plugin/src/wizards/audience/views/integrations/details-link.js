/**
 * A table cell that opens its row's details: plain cell text that reads as a
 * link, but is a button because it opens a drawer rather than navigating.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onClick  Opens the details.
 * @param {*}        props.children The cell text.
 */
export const DetailsLink = ( { onClick, children } ) => (
	<button type="button" className="newspack-integration-logs__details-link" onClick={ onClick }>
		{ children }
	</button>
);
