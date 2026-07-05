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
import type { NewsletterList } from './settings';

type MailchimpProps = {
	value: { audienceId?: string; readerDefaultStatus?: string };
	onChange?: ( key: string, value: string ) => void;
};

export default function Mailchimp( { value, onChange }: MailchimpProps ) {
	const [ inFlight, setInFlight ] = useState( false );
	const [ lists, setLists ] = useState< NewsletterList[] >( [] );
	const [ error, setError ] = useState< { message?: string } | false >( false );
	const fetchLists = () => {
		setError( false );
		setInFlight( true );
		apiFetch< NewsletterList[] >( {
			path: '/newspack-newsletters/v1/lists',
		} )
			.then( res => setLists( res.filter( list => list.type_label === 'Mailchimp Audience' ) ) )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	useEffect( fetchLists, [] );
	const handleChange = ( key: string ) => ( val: string ) => onChange && onChange( key, val );
	return (
		<>
			{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }
			<SectionHeader
				title={ __( 'Mailchimp settings', 'newspack-plugin' ) }
				description={ __( 'Settings for the Mailchimp integration.', 'newspack-plugin' ) }
			/>
			{ value.audienceId === '' && (
				<Notice
					noticeText={ __(
						'No Mailchimp audience selected. You will not be able to send reader activity data to Mailchimp.',
						'newspack-plugin'
					) }
					isError
				/>
			) }
			<SelectControl
				label={ __( 'Audience ID', 'newspack-plugin' ) }
				help={ __( 'Choose an audience to receive reader activity data.', 'newspack-plugin' ) }
				disabled={ inFlight }
				value={ value.audienceId }
				onChange={ handleChange( 'audienceId' ) }
				options={ [
					{ value: '', label: __( 'None', 'newspack-plugin' ) },
					...lists.map( list => ( { label: list.name, value: list.id } ) ),
				] }
			/>
			{ value.audienceId && (
				<SelectControl
					label={ __( 'Default reader status', 'newspack-plugin' ) }
					help={ __(
						'Choose which MailChimp status readers should have by default if they are not subscribed to any newsletters',
						'newspack-plugin'
					) }
					disabled={ inFlight }
					value={ value.readerDefaultStatus }
					onChange={ handleChange( 'readerDefaultStatus' ) }
					options={ [
						{
							value: 'transactional',
							label: __( 'Transactional/Non-Subscribed', 'newspack-plugin' ),
						},
						{ value: 'subscribed', label: __( 'Subscribed', 'newspack-plugin' ) },
					] }
				/>
			) }
		</>
	);
}
