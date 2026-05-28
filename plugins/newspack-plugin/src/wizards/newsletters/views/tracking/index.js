/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { ActionCard, withWizardScreen } from '../../../../../packages/components/src';

const apiPath = '/newspack/v1/wizard/newspack-newsletters/settings/tracking';

export default withWizardScreen( () => {
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
			<h1>{ __( 'Ads Tracking', 'newspack-plugin' ) }</h1>
			<ActionCard
				title={ __( 'Click-tracking', 'newspack-plugin' ) }
				description={ __( 'Track the clicks on ads in your newsletter.', 'newspack-plugin' ) }
				disabled={ inFlight }
				toggleOnChange={ handleChange( 'click' ) }
				toggleChecked={ tracking.click }
			/>
			<ActionCard
				title={ __( 'Ads impressions', 'newspack-plugin' ) }
				description={ __( 'Track the impressions of ads in your newsletter.', 'newspack-plugin' ) }
				disabled={ inFlight }
				toggleOnChange={ handleChange( 'pixel' ) }
				toggleChecked={ tracking.pixel }
			/>
		</>
	);
} );
