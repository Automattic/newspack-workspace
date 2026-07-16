/**
 * `newspack-components` (`packages/components`) builds to `dist/esm` with no
 * accompanying `.d.ts` (no `types`/`typings` field in its package.json), so its
 * public shape is re-declared here for the component(s) this unit imports,
 * mirroring `packages/components/src/autocomplete-tokenfield/index.tsx`'s and
 * `packages/components/src/autocomplete-with-suggestions/index.tsx`'s real props.
 */
declare module 'newspack-components' {
	/**
	 * Mirrors `packages/components/src/autocomplete-tokenfield`'s `Suggestion`.
	 */
	type Suggestion = { value: string | number; label: string };

	/**
	 * The real component's `TokenValue` also allows a `Suggestion` object, but this
	 * unit's call sites only ever pass/expect plain `string | number` tokens (post,
	 * category, and tag IDs), so the narrower shape is used here -- it's this unit's
	 * own local contract, not the published type. `undefined` is included because
	 * `onChange` (see `getValuesForLabels` in the real component) can hand back
	 * `undefined` entries for tokens whose label no longer matches a known value
	 * (e.g. deleted terms), and those round-trip back into `tokens` on rerender.
	 */
	type TokenValue = string | number | undefined;

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
		/**
		 * Not read by the real component (no destructured prop or prop-spread picks it up) --
		 * declared here only because this unit's call sites already pass it.
		 */
		disabled?: boolean;
	};

	function AutocompleteTokenField( props: AutocompleteTokenFieldProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/autocomplete-with-suggestions`'s `SelectedItem`.
	 */
	type SelectedItem = Suggestion & { postType?: string };

	type FetchSavedPosts = ( postIds?: TokenValue[], searchSlug?: string | null ) => Promise< Suggestion[] >;
	type FetchSuggestions = ( search?: string | null, offset?: number, searchSlug?: string | null ) => Promise< Suggestion[] >;

	type AutocompleteWithSuggestionsProps = {
		fetchSavedPosts?: FetchSavedPosts;
		fetchSuggestions?: FetchSuggestions;
		help?: string;
		hideHelp?: boolean;
		label?: string;
		maxItemsToSuggest?: number;
		multiSelect?: boolean;
		onChange: ( selections: SelectedItem[] ) => void;
		onPostTypeChange?: ( postType: string ) => void;
		postTypes?: { slug: string; label: string }[];
		postTypeLabel?: string;
		postTypeLabelPlural?: string;
		selectedItems?: SelectedItem[];
		/**
		 * The real component only reads `selectedPost` when rendering the
		 * "already selected" list (gated on a non-empty `selectedItems`) or in
		 * `multiSelect` mode -- `blocks/listing/edit.tsx` uses neither, so its
		 * `selectedPost={ isEditingPost ? null : listing }` (a raw listing ID
		 * string, not a `Suggestion`) is inert at that call site. Widened here
		 * to accept that shape rather than the published `Suggestion | 0` alone.
		 */
		selectedPost?: Suggestion | 0 | string | null;
		suggestionsToFetch?: number;
		/**
		 * None of these four are declared or read by the real component (no
		 * destructured prop or prop-spread picks them up) -- declared here only
		 * because `blocks/listing/edit.tsx` already passes them. `postType` is
		 * typed `unknown` because that call site actually passes the post-type
		 * info object (`{ name, label, show_in_inserter }`), not a string, despite
		 * the name -- since the prop is never read, its shape doesn't matter.
		 */
		postType?: unknown;
		postTypeSlug?: string;
		maxLength?: number;
		listItems?: TokenValue[];
	};

	function AutocompleteWithSuggestions( props: AutocompleteWithSuggestionsProps ): import( 'react' ).JSX.Element;
}
