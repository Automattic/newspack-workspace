import type { GapSize } from '@wordpress/theme';

export type EmptyStateSize = 'default' | 'small';

export type EmptyStateActionsOrientation = 'row' | 'column';

export type EmptyStateRootProps = {
	/** Read by `EmptyState.Header` through context. */
	size?: EmptyStateSize;
	/** Merged onto the grid, which is the element consumers' `:has()` selectors look for. */
	className?: string;
	children?: React.ReactNode;
};

export type EmptyStateHeaderProps = {
	icon?: JSX.Element;
	title: string;
	description?: React.ReactNode;
	/** Defaults to 3 when the root is small, 2 otherwise. */
	heading?: 1 | 2 | 3 | 4 | 5 | 6;
	/** Merged onto `newspack-empty-state__header`. */
	className?: string;
};

export type EmptyStateActionsProps = {
	/** `column` stacks the actions instead, for a button above a link or a note. */
	orientation?: EmptyStateActionsOrientation;
	/** Gap between actions, on the design-system scale. */
	gap?: GapSize;
	/** Merged onto the stack. */
	className?: string;
	children?: React.ReactNode;
};
