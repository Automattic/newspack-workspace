/**
 * CollapsibleGroup
 */

/**
 * Internal dependencies
 */
import Item from './item';
import Root from './root';

// The group is itself renderable, so `Item` hangs off it rather than forming a namespace object like `Drawer`.
const CollapsibleGroup = Object.assign( Root, { Item } );

( CollapsibleGroup as { displayName?: string } ).displayName = 'CollapsibleGroup';
( Item as { displayName?: string } ).displayName = 'CollapsibleGroup.Item';

export default CollapsibleGroup;
