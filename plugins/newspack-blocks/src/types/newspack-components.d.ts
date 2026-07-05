declare module 'newspack-components' {
	function useObjectState<StateObject>(
		object: Partial<StateObject>
	): [object: StateObject, (object: Partial<StateObject>) => void];
	const hooks = { useObjectState };
	export { hooks };

	/**
	 * Mirrors `packages/components/src/autocomplete-tokenfield`'s `Suggestion`/`TokenValue`
	 * (that package builds to `dist/esm` with no accompanying `.d.ts`, so its public shape
	 * is re-declared here rather than imported).
	 */
	type Suggestion = { value: string | number; label: string };
	type TokenValue = string | number | Suggestion;

	/**
	 * Mirrors `packages/components/src/autocomplete-with-suggestions`'s `SelectedItem`.
	 */
	type SelectedItem = Suggestion & { postType?: string };

	/**
	 * Mirrors `packages/components/src/autocomplete-with-suggestions`'s
	 * `AutocompleteWithSuggestionsProps`.
	 */
	type AutocompleteWithSuggestionsProps = {
		fetchSavedPosts?: ( postIds?: TokenValue[], searchSlug?: string | null ) => Promise<Suggestion[]>;
		fetchSuggestions?: ( search?: string | null, offset?: number, searchSlug?: string | null ) => Promise<Suggestion[]>;
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
		selectedPost?: Suggestion | 0;
		suggestionsToFetch?: number;
		/**
		 * Not read by the real component (no destructured prop or prop-spread picks it up) --
		 * declared here only because call sites already pass it, matching the opt-in flag
		 * convention used by several `@wordpress/components` inputs.
		 */
		__next40pxDefaultSize?: boolean;
	};

	function AutocompleteWithSuggestions( props: AutocompleteWithSuggestionsProps ): import('react').JSX.Element;
	export { AutocompleteWithSuggestions };
}
