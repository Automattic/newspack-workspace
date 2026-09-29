// Types for the collections settings feature

type CollectionsSettingsData = {
	// Naming section.
	custom_naming_enabled: boolean;
	custom_name: string;
	custom_singular_name: string;
	custom_slug: string;
	// Calls to Action section.
	subscribe_link: string;
	order_link: string;
	// Archive Page section.
	posts_per_page: number;
	category_filter_label: string;
	highlight_latest: boolean;
	// Single Pages section.
	articles_block_attrs: {
		showCategory?: boolean;
	};
	show_cover_story_img: boolean;
	// Posts section.
	post_indicator_style: 'default' | 'card';
	card_message: string;
};
