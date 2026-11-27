type DetailsLinkProps = {
	/** Opens the details. */
	onClick: () => void;
	/** The cell text. */
	children: React.ReactNode;
	/** Shown on hover, and set when the text may be cut short to one line. */
	title?: string;
};

/**
 * A table cell that opens its row's details. A button rather than a link,
 * because it opens a drawer instead of navigating.
 */
export const DetailsLink = ( { onClick, children, title }: DetailsLinkProps ) => (
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
