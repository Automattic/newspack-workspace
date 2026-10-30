import type { GapSize } from '@wordpress/theme';

export type HeadingLevel = 1 | 2 | 3 | 4 | 5 | 6;

export type CollapsibleGroupProps = {
	className?: string;
	/** `Stack` gap either side of each divider, so items sit twice this far apart. */
	gap?: GapSize;
	/** Renders a lone item open and untitled, since it has nothing to collapse against. */
	hideSingleTitle?: boolean;
	/** Heading level for every item title, so the group shares one place in the outline. Defaults to the level inherited from an enclosing group, or 2. */
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
