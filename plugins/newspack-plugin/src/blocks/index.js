/* globals newspack_blocks */

/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import * as readerRegistration from './reader-registration';
import * as myAccountButton from './my-account-button';
import * as correctionBox from './correction-box';
import * as correctionItem from './correction-item';
import * as avatar from './avatar';
import * as byline from './byline';
import * as featuredImageCaption from './featured-image-caption';
import * as authorProfileSocial from './author-profile-social';
import * as authorSocialLink from './author-social-link';
import * as collections from './collections';
import * as contentGateCountdown from './content-gate/countdown';
import * as contentGateCountdownBox from './content-gate/countdown-box';
import * as copyrightDate from './copyright-date';
import * as overlayMenu from './overlay-menu';
import * as overlayMenuTrigger from './overlay-menu/trigger';
import * as overlayMenuPanel from './overlay-menu/panel';
import * as overlaySearch from './overlay-search';
import * as responsiveContainer from './responsive-container';
import * as responsiveContainerBreakpoint from './responsive-container/breakpoint';

/**
 * Block Scripts
 */
import './core-image';

export const blocks = [
	readerRegistration,
	myAccountButton,
	correctionBox,
	correctionItem,
	avatar,
	byline,
	featuredImageCaption,
	authorProfileSocial,
	authorSocialLink,
	collections,
	contentGateCountdown,
	contentGateCountdownBox,
	copyrightDate,
	overlayMenu,
	overlayMenuTrigger,
	overlayMenuPanel,
	overlaySearch,
	responsiveContainer,
	responsiveContainerBreakpoint,
];

const readerActivationBlocks = [ 'newspack/reader-registration', 'newspack/my-account-button' ];
const correctionBlocks = [ 'newspack/correction-box', 'newspack/correction-item' ];
const collectionsBlocks = [ 'newspack/collections' ];
const contentGateBlocks = [ 'newspack/content-gate-countdown', 'newspack/content-gate-countdown-box' ];
const blockThemeBlocks = [
	'newspack/author-profile-social',
	'newspack/author-social-link',
	'newspack/avatar',
	'newspack/byline',
	'newspack/copyright-date',
	'newspack/featured-image-caption',
	'newspack/overlay-menu',
	'newspack/overlay-menu-trigger',
	'newspack/overlay-menu-panel',
	'newspack/my-account-button',
	'newspack/overlay-search',
	'newspack/responsive-container',
	'newspack/responsive-container-breakpoint',
];
const siteEditorOnlyBlocks = [ 'newspack/responsive-container', 'newspack/responsive-container-breakpoint' ];

/**
 * Function to register an individual block.
 *
 * @param {Object} block The block to be registered.
 */
const registerBlock = block => {
	if ( ! block ) {
		return;
	}

	const { metadata, settings, name } = block;
	const blockMetadata = { name, ...metadata };

	/** Do not register reader activation blocks if it's disabled. */
	if ( readerActivationBlocks.includes( name ) && ! newspack_blocks.has_reader_activation ) {
		return;
	}
	/** Do not register correction blocks if it's disabled. */
	if ( correctionBlocks.includes( name ) && ! newspack_blocks.corrections_enabled ) {
		return;
	}
	/** Do not register collections blocks if Collections module is disabled. */
	if ( collectionsBlocks.includes( name ) && ! newspack_blocks.collections_enabled ) {
		return;
	}
	/** Do not register content gate blocks if the feature or Memberships is not active. */
	if ( contentGateBlocks.includes( name ) && ( ! newspack_blocks.has_memberships || ! newspack_blocks.is_content_gate_countdown_active ) ) {
		return;
	}
	/** Do not register block theme blocks if not using a block theme. */
	if ( blockThemeBlocks.includes( name ) && ! newspack_blocks.is_block_theme ) {
		return;
	}
	/** Do not register Site Editor-only blocks outside the Site Editor. */
	if ( siteEditorOnlyBlocks.includes( name ) && window.pagenow !== 'site-editor' ) {
		return;
	}

	registerBlockType( blockMetadata, settings );
};

for ( const block of blocks ) {
	registerBlock( block );
}
