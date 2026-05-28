/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Notice, SectionHeader, SelectControl } from '../../../../packages/components/src';

export default function ActiveCampaign( { value, onChange } ) {
	const [ inFlight, setInFlight ] = useState( false );
	const [ lists, setLists ] = useState( [] );
	const [ error, setError ] = useState( false );
	const fetchLists = () => {
		setError( false );
		setInFlight( true );
		apiFetch( {
			path: '/newspack-newsletters/v1/lists',
		} )
			.then( setLists )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	useEffect( fetchLists, [] );
	const handleChange = key => val => onChange && onChange( key, val );
	return (
		<>
			{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }
			<SectionHeader
				title={ __( 'ActiveCampaign settings', 'newspack-plugin' ) }
				description={ __( 'Settings for the ActiveCampaign integration.', 'newspack-plugin' ) }
			/>
			{ value.masterList === '' && (
				<Notice
					noticeText={ __(
						'No Master List selected. You will not be able to send reader activity data to ActiveCampaign.',
						'newspack-plugin'
					) }
					isError
				/>
			) }
			<SelectControl
				label={ __( 'Master List', 'newspack-plugin' ) }
				help={ __( 'Choose a list to which all registered readers will be added.', 'newspack-plugin' ) }
				disabled={ inFlight }
				value={ value.masterList }
				onChange={ handleChange( 'masterList' ) }
				options={ [
					{ value: '', label: __( 'None', 'newspack-plugin' ) },
					...lists.map( list => ( { label: list.name, value: list.id } ) ),
				] }
			/>
		</>
	);
}
