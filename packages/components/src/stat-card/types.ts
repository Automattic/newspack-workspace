export type StatCardHeadingLevel = 2 | 3 | 4 | 5 | 6;

export type StatCardValueVariant = 'figure' | 'text';

/** Pre-formatted by the caller. Null and undefined both render the null glyph. */
export type StatCardValue = string | number | null | undefined;

export type StatCardRootProps = Omit< React.ComponentPropsWithoutRef< 'div' >, 'children' > & {
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
	value: StatCardValue;
	/** Spoken instead of the visible value, whose meaning may rest on punctuation. */
	valueLabel?: string;
	/** `text` drops the hero scale, for a phrase standing in for a number. */
	variant?: StatCardValueVariant;
	/** Rendered in a row beside the figure, e.g. a `StatCard.Delta`. */
	suffix?: React.ReactNode;
	className?: string;
};

export type StatCardDeltaDirection = 'up' | 'down';

export type StatCardDeltaTone = 'positive' | 'negative' | 'neutral';

export type StatCardDeltaProps = {
	/** Which arrow to show. Says nothing about whether the change is good. */
	direction: StatCardDeltaDirection;
	/** Which colour to use. The caller decides, because a rise is not always good news. */
	tone?: StatCardDeltaTone;
	/** Spoken in place of "Up" or "Down". */
	directionLabel?: string;
	className?: string;
	children?: React.ReactNode;
};

export type StatCardSecondaryProps = {
	className?: string;
	children?: React.ReactNode;
};

export type StatCardFooterProps = {
	className?: string;
	children?: React.ReactNode;
};
