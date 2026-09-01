export type StatCardHeadingLevel = 2 | 3 | 4 | 5 | 6;

export type StatCardValueVariant = 'figure' | 'text';

/** Pre-formatted by the caller. Null, undefined and a blank string all render the null glyph. */
export type StatCardValue = string | number | null | undefined;

type DivProps = Omit< React.ComponentPropsWithoutRef< 'div' >, 'children' >;

type SpanProps = Omit< React.ComponentPropsWithoutRef< 'span' >, 'children' >;

/** The strings the card speaks for itself, when the caller has supplied none of its own. */
export type StatCardLabels = {
	/** Names the null glyph. */
	notApplicable: string;
	/** Spoken for an up arrow. */
	up: string;
	/** Spoken for a down arrow. */
	down: string;
};

export type StatCardRootProps = DivProps & {
	/** Heading level for `StatCard.Label`, read through context. */
	heading?: StatCardHeadingLevel;
	/** Replaces the spoken defaults for every card underneath, e.g. from a consumer's own text domain. */
	labels?: Partial< StatCardLabels >;
	/** Merged onto the card, which is the element the hero scale queries. */
	className?: string;
	children?: React.ReactNode;
};

export type StatCardLabelProps = DivProps & {
	/** Rendered beside the heading rather than inside it, so a control here stays out of the document outline. */
	suffix?: React.ReactNode;
	/** Overrides the level set on `StatCard.Root`. */
	heading?: StatCardHeadingLevel;
	/** Merged onto the label row, not the heading. */
	className?: string;
	children?: React.ReactNode;
};

export type StatCardBodyProps = DivProps & {
	className?: string;
	children?: React.ReactNode;
};

export type StatCardValueProps = SpanProps & {
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

export type StatCardDeltaProps = SpanProps & {
	/** Which arrow to show. Says nothing about whether the change is good. */
	direction: StatCardDeltaDirection;
	/** Which colour to use. The caller decides, because a rise is not always good news. */
	tone?: StatCardDeltaTone;
	/** Spoken in place of "Up" or "Down". */
	directionLabel?: string;
	/** Spoken in place of the whole delta, arrow and change together. Wins over `directionLabel`. */
	label?: string;
	className?: string;
	/** The change, pre-formatted. Must be non-interactive: `label` hides it from assistive technology. */
	children?: React.ReactNode;
};

export type StatCardSecondaryProps = DivProps & {
	className?: string;
	children?: React.ReactNode;
};

export type StatCardFooterProps = DivProps & {
	className?: string;
	children?: React.ReactNode;
};
