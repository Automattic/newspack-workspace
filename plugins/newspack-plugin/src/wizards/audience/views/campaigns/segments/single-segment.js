/* globals newspackAudienceCampaigns */
/**
 * WordPress dependencies.
 */
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { ToggleControl, CheckboxControl } from '@wordpress/components';
import { useEffect, useState, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import find from 'lodash/find';
import { applyFilters, addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies.
 */
import { Button, CategoryAutocomplete, Router, SelectControl, Settings, TextControl, hooks, Grid } from '../../../../../../packages/components/src';
import ListsControl from '../../../components/lists-control';

const { useHistory } = Router;
const { SettingsCard, SettingsSection, MinMaxSetting } = Settings;

const DEFAULT_CONFIG = {
	is_disabled: false,
};

const SingleSegment = ( { segmentId, setSegments, wizardApiFetch } ) => {
	const allCriteria = window.newspackAudienceCampaigns?.criteria || [];

	const [ segmentConfig, updateSegmentConfig ] = hooks.useObjectState( DEFAULT_CONFIG );
	const [ name, setName ] = useState( '' );
	const [ segmentCriteria, setSegmentCriteria ] = useState( [] );
	const [ segmentCriteriaInitially, setSegmentCriteriaInitially ] = useState( [] );
	const [ nameInitially, setNameInitially ] = useState( '' );
	const history = useHistory();

	const [ segmentInitially, setSegmentInitially ] = useState( '[]' );

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

	const unblock = hooks.usePrompt( isDirty, __( 'There are unsaved changes to this segment. Discard changes?', 'newspack-plugin' ) );

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

	const saveSegment = () => {
		unblock();

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
			.then( history.push( '/segments' ) );
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
				item.value = { ...item.value, ...value };
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
					<MinMaxSetting
						data-testid={ `newspack-criteria-${ criteria.id }` }
						min={ value?.min }
						max={ value?.max }
						onChangeMin={ min => update( { min } ) }
						onChangeMax={ max => update( { max } ) }
					/>
				);
			}
			if ( criteria.options?.length ) {
				return (
					<SelectControl
						data-testid={ `newspack-criteria-${ criteria.id }` }
						isWide
						onChange={ update }
						value={ value }
						options={ criteria.options }
					/>
				);
			}
			return (
				<TextControl
					data-testid={ `newspack-criteria-${ criteria.id }` }
					isWide
					placeholder={ criteria.placeholder }
					help={ criteria.help }
					value={ value }
					onChange={ update }
				/>
			);
		};
		return applyFilters( 'newspack.criteria.input', getInput(), criteria, value, update );
	};

	return (
		<Fragment>
			<TextControl
				placeholder={ __( 'Untitled Segment', 'newspack-plugin' ) }
				value={ name }
				onChange={ setName }
				label={ __( 'Title', 'newspack-plugin' ) }
				className={ 'newspack-campaigns-wizard-segments__title' }
			/>

			<SettingsCard
				title={ __( 'Segment Status', 'newspack-plugin' ) }
				description={ __( 'If not enabled, the segment will be ignored for reader segmentation.', 'newspack-plugin' ) }
				noBorder
			>
				<ToggleControl
					label={ __( 'Segment enabled', 'newspack-plugin' ) }
					checked={ ! segmentConfig.is_disabled }
					onChange={ () => updateSegmentConfig( { is_disabled: ! segmentConfig.is_disabled } ) }
				/>
			</SettingsCard>

			<SettingsCard
				title={ __( 'Reader Engagement', 'newspack-plugin' ) }
				description={ __( 'Target readers based on their browsing behavior.', 'newspack-plugin' ) }
				noBorder
			>
				{ allCriteria
					.filter( criteria => criteria.category === 'reader_engagement' )
					.map( criteria => (
						<SettingsSection key={ criteria.id } title={ criteria.name } description={ criteria.description }>
							{ getCriteriaInput( criteria ) }
						</SettingsSection>
					) ) }
			</SettingsCard>
			<SettingsCard
				title={ __( 'Registration', 'newspack-plugin' ) }
				description={ __( 'Target readers based on their user account registration status.', 'newspack-plugin' ) }
				columns={ 3 }
				noBorder
			>
				{ allCriteria
					.filter( criteria => criteria.category === 'reader_activity' )
					.map( criteria => (
						<SettingsSection key={ criteria.id } title={ criteria.name } description={ criteria.description }>
							{ getCriteriaInput( criteria ) }
						</SettingsSection>
					) ) }
			</SettingsCard>
			<SettingsCard
				title={ __( 'Newsletters', 'newspack-plugin' ) }
				description={ __( 'Target readers based on their newsletter subscription status.', 'newspack-plugin' ) }
				columns={ 3 }
				noBorder
			>
				{ allCriteria
					.filter( criteria => criteria.category === 'newsletter' )
					.map( criteria => (
						<SettingsSection key={ criteria.id } title={ criteria.name } description={ criteria.description }>
							{ getCriteriaInput( criteria ) }
						</SettingsSection>
					) ) }
			</SettingsCard>
			<SettingsCard
				title={ __( 'Reader Revenue', 'newspack-plugin' ) }
				description={ __( 'Target readers based on their revenue activity.', 'newspack-plugin' ) }
				columns={ 3 }
				noBorder
			>
				{ allCriteria
					.filter( criteria => criteria.category === 'reader_revenue' )
					.map( criteria => (
						<SettingsSection key={ criteria.id } title={ criteria.name } description={ criteria.description }>
							{ getCriteriaInput( criteria ) }
						</SettingsSection>
					) ) }
			</SettingsCard>
			<SettingsCard
				title={ __( 'Referrer Sources', 'newspack-plugin' ) }
				description={ __( 'Target readers based on where they’re coming from.', 'newspack-plugin' ) }
				notification={ __(
					'Segments using these options will apply only to the first page visited after coming from an external source.',
					'newspack-plugin'
				) }
				columns={ 2 }
				noBorder
			>
				{ allCriteria
					.filter( criteria => criteria.category === 'referrer_sources' )
					.map( criteria => (
						<SettingsSection key={ criteria.id } title={ criteria.name } description={ criteria.description }>
							{ getCriteriaInput( criteria ) }
						</SettingsSection>
					) ) }
			</SettingsCard>
			{ allCriteria.some( criteria => criteria.category === 'integrations' ) && (
				<SettingsCard
					title={ __( 'Integrations', 'newspack-plugin' ) }
					description={ __( 'Target readers based on data from connected integrations.', 'newspack-plugin' ) }
					noBorder
				>
					{ allCriteria
						.filter( criteria => criteria.category === 'integrations' )
						.map( criteria => (
							<SettingsSection key={ criteria.id } title={ criteria.name } description={ criteria.description }>
								{ getCriteriaInput( criteria ) }
							</SettingsSection>
						) ) }
				</SettingsCard>
			) }
			<div className="newspack-buttons-card">
				<Button disabled={ ! isSegmentValid() || ( ! isNew && ! isDirty ) } isPrimary onClick={ saveSegment }>
					{ __( 'Save', 'newspack-plugin' ) }
				</Button>
				<Button isSecondary href="#/segments">
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
			</div>
		</Fragment>
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
				placeholder={ __( 'Start typing to search for lists…', 'newspack-plugin' ) }
				path="/newspack-newsletters/v1/lists_config"
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
				placeholder={ __( 'Start typing to search for products…', 'newspack-plugin' ) }
				path={ `${ newspackAudienceCampaigns.api }/subscription-products` }
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
			<div className="newspack-device-segments">
				<Grid columns={ 1 } rowGap={ 16 }>
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
						/>
					) ) }
				</Grid>
			</div>
		);
	}
	return element;
} );

export default SingleSegment;
