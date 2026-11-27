declare global {
	interface Window {
		wpcomGutenberg: {
			blogPublic: string;
		};
		newspack_blocks_data: {
			assets_path: string;
			supports_recaptcha: boolean;
			has_recaptcha: boolean;
			recaptcha_url: string;
			post_subtitle: boolean;
			can_use_name_your_price: boolean;
			tier_amounts_template: string;
			currency: string;
			coupons_enabled?: boolean;
			posts_rest_url: string;
			posts_batch_rest_url: string;
			posts_batch_max_queries: string;
			specific_posts_rest_url: string;
			authors_rest_url: string;
			custom_taxonomies: { slug: string; label: string }[];
		};
		newspackIsBlockTheme?: boolean;
		grecaptcha: any;
		newspackReaderActivation: ReaderActivation;
		wp?: {
			hooks?: {
				addFilter: ( hookName: string, namespace: string, callback: ( value: unknown, ...args: unknown[] ) => unknown, priority?: number ) => void;
			};
		};
	}

	/**
	 * A Reader Activation activity payload dispatched on the `activity` event.
	 */
	type RASActivity = { detail: { action: string; data: unknown } };

	/**
	 * The reader-activation client exposed cross-plugin on
	 * `window.newspackReaderActivation`. Only the surface this plugin calls is
	 * typed; the canonical contract lives in newspack-plugin.
	 */
	type ReaderActivation = {
		on: ( event: string, handler: ( payload: RASActivity ) => void ) => void;
		off: ( event: string, handler: ( payload: RASActivity ) => void ) => void;
		setReaderEmail: ( email: string ) => void;
		setAuthenticated: ( authenticated: boolean ) => void;
		refreshAuthentication?: () => void;
		getReader: () => { email?: string; authenticated?: boolean };
		hasAuthLink?: () => boolean;
		setAuthStrategy?: ( strategy: string ) => void;
		getAuthStrategy?: () => string;
		refreshNewslettersSignupModal: ( email?: string ) => void;
		openNewslettersSignupModal: ( options: { onSuccess?: () => void; onError?: () => void; onDismiss?: () => void; closeOnSuccess?: boolean } ) => void;
		setPendingCheckout: ( url?: string ) => void;
		getPendingCheckout: () => string | undefined;
		openAuthModal: ( options: {
			title?: string;
			onSuccess?: ( message?: string, authData?: { registered?: boolean; [ key: string ]: unknown } ) => void;
			onError?: () => void;
			onDismiss?: () => void;
			skipSuccess?: boolean;
			skipVerification?: boolean;
			skipNewslettersSignup?: boolean;
			labels?: { signin?: { title?: string }; register?: { title?: string } };
			content?: string;
			trigger?: HTMLElement | null;
			closeOnSuccess?: boolean;
		} ) => void;
		overlays: {
			add: () => string;
			remove: ( id: string ) => void;
		};
	};

	type PostId = number;
	type CategoryId = number;
	type TagId = number;
	type Taxonomy = { slug: string; terms: number[] }[];
	type AuthorId = number;

	type PostType = { name: string; slug: string; supports: { newspack_blocks: boolean } };

	// The @wordpress/components `Toolbar` no longer types the legacy `controls`
	// prop (its modern replacement lives on `ToolbarGroup`) and requires `label`
	// even when wrapping children. The blocks render toolbars the historical way,
	// so this shape re-types the component at the boundary without altering the
	// rendered markup.
	type LegacyToolbarProps = {
		label?: string;
		controls?: Array< Record< string, unknown > >;
		className?: string;
		children?: import('react').ReactNode;
	};

	// As used by Newspack_Blocks_API::posts_endpoint.
	type PostsQuery = {
		include?: PostId[];
		excerptLength?: number;
		showExcerpt?: boolean;
		showCaption?: boolean,
		showCredit?: boolean,
	};

	type Block = {
		name: string;
		clientId: string;
		attributes: {
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			[key: string]: any;
		};
		innerBlocks: Block[];
	};

	type Post = {
		id: number;
		title: {
			rendered: string;
		};
		post_type: string;
		post_status?: string;
		date: string;
		date_formatted: string;
		article_meta_footer: string;
		excerpt: {
			rendered: string;
		};
		full_content: string;
		meta: {
			newspack_post_subtitle: string;
		};
		post_link: string;
		newspack_article_classes: string;
		newspack_featured_image_caption: string;
		newspack_featured_image_src: {
			large: string;
			landscape: string;
			portrait: string;
			square: string;
			uncropped: string;
		};
		newspack_category_info: string;
		newspack_post_avatars: string;
		newspack_post_byline: string;
		newspack_sponsors_show_categories: boolean;
		newspack_sponsors_show_author: boolean;
		newspack_post_sponsors?:
		| {
			flag: string;
		}[]
		| false;
		newspack_tag_labels?: { flag: string; link: string }[] | false;
		newspack_listings_hide_author?: boolean;
		newspack_listings_hide_publish_date?: boolean;
	};

	type HomepageArticlesAttributes = {
		includedPostStatuses: string[];
		postsToShow: number;
		authors: AuthorId[];
		categories: CategoryId[];
		includeSubcategories: boolean;
		categoryJoinType: string;
		excerptLength: number;
		postType: string[];
		showImage: boolean;
		showExcerpt: boolean;
		showFullContent: boolean;
		tags: TagId[];
		customTaxonomies: Taxonomy;
		specificPosts: string[];
		specificMode: boolean;
		tagExclusions: TagId[];
		categoryExclusions: CategoryId[];
		customTaxonomyExclusions: Taxonomy;
		className: string;
		excerptLength: number;
		showReadMore: boolean;
		readMoreLabel: string;
		showDate: boolean;
		showImage: boolean;
		showCaption: boolean;
		showCredit: boolean;
		disableImageLazyLoad: boolean;
		fetchPriority: string;
		imageShape: string;
		minHeight: integer;
		moreButton: boolean;
		infiniteScroll: boolean;
		moreButtonText: string;
		showAuthor: boolean;
		showAvatar: boolean;
		showCategory: boolean;
		showTagLabels: boolean;
		postLayout: string;
		columns: integer;
		colGap: integer;
		postsToShow: integer;
		mediaPosition: string;
		showSubtitle: boolean;
		sectionHeader: string;
		imageScale: number;
		mobileStack: boolean;
		typeScale: number;
		textAlign: string;
		deduplicate: boolean;
	};

	type HomepageArticlesAttributesKey = keyof HomepageArticlesAttributes;

	type HomepageArticlesPropsFromDataSelector = {
		topBlocksClientIdsInOrder: Block['clientId'][];
		latestPosts: Post[];
		isEditorBlock: boolean;
		isUIDisabled: boolean;
		error: undefined | string;
	};

	type HomepageArticlesProps = HomepageArticlesPropsFromDataSelector & {
		attributes: HomepageArticlesAttributes;
		setAttributes: (attributes: Partial<HomepageArticlesAttributes>) => void;
		textColor: {
			color: string;
		};
		setTextColor: (color: string) => void;
		triggerReflow: () => void;
		className: string;
		isSelected: boolean;
		// Injected by the EditWithBlockProps wrapper via useBlockProps().
		blockProps: { className?: string; [key: string]: unknown };
	};
}

export { };
