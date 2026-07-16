/**
 * `newspack-components` (`packages/components`) builds to `dist/esm` with no
 * accompanying `.d.ts` (no `types`/`typings` field in its package.json), so its
 * public shape is re-declared here for the two components this plugin imports,
 * mirroring their real prop types in `packages/components/src`.
 */
declare module 'newspack-components' {
	/**
	 * Mirrors `packages/components/src/newspack-icon`'s `NewspackIconProps`.
	 */
	interface NewspackIconProps {
		className?: string;
		simple?: boolean;
		size?: number;
		white?: boolean;
	}

	function NewspackIcon( props: NewspackIconProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/action-card`'s `ActionCardProps`.
	 */
	interface ActionCardProps {
		id?: string;
		title?: import( 'react' ).ReactNode;
		titleLink?: string | null;
		href?: string | null;
		description?: import( 'react' ).ReactNode | ( () => import( 'react' ).ReactNode );
		actionText?: import( 'react' ).ReactNode | null;
		/**
		 * The real component types this `string | string[] | null`, but its own implementation
		 * renders a non-array `badge` as-is via JSX (numbers stringify fine there), and this
		 * plugin's only call site passes a `number` (a story count) -- widened here to match.
		 */
		badge?: string | number | string[] | null;
		badgeLevel?: string;
		className?: string;
		indent?: boolean | string;
		notification?: import( 'react' ).ReactNode;
		notificationLevel?: 'error' | 'warning' | 'info' | 'success';
		notificationHTML?: boolean;
		isSmall?: boolean;
		isMedium?: boolean;
		disabled?: boolean | string;
		hasGreyHeader?: boolean;
		hasWhiteHeader?: boolean;
		heading?: 1 | 2 | 3 | 4 | 5 | 6;
		toggleChecked?: boolean;
		toggleOnChange?: ( value: boolean ) => void;
		togglePosition?: 'leading' | 'trailing';
		actionContent?: import( 'react' ).ReactNode;
		error?: Error | string | null;
		handoff?: string | null;
		handoffUrl?: string | null;
		bannerText?: string | null;
		bannerButtonText?: string | null;
		isErrorStatus?: boolean;
		checkbox?: 'checked' | 'unchecked' | false;
		isChecked?: boolean;
		isPending?: boolean;
		isWaiting?: boolean;
		isButtonEnabled?: boolean;
		children?: import( 'react' ).ReactNode;
		editLink?: string;
		image?: string | false | null;
		imageLink?: string;
		simple?: boolean;
		onClick?: () => void;
		secondaryActionText?: import( 'react' ).ReactNode;
		onSecondaryActionClick?: () => void;
		secondaryDestructive?: boolean;
		noBorder?: boolean;
		noMargin?: boolean;
		collapse?: boolean;
		expandable?: boolean;
		isExpanded?: boolean;
		draggable?: boolean;
		dragIndex?: number;
		onDragCallback?: ( index: number ) => void;
		dragWrapperRef?: import( 'react' ).RefObject< HTMLElement | null >;
	}

	function ActionCard( props: ActionCardProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/tabbed-navigation`'s `TabbedNavigationItem`/`TabbedNavigationProps`.
	 */
	interface TabbedNavigationItem {
		label?: import( 'react' ).ReactNode;
		path: string;
		exact?: boolean;
		activeTabPaths?: string[];
		isHiddenInTabbedNavigation?: boolean;
	}

	interface TabbedNavigationProps {
		items: TabbedNavigationItem[];
		className?: string;
		disableUpcoming?: boolean;
		children?: import( 'react' ).ReactNode;
	}

	function TabbedNavigation( props: TabbedNavigationProps ): import( 'react' ).JSX.Element;
}
