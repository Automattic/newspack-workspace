/**
 * SEO data type.
 */
type SeoData = {
	search_engines_discouraged: boolean;
	urls: {
		bluesky: string;
		facebook: string;
		instagram: string;
		linkedin: string;
		mastodon: string;
		pinterest: string;
		threads: string;
		tiktok: string;
		twitter: string;
		youtube: string;
	};
	verification: {
		bing: string;
		google: string;
	};
};
