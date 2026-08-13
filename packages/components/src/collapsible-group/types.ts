export type CollapsibleGroupProps = {
	className?: string;
	/** Renders a lone item open and untitled, since it has nothing to collapse against. */
	hideSingleTitle?: boolean;
	/** `VStack` spacing between items, in 4px units. */
	spacing?: number;
	children?: React.ReactNode;
};

export type CollapsibleGroupItemProps = {
	className?: string;
	defaultOpen?: boolean;
	/** Without a title there is no trigger, so the content renders permanently open. */
	title?: string;
	children?: React.ReactNode;
};
