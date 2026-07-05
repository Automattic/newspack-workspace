/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { ToggleControl, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { Divider, Grid, SectionHeader } from '../../../../../packages/components/src';

const apiPath = '/newspack/v1/wizard/newspack-newsletters/settings/tracking';

const Tracking = () => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ tracking, setTracking ] = useState( {} );

	const fetchData = () => {
		setInFlight( true );
		apiFetch( { path: apiPath } )
			.then( response => {
				setTracking( response );
			} )
			.finally( () => {
				setInFlight( false );
			} );
	};

	const handleChange = type => async value => {
		const newData = {
			...tracking,
			[ type ]: value,
		};
		setInFlight( true );
		apiFetch( {
			path: apiPath,
			method: 'POST',
			data: newData,
		} )
			.then( () => {
				setTracking( newData );
			} )
			.finally( () => {
				setInFlight( false );
			} );
	};

	useEffect( () => {
		fetchData();
	}, [] );

	return (
		<>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					heading={ 2 }
					title={ __( 'Ads tracking', 'newspack-plugin' ) }
					description={ __( 'Choose what to track for ads inside newsletters.', 'newspack-plugin' ) }
					noMargin
				/>
				<VStack spacing={ 4 } className="newspack-newsletters-settings-stack">
					<ToggleControl
						label={ __( 'Click-tracking', 'newspack-plugin' ) }
						help={ __( 'Track the clicks on ads in your newsletter.', 'newspack-plugin' ) }
						checked={ !! tracking.click }
						onChange={ handleChange( 'click' ) }
						disabled={ inFlight }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Ads impressions', 'newspack-plugin' ) }
						help={ __( 'Track the impressions of ads in your newsletter.', 'newspack-plugin' ) }
						checked={ !! tracking.pixel }
						onChange={ handleChange( 'pixel' ) }
						disabled={ inFlight }
						__nextHasNoMarginBottom
					/>
				</VStack>
			</Grid>
		</>
	);
};

export default Tracking;
