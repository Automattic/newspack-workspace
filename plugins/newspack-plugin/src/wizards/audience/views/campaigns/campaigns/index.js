/**
 * Campaign management screen.
 */

/**
 * WordPress dependencies.
 */
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useContext, useEffect, useState, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ENTER } from '@wordpress/keycodes';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Card, CustomSelectControl, Modal, Router, TextControl, withWizardScreen } from '../../../../../../packages/components/src';
import CampaignManagementPopover from '../../../components/campaign-management-popover';
import SegmentGroup from '../../../components/segment-group';
import { dataForCampaignId } from '../utils';
import { CampaignsContext } from '../../../contexts';
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';
import find from 'lodash/find';

const { useHistory } = Router;

const MODAL_TYPE_DUPLICATE = 1;
const MODAL_TYPE_RENAME = 2;
const MODAL_TYPE_NEW = 3;
const DEFAULT_CAMPAIGNS_FILTER = 'active';

const modalTitle = modalType => {
	if ( MODAL_TYPE_RENAME === modalType ) {
		return __( 'Rename Campaign', 'newspack-plugin' );
	} else if ( MODAL_TYPE_DUPLICATE === modalType ) {
		return __( 'Duplicate Campaign', 'newspack-plugin' );
	}
	return __( 'Add New Campaign', 'newspack-plugin' );
};

const modalButton = modalType => {
	if ( MODAL_TYPE_RENAME === modalType ) {
		return __( 'Rename', 'newspack-plugin' );
	} else if ( MODAL_TYPE_DUPLICATE === modalType ) {
		return __( 'Duplicate', 'newspack-plugin' );
	}
	return __( 'Add', 'newspack-plugin' );
};

const filterByCampaign = ( prompts, campaignId ) => {
	if ( 'trash' === campaignId ) {
		return prompts.filter( ( { status } ) => 'trash' === status );
	}
	const notTrashedPrompts = prompts.filter( ( { status } ) => 'trash' !== status );
	if ( DEFAULT_CAMPAIGNS_FILTER === campaignId ) {
		return notTrashedPrompts.filter( ( { status } ) => 'publish' === status );
	}
	if ( 'all' === campaignId ) {
		return notTrashedPrompts.filter( ( { status } ) => 'trash' !== status );
	}
	if ( 'unassigned' === campaignId ) {
		return notTrashedPrompts.filter( ( { campaign_groups: campaigns } ) => ! campaigns || ! campaigns.length );
	}
	return notTrashedPrompts.filter( ( { campaign_groups: campaigns } ) => campaigns && campaigns.find( term => +term.term_id === +campaignId ) );
};

const groupBySegment = ( segments, prompts ) => {
	const grouped = [];
	grouped.push(
		...segments.map( ( { name: label, id, configuration, criteria } ) => ( {
			label,
			id,
			configuration,
			criteria,
			prompts: prompts.filter( ( { segments: _segments } ) => {
				if ( ! _segments ) {
					return false;
				}
				const found = _segments.find( _segment => _segment.term_id === parseInt( id ) );
				return !! found;
			} ),
		} ) )
	);
	grouped.push( {
		label: __( 'Everyone', 'newspack-plugin' ),
		id: '',
		prompts: prompts.filter( ( { segments: _segments } ) => _segments.length === 0 ),
		configuration: {},
	} );
	return grouped;
};

/**
 * Campaign management screen.
 */
const Campaigns = props => {
	const {
		campaignId = DEFAULT_CAMPAIGNS_FILTER,
		campaigns = [],
		manageCampaignGroup,
		segments,
		createCampaignGroup,
		duplicateCampaignGroup,
		deleteCampaignGroup,
		archiveCampaignGroup,
		renameCampaignGroup,
	} = props;

	const [ popoverVisible, setPopoverVisible ] = useState();
	const [ modalVisible, setModalVisible ] = useState();
	const [ modalType, setModalType ] = useState();
	const [ campaignName, setCampaignName ] = useState();

	const allPrompts = useContext( CampaignsContext );
	const prompts = filterByCampaign( allPrompts, campaignId );
	const hasUnassigned = filterByCampaign( allPrompts, 'unassigned' ).length;

	useEffect( () => {
		if ( modalVisible ) {
			document.getElementById( 'newspack-add-campaigns-modal-input' )?.focus();
		}
	}, [ modalVisible ] );

	const history = useHistory();

	const submitModal = modalText => {
		if ( MODAL_TYPE_NEW === modalType ) {
			createCampaignGroup( modalText );
		} else if ( MODAL_TYPE_RENAME === modalType ) {
			renameCampaignGroup( campaignId, modalText );
		} else if ( MODAL_TYPE_DUPLICATE === modalType ) {
			duplicateCampaignGroup( campaignId, modalText );
		}
		setModalVisible( false );
	};

	const activeCampaigns = campaigns.filter( ( { status } ) => 'archive' !== status );
	const archivedCampaigns = campaigns.filter( ( { status } ) => 'archive' === status );
	const campaignData = dataForCampaignId( campaignId, campaigns );

	const campaignsSelectOptions = [
		{
			key: DEFAULT_CAMPAIGNS_FILTER,
			name: __( 'Active Prompts', 'newspack-plugin' ),
		},
		{
			key: 'all',
			name: __( 'All Prompts', 'newspack-plugin' ),
		},
		{
			key: 'trash',
			name: __( 'Trash', 'newspack-plugin' ),
		},
		...( hasUnassigned
			? [
					{
						key: 'unassigned',
						name: __( 'Unassigned Prompts', 'newspack-plugin' ),
					},
			  ]
			: [] ),
		...( activeCampaigns.length
			? [
					{
						key: 'header-campaigns',
						name: __( 'Campaigns', 'newspack-plugin' ),
						className: 'is-header',
					},
			  ]
			: [] ),
		...activeCampaigns.map( ( { term_id: id, name } ) => ( {
			key: String( id ),
			name,
			className: 'newspack-campaigns__campaign-group__select-control-group-item',
		} ) ),
		...( archivedCampaigns.length
			? [
					{
						key: 'header-archived-campaigns',
						name: __( 'Archived Campaigns', 'newspack-plugin' ),
						className: 'is-header',
					},
			  ]
			: [] ),
		...archivedCampaigns.map( ( { term_id: id, name } ) => ( {
			key: String( id ),
			name: name + ' ' + __( '(archived)', 'newspack-plugin' ),
			className: 'newspack-campaigns__campaign-group__select-control-group-item',
		} ) ),
	];

	return (
		<Fragment>
			<Card headerActions noBorder>
				<div className="newspack-campaigns__campaign-group__filter-group-actions">
					<CustomSelectControl
						label={ __( 'Campaigns', 'newspack-plugin' ) }
						options={ campaignsSelectOptions.map( option => ( {
							...option,
							className: classnames( option.className, {
								'is-selected': option.key === campaignId,
							} ),
						} ) ) }
						onChange={ ( { selectedItem: { key } } ) =>
							DEFAULT_CAMPAIGNS_FILTER === key ? history.push( '/campaigns' ) : history.push( `/campaigns/${ key }` )
						}
						value={ find( campaignsSelectOptions, [ 'key', campaignId || DEFAULT_CAMPAIGNS_FILTER ] ) }
						hideLabelFromVision={ true }
					/>
					{ campaignData && (
						<div className="newspack-campaigns__campaign-group__filter-group-actions__button">
							<Button
								className={ popoverVisible && 'popover-active' }
								onClick={ () => setPopoverVisible( ! popoverVisible ) }
								icon={ moreVertical }
								label={ __( 'Actions', 'newspack-plugin' ) }
								tooltipPosition="bottom center"
							/>
							{ popoverVisible && (
								<CampaignManagementPopover
									dismiss={ () => setPopoverVisible( false ) }
									isArchive={ campaignData && 'archive' === campaignData.status }
									onActivate={ () => manageCampaignGroup( prompts ) }
									onArchive={ () => archiveCampaignGroup( campaignId, true ) }
									onDeactivate={ () => manageCampaignGroup( prompts, 'DELETE' ) }
									onDelete={ () => deleteCampaignGroup( campaignId ) }
									onDuplicate={ () => {
										setModalVisible( true );
										setCampaignName( '' );
										setModalType( MODAL_TYPE_DUPLICATE );
									} }
									onRename={ () => {
										setCampaignName( campaignData.name );
										setModalVisible( true );
										setModalType( MODAL_TYPE_RENAME );
									} }
									onUnarchive={ () => archiveCampaignGroup( campaignId, false ) }
									hasPrompts={ prompts.length > 0 }
									hasPublished={ prompts.some( ( { status } ) => 'publish' === status ) }
								/>
							) }
						</div>
					) }
				</div>
				<div className="newspack-campaigns__campaign-group__add-new-button">
					<Button
						isPrimary
						onClick={ () => {
							setModalVisible( ! modalVisible );
							setCampaignName( '' );
							setModalType( MODAL_TYPE_NEW );
						} }
					>
						{ __( 'Add New Campaign', 'newspack-plugin' ) }
					</Button>
					{ modalVisible && (
						<Modal
							title={ modalTitle( modalType ) }
							onRequestClose={ () => {
								setModalVisible( false );
							} }
						>
							<TextControl
								id="newspack-add-campaigns-modal-input"
								placeholder={ __( 'Campaign Name', 'newspack-plugin' ) }
								onChange={ setCampaignName }
								label={ __( 'Campaign Name', 'newspack-plugin' ) }
								hideLabelFromVision={ true }
								value={ campaignName }
								onKeyDown={ event => {
									if ( ENTER === event.keyCode && '' !== campaignName ) {
										event.preventDefault();
										submitModal( campaignName );
									}
								} }
							/>
							<Card buttonsCard noBorder className="justify-end">
								<Button
									variant="secondary"
									onClick={ () => {
										setModalVisible( false );
									} }
								>
									{ __( 'Cancel', 'newspack-plugin' ) }
								</Button>
								<Button variant="primary" disabled={ ! campaignName } onClick={ () => submitModal( campaignName ) }>
									{ modalButton( modalType ) }
								</Button>
							</Card>
						</Modal>
					) }
				</div>
			</Card>
			<VStack spacing={ 4 }>
				{ groupBySegment( segments, prompts ).map( ( segment, index ) =>
					DEFAULT_CAMPAIGNS_FILTER === campaignId && segment.configuration.is_disabled ? null : (
						<SegmentGroup key={ index } segment={ segment } campaignId={ campaignId } campaignData={ campaignData } { ...props } />
					)
				) }
			</VStack>
		</Fragment>
	);
};
export default withWizardScreen( Campaigns );
