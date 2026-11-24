export const sources = {
	background: [ '--newspack-ui-color-surface', 'Page surface. Every newspack-ui component sits on it.' ],
	foreground: [ '--newspack-ui-color-text', 'Body text color.' ],
	card: [ '--newspack-ui-color-surface', 'newspack-ui cards and boxes are the page surface with a border, not a raised fill.' ],
	'card-foreground': [ '--newspack-ui-color-text', 'Same text color as the page.' ],
	popover: [ '--newspack-ui-color-surface', 'Dropdowns and modals use the surface color.' ],
	'popover-foreground': [ '--newspack-ui-color-text', 'Same text color as the page.' ],
	primary: [ '--newspack-ui-color-button-bg', 'The Neutral primary button background. It is the text color, not the brand blue (--newspack-ui-color-primary), which Neutral reserves for site branding.' ],
	'primary-foreground': [ '--newspack-ui-color-button-text', 'Text on the primary button.' ],
	secondary: [ '--newspack-ui-color-neutral-fill', 'The secondary button fill: 7% of the text color mixed into the surface.' ],
	'secondary-foreground': [ '--newspack-ui-color-text', 'Text on the secondary button.' ],
	muted: [ '--newspack-ui-color-surface-sunken', 'Sunken surface, also used for disabled input backgrounds.' ],
	'muted-foreground': [ '--newspack-ui-color-text-muted', 'Muted text. neutral-70, which holds Lc 60 on the surface.' ],
	accent: [ '--newspack-ui-color-neutral-fill', 'Hover fill of the ghost button, the closest match to shadcn\'s accent role.' ],
	'accent-foreground': [ '--newspack-ui-color-text', 'Text on hovered ghost items.' ],
	destructive: [ '--newspack-ui-color-destructive-fill', 'Destructive button fill (error-50).' ],
	border: [ '--newspack-ui-color-border', 'Decorative borders and dividers (neutral-30).' ],
	input: [ '--newspack-ui-color-input-border', 'Form control border (neutral-50). It is darker than --border on purpose, so controls meet non-text contrast.' ],
	ring: [ '--newspack-ui-color-focus', 'Focus color. shadcn draws it as a 3px ring at 50% alpha; newspack-ui draws a 3px halo at 30%.' ],
	sidebar: [ '--newspack-ui-color-surface-subtle', 'newspack-ui has no sidebar component. A slightly tinted surface.' ],
	'sidebar-foreground': [ '--newspack-ui-color-text', 'Same text color as the page.' ],
	'sidebar-primary': [ '--newspack-ui-color-button-bg', 'Same as --primary.' ],
	'sidebar-primary-foreground': [ '--newspack-ui-color-button-text', 'Same as --primary-foreground.' ],
	'sidebar-accent': [ '--newspack-ui-color-neutral-fill', 'Same as --accent.' ],
	'sidebar-accent-foreground': [ '--newspack-ui-color-text', 'Same as --accent-foreground.' ],
	'sidebar-border': [ '--newspack-ui-color-border', 'Same as --border.' ],
	'sidebar-ring': [ '--newspack-ui-color-focus', 'Same as --ring.' ],
};

export const fontStack = '"Inter Variable", "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';

export const radiusScale = {
	xs: [ '--newspack-ui-border-radius-2xs', 'rounded-xs. Hairline rounding: small marks and inner corners.' ],
	sm: [ '--newspack-ui-radius-badge', 'rounded-sm. Badges, tags and other small chips.' ],
	md: [ '--newspack-ui-radius-control', 'rounded-md. Buttons, inputs, selects, alerts: every control.' ],
	lg: [ '--newspack-ui-radius-surface', 'rounded-lg. Cards, panels, popovers and dialogs.' ],
	xl: [ '--newspack-ui-radius-sheet', 'rounded-xl. Bottom sheets and other large surfaces.' ],
	'2xl': [ '1rem', 'rounded-2xl. No newspack-ui equivalent: Tailwind\'s default, pinned so it does not drift with --radius.' ],
	'3xl': [ '1.5rem', 'rounded-3xl. No newspack-ui equivalent: Tailwind\'s default, pinned.' ],
	'4xl': [ '2rem', 'rounded-4xl. No newspack-ui equivalent: Tailwind\'s default, pinned.' ],
};

export const chartSource = 'The Insights chart series palette (.is-series-0 to 7): blue and orange families, because that pairing holds up for color-blind readers, then two grays.';

export const chartSeries = [
	[ 'primary-700', 'Darkest blue.' ],
	[ 'primary-500', 'Mid blue.' ],
	[ 'primary-300', 'Light blue.' ],
	[ '#9a3412', 'Darkest orange. No newspack-colors token yet.' ],
	[ '#c2410c', 'Mid orange. No newspack-colors token yet.' ],
	[ '#ea580c', 'Light orange. No newspack-colors token yet.' ],
	[ 'neutral-600', 'Lighter gray, for the tail.' ],
	[ 'neutral-800', 'Darker gray, for the tail.' ],
];

export const chartOrder = [ 0, 3, 1, 4, 6 ];

export const gaps = [
	'chart-1 to chart-5: taken from the Insights series palette, alternating blue and orange the way Insights pairs two series. All eight are also written as --newspack-ui-chart-0 to 7. The three light oranges (#9a3412, #c2410c, #ea580c) are hex values with no newspack-colors token yet. In dark, series 0 to 2 are newspack-colors primary-100 to 300, series 3 to 5 are quaternary-500 to 700, and series 6 and 7 are dark grays.',
	'sidebar-*: newspack-ui has no sidebar component, so these repeat the surface, accent and border roles.',
	'--destructive-foreground is not emitted. Current shadcn buttons use white text on destructive, which matches --newspack-ui-color-on-error (#fff).',
	'Radius: Tailwind\'s rounded-xs to rounded-xl are mapped onto newspack-ui radii on purpose (badge, control, surface and sheet), so a stock component or a page using rounded-md gets the control radius. --radius is the surface radius and nothing derives from it. rounded-2xl to rounded-4xl have no newspack-ui equivalent and keep Tailwind\'s values. The Newspack components read the radius tokens directly.',
	'Inter: newspack-ui names the family "Inter" and loads its own font files. The shadcn side installs @fontsource-variable/inter, which registers "Inter Variable", so the family stacks name both, then system fonts. The WordPress and shadcn sides therefore render the same face, from different files.',
	'--font-mono: newspack-ui has no monospace family. The generator falls back to the system monospace stack.',
	'Dark mode: the .dark block is generated from the dark palette (dark-tokens.json, read at build from build/dark-palette). shadcn variables point at the dark tokens, and chart series have their own dark values. The palette is not yet part of newspack-ui, so a theme\'s own brand color is not adapted.',
	'Hover and pressed states: stock shadcn components use opacity utilities (hover:bg-primary/90), so their hover shades differ. The Newspack Button reads the hover and pressed tokens.',
	'Elevation: stock shadcn components use fixed Tailwind shadows. The Newspack components use --nui-elevation-1 to 4, compiled from the SCSS mixin.',
	'Spacing, control height and type scale: stock shadcn components hardcode Tailwind sizes that no variable reaches. That is why the registry ships its own components, not only a theme.',
];

export const noToken = [
	{ component: 'All', value: 'Elevation 1 to 4 (offsets, blurs, spreads, ink shares)', source: 'scss/_mixins.scss, $elevation-levels. Only ink, edge and strength are tokens. The generator compiles the real mixin and writes --nui-elevation-1 to 4, so the shapes follow the SCSS but are not token values.' },
	{ component: 'All', value: 'Focus edge width: 1px on fields and checkboxes, 2px on buttons, checked controls and invalid fields', source: 'scss/_mixins.scss, focus-edge() arguments. The halo width and color are tokens.' },
	{ component: 'Button', value: 'Pressed scale 0.97', source: 'scss/elements/forms/_buttons.scss, &:active' },
	{ component: 'Button', value: 'Sizes as multiples of the spacer: 28px (3.5), 36px (spacer-7), 48px (spacer-9); small padding 14px (1.75); x-small padding 10px (1.25) and radius 0.75 of the button radius', source: 'scss/elements/forms/_buttons.scss. The multipliers are literals; only the 8px spacer base is a token.' },
	{ component: 'Button', value: 'Link underline offset 3px and 50% underline mix', source: 'scss/elements/forms/_buttons.scss, .newspack-ui__link' },
	{ component: 'Checkbox', value: 'Radius at half the control radius; tick path', source: 'scss/elements/forms/_checkbox-radio.scss' },
	{ component: 'Radio Group', value: 'Dot drawn as a 5px inset shadow', source: 'scss/elements/forms/_checkbox-radio.scss' },
	{ component: 'Select', value: 'Caret inset (spacer-1) plus width (spacer-5) plus 1.25 of spacer-1 as right padding', source: 'scss/elements/forms/_select.scss. Option padding is not defined: newspack-ui renders a native select, so the list rows are our choice.' },
	{ component: 'Badge', value: 'Letter spacing 0.01em; padding 0.75 of the spacer; dot sizes', source: 'scss/elements/misc/_badge.scss' },
	{ component: 'Alert', value: 'Icon margin of half the half spacer (2px)', source: 'scss/elements/_notices.scss' },
	{ component: 'Tabs', value: 'Indicator and focus line 2px', source: 'scss/elements/_tabs.scss' },
	{ component: 'Dialog', value: 'Bottom sheet breakpoint 600px', source: 'scss/variables/_breakpoints.scss ($mobile_width), a Sass variable, not a custom property' },
	{ component: 'Dialog', value: 'Open scale 0.96, title minimum 18px, max height 90%', source: 'scss/_modals.scss' },
];
