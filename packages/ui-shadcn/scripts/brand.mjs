export const brandTokens = {
	'newspack-ui-color-btn': [ 'var(--newspack-ui-color-primary-60)', 'Default Button fill: newspack-colors primary-600, Cobalt.' ],
	'newspack-ui-color-btn-ink': [ 'var(--newspack-ui-color-neutral-0)', 'Text on the Cobalt Button: white, Lc 95.' ],
	'newspack-ui-color-btn-hover': [ 'color-mix(in oklab, var(--newspack-ui-color-btn) var(--newspack-ui-color-mix-hover), var(--newspack-ui-color-surface))', 'Button hover: the fill mixed 85% toward the surface, in oklab.' ],
	'newspack-ui-color-btn-pressed': [ 'color-mix(in oklab, var(--newspack-ui-color-btn) var(--newspack-ui-color-mix-pressed), var(--newspack-ui-color-surface))', 'Button pressed: the fill mixed 75% toward the surface, in oklab.' ],
	'newspack-ui-color-focus': [ 'var(--newspack-ui-color-primary-60)', 'Focus ring: Cobalt. The halo mixes from it.' ],
	'newspack-ui-color-focus-halo': [ 'color-mix(in oklab, var(--newspack-ui-color-focus) var(--newspack-ui-focus-halo-mix), transparent)', 'Focus halo: the Cobalt ring at the halo mix, restated so it resolves against the brand focus color.' ],
	'newspack-ui-color-mark': [ 'var(--newspack-ui-color-primary-60)', 'Checked Checkbox and checked Radio: Cobalt.' ],
	'newspack-ui-color-selected': [ 'var(--newspack-ui-color-primary-60)', 'Selected fill and the active Tab marker: Cobalt, with white text.' ],
	'newspack-ui-color-link': [ 'var(--newspack-ui-color-brand-text)', 'Links: the brand text blue, Cobalt.' ],
	'newspack-ui-color-input-border-focus': [ 'var(--newspack-ui-color-focus)', 'Input focus edge: Cobalt, matching the ring and halo.' ],
};

export const brandShadcn = {
	primary: [ '#003da5', 'Cobalt, newspack-colors primary-600. The brand site uses it for primary buttons and interactive elements.' ],
	'primary-foreground': [ '#ffffff', 'White text on Cobalt, Lc 95.' ],
	ring: [ '#003da5', 'Focus ring: Cobalt.' ],
	'sidebar-primary': [ '#003da5', 'Mirrors primary.' ],
	'sidebar-primary-foreground': [ '#ffffff', 'Mirrors primary-foreground.' ],
	'sidebar-ring': [ '#003da5', 'Mirrors ring.' ],
};

export const brandChart = [ '#003da5', '#26d07c', '#fc9bb3', '#ffa300', '#6086c7' ];

export const accentTokens = {
	'newspack-ui-color-brand-accent-emerald': [ '#26d07c', 'Emerald, newspack-colors secondary-500. For backgrounds, shapes, charts and highlight sections only.' ],
	'newspack-ui-color-brand-accent-emerald-ink': [ 'var(--newspack-ui-color-neutral-90)', 'Text on Emerald: Neutral 900, Lc 62.8.' ],
	'newspack-ui-color-brand-accent-emerald-subtle': [ '#c8ecd4', 'Emerald tint, newspack-colors secondary-100, for section backgrounds.' ],
	'newspack-ui-color-brand-accent-morganite': [ '#fc9bb3', 'Morganite, newspack-colors tertiary-500. For backgrounds, shapes, charts and highlight sections only.' ],
	'newspack-ui-color-brand-accent-morganite-ink': [ 'var(--newspack-ui-color-neutral-90)', 'Text on Morganite: Neutral 900, Lc 62.9.' ],
	'newspack-ui-color-brand-accent-morganite-subtle': [ '#fedbe3', 'Morganite tint, newspack-colors tertiary-100, for section backgrounds.' ],
	'newspack-ui-color-brand-accent-amber': [ '#ffa300', 'Amber, newspack-colors quaternary-500. For backgrounds, shapes, charts and highlight sections only.' ],
	'newspack-ui-color-brand-accent-amber-ink': [ 'var(--newspack-ui-color-neutral-90)', 'Text on Amber: Neutral 900, Lc 63.2.' ],
	'newspack-ui-color-brand-accent-amber-subtle': [ '#ffdec7', 'Amber tint, newspack-colors quaternary-100, for section backgrounds.' ],
};

export const inverseTokens = {
	'newspack-ui-color-surface': [ 'var(--newspack-ui-color-primary-60)', 'Surface on an inverse section: Cobalt.' ],
	'newspack-ui-color-text': [ 'var(--newspack-ui-color-neutral-0)', 'Text on Cobalt: white, Lc 95.' ],
	'newspack-ui-color-text-muted': [ 'var(--newspack-ui-color-primary-5)', 'Muted text on Cobalt: newspack-colors primary-050.' ],
	'newspack-ui-color-focus': [ 'var(--newspack-ui-color-neutral-0)', 'Focus ring on Cobalt: white.' ],
	'newspack-ui-color-btn': [ 'var(--newspack-ui-color-neutral-0)', 'Button fill on Cobalt: white.' ],
	'newspack-ui-color-btn-ink': [ 'var(--newspack-ui-color-primary-60)', 'Button text on Cobalt: Cobalt.' ],
	'newspack-ui-color-btn-hover': [ 'color-mix(in oklab, var(--newspack-ui-color-btn) var(--newspack-ui-color-mix-hover), var(--newspack-ui-color-surface))', 'Button hover on Cobalt: restated so it resolves against the white fill and the Cobalt surface.' ],
	'newspack-ui-color-btn-pressed': [ 'color-mix(in oklab, var(--newspack-ui-color-btn) var(--newspack-ui-color-mix-pressed), var(--newspack-ui-color-surface))', 'Button pressed on Cobalt: restated so it resolves against the white fill and the Cobalt surface.' ],
	'newspack-ui-color-focus-halo': [ 'color-mix(in oklab, var(--newspack-ui-color-focus) var(--newspack-ui-focus-halo-mix), transparent)', 'Focus halo on Cobalt: restated so it mixes from the white ring.' ],
	'newspack-ui-color-link': [ 'var(--newspack-ui-color-text)', 'Links on Cobalt: the inverse text color, since Cobalt link text would vanish into the Cobalt surface.' ],
	'newspack-ui-color-button-text-hover': [ 'var(--newspack-ui-color-btn-ink)', 'Button text on hover: the Cobalt ink, since the white Neutral value would vanish on the light hover fill.' ],
	'newspack-ui-color-focus-on-brand': [ 'var(--newspack-ui-color-primary-60)', 'Focus ring on the white button: Cobalt.' ],
	'newspack-ui-color-brand-text': [ 'var(--newspack-ui-color-text)', 'Brand text on Cobalt: white.' ],
	'newspack-ui-color-btn-brand-text': [ 'var(--newspack-ui-color-brand-text)', 'Brand outline text on Cobalt: white.' ],
	'newspack-ui-color-btn-brand': [ 'var(--newspack-ui-color-btn)', 'Brand variant fill on Cobalt: the white default button fill.' ],
	'newspack-ui-color-btn-brand-hover': [ 'color-mix(in oklab, var(--newspack-ui-color-btn-brand) var(--newspack-ui-color-mix-hover), var(--newspack-ui-color-surface))', 'Brand variant hover on Cobalt: restated against the white fill.' ],
	'newspack-ui-color-btn-brand-pressed': [ 'color-mix(in oklab, var(--newspack-ui-color-btn-brand) var(--newspack-ui-color-mix-pressed), var(--newspack-ui-color-surface))', 'Brand variant pressed on Cobalt: restated against the white fill.' ],
	'newspack-ui-color-btn-brand-on': [ 'var(--newspack-ui-color-btn-ink)', 'Brand variant text on Cobalt: the Cobalt ink.' ],
	'newspack-ui-color-mark': [ 'var(--newspack-ui-color-neutral-0)', 'Checked Checkbox and checked Radio on Cobalt: white.' ],
	'newspack-ui-color-on-mark': [ 'var(--newspack-ui-color-primary-60)', 'The tick on a white mark: Cobalt.' ],
	'newspack-ui-color-selected': [ 'var(--newspack-ui-color-neutral-0)', 'Selected fill and the active Tab marker on Cobalt: white.' ],
	'newspack-ui-color-on-selected': [ 'var(--newspack-ui-color-primary-60)', 'Text on the white selected fill: Cobalt.' ],
	'newspack-ui-color-neutral-fill': [ 'color-mix(in oklab, var(--newspack-ui-color-text) 7%, var(--newspack-ui-color-surface))', 'Secondary, Outline and Ghost fill on Cobalt: the Neutral 7% recipe with white ink mixed into the Cobalt surface.' ],
	'newspack-ui-color-neutral-fill-hover': [ 'color-mix(in oklab, var(--newspack-ui-color-text) 13%, var(--newspack-ui-color-surface))', 'Neutral fill hover on Cobalt: the 13% recipe.' ],
	'newspack-ui-color-neutral-fill-pressed': [ 'color-mix(in oklab, var(--newspack-ui-color-text) 19%, var(--newspack-ui-color-surface))', 'Neutral fill pressed on Cobalt: the 19% recipe.' ],
	'newspack-ui-color-border-hover': [ 'var(--newspack-ui-color-text-muted)', 'Hover border on Cobalt: the muted inverse text.' ],
	'newspack-ui-color-border-control': [ 'color-mix(in oklab, var(--newspack-ui-color-text) 70%, transparent)', 'Control border on Cobalt: white at 70%.' ],
	'newspack-ui-color-input-border': [ 'var(--newspack-ui-color-border-control)', 'Input border on Cobalt: the control border.' ],
	'newspack-ui-color-input-border-hover': [ 'var(--newspack-ui-color-text)', 'Input hover border on Cobalt: white.' ],
	'newspack-ui-color-input-border-focus': [ 'var(--newspack-ui-color-focus)', 'Input focus border on Cobalt: white.' ],
	'newspack-ui-color-text-subtle': [ 'color-mix(in oklab, var(--newspack-ui-color-text) 80%, var(--newspack-ui-color-surface))', 'Inactive tabs and placeholders on Cobalt: white at 80% into the surface.' ],
	'newspack-ui-label-color': [ 'var(--newspack-ui-color-text)', 'Label text on Cobalt: white.' ],
	'newspack-ui-field-ink': [ 'var(--newspack-ui-color-text)', 'Field text on Cobalt: white.' ],
	'newspack-ui-field-bg': [ 'var(--newspack-ui-color-neutral-fill)', 'Field fill on Cobalt: the neutral fill, white at 7% into Cobalt.' ],
	'newspack-ui-color-input-bg': [ 'var(--newspack-ui-color-neutral-fill)', 'Checkbox and Radio fill on Cobalt: the neutral fill.' ],
	'newspack-ui-color-input-bg-disabled': [ 'var(--newspack-ui-color-surface-subtle)', 'Disabled field fill on Cobalt: the subtle surface.' ],
	'newspack-ui-color-input-border-disabled': [ 'var(--newspack-ui-color-border)', 'Disabled field border on Cobalt: the inverse border.' ],
	'newspack-ui-color-surface-subtle': [ 'color-mix(in oklab, var(--newspack-ui-color-text) 7%, var(--newspack-ui-color-surface))', 'Default and Info Alert fill and the disabled Button fill on Cobalt: white at 7% into Cobalt.' ],
	'newspack-ui-color-surface-sunken': [ 'color-mix(in oklab, var(--newspack-ui-color-text) 7%, var(--newspack-ui-color-surface))', 'Muted Badge fill on Cobalt: white at 7% into Cobalt, the Neutral recipe.' ],
	'newspack-ui-color-disabled-ink': [ 'color-mix(in oklab, var(--newspack-ui-color-text) 55%, var(--newspack-ui-color-surface))', 'Disabled text on Cobalt: white at 55% into Cobalt.' ],
	'newspack-ui-color-border': [ 'color-mix(in oklab, var(--newspack-ui-color-neutral-0) 30%, transparent)', 'Border on Cobalt: white at 30%, in oklab.' ],
};

export const inverseUnsupported = [
	'Success, Warning, Error and Destructive Alerts: the Alert text is the inverse white and the status fills stay light, so the text is unreadable. Use the default or Info Alert on the inverse surface.',
	'Invalid field styling: the error red edges, ring and label text stay Neutral and do not read on Cobalt.',
];

const interFallback = '"Inter Variable", "Inter", ui-sans-serif, system-ui, sans-serif';

export const brandFonts = {
	'font-heading': [ `"American Grotesk", ${ interFallback }`, 'Subheads h2 to h5: American Grotesk, then Inter 500 where the fonts item is not installed.' ],
	'font-display': [ `"American Grotesk Compressed", ${ interFallback }`, 'h1 and hero or slide headlines: American Grotesk Compressed Black, then Inter 900 where the fonts item is not installed.' ],
};

export const brandType = {
	h1: { size: 'clamp(2.25rem, -3.639rem + 12.048vw, 6rem)', lineHeight: '1', note: 'Figma Brand H1: 36px to 96px, line height 96px (1), Compressed Black 900.' },
	h2: { size: 'clamp(2rem, -1.141rem + 6.426vw, 4rem)', lineHeight: '1.125', note: 'Figma Brand H2: 32px to 64px, line height 72px at the top (1.125), Medium 500.' },
	h3: { size: 'clamp(1.75rem, -0.213rem + 4.016vw, 3rem)', lineHeight: '1.25', note: 'Figma Brand H3: 28px to 48px, line height 60px at the top (1.25), Medium 500.' },
	h4: { size: 'clamp(1.625rem, 0.055rem + 3.213vw, 2.625rem)', lineHeight: '1.3333', note: 'Figma Brand H4: 26px to 42px, line height 56px at the top (1.3333), Medium 500.' },
	h5: { size: 'clamp(1.375rem, 0.394rem + 2.008vw, 2rem)', lineHeight: '1.375', note: 'Figma Brand H5: 22px to 32px, line height 44px at the top (1.375), Medium 500.' },
	h6: { size: 'clamp(1.25rem, 0.857rem + 0.803vw, 1.5rem)', lineHeight: '1.5', note: 'Figma Brand H6: 20px to 24px, line height 36px at the top (1.5). Author token only: no base rule uses it; the eyebrow style covers small labels.' },
};

export const brandTypeTokens = Object.fromEntries( Object.entries( brandType ).flatMap( ( [ level, { size, lineHeight, note } ] ) => [
	[ `newspack-ui-brand-${ level }-size`, [ size, note ] ],
	[ `newspack-ui-brand-${ level }-line-height`, [ lineHeight, note ] ],
] ) );

export const brandBase = {
	h1: {
		'font-family': 'var(--font-display)',
		'font-weight': '900',
		'font-size': 'var(--newspack-ui-brand-h1-size)',
		'line-height': 'var(--newspack-ui-brand-h1-line-height)',
		'text-transform': 'uppercase',
		color: 'var(--newspack-ui-color-brand-text)',
	},
	'h2, h3, h4, h5': {
		'font-family': 'var(--font-heading)',
		'font-weight': '500',
		color: 'var(--newspack-ui-color-text)',
	},
	...Object.fromEntries( [ 2, 3, 4, 5 ].map( level => [ `h${ level }`, {
		'font-size': `var(--newspack-ui-brand-h${ level }-size)`,
		'line-height': `var(--newspack-ui-brand-h${ level }-line-height)`,
	} ] ) ),
	'.newspack-brand-inverse': {
		'background-color': 'var(--newspack-ui-color-surface)',
		color: 'var(--newspack-ui-color-text)',
	},
	'.newspack-brand-inverse h1, .newspack-brand-inverse .font-display': {
		color: 'var(--newspack-ui-color-text)',
	},
	'.font-display': {
		'font-family': 'var(--font-display)',
		'font-weight': '900',
		'line-height': 'var(--newspack-ui-brand-h1-line-height)',
		'text-transform': 'uppercase',
		color: 'var(--newspack-ui-color-brand-text)',
	},
	'a:where(:not([class], [data-card]))': {
		color: 'var(--newspack-ui-color-link)',
		'text-decoration-line': 'underline',
		'text-decoration-color': 'color-mix(in oklab, var(--newspack-ui-color-link) 50%, var(--newspack-ui-color-surface))',
		'text-underline-offset': '3px',
	},
	'a:where(:not([class], [data-card])):hover': {
		'text-decoration-color': 'var(--newspack-ui-color-link)',
	},
	'.newspack-brand-eyebrow': {
		'font-family': 'var(--font-sans)',
		'font-weight': '600',
		'font-size': 'var(--newspack-ui-font-size-small)',
		'line-height': 'var(--newspack-ui-line-height-small)',
		'text-transform': 'uppercase',
	},
};

export const brandFontFiles = [
	[ 'American Grotesk Compressed', '900', 'normal', 'american-grotesk-compressed-black.woff2' ],
	[ 'American Grotesk', '400', 'normal', 'american-grotesk-regular.woff2' ],
	[ 'American Grotesk', '400', 'italic', 'american-grotesk-italic.woff2' ],
	[ 'American Grotesk', '500', 'normal', 'american-grotesk-medium.woff2' ],
	[ 'American Grotesk', '500', 'italic', 'american-grotesk-medium-italic.woff2' ],
];
