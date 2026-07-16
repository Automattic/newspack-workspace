/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { BaseControl, DateTimePicker, ExternalLink, PanelRow, ToggleControl } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { isListing } from '../utils';
import './style.scss';

import type { ComponentType } from 'react';

/**
 * Post meta read/written by this panel.
 */
interface SidebarMeta {
	newspack_listings_hide_author?: boolean;
	newspack_listings_hide_publish_date?: boolean;
	newspack_listings_expiration_date?: string;
	[ key: string ]: unknown;
}

interface SidebarComponentProps {
	createNotice: ( status: string, message: string, options?: Record< string, unknown > ) => void;
	meta: SidebarMeta;
	publishDate: string;
	updateMetaValue: ( key: string, value: unknown ) => void;
}

const SidebarComponent = ( { createNotice, meta, publishDate, updateMetaValue }: SidebarComponentProps ) => {
	const {
		is_listing_customer: isListingCustomer = false,
		post_type_label: postTypeLabel,
		post_types: postTypes,
		self_serve_listing_expiration: expirationPeriod,
	} = window.newspack_listings_data;
	const {
		newspack_listings_hide_author: hideAuthor,
		newspack_listings_hide_publish_date: hidePublishDate,
		newspack_listings_expiration_date: expirationDate,
	} = meta;
	const [ initialExpirationDate ] = useState( expirationDate );

	if ( ! postTypes ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			className="newspack-listings__editor-sidebar"
			name="newspack-listings"
			title={ sprintf(
				// translators: %s: listing post type.
				__( '%s Settings', 'newspack-listings' ),
				isListing() ? postTypeLabel : __( 'Newspack Listings', 'newspack-listings' )
			) }
		>
			<p>
				<em>
					{ __( 'Overrides', 'newspack-listings' ) }
					<ExternalLink href="/wp-admin/admin.php?page=newspack-listings-settings-admin">
						{ __( 'global settings', 'newspack-listings' ) }
					</ExternalLink>
				</em>
			</p>
			<PanelRow>
				<ToggleControl
					className={ 'newspack-listings__toggle-control' }
					label={ __( 'Hide listing author', 'newspack-listings' ) }
					help={ sprintf(
						// translators: %s: show or hide author byline toggle label.
						__( '%s the author byline for this listing.', 'newspack-listings' ),
						hideAuthor ? __( 'Hide', 'newspack-listings' ) : __( 'Show', 'newspack-listings' )
					) }
					checked={ hideAuthor }
					onChange={ value => updateMetaValue( 'newspack_listings_hide_author', value ) }
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					className={ 'newspack-listings__toggle-control' }
					label={ __( 'Hide publish date', 'newspack-listings' ) }
					help={ sprintf(
						// translators: %s: show or hide publish date toggle label.
						__( '%s the publish and updated dates for this listing.', 'newspack-listings' ),
						hidePublishDate ? __( 'Hide', 'newspack-listings' ) : __( 'Show', 'newspack-listings' )
					) }
					checked={ hidePublishDate }
					onChange={ value => updateMetaValue( 'newspack_listings_hide_publish_date', value ) }
				/>
			</PanelRow>
			<PanelRow>
				<div className="hide-time">
					<BaseControl
						id="newspack-listings-expiration-date"
						help={ __( 'If set, the listing will be automatically unpublished after this date.', 'newspack-listings' ) }
						label={ __( 'Expiration Date', 'newspack-listings' ) }
					>
						<DateTimePicker
							currentDate={ expirationDate ? new Date( expirationDate ) : null }
							onChange={ value => {
								/**
								 * If the current user is a listings customer, don't allow them to set the expiraiton date beyond the
								 * last saved expiration date or `expirationPeriod` days from the publish date, whichever is later.
								 */
								if ( isListingCustomer ) {
									const fromExpirationDate = initialExpirationDate ? new Date( initialExpirationDate ) : null;
									const publishDateDate = new Date( publishDate );
									const fromPublishDate = new Date(
										publishDateDate.setDate( publishDateDate.getDate() + parseInt( String( expirationPeriod ) ) )
									);
									const laterDate = fromExpirationDate
										? new Date( Math.max( fromPublishDate.getTime(), fromExpirationDate.getTime() ) )
										: fromPublishDate;

									if ( 0 < new Date( value as string ).getTime() - laterDate.getTime() ) {
										return createNotice(
											'warning',
											sprintf(
												// translators: %s: warning when listings customer tries to extend expiration beyond allowed range.
												__( 'Cannot set expiration date beyond %s.', 'newspack-listings' ),
												laterDate.toLocaleDateString( undefined, {
													weekday: 'long',
													year: 'numeric',
													month: 'long',
													day: 'numeric',
												} )
											),
											{
												id: 'newspack-listings__date-error',
												isDismissible: true,
												type: 'default',
											}
										);
									}
								}

								if (
									value &&
									publishDate &&
									0 <= new Date( value as string ).getTime() - new Date( publishDate ).getTime() // Expiration date must come after publish date.
								) {
									return updateMetaValue( 'newspack_listings_expiration_date', value );
								}

								// If clearing the value.
								if ( ! value ) {
									return updateMetaValue( 'newspack_listings_expiration_date', '' );
								}

								createNotice( 'warning', __( 'Expiration date must be after publish date.', 'newspack-listings' ), {
									id: 'newspack-listings__date-error',
									isDismissible: true,
									type: 'default',
								} );
							} }
						/>
					</BaseControl>
				</div>
			</PanelRow>
		</PluginDocumentSettingPanel>
	);
};

const mapStateToProps = ( select: ( store: string ) => unknown ) => {
	const { getEditedPostAttribute } = select( 'core/editor' ) as {
		getEditedPostAttribute: ( attribute: string ) => unknown;
	};

	return {
		meta: getEditedPostAttribute( 'meta' ) as SidebarMeta,
		publishDate: getEditedPostAttribute( 'date' ) as string,
	};
};

const mapDispatchToProps = ( dispatch: ( store: string ) => unknown ) => {
	const { editPost } = dispatch( 'core/editor' ) as { editPost: ( edits: Record< string, unknown > ) => void };
	const { createNotice } = dispatch( 'core/notices' ) as {
		createNotice: ( status: string, message: string, options?: Record< string, unknown > ) => void;
	};

	return {
		createNotice,
		updateMetaValue: ( key: string, value: unknown ) => editPost( { meta: { [ key ]: value } } ),
	} as Record< string, ( ...args: unknown[] ) => unknown >;
};

export const Sidebar = compose( withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) )( SidebarComponent ) as ComponentType;
