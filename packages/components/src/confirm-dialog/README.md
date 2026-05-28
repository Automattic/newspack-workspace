# ConfirmDialog

A modal confirmation dialog that intercepts client-side navigation when there are unsaved changes. Built on top of WordPress's `__experimentalConfirmDialog`. The dialog is invisible until a navigation attempt is blocked; when `when` becomes `true` and the user navigates away, the dialog appears automatically and resumes or cancels the navigation based on the user's choice.

For most imperative use cases (confirming a destructive action, guarding an action behind an unsaved-changes check) prefer the [`useConfirmDialog` hook](#useconfirmdialog-hook) over using `ConfirmDialog` directly.

## Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `cancelButtonText` | `string` | — | Label for the cancel button. |
| `children` | `React.ReactNode` | — | Content rendered in the modal body. |
| `className` | `string` | — | Additional CSS class. |
| `confirmButtonText` | `string` | — | Label for the confirm button. |
| `hideTitle` | `boolean` | — | When `true`, hides the modal title and the × close button. |
| `isDestructive` | `boolean` | — | When `true`, applies destructive (e.g. red) styling to the confirm button. |
| `isOpen` | `boolean` | `false` | When `true`, shows the dialog immediately without blocking navigation. Use this for imperative confirmation (e.g. confirming a destructive action on a button click). |
| `size` | `'small'` \| `'medium'` \| `'large'` \| `'x-large'` \| `'full'` | `'small'` | Controls the width of the modal. |
| `title` | `string` | — | Title displayed in the modal header. |
| `when` | `boolean` | `false` | When `true`, blocks router navigation and shows the dialog on any attempted navigation. Set this to reflect whether the current form has unsaved changes. |

## Usage

```jsx
import { ConfirmDialog } from 'newspack-components';

// Guard navigation when a form has unsaved changes.
// The dialog appears automatically when the user tries to navigate away.
<ConfirmDialog
	when={ hasUnsavedChanges }
	title="Unsaved changes"
	confirmButtonText="Leave anyway"
	cancelButtonText="Stay"
	isDestructive
>
	You have unsaved changes. Are you sure you want to leave?
</ConfirmDialog>

// Larger modal with a custom size
<ConfirmDialog
	when={ hasUnsavedChanges }
	size="medium"
	title="Discard changes?"
	confirmButtonText="Discard"
	cancelButtonText="Keep editing"
>
	Your changes will be lost if you leave this page.
</ConfirmDialog>

// Guard both navigation and an imperative action with a single dialog instance.
// isOpen triggers the dialog immediately (e.g. on a button click) without
// blocking navigation. Both when and isOpen can be used together.
const [ pendingAction, setPendingAction ] = useState( null );
<ConfirmDialog
	when={ hasUnsavedChanges }
	isOpen={ !! pendingAction }
	confirmButtonText="Discard changes"
	isDestructive
	hideTitle
	onConfirm={ () => {
		pendingAction?.();
		setPendingAction( null );
	} }
	onCancel={ () => setPendingAction( null ) }
>
	You have unsaved changes that will be lost. Discard changes?
</ConfirmDialog>
```

---

## `useConfirmDialog` hook

A higher-level hook that wraps `ConfirmDialog` and manages the pending-action state for you. Prefer this over using `ConfirmDialog` directly when you need imperative confirmation.

```jsx
import { useConfirmDialog } from 'newspack-components';
```

### Options

Accepts all `ConfirmDialog` props except `isOpen`, `onConfirm`, `onCancel`, and `children`, plus:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `message` | `React.ReactNode` | — | Content rendered in the modal body. |
| `when` | `boolean` | — | When explicitly `false`, `requestConfirm` skips the dialog and invokes the callback immediately. Omit (or pass `true`) to always show the dialog. Also controls router navigation blocking, same as the `when` prop on `ConfirmDialog`. |

### Returns

| Key | Type | Description |
|-----|------|-------------|
| `confirmDialog` | `React.ReactElement` | The dialog element — render this somewhere in your JSX. |
| `requestConfirm` | `( callback: () => void ) => void` | Call with a callback to request confirmation. Shows the dialog (unless `when` is `false`); invokes the callback only if the user confirms. |

### Usage

```jsx
// Always-confirm dialog (e.g. destructive delete action).
const { confirmDialog, requestConfirm } = useConfirmDialog( {
	title: 'Are you sure?',
	confirmButtonText: 'Delete',
	isDestructive: true,
	message: 'This will permanently delete the item and cannot be undone.',
} );

// Render the dialog element in JSX:
{ confirmDialog }

// Call requestConfirm when the user initiates the action:
<Button onClick={ () => requestConfirm( handleDelete ) }>Delete</Button>

// Conditional dialog — skips confirmation when there are no unsaved changes.
// Combines navigation blocking with imperative use in a single instance.
const { confirmDialog, requestConfirm } = useConfirmDialog( {
	when: isDirty,
	message: 'You have unsaved changes that will be lost. Discard changes?',
	confirmButtonText: 'Discard changes',
	isDestructive: true,
	hideTitle: true,
} );

{ confirmDialog }

// In an action handler — shows the dialog only if isDirty, otherwise
// calls the callback immediately:
requestConfirm( () => handleToggleEnabled( newConfig ) );
```
