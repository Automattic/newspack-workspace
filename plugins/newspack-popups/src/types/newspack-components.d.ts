/**
 * `newspack-components` (`packages/components`) builds to `dist/esm` with no
 * accompanying `.d.ts` (no `types`/`typings` field in its package.json), so its
 * public shape is re-declared here for the components this unit imports,
 * mirroring their real prop types in `packages/components/src`.
 */
declare module 'newspack-components' {
	/**
	 * Mirrors `packages/components/src/text-control`'s untyped destructured props
	 * (the real component has no declared props interface — it forwards
	 * `...otherProps` to the underlying `@wordpress/components` `TextControl`).
	 */
	interface TextControlProps {
		className?: string;
		required?: boolean;
		isWide?: boolean;
		withMargin?: boolean;
		label?: import( 'react' ).ReactNode;
		help?: import( 'react' ).ReactNode;
		value?: string | number;
		onChange?: ( value: string ) => void;
		placeholder?: string;
		disabled?: boolean;
		[ propName: string ]: unknown;
	}

	function TextControl( props: TextControlProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/category-autocomplete`'s `Term`/`TermValue`/
	 * `CategoryAutocompleteProps`.
	 */
	interface CategoryAutocompleteTerm {
		id?: number;
		term_id?: number;
		name?: string;
		value?: string;
	}

	type CategoryAutocompleteTermValue = number | CategoryAutocompleteTerm;

	interface CategoryAutocompleteProps {
		value: CategoryAutocompleteTermValue[];
		onChange: ( terms: CategoryAutocompleteTerm[] ) => void;
		taxonomy?: string;
		className?: string;
		disabled?: boolean;
		description?: import( 'react' ).ReactNode;
		hideHelpFromVision?: boolean;
		hideLabelFromVision?: boolean;
		label?: string;
	}

	function CategoryAutocomplete( props: CategoryAutocompleteProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/web-preview`'s `WebPreviewProps`.
	 */
	interface WebPreviewProps {
		url: string;
		label?: import( 'react' ).ReactNode;
		onLoad: ( iframe: HTMLIFrameElement | null ) => void;
		title?: string;
		variant?: string;
		onClose?: () => void;
		beforeLoad?: () => void;
		renderButton?: ( props: { showPreview: () => void } ) => import( 'react' ).ReactNode;
	}

	function WebPreview( props: WebPreviewProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/autocomplete-with-suggestions`'s
	 * `Suggestion`/`TokenValue` (from `autocomplete-tokenfield`, which builds to
	 * `dist/esm` with no accompanying `.d.ts`, so its public shape is re-declared
	 * here rather than imported).
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
		fetchSavedPosts?: ( postIds?: TokenValue[], searchSlug?: string | null ) => Promise< Suggestion[] >;
		fetchSuggestions?: ( search?: string | null, offset?: number, searchSlug?: string | null ) => Promise< Suggestion[] >;
		help?: import( 'react' ).ReactNode;
		hideHelp?: boolean;
		label?: import( 'react' ).ReactNode;
		maxItemsToSuggest?: number;
		multiSelect?: boolean;
		onChange: ( selections: SelectedItem[] ) => void;
		onPostTypeChange?: ( postType: string ) => void;
		postTypes?: { slug: string; label: string }[];
		postTypeLabel?: string;
		postTypeLabelPlural?: string;
		selectedItems?: SelectedItem[];
		selectedPost?: Suggestion | 0 | null;
		suggestionsToFetch?: number;
		/**
		 * Not a real prop of the component (`src/blocks/single-prompt/edit.js`
		 * passes `maxLength={1}`, which the real component doesn't destructure or
		 * forward -- a pre-existing dead prop, not introduced by this migration
		 * and left as-is).
		 */
		[ propName: string ]: unknown;
	};

	function AutocompleteWithSuggestions( props: AutocompleteWithSuggestionsProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/newspack-icon`'s `NewspackIconProps`.
	 * `size` is optional in JSX because the real component declares
	 * `static defaultProps = { size: 32 }`.
	 */
	interface NewspackIconProps {
		className?: string;
		simple?: boolean;
		size?: number;
		white?: boolean;
		/**
		 * Not a real prop of the component (only className/simple/size/white are
		 * read) -- src/settings/index.js passes `height`, which the real
		 * component silently ignores (a pre-existing dead prop, not introduced by
		 * this migration and left as-is).
		 */
		[ propName: string ]: unknown;
	}

	function NewspackIcon( props: NewspackIconProps ): import( 'react' ).JSX.Element;
}
