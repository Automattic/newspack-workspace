// Types for the collections settings feature

type CollectionsSettingsData = {
	// Custom Naming section.
	custom_naming_enabled: boolean;
	custom_name: string;
	custom_singular_name: string;
	custom_slug: string;
	// Global CTAs section.
	subscribe_link: string;
	order_link: string;
	// Collections Archive section.
	posts_per_page: number;
	category_filter_label: string;
	highlight_latest: boolean;
	// Collection Single section.
	articles_block_attrs: {
		showCategory?: boolean;
	};
	show_cover_story_img: boolean;
	// Collection Posts section.
	post_indicator_style: 'default' | 'card';
	card_message: string;
};

type FieldChangeHandler< T > = < K extends keyof T >( key: K, value: T[ K ] ) => void;
