/**
 * `newspack-components` (`packages/components`) builds to `dist/esm` with no
 * accompanying `.d.ts` (no `types`/`typings` field in its package.json), so its
 * public shape is re-declared here for the two components this unit imports,
 * mirroring their real prop types in `packages/components/src`.
 */
declare module 'newspack-components' {
	/**
	 * Mirrors `packages/components/src/autocomplete-tokenfield`'s `Suggestion`/`TokenValue`/props.
	 * The real component's `TokenValue` also allows a `Suggestion` object, but this unit's call
	 * sites only ever pass/expect plain `string | number` tokens, so the narrower shape is used
	 * here — it's this unit's own local contract, not a published type.
	 */
	type Suggestion = { value: string | number; label: string };
	type TokenValue = string | number;

	type AutocompleteTokenFieldProps = {
		tokens?: TokenValue[];
		onChange: ( values: TokenValue[] ) => void;
		fetchSuggestions?: ( input: string ) => Promise< Suggestion[] >;
		fetchSavedInfo?: ( tokens: TokenValue[] ) => Promise< Suggestion[] >;
		returnFullObjects?: boolean;
		help?: import( 'react' ).ReactNode;
		label?: string;
		placeholder?: string;
		maxLength?: number;
		style?: import( 'react' ).CSSProperties;
		__next40pxDefaultSize?: boolean;
		loading?: boolean;
	};

	function AutocompleteTokenField( props: AutocompleteTokenFieldProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/select-control`'s `SelectControlProps`.
	 */
	interface SelectControlOption {
		label?: string;
		value: string | number;
		disabled?: boolean;
	}

	interface SelectControlProps {
		className?: string;
		optgroups?: { label: string; options: SelectControlOption[] }[];
		buttonOptions?: { label?: string; value: string | number }[];
		buttonSmall?: boolean;
		label?: import( 'react' ).ReactNode;
		help?: import( 'react' ).ReactNode;
		hideLabelFromVision?: boolean;
		disabled?: boolean;
		multiple?: boolean;
		required?: boolean;
		name?: string;
		value?: string | number | boolean | string[];
		options?: SelectControlOption[];
		onChange?: ( value: never, extra: never ) => void;
		children?: never;
		[ propName: string ]: unknown;
	}

	function SelectControl( props: SelectControlProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/grid`'s destructured props (the real component has no
	 * declared props interface — it's still untyped JS-shaped despite the `.tsx` extension).
	 */
	interface GridProps {
		className?: string;
		borders?: boolean;
		columns?: number;
		gutter?: number;
		noMargin?: boolean;
		rowGap?: number;
		[ propName: string ]: unknown;
	}

	function Grid( props: GridProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/section-header`'s `SectionHeaderProps`.
	 */
	interface SectionHeaderProps {
		backNav?: string;
		badges?: { label: string; level?: string }[];
		centered?: boolean;
		className?: string | null;
		description?: import( 'react' ).ReactNode | ( () => import( 'react' ).ReactNode );
		heading?: 1 | 2 | 3 | 4 | 5 | 6;
		icon?: import( 'react' ).ReactNode | null;
		isWhite?: boolean;
		noMargin?: boolean;
		pageHeader?: boolean;
		title: string | ( () => import( 'react' ).ReactNode );
		id?: string | null;
		menu?: { label: import( 'react' ).ReactNode; icon?: unknown; href?: string; action?: () => void; disabled?: boolean; destructive?: boolean }[];
		primaryAction?: { label: import( 'react' ).ReactNode; href?: string; action?: () => void };
		secondaryAction?: { label: import( 'react' ).ReactNode; href?: string; action?: () => void };
		children?: import( 'react' ).ReactNode;
	}

	function SectionHeader( props: SectionHeaderProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/dataviews/format-date`'s `SiteDateValue` and helpers.
	 */
	type SiteDateValue = string | number | Date | null | undefined;
	function formatSiteDate( value: SiteDateValue ): string;
	function formatSiteDateTime( value: SiteDateValue ): string;
	function formatSiteTime( value: SiteDateValue ): string;

	/**
	 * Mirrors `packages/components/src/breadcrumbs/format-count`: a locale-aware
	 * compact count ("1.2K", "3.4M") used in list footers and column cells.
	 */
	function formatCount( count: number ): string;

	/**
	 * Mirrors `packages/components/src/cell-list`'s `CellListProps`.
	 */
	interface CellListProps {
		children: import( 'react' ).ReactNode;
		limit?: number;
		defaultExpanded?: boolean;
		onToggle?: ( isExpanded: boolean ) => void;
	}

	function CellList( props: CellListProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/status-indicator`'s `StatusName`, `StatusTone` and props.
	 * The props are the discriminated union of a named status (tinted) and a bare glyph.
	 */
	type StatusName =
		| 'active'
		| 'done'
		| 'scheduled'
		| 'draft'
		| 'pending'
		| 'attention'
		| 'error'
		| 'progress'
		| 'cancelled'
		| 'ended'
		| 'private'
		| 'trash';
	type StatusTone = 'success' | 'info' | 'warning' | 'error' | 'neutral';

	type StatusIndicatorProps = Omit< import( 'react' ).ComponentProps< 'div' >, 'children' > & {
		children: import( 'react' ).ReactNode;
	} & (
			| { status: StatusName; tone?: StatusTone; icon?: never }
			| { status?: never; tone?: never; icon: NonNullable< import( 'react' ).ComponentProps< typeof import( '@wordpress/components' ).Icon >[ 'icon' ] > }
		);

	function StatusIndicator( props: StatusIndicatorProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/dataviews`'s wrapper props: the upstream
	 * `@wordpress/dataviews` `DataViews` props plus the Newspack wrapper's extras
	 * (`className`, `inlineFilters`, `viewOptions`, `countLabel`, `fitColumns`).
	 */
	interface DataViewsProps< Item > {
		view: import( '@wordpress/dataviews' ).View;
		onChangeView: ( view: import( '@wordpress/dataviews' ).View ) => void;
		fields: import( '@wordpress/dataviews' ).Field< Item >[];
		search?: boolean;
		searchLabel?: string;
		actions?: import( '@wordpress/dataviews' ).Action< Item >[];
		data: Item[];
		isLoading?: boolean;
		paginationInfo: { totalItems: number; totalPages: number };
		defaultLayouts?: import( '@wordpress/dataviews' ).SupportedLayouts;
		selection?: string[];
		onChangeSelection?: ( items: string[] ) => void;
		onClickItem?: ( item: Item ) => void;
		isItemClickable?: ( item: Item ) => boolean;
		getItemId?: ( item: Item ) => string;
		getItemLevel?: ( item: Item ) => number;
		header?: import( 'react' ).ReactNode;
		children?: import( 'react' ).ReactNode;
		config?: { perPageSizes: number[] };
		empty?: import( 'react' ).ReactNode;
		onReset?: ( () => void ) | false;
		className?: string;
		inlineFilters?: boolean;
		viewOptions?: boolean;
		countLabel?: ( count: number, formatted: string ) => import( 'react' ).ReactNode;
		fitColumns?: string[];
	}

	function DataViews< Item >( props: DataViewsProps< Item > ): import( 'react' ).JSX.Element;
}

/**
 * The Subscribe block editor imports `ReorderModal` by its build path (not the
 * package root) to avoid pulling the whole component library and its global
 * `:root` styles into the editor bundle. That path ships no `.d.ts`, so the
 * default export's props are mirrored from `packages/components/src/reorder-modal`.
 * The real component's item id is `string | number`, but this unit's only caller
 * works with string list IDs throughout, so it is narrowed to `string` here (as
 * the AutocompleteTokenField token type is narrowed above). `label` is widened to
 * optional because this unit's `fetchItems` resolves labels from a possibly-
 * incomplete list config.
 */
declare module 'newspack-components/dist/esm/reorder-modal' {
	type ReorderItemId = string;

	interface ReorderItemBadge {
		label: string;
		tooltip?: string;
	}

	interface ReorderFetchResult {
		value: ReorderItemId;
		label?: string;
		badge?: ReorderItemBadge;
	}

	interface ReorderModalProps {
		title: string;
		ids: ReorderItemId[];
		fetchItems: ( ids: ReorderItemId[] ) => Promise< ReorderFetchResult[] >;
		onSave: ( order: ReorderItemId[], enabledIds?: ReorderItemId[] ) => void;
		onClose: () => void;
		enabledIds?: ReorderItemId[];
		description?: string;
		noneOnHelp?: string;
	}

	export default function ReorderModal( props: ReorderModalProps ): import( 'react' ).JSX.Element;
}
