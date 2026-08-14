export type HeadingLevel = 1 | 2 | 3 | 4 | 5 | 6;

export type CollapsibleGroupProps = {
	className?: string;
	/** Renders a lone item open and untitled, since it has nothing to collapse against. */
	hideSingleTitle?: boolean;
	/** `VStack` spacing between items, in 4px units. */
	spacing?: number;
	/** Heading level for every item title, so the group shares one place in the outline. */
	titleLevel?: HeadingLevel;
	children?: React.ReactNode;
};

export type CollapsibleGroupItemProps = {
	className?: string;
	defaultOpen?: boolean;
	/** Without a title there is no trigger, so the content renders permanently open. */
	title?: string;
	children?: React.ReactNode;
};
