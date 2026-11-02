# EditTitleLink

A title that links to its edit screen. On hover and keyboard focus the title takes the brand color and a pencil fades in after it; on touch screens, with no hover, the pencil is always shown.

On a pointer device the pencil takes no space until it appears, so whatever follows the title stays close to it. Elements after the link inside the same heading slide over to make room while the pencil shows. When badges sit beside the heading rather than inside it, add `newspack-edit-title-link-row` to the row that holds the heading and the badges, and they slide over the same way. Both rely on the structure shown below: the link is a direct child of its heading, and for the row opt-in, the heading is a direct child of the row.

The styles apply inside a `.newspack-wizard` screen, where they take over from the wizard's own link styles. A modal renders outside that screen, so a link placed in one shows in the default link style.

## Props

| Prop | Type | Description |
|------|------|-------------|
| `href` | `string` | Where the title links to. |
| `children` | `ReactNode` | The title text. |

## Usage

```jsx
<h3>
	<EditTitleLink href="#/segments/12">Donors</EditTitleLink>
</h3>
```

With badges beside the heading:

```jsx
<Stack direction="row" align="center" gap="sm" className="newspack-edit-title-link-row">
	<h3>
		<EditTitleLink href="#/edit/7">Paid wall</EditTitleLink>
	</h3>
	<Badge intent="stable">Active</Badge>
</Stack>
```
