export type StatCardHeadingLevel = 2 | 3 | 4 | 5 | 6;

export type StatCardValueVariant = 'figure' | 'text';

export type StatCardRootProps = {
	/** Heading level for `StatCard.Label`, read through context. */
	heading?: StatCardHeadingLevel;
	/** Merged onto the card, which is the element the hero scale queries. */
	className?: string;
	children?: React.ReactNode;
};

export type StatCardLabelProps = {
	/** Rendered beside the heading rather than inside it, so a control here stays out of the document outline. */
	suffix?: React.ReactNode;
	/** Overrides the level set on `StatCard.Root`. */
	heading?: StatCardHeadingLevel;
	/** Merged onto the label row, not the heading. */
	className?: string;
	children?: React.ReactNode;
};

export type StatCardBodyProps = {
	className?: string;
	children?: React.ReactNode;
};

export type StatCardValueProps = {
	/** Pre-formatted by the caller. Null renders the null glyph. */
	value: React.ReactNode | null;
	/** Spoken instead of the visible value, whose meaning may rest on punctuation. */
	valueLabel?: string;
	/** `text` drops the hero scale, for a phrase standing in for a number. */
	variant?: StatCardValueVariant;
	className?: string;
};

export type StatCardSecondaryProps = {
	className?: string;
	children?: React.ReactNode;
};

export type StatCardFooterProps = {
	className?: string;
	children?: React.ReactNode;
};
