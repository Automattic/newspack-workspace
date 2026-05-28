# CardSettingsGroup

A collapsible settings card that renders a title, optional description, and optional icon in the header. Children are rendered only when the card is active.

## Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `actionType` | `'chevron'` \| `'toggle'` \| `'button'` \| `'link'` \| `'none'` | `'chevron'` | Controls the interactive element rendered in the card header. |
| `children` | `React.ReactNode` | — | Content displayed inside the card body. Only rendered when `isActive` is `true`. |
| `description` | `string` | `''` | Optional description rendered below the title in the card header. |
| `icon` | `React.ReactNode` | `null` | Optional icon rendered in the card header with a colored background. |
| `isActive` | `boolean` | `false` | When `true`, the card is expanded and children are visible. |
| `onEnable` | `() => void` | `() => {}` | Callback fired when the card header is clicked. Typically used to toggle `isActive`. |
| `title` | `string` | — | (**Required**) Title rendered in the card header. |

## Usage

```jsx
import { CardSettingsGroup } from 'newspack-components';
import { megaphone } from '@wordpress/icons';

// Basic collapsible settings group
<CardSettingsGroup
	title="Email Notifications"
	description="Configure how readers are notified."
	isActive={ isEnabled }
	onEnable={ () => setIsEnabled( ! isEnabled ) }
>
	<p>Settings content shown when active.</p>
</CardSettingsGroup>

// With an icon and toggle action type
<CardSettingsGroup
	actionType="toggle"
	icon={ megaphone }
	title="Push Notifications"
	isActive={ isEnabled }
	onEnable={ () => setIsEnabled( ! isEnabled ) }
>
	<p>Settings content shown when active.</p>
</CardSettingsGroup>
```
