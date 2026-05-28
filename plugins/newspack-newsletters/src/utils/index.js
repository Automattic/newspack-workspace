/**
 * Internal dependencies
 */
import { LAYOUT_CPT_SLUG } from './consts';

export const isUserDefinedLayout = layout => layout && layout.post_type === LAYOUT_CPT_SLUG;
