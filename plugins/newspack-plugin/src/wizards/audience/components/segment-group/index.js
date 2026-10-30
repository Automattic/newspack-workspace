/**
 * Segment group component.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { useState, Fragment } from '@wordpress/element';
import { blockTable, header, layout, moreVertical, postList } from '@wordpress/icons';
import { Card as WPCard, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button, Card, Grid, Modal } from '../../../../../packages/components/src';
import SegmentationPreview from '../segmentation-preview';
import PromptActionCard from '../prompt-action-card';
import { segmentDescriptionItems, splitCriteriaMessage, warningForPopup } from '../../views/campaigns/utils';
import { overlayBottom, overlayInline, overlayCenter, overlayTop } from '../../../../../packages/icons';
import './style.scss';

const TitleTag = 'h2';

const addNewURL = ( placement, campaignId, segmentId ) => {
	const base = '/wp-admin/post-new.php?post_type=newspack_popups_cpt&';
	const params = [];
	if ( placement ) {
		params.push( `placement=${ placement }` );
	}
	if ( +campaignId > 0 ) {
		params.push( `group=${ campaignId }` );
	}
	if ( segmentId ) {
		params.push( `segment=${ segmentId }` );
	}
	return base + params.join( '&' );
};

const SegmentGroup = props => {
	const { campaignData, campaignId, isTrashView, segment } = props;
	const [ modalVisible, setModalVisible ] = useState( false );
	const { label, id, prompts } = segment;
	const isPreviewingCampaign = 'unassigned' === campaignId || +campaignId > 0;
	const campaignToPreview = 'unassigned' === campaignId ? -1 : parseInt( campaignId );

	const description = id ? segmentDescriptionItems( segment ) : [ __( 'All readers, regardless of segment', 'newspack-plugin' ) ];

	let emptySegmentText;
	if ( 'unassigned' === campaignId ) {
		emptySegmentText = __( 'No unassigned prompts in this segment.', 'newspack-plugin' );
	} else if ( campaignData ) {
		emptySegmentText = sprintf(
			// Translators: %s: campaign name.
			__( 'No prompts in this segment for %s.', 'newspack-plugin' ),
			campaignData.name
		);
	} else if ( 'all' === campaignId ) {
		emptySegmentText = __( 'No prompts in this segment.', 'newspack-plugin' );
	} else {
		emptySegmentText = __( 'No active prompts in this segment.', 'newspack-plugin' );
	}

	return (
		<WPCard.Root className="newspack-card--core--sortable-list__card is-large newspack-campaigns__segment-group__card">
			<WPCard.Header
				className="newspack-campaigns__segment-group__header"
				render={ <Stack direction="row" gap="md" wrap="wrap" align="center" justify="space-between" /> }
			>
				<Stack direction="column" gap="xs" className="newspack-campaigns__segment-group__heading">
					<WPCard.Title
						render={ <TitleTag /> }
						className="newspack-card--core--sortable-list__title newspack-campaigns__segment-group__title"
					>
						{ id ? (
							<Button href={ `#/segments/${ id }` } isLink>
								{ label }
							</Button>
						) : (
							label
						) }
					</WPCard.Title>
					{ description.length > 0 && (
						<div className="newspack-campaigns__details">
							{ description.map( ( item, index ) => {
								const { label: itemLabel, value } = splitCriteriaMessage( item );
								return (
									<span key={ index }>
										{ itemLabel && (
											<>
												<span className="newspack-campaigns__details-key">{ itemLabel }</span>{ ' ' }
											</>
										) }
										<span className="newspack-campaigns__details-value">{ value }</span>
									</span>
								);
							} ) }
						</div>
					) }
				</Stack>
				<Stack direction="row" gap="sm" align="center">
					{ 'unassigned' !== campaignId && ! isTrashView && (
						<Fragment>
							<Button variant="secondary" onClick={ () => setModalVisible( ! modalVisible ) }>
								{ __( 'Add Prompt', 'newspack-plugin' ) }
							</Button>
							{ modalVisible && (
								<Modal
									title={ __( 'Add Prompt', 'newspack-plugin' ) }
									onRequestClose={ () => setModalVisible( false ) }
									shouldCloseOnEsc={ false }
									shouldCloseOnClickOutside={ false }
									size="large"
								>
									<Grid columns={ 2 } gutter={ 16 } noMargin>
										<Card
											__experimentalCoreCard
											isSmall
											buttonsCard
											href={ addNewURL( 'overlay-center', campaignId, id ) }
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Center Overlay', 'newspack-plugin' ) }</h3>
														<p>{ __( 'Fixed at the center of the screen', 'newspack-plugin' ) }</p>
													</>
												),
												icon: overlayCenter,
												actionType: 'chevron',
												iconBackgroundColor: true,
											} }
										/>
										<Card
											__experimentalCoreCard
											isSmall
											buttonsCard
											href={ addNewURL( 'overlay-top', campaignId, id ) }
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Top Overlay', 'newspack-plugin' ) }</h3>
														<p>{ __( 'Fixed at the top of the screen', 'newspack-plugin' ) }</p>
													</>
												),
												icon: overlayTop,
												actionType: 'chevron',
												iconBackgroundColor: true,
											} }
										/>
										<Card
											__experimentalCoreCard
											isSmall
											buttonsCard
											href={ addNewURL( 'overlay-bottom', campaignId, id ) }
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Bottom Overlay', 'newspack-plugin' ) }</h3>
														<p>{ __( 'Fixed at the bottom of the screen', 'newspack-plugin' ) }</p>
													</>
												),
												icon: overlayBottom,
												actionType: 'chevron',
												iconBackgroundColor: true,
											} }
										/>
										<Card
											__experimentalCoreCard
											isSmall
											buttonsCard
											href={ addNewURL( null, campaignId, id ) }
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Inline', 'newspack-plugin' ) }</h3>
														<p>{ __( 'Embedded in content', 'newspack-plugin' ) }</p>
													</>
												),
												icon: overlayInline,
												actionType: 'chevron',
												iconBackgroundColor: true,
											} }
										/>
										<Card
											__experimentalCoreCard
											isSmall
											buttonsCard
											href={ addNewURL( 'above-header', campaignId, id ) }
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Above Header', 'newspack-plugin' ) }</h3>
														<p>{ __( 'Embedded at the very top of the page', 'newspack-plugin' ) }</p>
													</>
												),
												icon: header,
												actionType: 'chevron',
												iconBackgroundColor: true,
											} }
										/>
										<Card
											__experimentalCoreCard
											isSmall
											buttonsCard
											href={ addNewURL( 'custom', campaignId, id ) }
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Custom Placement', 'newspack-plugin' ) }</h3>
														<p>{ __( 'Only appears when placed in content', 'newspack-plugin' ) }</p>
													</>
												),
												icon: layout,
												actionType: 'chevron',
												iconBackgroundColor: true,
											} }
										/>
										<Card
											__experimentalCoreCard
											isSmall
											buttonsCard
											href={ addNewURL( 'archives', campaignId, id ) }
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'In Archive Pages', 'newspack-plugin' ) }</h3>
														<p>{ __( 'Embedded once or many times in archive pages', 'newspack-plugin' ) }</p>
													</>
												),
												icon: postList,
												actionType: 'chevron',
												iconBackgroundColor: true,
											} }
										/>
										<Card
											__experimentalCoreCard
											isSmall
											buttonsCard
											href={ addNewURL( 'manual', campaignId, id ) }
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Manual Only', 'newspack-plugin' ) }</h3>
														<p>{ __( 'Only appears where Single Prompt block is inserted', 'newspack-plugin' ) }</p>
													</>
												),
												icon: blockTable,
												actionType: 'chevron',
												iconBackgroundColor: true,
											} }
										/>
									</Grid>
								</Modal>
							) }
						</Fragment>
					) }
					<SegmentationPreview
						campaign={ isPreviewingCampaign ? campaignToPreview : false }
						segment={ id }
						showUnpublished={ isPreviewingCampaign } // Only if previewing a specific campaign/group.
						renderButton={ ( { showPreview } ) => (
							<DropdownMenu
								icon={ moreVertical }
								label={ sprintf(
									// Translators: %s: segment name.
									__( 'Segment actions: %s', 'newspack-plugin' ),
									label
								) }
							>
								{ ( { onClose } ) => (
									<MenuGroup>
										<MenuItem
											onClick={ () => {
												onClose();
												showPreview();
											} }
										>
											{ __( 'Preview Segment', 'newspack-plugin' ) }
										</MenuItem>
										{ id && <MenuItem href={ `#/segments/${ id }` }>{ __( 'Edit Segment', 'newspack-plugin' ) }</MenuItem> }
									</MenuGroup>
								) }
							</DropdownMenu>
						) }
						title={ label }
					/>
				</Stack>
			</WPCard.Header>
			{ prompts.map( item => (
				<PromptActionCard
					key={ item.id }
					segmentDisabled={ segment.configuration.is_disabled }
					warning={ warningForPopup( segment.statusPrompts || prompts, item ) }
					prompt={ item }
					{ ...props }
				/>
			) ) }
			{ prompts.length < 1 && (
				<WPCard.Content className="newspack-campaigns__segment-group__content">
					<p className="newspack-campaigns__segment-group__empty-segment-text">{ emptySegmentText }</p>
				</WPCard.Content>
			) }
		</WPCard.Root>
	);
};
export default SegmentGroup;
