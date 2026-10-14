interface ThemeColorsMeta {
	color: string;
	name: string;
	theme_mod_name?: string;
	default?: string;
}

type Brand = {
	id: number;
	count: number;
	description: string;
	link: string;
	name: string;
	slug: string;
	taxonomy: string;
	parent: number;
	meta: {
		_custom_url: string;
		_show_page_on_front: number;
		// A media ID, a fetched REST attachment, a media-modal selection ({ id, url, … }), or null after removal.
		_logo: number | Attachment | { id: number; url: string } | null;
		_theme_colors: ThemeColorsMeta[];
		_menus: Array< {
			location: string;
			menu: number;
		} >;
	};
};

interface PublicPage {
	id: string;
	title: { rendered: string };
}
