# Handoff

Navigates the user to another admin page and displays a return banner at the top of the destination so they can find their way back.

## How it works

1. The user clicks the Handoff button.
2. A POST request registers the current URL as the return destination and stores optional banner customisations.
3. The user is redirected to the destination page.
4. A banner appears at the top of that page with a "Back to Newspack" button (or custom text) that returns them to the origin.

## Usage

### With a URL

Use the `url` prop to hand off to any WordPress admin URL. No plugin needs to be installed.

```jsx
<Handoff url="/wp-admin/admin.php?page=newspack-dashboard#/">
    Go to Dashboard
</Handoff>
```

### With a managed plugin slug

Use the `plugin` prop to hand off to a Newspack-managed plugin. The button is automatically disabled if the plugin is not installed or inactive.

```jsx
<Handoff plugin="jetpack">
    Configure Jetpack
</Handoff>
```

Use `editLink` to override the destination to a specific page within the plugin:

```jsx
<Handoff plugin="wordpress-seo" editLink="/wp-admin/admin.php?page=wpseo_dashboard">
    Configure Yoast SEO
</Handoff>
```

### Via ActionCard

`ActionCard` accepts `handoffUrl` (URL-based) or `handoff` (plugin slug) as convenience props that render a `Handoff` as the action button. `bannerText` and `bannerButtonText` are supported on both:

```jsx
<ActionCard
    title="My Feature"
    actionText="Configure"
    handoffUrl="/wp-admin/admin.php?page=my-settings"
    bannerText="Return here once you're done."
    bannerButtonText="Back to My Feature"
/>

<ActionCard
    title="Jetpack"
    actionText="Configure"
    handoff="jetpack"
    editLink="/wp-admin/admin.php?page=jetpack#/settings"
    bannerText="Return here once you're done."
    bannerButtonText="Back to My Feature"
/>
```

## Props

| Prop | Type | Description |
|---|---|---|
| `url` | `string` | Admin URL to hand off to. Use this or `plugin`, not both. |
| `plugin` | `string` | Slug of a Newspack-managed plugin to hand off to. |
| `editLink` | `string` | Overrides the destination URL when using `plugin`. |
| `bannerText` | `string` | Custom body text for the return banner. Defaults to "Return to Newspack after completing configuration". |
| `bannerButtonText` | `string` | Custom label for the return button. Defaults to "Back to Newspack". |
| `showOnBlockEditor` | `bool` | Show the return banner inside the block editor. Default `false`. |
| `useModal` | `bool` | Show a confirmation modal before navigating. |
| `modalTitle` | `string` | Title for the confirmation modal. |
| `modalBody` | `string` | Body text for the confirmation modal. |
| `onReady` | `func` | Called with plugin info once loaded (plugin mode only). |
| `children` | `node` | Button label. Falls back to "Manage {Plugin Name}" in plugin mode. |

All other props are passed through to the underlying `Button` component (`isPrimary`, `isLink`, `isTertiary`, `className`, etc.).

## Banner customisation

```jsx
<Handoff
    url="/wp-admin/admin.php?page=my-settings"
    bannerText="Finish your configuration, then come back here."
    bannerButtonText="Back to My Feature"
>
    Open Settings
</Handoff>
```
