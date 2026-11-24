/**
 * A table cell that opens its row's details. A button rather than a link,
 * because it opens a drawer instead of navigating.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onClick  Opens the details.
 * @param {*}        props.children The cell text.
 * @param {string}   props.title    Shown on hover, and set when the text may be cut short to one line.
 */
export const DetailsLink = ( { onClick, children, title } ) => (
	<button
		type="button"
		className={ title ? 'newspack-integration-logs__details-link is-truncated' : 'newspack-integration-logs__details-link' }
		title={ title }
		aria-haspopup="dialog"
		onClick={ onClick }
	>
		{ children }
	</button>
);
