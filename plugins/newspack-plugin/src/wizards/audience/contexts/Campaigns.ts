/**
 * Context data for Campaigns.
 */

/**
 * WordPress dependencies.
 */
import { createContext } from '@wordpress/element';

/**
 * The provider (views/campaigns/index) supplies the prompts array itself,
 * and consumers read it as an array — but the default value is an object
 * wrapping the array, which would break consumers if no provider were
 * mounted. Kept as-is to avoid a runtime change; the union type documents
 * the mismatch.
 */
export const CampaignsContext = createContext< CampaignsPrompt[] | { prompts: CampaignsPrompt[] } >( { prompts: [] } );
