/* globals newspackAudienceCampaigns */
/**
 * WordPress dependencies.
 */
import {
	BaseControl,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { useEffect, useState, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import find from 'lodash/find';
import { applyFilters, addFilter } from '@wordpress/hooks';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies.
 */
import {
	CategoryAutocomplete,
	Divider,
	Grid,
	Router,
	SectionHeader,
	Settings,
	hooks,
	useUnsavedChangesDialog,
} from '../../../../../../packages/components/src';
import ListsControl from '../../../components/lists-control';
import { useErrorNoticeFocus } from '../../../../hooks/use-error-notice-focus';
import SegmentsScreen from './screen';

const { useHistory } = Router;
const { DateRangeSetting } = Settings;

/**
 * Whether a criterion should render as a multi-select checkbox group: ESP
 * fields marked "Multiple values" use the list__in / list__not_in matching
 * functions and have a fixed set of options.
 */
export const isMultiSelectCriteria = criteria =>
	[ 'list__in', 'list__not_in' ].includes( criteria?.matching_function ) && !! criteria?.options?.length;

const DEFAULT_CONFIG = {
	is_disabled: false,
};

const SingleSegment = ( { segmentId, setSegments, wizardApiFetch, ...screenProps } ) => {
	const allCriteria = window.newspackAudienceCampaigns?.criteria || [];

	const [ segmentConfig, updateSegmentConfig ] = hooks.useObjectState( DEFAULT_CONFIG );
	const [ name, setName ] = useState( '' );
	const [ segmentCriteria, setSegmentCriteria ] = useState( [] );
	const [ segmentCriteriaInitially, setSegmentCriteriaInitially ] = useState( '[]' );
	const [ nameInitially, setNameInitially ] = useState( '' );
	const history = useHistory();

	const [ segmentInitially, setSegmentInitially ] = useState( DEFAULT_CONFIG );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	const {
		wrapperProps: noticeWrapperProps,
		registerSubmit,
		spokenMessage,
	} = useErrorNoticeFocus( saveError, __( 'Segment error', 'newspack-plugin' ) );

	const isSegmentValid = () => {
		const _segmentConfig = { ...segmentConfig };
		const _defaultConfig = { ...DEFAULT_CONFIG };
		delete _segmentConfig.is_disabled;
		delete _defaultConfig.is_disabled;
		return name.length > 0 && segmentCriteria.length; // Segment has a name. // Segment has criteria.
	};

	const isDirty =
		segmentCriteriaInitially !== JSON.stringify( segmentCriteria ) ||
		nameInitially !== name ||
		JSON.stringify( segmentInitially.is_disabled ) !== JSON.stringify( segmentConfig.is_disabled );

	const { confirmDialog: unsavedChangesDialog } = useUnsavedChangesDialog( { when: isDirty && ! isSaving } );

	const isNew = segmentId === 'new';
	useEffect( () => {
		if ( ! isNew ) {
			wizardApiFetch( {
				path: `${ newspackAudienceCampaigns.api }/segmentation`,
			} ).then( segments => {
				const foundSegment = find( segments, ( { id } ) => id === segmentId );
				if ( foundSegment ) {
					const segmentConfigurationWithDefaults = {
						...DEFAULT_CONFIG,
						...foundSegment.configuration,
					};
					updateSegmentConfig( segmentConfigurationWithDefaults );
					setSegmentInitially( segmentConfigurationWithDefaults );
					setName( foundSegment.name );
					setNameInitially( foundSegment.name );
					setSegmentCriteria( [ ...foundSegment.criteria ] );
					setSegmentCriteriaInitially( JSON.stringify( foundSegment.criteria ) );
				}
			} );
		}
	}, [ isNew ] );

	const saveSegment = event => {
		setSaveError( null );
		registerSubmit( event );
		setIsSaving( true );

		const path = isNew ? `${ newspackAudienceCampaigns.api }/segmentation` : `${ newspackAudienceCampaigns.api }/segmentation/${ segmentId }`;
		wizardApiFetch( {
			path,
			method: 'POST',
			data: {
				name,
				criteria: segmentCriteria,
				configuration: segmentConfig,
			},
			quiet: true,
		} )
			.then( setSegments )
			.then( () => history.push( '/segments' ) )
			.catch( error => {
				setSaveError(
					decodeEntities( error?.message || __( 'There was an error saving the segment. Please try again.', 'newspack-plugin' ) )
				);
				setIsSaving( false );
			} );
	};

	const getCriteriaValue = id => {
		const item = segmentCriteria.find( c => c.criteria_id === id );
		if ( ! item ) {
			return '';
		}
		return item.value;
	};

	const updateCriteria = id => value => {
		const config = [ ...segmentCriteria ];
		const item = config.find( c => c.criteria_id === id );
		if ( item ) {
			if ( ! value || ( Array.isArray( value ) && 0 === value.length ) ) {
				config.splice( config.indexOf( item ), 1 );
			} else if ( ! Array.isArray( value ) && typeof value === 'object' ) {
				// A criterion that changed operators may still hold a scalar value
				// (e.g. a date field switched from Text to Date range). Spreading a
				// string would scatter it into character-indexed keys, so only merge
				// onto a previous value that is itself a plain object.
				const previous = item.value && typeof item.value === 'object' && ! Array.isArray( item.value ) ? item.value : {};
				item.value = { ...previous, ...value };
			} else {
				item.value = value;
			}
		} else if ( value ) {
			config.push( { criteria_id: id, value } );
		}
		setSegmentCriteria( config );
	};

	const getCriteriaInput = criteria => {
		const value = getCriteriaValue( criteria.id );
		const update = updateCriteria( criteria.id );
		const getInput = () => {
			if ( criteria.matching_function === 'range' ) {
				return (
					<Stack direction="column" gap="sm" data-testid={ `newspack-criteria-${ criteria.id }` }>
						{ [
							{
								key: 'min',
								label: __( 'Min', 'newspack-plugin' ),
								inputLabel: __( 'Min value', 'newspack-plugin' ),
								number: value?.min,
								checked: value?.min > 0,
								onCheck: isChecked => update( { min: isChecked ? 1 : 0 } ),
							},
							{
								key: 'max',
								label: __( 'Max', 'newspack-plugin' ),
								inputLabel: __( 'Max value', 'newspack-plugin' ),
								number: value?.max,
								checked: value?.max > 0,
								onCheck: isChecked => update( { max: isChecked ? value?.min || 1 : 0 } ),
							},
						].map( ( { key, label, inputLabel, number, checked, onCheck } ) => (
							<Stack key={ key } direction="row" gap="sm" align="center" className="newspack-campaigns-wizard-segments__range">
								<CheckboxControl label={ label } checked={ checked } onChange={ onCheck } __nextHasNoMarginBottom />
								<TextControl
									data-testid={ key }
									label={ inputLabel }
									hideLabelFromVision
									type="number"
									value={ number ?? '' }
									onChange={ next => update( { [ key ]: next > 0 ? next : 0 } ) }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
							</Stack>
						) ) }
					</Stack>
				);
			}
			if ( 'date_range' === criteria.matching_function ) {
				return (
					<DateRangeSetting
						data-testid={ `newspack-criteria-${ criteria.id }` }
						label={ criteria.name }
						start={ value?.start }
						end={ value?.end }
						onChange={ update }
					/>
				);
			}
			if ( isMultiSelectCriteria( criteria ) ) {
				const selected = Array.isArray( value ) ? value : [];
				// `register_segment_criteria` prepends an "Any" option (value '') for the
				// single-select case; it is meaningless for a multi-select, so drop it.
				const options = criteria.options.filter( option => '' !== option.value );
				return (
					<Stack direction="column" gap="sm" data-testid={ `newspack-criteria-${ criteria.id }` }>
						{ options.map( option => (
							<CheckboxControl
								key={ option.value }
								label={ option.label }
								checked={ selected.includes( option.value ) }
								onChange={ isChecked =>
									update( isChecked ? [ ...selected, option.value ] : selected.filter( v => v !== option.value ) )
								}
								__nextHasNoMarginBottom
							/>
						) ) }
					</Stack>
				);
			}
			if ( criteria.options?.length ) {
				return (
					<SelectControl
						data-testid={ `newspack-criteria-${ criteria.id }` }
						label={ criteria.name }
						help={ criteria.description }
						onChange={ update }
						value={ value }
						options={ criteria.options }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				);
			}
			return (
				<TextControl
					data-testid={ `newspack-criteria-${ criteria.id }` }
					label={ criteria.name }
					placeholder={ criteria.placeholder }
					help={
						criteria.description && criteria.help ? (
							<>
								{ criteria.description }
								<br />
								{ criteria.help }
							</>
						) : (
							criteria.description || criteria.help
						)
					}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					// A criterion that changed operators may still hold an object value
					// (a saved { start, end } range after the field moved off Date range).
					// Rendered raw it becomes "[object Object]", and one keystroke would
					// save that literal over the range — show an empty input instead and
					// leave the stored value alone until it is deliberately replaced.
					value={ value && 'object' === typeof value && ! Array.isArray( value ) ? '' : value }
					onChange={ update }
				/>
			);
		};
		const input = getInput();
		const filteredInput = applyFilters( 'newspack.criteria.input', input, criteria, value, update );
		const labelsItself =
			filteredInput === input &&
			'range' !== criteria.matching_function &&
			'date_range' !== criteria.matching_function &&
			! isMultiSelectCriteria( criteria );
		if ( labelsItself ) {
			return filteredInput;
		}
		const controlId = `newspack-segment-criteria-${ criteria.id }`;
		const isGroup = 'range' === criteria.matching_function || isMultiSelectCriteria( criteria );
		return (
			<BaseControl id={ controlId } help={ criteria.description } __nextHasNoMarginBottom>
				<BaseControl.VisualLabel id={ `${ controlId }__label` }>{ criteria.name }</BaseControl.VisualLabel>
				{ isGroup ? (
					<div
						role="group"
						aria-labelledby={ `${ controlId }__label` }
						aria-describedby={ criteria.description ? `${ controlId }__help` : undefined }
					>
						{ filteredInput }
					</div>
				) : (
					filteredInput
				) }
			</BaseControl>
		);
	};

	// translators: %s is the segment's numeric ID.
	const analyticsIdText = __( 'Analytics ID: %s — this segment’s identifier in Google Analytics reports.', 'newspack-plugin' );
	const analyticsIdHelp = isNew ? undefined : sprintf( analyticsIdText, segmentId );

	const criteriaCategories = [
		{
			id: 'reader_engagement',
			title: __( 'Reader Engagement', 'newspack-plugin' ),
			description: __( 'Target readers based on their browsing behavior.', 'newspack-plugin' ),
		},
		{
			id: 'reader_activity',
			title: __( 'Registration', 'newspack-plugin' ),
			description: __( 'Target readers based on their user account registration status.', 'newspack-plugin' ),
		},
		{
			id: 'newsletter',
			title: __( 'Newsletters', 'newspack-plugin' ),
			description: __( 'Target readers based on their newsletter subscription status.', 'newspack-plugin' ),
		},
		{
			id: 'reader_revenue',
			title: __( 'Reader Revenue', 'newspack-plugin' ),
			description: __( 'Target readers based on their revenue activity.', 'newspack-plugin' ),
		},
		{
			id: 'referrer_sources',
			title: __( 'Referrer Sources', 'newspack-plugin' ),
			description: __( 'Target readers based on where they’re coming from.', 'newspack-plugin' ),
			notification: __( 'These only apply to the first page a reader sees after arriving from another site.', 'newspack-plugin' ),
		},
		{
			id: 'integrations',
			title: __( 'Integrations', 'newspack-plugin' ),
			description: __( 'Target readers based on data from connected integrations.', 'newspack-plugin' ),
		},
	];

	const headerActions = [
		{
			type: 'primary',
			label: __( 'Save', 'newspack-plugin' ),
			onClick: saveSegment,
			disabled: isSaving || ! isSegmentValid() || ( ! isNew && ! isDirty ),
			isBusy: isSaving,
		},
	];

	const breadcrumbItems = [
		{ label: __( 'Audience Management', 'newspack-plugin' ) },
		{ label: __( 'Campaigns', 'newspack-plugin' ), url: '#/campaigns' },
		{ label: __( 'Segments', 'newspack-plugin' ), url: '#/segments' },
		{ label: isNew ? __( 'Add Segment', 'newspack-plugin' ) : __( 'Edit Segment', 'newspack-plugin' ) },
	];

	return (
		<SegmentsScreen
			{ ...screenProps }
			tabbedNavigation={ undefined }
			breadcrumbItems={ breadcrumbItems }
			headerActions={ headerActions }
			subHeaderText={ __( 'Choose which readers belong to this segment.', 'newspack-plugin' ) }
		>
			{ unsavedChangesDialog }
			{ saveError && (
				<div { ...noticeWrapperProps } className="newspack-campaigns-wizard-segments__notice">
					<Notice status="error" isDismissible={ false } spokenMessage={ spokenMessage }>
						{ saveError }
					</Notice>
				</div>
			) }
			<div className="newspack-campaigns-wizard-segments__form">
				<SectionHeader
					backNav="#/segments"
					className="newspack-campaigns-wizard-segments__form-header"
					heading={ 2 }
					noMargin
					title={ isNew ? __( 'Add Segment', 'newspack-plugin' ) : __( 'Edit Segment', 'newspack-plugin' ) }
				/>
				<Grid columns={ 2 } gutter={ 32 } noMargin>
					<SectionHeader
						heading={ 3 }
						noMargin
						title={ __( 'Details', 'newspack-plugin' ) }
						description={ __( 'Name this segment and choose whether it’s in use.', 'newspack-plugin' ) }
					/>
					<Stack direction="column" gap="xl">
						<TextControl
							placeholder={ __( 'Untitled Segment', 'newspack-plugin' ) }
							value={ name }
							onChange={ setName }
							label={ __( 'Title', 'newspack-plugin' ) }
							help={ analyticsIdHelp }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<ToggleGroupControl
							label={ __( 'Status', 'newspack-plugin' ) }
							help={ __( 'A disabled segment doesn’t match any readers.', 'newspack-plugin' ) }
							value={ segmentConfig.is_disabled ? 'disabled' : 'enabled' }
							onChange={ value => updateSegmentConfig( { is_disabled: 'disabled' === value } ) }
							isBlock
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						>
							<ToggleGroupControlOption value="enabled" label={ __( 'Enabled', 'newspack-plugin' ) } />
							<ToggleGroupControlOption value="disabled" label={ __( 'Disabled', 'newspack-plugin' ) } />
						</ToggleGroupControl>
					</Stack>
				</Grid>
				{ criteriaCategories.map( ( { id, title, description, notification } ) => {
					const categoryCriteria = allCriteria.filter( criteria => criteria.category === id );
					if ( 'integrations' === id && ! categoryCriteria.length ) {
						return null;
					}
					return (
						<Fragment key={ id }>
							<Divider alignment="full-width" variant="tertiary" />
							<Grid columns={ 2 } gutter={ 32 } noMargin>
								<Stack direction="column" gap="lg">
									<SectionHeader heading={ 3 } noMargin title={ title } description={ description } />
									{ notification && (
										<Notice status="info" isDismissible={ false } spokenMessage="">
											{ notification }
										</Notice>
									) }
								</Stack>
								<Stack direction="column" gap="xl" className="newspack-settings__card newspack-campaigns-wizard-segments__fields">
									{ categoryCriteria.map( criteria => (
										<Fragment key={ criteria.id }>{ getCriteriaInput( criteria ) }</Fragment>
									) ) }
								</Stack>
							</Grid>
						</Fragment>
					);
				} ) }
			</div>
		</SegmentsScreen>
	);
};

/**
 * Adds a custom input for the favorite_categories criteria.
 */
addFilter( 'newspack.criteria.input', 'newspack.favoriteCategories', function ( element, criteria, value, update ) {
	if ( criteria.id === 'favorite_categories' ) {
		return (
			<CategoryAutocomplete
				value={ value || [] }
				onChange={ selected => {
					update( selected.map( item => item.id ) );
				} }
				label={ criteria.name }
				hideLabelFromVision
			/>
		);
	}
	return element;
} );

/**
 * Adds a custom input for the subscribed_lists and not_subscribed_lists criteria.
 */
addFilter( 'newspack.criteria.input', 'newspack.newsletterSubscribedLists', function ( element, criteria, value, update ) {
	if ( [ 'subscribed_lists', 'not_subscribed_lists' ].includes( criteria.id ) ) {
		return (
			<ListsControl
				label={ criteria.name }
				hideLabelFromVision
				placeholder={ __( 'Start typing to search for lists…', 'newspack-plugin' ) }
				// `context=edit` skips the reader-facing filter that drops hidden lists,
				// so a segment can still target (and name) a list a gate hides.
				path="/newspack-newsletters/v1/lists_config?context=edit"
				value={ value }
				onChange={ update }
				deletedItemLabel={ __( 'Deleted list', 'newspack-plugin' ) }
			/>
		);
	}
	return element;
} );

/**
 * Adds a custom input for the active_subscriptions and not_active_subscriptions criteria.
 */
addFilter( 'newspack.criteria.input', 'newspack.activeSubscriptions', function ( element, criteria, value, update ) {
	if ( [ 'active_subscriptions', 'not_active_subscriptions' ].includes( criteria.id ) ) {
		return (
			<ListsControl
				label={ criteria.name }
				hideLabelFromVision
				placeholder={ __( 'Start typing to search for products…', 'newspack-plugin' ) }
				path={ `${ newspackAudienceCampaigns.api }/subscription-products` }
				savedInfoPath={ ids => addQueryArgs( `${ newspackAudienceCampaigns.api }/subscription-products`, { include: ids } ) }
				labelWithId
				value={ value }
				onChange={ update }
				deletedItemLabel={ __( 'Deleted subscription', 'newspack-plugin' ) }
			/>
		);
	}
	return element;
} );

/**
 * Adds a custom input for the active_memberships and not_active_memberships criteria.
 */
addFilter( 'newspack.criteria.input', 'newspack.activeMemberships', function ( element, criteria, value, update ) {
	if ( [ 'active_memberships', 'not_active_memberships' ].includes( criteria.id ) ) {
		return (
			<ListsControl
				label={ criteria.name }
				hideLabelFromVision
				placeholder={ __( 'Start typing to search for membership plans…', 'newspack-plugin' ) }
				path="/wc/v3/memberships/plans?per_page=100"
				value={ value }
				onChange={ update }
				deletedItemLabel={ __( 'Deleted plan', 'newspack-plugin' ) }
			/>
		);
	}
	return element;
} );

/**
 * Adds a custom input for the devices criteria so more than one device can be selected.
 */
addFilter( 'newspack.criteria.input', 'newspack.devices', function ( element, criteria, value, update ) {
	if ( criteria.id === 'devices' ) {
		const selectedDevices = Array.isArray( value ) ? value : [];

		return (
			<Stack direction="column" gap="sm" className="newspack-device-segments">
				{ criteria.options.map( device => (
					<CheckboxControl
						key={ device.value }
						label={ device.label }
						checked={ selectedDevices.includes( device.value ) }
						onChange={ isChecked => {
							if ( isChecked ) {
								update( [ ...selectedDevices, device.value ] );
							} else {
								update( selectedDevices.filter( item => item !== device.value ) );
							}
						} }
						__nextHasNoMarginBottom
					/>
				) ) }
			</Stack>
		);
	}
	return element;
} );

export default SingleSegment;
