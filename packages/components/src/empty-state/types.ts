export type EmptyStateSize = 'default' | 'small';

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
	heading?: number;
	className?: string;
};

export type EmptyStateActionsProps = {
	className?: string;
	children?: React.ReactNode;
};
