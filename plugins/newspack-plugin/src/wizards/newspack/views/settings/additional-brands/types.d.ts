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
		_logo: number | Attachment;
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
