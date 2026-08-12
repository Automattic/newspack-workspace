export type DrawerSize = 'small' | 'medium' | 'large' | 'x-large' | 'full';

/** `text` is null unless the title's children are a plain string. */
export type DrawerTitleInfo = {
	id: string;
	text: string | null;
};

export type DrawerRootProps = {
	isOpen?: boolean;
	size?: DrawerSize;
	onRequestClose: () => void;
	isDirty?: boolean;
	confirmCloseMessage?: React.ReactNode;
	confirmButtonText?: string;
	requestConfirm?: ( callback: () => void ) => void;
	contentLabel?: string;
	describedBy?: string;
	className?: string;
	/** Merged into the frame's own style. A `ref` reaches the overlay around it. */
	style?: React.CSSProperties;
	children?: React.ReactNode;
};

export type DrawerHeaderProps = {
	className?: string;
	children?: React.ReactNode;
};

export type DrawerTitleProps = {
	className?: string;
	children?: React.ReactNode;
};

export type DrawerCloseIconProps = {
	label?: string;
	icon?: JSX.Element;
	className?: string;
};

export type DrawerContentProps = {
	/** 4px scale, as VStack's `spacing`. `0` for a flush section. */
	padding?: number;
	/** 4px scale, as VStack's `spacing`. */
	gap?: number;
	className?: string;
	children?: React.ReactNode;
};

export type DrawerDividerProps = {
	className?: string;
};

export type DrawerBodyProps = {
	children?: React.ReactNode;
};

export type DrawerFooterProps = {
	className?: string;
	children?: React.ReactNode;
};

/** `ariaLabel` extends the visible label and must contain it (WCAG 2.5.3, Label in Name). */
type DrawerActionBaseProps = {
	ariaLabel?: string;
	variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
	disabled?: boolean;
	isBusy?: boolean;
	isDestructive?: boolean;
	className?: string;
	children?: React.ReactNode;
};

/** An action either navigates or acts: `href` cannot close the drawer or run a handler. */
export type DrawerActionProps = DrawerActionBaseProps &
	(
		| { href: string; onClick?: never; closes?: never }
		| { href?: null; onClick?: ( event?: React.MouseEvent< HTMLElement > ) => void; closes?: boolean }
	);
