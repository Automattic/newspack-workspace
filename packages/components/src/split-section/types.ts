export type SplitSectionHeadingLevel = 2 | 3 | 4 | 5 | 6;

type DivProps = Omit< React.ComponentPropsWithoutRef< 'div' >, 'children' >;

export type SplitSectionRootProps = DivProps & {
	/** A `SplitSection.Header` followed by a `SplitSection.Content`. */
	children?: React.ReactNode;
};

export type SplitSectionHeaderProps = {
	/** The section's name. */
	title: string;
	/** A short line on what the section controls. */
	description?: React.ReactNode;
	/** Heading level for the title. */
	heading?: SplitSectionHeadingLevel;
	/** Placed on the header, so a link can scroll to the section with `?scrollTo=`. */
	id?: string;
	className?: string;
	/** Shown below the title and description, such as a notice or a button. */
	children?: React.ReactNode;
};

export type SplitSectionContentProps = DivProps & {
	/** The section's fields. */
	children?: React.ReactNode;
};
