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
}
