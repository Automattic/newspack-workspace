'use strict';

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';
import { Fragment, Component } from '@wordpress/element';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';

import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import './editor.scss';

interface NewspackSuppressAdsPanelProps {
	newspack_ads_suppress_ads?: boolean;
	newspack_ads_suppress_ads_placements?: string[];
	updateSuppressAds: ( value: boolean ) => void;
	updateSuppressPlacements: ( value: string[] ) => void;
}

/**
 * Add a section to the Document settings with a toggle for suppressing ads on the current single.
 */
class NewspackSuppressAdsPanel extends Component< NewspackSuppressAdsPanelProps > {
	render() {
		const placements = window.newspackAdsSuppressAds?.placements || {};
		const { newspack_ads_suppress_ads, newspack_ads_suppress_ads_placements, updateSuppressAds, updateSuppressPlacements } = this.props;
		return (
			<PluginDocumentSettingPanel
				name="newspack-ad-free"
				title={ __( 'Newspack Ads Settings', 'newspack-ads' ) }
				className="newspack-ads-settings"
			>
				<ToggleControl
					label={ __( "Don't show ads on this content", 'newspack-ads' ) }
					checked={ newspack_ads_suppress_ads }
					onChange={ value => {
						updateSuppressAds( value );
					} }
				/>
				{ ! newspack_ads_suppress_ads && (
					<Fragment>
						<p>{ __( 'Suppress specific placements:', 'newspack-ads' ) }</p>
						{ Object.keys( placements ).map( placementKey => (
							<ToggleControl
								key={ placementKey }
								label={ placements[ placementKey ].name }
								checked={
									newspack_ads_suppress_ads_placements && newspack_ads_suppress_ads_placements.indexOf( placementKey ) !== -1
								}
								onChange={ () => {
									const suppressPlacements = newspack_ads_suppress_ads_placements?.length
										? [ ...newspack_ads_suppress_ads_placements ]
										: [];
									if ( suppressPlacements.indexOf( placementKey ) !== -1 ) {
										suppressPlacements.splice( suppressPlacements.indexOf( placementKey ), 1 );
									} else {
										suppressPlacements.push( placementKey );
									}
									updateSuppressPlacements( suppressPlacements );
								} }
							/>
						) ) }
					</Fragment>
				) }
			</PluginDocumentSettingPanel>
		);
	}
}

interface EditedPostMeta {
	newspack_ads_suppress_ads?: boolean;
	newspack_ads_suppress_ads_placements?: string[];
}

const ComposedPanel = compose(
	withSelect( select => {
		// `select( 'core/editor' )` is typed against a registered `StoreDescriptor`, which this
		// (legacy, string-keyed) call site doesn't provide -- @wordpress/data has no exported
		// descriptor for `core/editor` selectors, so the return value is cast at this boundary.
		const { getEditedPostAttribute } = select( 'core/editor' ) as {
			getEditedPostAttribute: ( attribute: 'meta' ) => EditedPostMeta | undefined;
		};
		const meta = getEditedPostAttribute( 'meta' );
		if ( ! meta ) {
			return {};
		}
		const { newspack_ads_suppress_ads, newspack_ads_suppress_ads_placements } = meta;
		return { newspack_ads_suppress_ads, newspack_ads_suppress_ads_placements };
	} ),
	withDispatch( dispatch => ( {
		// `withDispatch`'s `mapDispatchToProps` return type requires each action creator to be
		// typed `(...args: unknown[]) => unknown` (an index-signature-shaped type, so -- unlike a
		// method declaration -- it isn't checked bivariantly); the params are cast back to their
		// real types immediately, with no change to what's forwarded to `editPost`.
		updateSuppressAds( value: unknown ) {
			dispatch( 'core/editor' ).editPost( { meta: { newspack_ads_suppress_ads: value as boolean } } );
		},
		updateSuppressPlacements( value: unknown ) {
			dispatch( 'core/editor' ).editPost( {
				meta: { newspack_ads_suppress_ads_placements: value as string[] },
			} );
		},
	} ) )
	// `compose`'s return type is a fully-untyped `(...args: unknown[]) => unknown` (see
	// `@wordpress/compose`'s `build-types/higher-order/compose.d.ts`), so the composed component
	// is cast back to a `ComponentType` at this boundary for use in `registerPlugin` below.
)( NewspackSuppressAdsPanel ) as ComponentType;

registerPlugin( 'plugin-document-setting-panel-newspack-suppress-ads', {
	render: ComposedPanel,
	// `icon` is `IconType | undefined` (no `null` in the type, unlike the `Icon` component's own
	// prop); omitted here rather than set to `null`, which is equivalent (both mean "no icon").
} );
