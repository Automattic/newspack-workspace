# Block-theme Default Template Picker — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the block-theme default-template dropdowns with a visual grid of live, accurate template previews; the classic theme keeps its dropdowns.

**Architecture:** Additive on top of PR #169. The existing theme-mod storage, `wp_insert_post` apply logic, validation, and classic-theme path are unchanged. The REST endpoint gains a template `id` per block option, an `is_block_theme` flag, and a block-editor `settings` payload. A new set of React components renders `@wordpress/block-editor`'s `BlockPreview` inside a `BlockEditorProvider` fed those settings — the same engine the Site Editor uses, validated by a spike on 2026-06-01.

**Tech Stack:** PHP (WordPress REST + `get_block_editor_settings`), TypeScript/React, `@wordpress/block-editor` (`BlockPreview`, `BlockEditorProvider`), `@wordpress/core-data` (`useEntityBlockEditor`), `@wordpress/block-library` (`registerCoreBlocks`).

**Prerequisite:** This plan assumes a branch cut from `main` **after PR #169 has merged**, so `class-default-templates.php`, the endpoint, and the `DefaultTemplates` component already exist, and the container build works (the spike branch predated main's pnpm-workspace build fixes — do not build the picker on that branch).

**Reference spec:** `docs/superpowers/specs/2026-06-01-block-theme-template-picker-design.md`

---

## File Structure

**Modify:**
- `plugins/newspack-plugin/includes/class-default-templates.php` — add `id` to block options; add `is_block_theme` + `settings` to the endpoint response; add `get_editor_settings()`.
- `plugins/newspack-plugin/includes/wizards/newspack/class-newspack-settings.php` — enqueue block-editor scripts/styles on the settings page for block themes.
- `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/default-templates.tsx` — branch on `is_block_theme`: classic → dropdowns (unchanged); block → two `TemplatePicker`s.
- `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/index.tsx` — pass `settings`/new shape to `DefaultTemplates`.
- `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/default-templates.test.js` — update for the new shape.
- `plugins/newspack-plugin/tests/unit-tests/default-templates.php` — assert new response keys.

**Create:**
- `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-picker.tsx` — grid for one post type; owns the shared `BlockEditorProvider`, selection, and the "Default" badge.
- `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-card.tsx` — one selectable card: a `BlockPreview` thumbnail (or a "Default" placeholder).
- `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-picker.test.js` — component tests for the picker/card.

---

## Task 1: Endpoint returns template `id`, `is_block_theme`, and editor `settings`

**Files:**
- Modify: `plugins/newspack-plugin/includes/class-default-templates.php`
- Test: `plugins/newspack-plugin/tests/unit-tests/default-templates.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/unit-tests/default-templates.php` (inside the test class):

```php
/**
 * Block options expose the template entity id for preview loading.
 */
public function test_block_template_options_include_id() {
	switch_theme( 'twentytwentyfour' );
	$options = Default_Templates::get_block_template_options( 'post' );
	// First option is always "Default" with no id.
	$this->assertSame( 'default', $options[0]['value'] );
	$this->assertArrayNotHasKey( 'id', $options[0] );
	// Any real template option carries an id.
	if ( count( $options ) > 1 ) {
		$this->assertArrayHasKey( 'id', $options[1] );
		$this->assertNotEmpty( $options[1]['id'] );
	}
}

/**
 * The endpoint response includes is_block_theme and settings for block themes.
 */
public function test_rest_response_includes_block_theme_flag_and_settings() {
	switch_theme( 'twentytwentyfour' );
	$response = Default_Templates::api_get_template_options();
	$data     = $response->get_data();
	$this->assertTrue( $data['is_block_theme'] );
	$this->assertIsArray( $data['settings'] );
	$this->assertArrayHasKey( 'post', $data );
	$this->assertArrayHasKey( 'page', $data );
}

/**
 * Classic themes report is_block_theme false and a null settings payload.
 */
public function test_rest_response_classic_theme_has_no_settings() {
	switch_theme( 'newspack-theme' );
	$response = Default_Templates::api_get_template_options();
	$data     = $response->get_data();
	$this->assertFalse( $data['is_block_theme'] );
	$this->assertNull( $data['settings'] );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd plugins/newspack-plugin && n test-php --filter test_block_template_options_include_id`
Expected: FAIL (`id` key missing).
Run: `n test-php --filter test_rest_response_includes_block_theme_flag_and_settings`
Expected: FAIL (`is_block_theme`/`settings` keys missing).

- [ ] **Step 3: Add `id` to block options**

In `class-default-templates.php`, in `get_block_template_options()`, update the option pushed inside the `foreach`:

```php
$options[] = [
	'label' => empty( $template->title ) ? $template->slug : $template->title,
	'value' => $template->slug,
	'id'    => $template->id,
];
```

- [ ] **Step 4: Add `get_editor_settings()` helper**

Add this method to `Default_Templates`:

```php
/**
 * Block editor settings for rendering accurate template previews.
 *
 * Returns null for classic themes (no block previews) and when core's
 * block editor settings helper is unavailable.
 *
 * @return array|null
 */
public static function get_editor_settings() {
	if ( ! wp_is_block_theme() || ! function_exists( 'get_block_editor_settings' ) ) {
		return null;
	}
	$context = new \WP_Block_Editor_Context( [ 'name' => 'core/edit-post' ] );
	return get_block_editor_settings( [], $context );
}
```

- [ ] **Step 5: Expand the endpoint response shape**

Replace `get_template_options()` with:

```php
public static function get_template_options() {
	if ( wp_is_block_theme() ) {
		return [
			'is_block_theme' => true,
			'post'           => self::get_block_template_options( 'post' ),
			'page'           => self::get_block_template_options( 'page' ),
			'settings'       => self::get_editor_settings(),
		];
	}
	$classic = self::get_classic_template_options();
	return [
		'is_block_theme' => false,
		'post'           => $classic,
		'page'           => $classic,
		'settings'       => null,
	];
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd plugins/newspack-plugin && n test-php --filter test_block_template_options_include_id`
Run: `n test-php --filter test_rest_response_includes_block_theme_flag_and_settings`
Run: `n test-php --filter test_rest_response_classic_theme_has_no_settings`
Expected: PASS. Then run the full file to confirm no regression:
Run: `n test-php --filter Default_Templates`
Expected: PASS (existing tests still green; if `test_rest_endpoint_returns_post_and_page_options` asserted an exact top-level key set, loosen it to allow the new keys).

- [ ] **Step 7: Lint and commit**

```bash
cd plugins/newspack-plugin && npm run lint:php
git add includes/class-default-templates.php tests/unit-tests/default-templates.php
git commit -m "feat(settings): expose template id and editor settings for previews

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Enqueue block-editor assets on the settings page (block theme only)

`BlockPreview` requires the block-editor JS globals and block styles, which the
Newspack Settings admin page does not load by default (spike finding). Load them
only on the settings page and only for block themes.

**Files:**
- Modify: `plugins/newspack-plugin/includes/wizards/newspack/class-newspack-settings.php`

- [ ] **Step 1: Add the enqueue, scoped to block theme**

In `enqueue_scripts_and_styles()`, immediately before the final
`wp_enqueue_script( 'newspack-wizards' );`, insert:

```php
// Block-theme template previews need the block-editor scripts + styles, which
// aren't on this admin page by default. Declare them as dependencies of the
// wizard bundle so they load first, and enqueue the block styles. Scoped to
// the settings page (this method already returns early otherwise) and block
// themes only.
if ( wp_is_block_theme() ) {
	$registered = wp_scripts()->registered['newspack-wizards'] ?? null;
	if ( $registered ) {
		foreach ( [ 'wp-block-library', 'wp-block-editor', 'wp-format-library' ] as $handle ) {
			if ( ! in_array( $handle, $registered->deps, true ) ) {
				$registered->deps[] = $handle;
			}
		}
	}
	wp_enqueue_style( 'wp-block-library' );
	wp_enqueue_style( 'wp-edit-blocks' );
}
```

- [ ] **Step 2: Verify manually**

Run: `cd plugins/newspack-plugin && n build`
Then load **Newspack → Settings → Advanced Settings** on a block-theme site.
Expected: no console error; `window.wp.blockLibrary` is defined (check in devtools console: `typeof wp.blockLibrary`).
On a classic-theme site, confirm the block scripts are NOT enqueued (view source; no `wp-block-editor` script tag).

- [ ] **Step 3: Lint and commit**

```bash
cd plugins/newspack-plugin && npm run lint:php
git add includes/wizards/newspack/class-newspack-settings.php
git commit -m "feat(settings): load block-editor assets for template previews

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `TemplateCard` component

A single selectable card. With an `id`, it renders a `BlockPreview` thumbnail of
the template; without one (the "Default" option), it renders a placeholder.

**Files:**
- Create: `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-card.tsx`
- Test: covered by `template-picker.test.js` in Task 5.

- [ ] **Step 1: Create the component**

```tsx
/**
 * Newspack > Settings > Advanced Settings > Template Card.
 *
 * One selectable template preview within a TemplatePicker grid.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { BlockPreview } from '@wordpress/block-editor';
import { useEntityBlockEditor } from '@wordpress/core-data';

export interface TemplateOption {
	label: string;
	value: string;
	id?: string;
}

interface TemplateCardProps {
	template: TemplateOption;
	selected: boolean;
	onSelect: ( value: string ) => void;
}

function CardPreview( { id }: { id: string } ) {
	const [ blocks ] = useEntityBlockEditor( 'postType', 'wp_template', { id } );
	if ( ! blocks || ! blocks.length ) {
		return <div className="newspack-template-card__placeholder" />;
	}
	return (
		<BlockPreview.Async>
			<BlockPreview blocks={ blocks } viewportWidth={ 1000 } />
		</BlockPreview.Async>
	);
}

export default function TemplateCard( { template, selected, onSelect }: TemplateCardProps ) {
	return (
		<button
			type="button"
			className={ `newspack-template-card${ selected ? ' is-selected' : '' }` }
			aria-pressed={ selected }
			onClick={ () => onSelect( template.value ) }
		>
			<div className="newspack-template-card__thumb">
				{ template.id ? (
					<CardPreview id={ template.id } />
				) : (
					<div className="newspack-template-card__placeholder">
						{ __( 'Theme default', 'newspack-plugin' ) }
					</div>
				) }
			</div>
			<div className="newspack-template-card__label">
				{ template.label }
				{ selected && (
					<span className="newspack-template-card__badge">
						{ __( 'Default', 'newspack-plugin' ) }
					</span>
				) }
			</div>
		</button>
	);
}
```

- [ ] **Step 2: Add styles**

Create `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-card.scss` (import it from `template-picker.tsx` in Task 4):

```scss
.newspack-template-card {
	display: block;
	width: 220px;
	padding: 0;
	cursor: pointer;
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 4px;
	overflow: hidden;
	text-align: left;

	&.is-selected {
		border-color: var( --wp-admin-theme-color, #3858e9 );
		box-shadow: 0 0 0 1px var( --wp-admin-theme-color, #3858e9 );
	}

	&__thumb {
		aspect-ratio: 4 / 3;
		overflow: hidden;
		pointer-events: none;
		background: #fff;
		border-bottom: 1px solid #eee;
	}

	&__placeholder {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		height: 100%;
		color: #757575;
		font-size: 12px;
	}

	&__label {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding: 8px 12px;
		font-size: 13px;
	}

	&__badge {
		padding: 2px 6px;
		font-size: 11px;
		color: #fff;
		background: var( --wp-admin-theme-color, #3858e9 );
		border-radius: 2px;
	}
}
```

- [ ] **Step 3: Commit**

```bash
cd plugins/newspack-plugin
git add src/wizards/newspack/views/settings/advanced-settings/template-card.tsx src/wizards/newspack/views/settings/advanced-settings/template-card.scss
git commit -m "feat(settings): add TemplateCard preview component

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `TemplatePicker` component

Renders the grid for one post type, owns the shared `BlockEditorProvider` (so all
cards share the editor settings), registers core blocks once, and handles
selection.

**Files:**
- Create: `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-picker.tsx`
- Test: `template-picker.test.js` in Task 5.

- [ ] **Step 1: Create the component**

```tsx
/**
 * Newspack > Settings > Advanced Settings > Template Picker.
 *
 * A grid of selectable template previews for one post type.
 */

/**
 * WordPress dependencies.
 */
import { useEffect } from '@wordpress/element';
import { BlockEditorProvider } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Internal dependencies.
 */
import TemplateCard, { TemplateOption } from './template-card';
import './template-card.scss';

interface TemplatePickerProps {
	label: string;
	help?: string;
	options: TemplateOption[];
	settings: Record< string, unknown >;
	value: string;
	onChange: ( value: string ) => void;
}

// Core blocks aren't registered on the settings page; register once so
// BlockPreview can render them. wp-block-library may also register them on
// load, so guard to avoid running twice.
let coreBlocksRegistered = false;
function ensureCoreBlocks() {
	if ( ! coreBlocksRegistered ) {
		registerCoreBlocks();
		coreBlocksRegistered = true;
	}
}

export default function TemplatePicker( {
	label,
	help,
	options,
	settings,
	value,
	onChange,
}: TemplatePickerProps ) {
	useEffect( () => {
		ensureCoreBlocks();
	}, [] );

	return (
		<div className="newspack-template-picker">
			<p className="newspack-template-picker__label">{ label }</p>
			{ help && <p className="newspack-template-picker__help">{ help }</p> }
			<BlockEditorProvider value={ [] } settings={ settings }>
				<div className="newspack-template-picker__grid">
					{ options.map( option => (
						<TemplateCard
							key={ option.value }
							template={ option }
							selected={ option.value === value }
							onSelect={ onChange }
						/>
					) ) }
				</div>
			</BlockEditorProvider>
		</div>
	);
}
```

- [ ] **Step 2: Add grid styles**

Append to `template-card.scss`:

```scss
.newspack-template-picker {
	margin-bottom: 32px;

	&__label {
		margin: 0 0 4px;
		font-weight: 600;
	}

	&__help {
		margin: 0 0 12px;
		color: #757575;
	}

	&__grid {
		display: flex;
		flex-wrap: wrap;
		gap: 16px;
	}
}
```

- [ ] **Step 3: Commit**

```bash
cd plugins/newspack-plugin
git add src/wizards/newspack/views/settings/advanced-settings/template-picker.tsx src/wizards/newspack/views/settings/advanced-settings/template-card.scss
git commit -m "feat(settings): add TemplatePicker grid component

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Make `DefaultTemplates` theme-aware and wire it up

Branch on `is_block_theme`: classic keeps the dropdowns; block themes render two
pickers. Update the parent view to pass the new options shape.

**Files:**
- Modify: `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/default-templates.tsx`
- Modify: `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/index.tsx`
- Test: `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-picker.test.js`
- Modify: `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/default-templates.test.js`

- [ ] **Step 1: Write the failing picker test**

Create `template-picker.test.js`:

```js
/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TemplatePicker from './template-picker';

// BlockPreview spins up a real editor iframe; stub it and the entity hook so
// the grid renders in jsdom without a block editor.
jest.mock( '@wordpress/block-editor', () => ( {
	BlockEditorProvider: ( { children } ) => <div>{ children }</div>,
	BlockPreview: Object.assign( () => <div data-testid="preview" />, {
		Async: ( { children } ) => <div>{ children }</div>,
	} ),
} ) );
jest.mock( '@wordpress/core-data', () => ( {
	useEntityBlockEditor: () => [ [ { name: 'core/paragraph' } ] ],
} ) );
jest.mock( '@wordpress/block-library', () => ( { registerCoreBlocks: jest.fn() } ) );

const options = [
	{ label: 'Default', value: 'default' },
	{ label: 'Large Image', value: 'single/large-image', id: 'theme//single/large-image' },
];

describe( 'TemplatePicker', () => {
	it( 'renders a card per option', () => {
		render(
			<TemplatePicker label="Posts" options={ options } settings={ {} } value="default" onChange={ () => {} } />
		);
		expect( screen.getByText( 'Default' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Large Image' ) ).toBeInTheDocument();
	} );

	it( 'marks the selected option with a Default badge', () => {
		render(
			<TemplatePicker label="Posts" options={ options } settings={ {} } value="single/large-image" onChange={ () => {} } />
		);
		// "Default" badge text appears on the selected card.
		const badges = screen.getAllByText( 'Default' );
		expect( badges.length ).toBeGreaterThan( 0 );
	} );

	it( 'calls onChange with the chosen template value', () => {
		const onChange = jest.fn();
		render(
			<TemplatePicker label="Posts" options={ options } settings={ {} } value="default" onChange={ onChange } />
		);
		fireEvent.click( screen.getByText( 'Large Image' ) );
		expect( onChange ).toHaveBeenCalledWith( 'single/large-image' );
	} );
} );
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd plugins/newspack-plugin && npm test`
Expected: FAIL (`template-picker` exists from Task 4, but `default-templates.test.js` still asserts the old dropdown shape — see Step 5; the picker tests should pass once Task 4 is in. If they fail on import, confirm Task 4 was completed.)

- [ ] **Step 3: Rewrite `default-templates.tsx` to branch on theme**

```tsx
/**
 * Newspack > Settings > Advanced Settings > Default Templates
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid, SelectControl } from '../../../../../../packages/components/src';
import TemplatePicker from './template-picker';
import { TemplateOption } from './template-card';

export interface TemplateOptions {
	is_block_theme: boolean;
	post: TemplateOption[];
	page: TemplateOption[];
	settings: Record< string, unknown > | null;
}

export default function DefaultTemplates( {
	data,
	update,
	options,
}: ThemeModComponentProps< AdvancedSettings > & { options: TemplateOptions } ) {
	if ( options.is_block_theme && options.settings ) {
		return (
			<>
				<TemplatePicker
					label={ __( 'Default template for new posts', 'newspack-plugin' ) }
					help={ __( 'New posts will use this template.', 'newspack-plugin' ) }
					options={ options.post }
					settings={ options.settings }
					value={ data.post_template_default }
					onChange={ ( post_template_default: string ) => update( { post_template_default } ) }
				/>
				<TemplatePicker
					label={ __( 'Default template for new pages', 'newspack-plugin' ) }
					help={ __( 'New pages will use this template.', 'newspack-plugin' ) }
					options={ options.page }
					settings={ options.settings }
					value={ data.page_template_default }
					onChange={ ( page_template_default: string ) => update( { page_template_default } ) }
				/>
			</>
		);
	}

	return (
		<Grid gutter={ 32 }>
			<SelectControl
				label={ __( 'Default template for new posts', 'newspack-plugin' ) }
				help={ __( 'Set a default template for new posts.', 'newspack-plugin' ) }
				value={ data.post_template_default }
				options={ options.post }
				onChange={ ( post_template_default: string ) => update( { post_template_default } ) }
			/>
			<SelectControl
				label={ __( 'Default template for new pages', 'newspack-plugin' ) }
				help={ __( 'Set a default template for new pages.', 'newspack-plugin' ) }
				value={ data.page_template_default }
				options={ options.page }
				onChange={ ( page_template_default: string ) => update( { page_template_default } ) }
			/>
		</Grid>
	);
}
```

- [ ] **Step 4: Update the parent view's default options shape**

In `index.tsx`, update the `templateOptions` initial state (around the existing `useObjectState< TemplateOptions >` call) to match the new shape:

```tsx
const [ templateOptions, setTemplateOptions ] = hooks.useObjectState< TemplateOptions >( {
	is_block_theme: false,
	post: [ { label: __( 'Default', 'newspack-plugin' ), value: 'default' } ],
	page: [ { label: __( 'Default', 'newspack-plugin' ), value: 'default' } ],
	settings: null,
} );
```

The existing `wizardApiFetchTemplateOptions(...).onSuccess: setTemplateOptions` call and the `<DefaultTemplates ... options={ templateOptions } />` usage need no other change.

- [ ] **Step 5: Update the existing dropdown test for the new shape**

In `default-templates.test.js`, the `options` fixture must include the new keys, and the classic-theme assertions still apply (set `is_block_theme: false`):

```js
const options = {
	is_block_theme: false,
	settings: null,
	post: [
		{ label: 'Default', value: 'default' },
		{ label: 'Large Image', value: 'single/large-image' },
	],
	page: [
		{ label: 'Default', value: 'default' },
		{ label: 'Wide Page', value: 'page/wide' },
	],
};
```

Leave the two existing `it(...)` cases as-is — they assert the dropdown path, which is what `is_block_theme: false` renders.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd plugins/newspack-plugin && npm test`
Expected: PASS — both `default-templates.test.js` (dropdown path) and `template-picker.test.js` (picker path) green.

- [ ] **Step 7: Type-check**

Run: `cd plugins/newspack-plugin && npm run tsc` (Ctrl-C after it reports no errors; it runs in watch mode)
Expected: no type errors. If `TemplateOptions` is referenced in `index.tsx`'s import from `./default-templates`, confirm the import still resolves (it is re-exported from `default-templates.tsx`).

- [ ] **Step 8: Lint and commit**

```bash
cd plugins/newspack-plugin && npm run lint:js
git add src/wizards/newspack/views/settings/advanced-settings/default-templates.tsx \
        src/wizards/newspack/views/settings/advanced-settings/index.tsx \
        src/wizards/newspack/views/settings/advanced-settings/default-templates.test.js \
        src/wizards/newspack/views/settings/advanced-settings/template-picker.test.js
git commit -m "feat(settings): visual template picker for block themes

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Loading skeleton and empty state

Async previews should not pop in jarringly, and a block theme with no custom
templates should still render cleanly (only the "Default" card).

**Files:**
- Modify: `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-card.tsx`
- Modify: `plugins/newspack-plugin/src/wizards/newspack/views/settings/advanced-settings/template-card.scss`

- [ ] **Step 1: Add a skeleton while the preview resolves**

In `template-card.tsx`, replace the `CardPreview` early-return so an unresolved
preview shows a skeleton rather than an empty box:

```tsx
function CardPreview( { id }: { id: string } ) {
	const [ blocks ] = useEntityBlockEditor( 'postType', 'wp_template', { id } );
	if ( ! blocks || ! blocks.length ) {
		return <div className="newspack-template-card__skeleton" aria-hidden="true" />;
	}
	return (
		<BlockPreview.Async>
			<BlockPreview blocks={ blocks } viewportWidth={ 1000 } />
		</BlockPreview.Async>
	);
}
```

- [ ] **Step 2: Add skeleton styles**

Append to `template-card.scss`:

```scss
.newspack-template-card__skeleton {
	width: 100%;
	height: 100%;
	background: linear-gradient( 90deg, #f0f0f0 25%, #e6e6e6 37%, #f0f0f0 63% );
	background-size: 400% 100%;
	animation: newspack-template-card-shimmer 1.4s ease infinite;
}

@keyframes newspack-template-card-shimmer {
	0% { background-position: 100% 0; }
	100% { background-position: -100% 0; }
}
```

- [ ] **Step 3: Verify the empty state manually**

The empty state needs no code: a block theme with no custom templates returns
just the `{ label: 'Default', value: 'default' }` option, so the picker renders a
single "Default" card. Confirm by previewing on a block theme whose
`get_block_template_options('post')` returns only the default (or temporarily
filter the options to one item) — the grid shows one card, no errors.

- [ ] **Step 4: Run tests, lint, commit**

```bash
cd plugins/newspack-plugin && npm test && npm run lint:js
git add src/wizards/newspack/views/settings/advanced-settings/template-card.tsx \
        src/wizards/newspack/views/settings/advanced-settings/template-card.scss
git commit -m "feat(settings): add loading skeleton to template previews

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Full build and manual QA

**Files:** none (verification only).

- [ ] **Step 1: Build**

Run: `cd plugins/newspack-plugin && n build`
Expected: build succeeds, no errors.

- [ ] **Step 2: Block-theme QA**

On a block-theme site, open **Newspack → Settings → Advanced Settings → Default
Templates**. Verify:
- Two picker grids (posts, pages) render accurate thumbnails.
- Visually-similar single templates are distinguishable.
- The currently-saved default is highlighted with a "Default" badge.
- Clicking a card, then **Save**, persists the choice (reload: same card stays highlighted).
- Creating a new post applies the chosen template (check the post's Template panel).

- [ ] **Step 3: Classic-theme QA**

Switch to `newspack-theme`. Confirm the section renders the two dropdowns
(unchanged), with no block-editor scripts loaded and no console errors.

- [ ] **Step 4: Final commit (if any QA fixes were needed)**

```bash
cd plugins/newspack-plugin
git add -A
git commit -m "fix(settings): template picker QA fixes

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review Notes

- **Spec coverage:** endpoint `id`/`settings` (Task 1), asset enqueue (Task 2), `TemplateCard`/`TemplatePicker` reusable components (Tasks 3–4), theme-aware branch + classic dropdowns preserved (Task 5), loading skeleton + empty state (Task 6), QA incl. apply-on-create (Task 7). Bulk "all posts" control is intentionally out of scope per spec.
- **Reusability:** `TemplateCard`/`TemplatePicker` take plain props (no coupling to the defaults flow), so the future bulk-apply control can reuse them in its own framing.
- **Type consistency:** `TemplateOption` ({ label, value, id? }) defined in `template-card.tsx` and reused by `template-picker.tsx` and `default-templates.tsx`; `TemplateOptions` ({ is_block_theme, post, page, settings }) defined in `default-templates.tsx` and imported by `index.tsx`.
- **Not covered (acceptable):** the stored-default-no-longer-exists fallback is handled server-side by #169's `validate_template` on apply; in the picker, a `value` with no matching option simply leaves no card highlighted — acceptable, revisit only if QA flags it.
