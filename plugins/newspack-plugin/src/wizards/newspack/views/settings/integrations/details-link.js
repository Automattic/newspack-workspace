/**
 * A table cell that opens its row's details. A button rather than a link,
 * because it opens a drawer instead of navigating.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onClick  Opens the details.
 * @param {*}        props.children The cell text.
 */
export const DetailsLink = ( { onClick, children } ) => (
	<button type="button" className="newspack-integration-logs__details-link" aria-haspopup="dialog" onClick={ onClick }>
		{ children }
	</button>
);
