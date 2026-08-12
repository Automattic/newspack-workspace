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
	heading?: number;
	/** Merged onto `.newspack-section-header__container`, where `SectionHeader` puts its own `className`. */
	className?: string;
};

export type EmptyStateActionsProps = {
	/** `column` stacks the actions instead, for a button above a link or a note. */
	orientation?: EmptyStateActionsOrientation;
	/** Gap between actions, on the `@wordpress/components` spacing scale. */
	spacing?: number;
	/** Merged onto the stack. */
	className?: string;
	children?: React.ReactNode;
};
