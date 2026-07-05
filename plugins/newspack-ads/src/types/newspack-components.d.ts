/**
 * `newspack-components` (`packages/components`) builds to `dist/esm` with no
 * accompanying `.d.ts` (no `types`/`typings` field in its package.json), so its
 * public shape is re-declared here for the components this unit imports,
 * mirroring their real prop types in `packages/components/src`.
 */
declare module 'newspack-components' {
	/**
	 * Mirrors `packages/components/src/action-card`'s `ActionCardProps` (only the
	 * fields used by the header-bidding-gam wizard screen).
	 */
	interface ActionCardProps {
		className?: string;
		title?: import('react').ReactNode;
		description?: import('react').ReactNode | ( () => import('react').ReactNode );
		actionText?: import('react').ReactNode | null;
		badge?: string | string[] | null;
		isSmall?: boolean;
		noBorder?: boolean;
		onClick?: () => void;
		children?: import('react').ReactNode;
	}

	function ActionCard( props: ActionCardProps ): import('react').JSX.Element;

	/**
	 * Mirrors `packages/components/src/card`'s `CardProps` (only the fields used here).
	 */
	interface CardProps {
		className?: string;
		buttonsCard?: boolean;
		noBorder?: boolean;
		children?: import('react').ReactNode;
	}

	function Card( props: CardProps ): import('react').JSX.Element;

	/**
	 * Mirrors `packages/components/src/modal`'s `ModalProps`.
	 */
	interface ModalProps {
		title?: string;
		onRequestClose?: () => void;
		children?: import('react').ReactNode;
	}

	function Modal( props: ModalProps ): import('react').JSX.Element;

	/**
	 * Mirrors `packages/components/src/notice`'s `NoticeProps` (only the fields used here).
	 */
	interface NoticeProps {
		isError?: boolean;
		isWarning?: boolean;
		noticeText?: import('react').ReactNode;
		isDismissible?: boolean;
	}

	function Notice( props: NoticeProps ): import('react').JSX.Element;

	/**
	 * Mirrors `packages/components/src/button`'s wrapped `Button` (only the fields used here).
	 */
	interface ButtonProps {
		isPrimary?: boolean;
		isSecondary?: boolean;
		isQuaternary?: boolean;
		isSmall?: boolean;
		disabled?: boolean;
		className?: string | false;
		onClick?: () => void;
		icon?: unknown;
		label?: string;
		tooltipPosition?: string;
		children?: import('react').ReactNode;
	}

	function Button( props: ButtonProps ): import('react').JSX.Element;

	/**
	 * Mirrors `packages/components/src/popover`'s wrapped `Popover` (only the fields used here).
	 */
	interface PopoverProps {
		position?: string;
		onFocusOutside?: () => void;
		onKeyDown?: ( event: import('react').KeyboardEvent ) => unknown;
		children?: import('react').ReactNode;
	}

	function Popover( props: PopoverProps ): import('react').JSX.Element;

	/**
	 * Mirrors `packages/components/src/text-control`'s wrapped `TextControl` (only the fields used here).
	 */
	interface TextControlProps {
		label?: import('react').ReactNode;
		help?: import('react').ReactNode;
		type?: string;
		min?: string | number;
		max?: string | number;
		disabled?: boolean;
		value: string | number;
		onChange: ( value: string ) => void;
	}

	function TextControl( props: TextControlProps ): import('react').JSX.Element;

	/**
	 * Mirrors `packages/components/src/select-control`'s `SelectControlProps` (only the
	 * fields used here).
	 */
	interface SelectControlOption {
		label?: string;
		value: string | number;
	}

	interface SelectControlProps {
		label?: import('react').ReactNode;
		help?: import('react').ReactNode;
		disabled?: boolean;
		multiple?: boolean;
		value?: string | number | string[];
		options?: SelectControlOption[];
		/**
		 * Typed `never` so any handler is accepted: the value's type depends on the
		 * rendering mode (single vs. `multiple`), matching the real component's own
		 * `onChange` typing in `packages/components/src/select-control`.
		 */
		onChange?: ( value: never, extra: never ) => void;
	}

	function SelectControl( props: SelectControlProps ): import('react').JSX.Element;

	/**
	 * Mirrors `packages/components/src/progress-bar`'s `ProgressBarProps`.
	 */
	interface ProgressBarProps {
		label?: import('react').ReactNode;
		completed?: number | string;
		total?: number | string;
	}

	function ProgressBar( props: ProgressBarProps ): import('react').JSX.Element;
}
