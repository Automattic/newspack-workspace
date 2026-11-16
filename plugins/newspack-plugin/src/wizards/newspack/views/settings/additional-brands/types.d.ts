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
		_block_theme_menus: Record< string, number >;
		_block_theme_parts: Record< string, string >;
		_block_theme_palette: string;
	};
};

type BlockThemeMenu = {
	value: number;
	label: string;
	editUrl: string;
};

type BlockThemePart = {
	slug: string;
	title: string;
	area: string;
	source: string;
	editUrl: string;
	navs: Array< { id: number; fallback: boolean } >;
	copyOf: string;
};

type BlockThemeSlot = {
	slot: string;
	label: string;
	area: 'header' | 'footer' | 'sidebar';
	main: string;
};

type BlockThemeData = {
	slots: BlockThemeSlot[];
	parts: Record< string, BlockThemePart >;
	menus: BlockThemeMenu[];
	palettes: Array< { title: string; colors: Record< string, string > } >;
	colorPrefix: string;
};

interface PublicPage {
	id: string;
	title: { rendered: string };
}
