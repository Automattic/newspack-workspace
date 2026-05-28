/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	Button,
	Notice,
	SelectControl,
	TextControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Icon, external } from '@wordpress/icons';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import { useNewsletterData } from '../../newsletter-editor/store';
import { hasValidEmail } from '../utils';

const Sender = ( { errors, inFlight, senderEmail, senderName, updateMeta, postStatus } ) => {
	const { newsletterData } = useNewsletterData();
	const { allowed_sender_domains: allowedDomains, allowed_sender_emails: allowedEmails = null, email_settings_url: settingsUrl } = newsletterData;
	const senderEmailClasses = classnames(
		'newspack-newsletters__email-textcontrol',
		errors.newspack_newsletters_unverified_sender_domain && 'newspack-newsletters__error'
	);
	const validDomainsMessage =
		postStatus !== 'future' && allowedDomains?.length && allowedDomains.every( domain => ! senderEmail || ! senderEmail.includes( domain ) )
			? sprintf(
					/* translators: %s: list of allowed domains */
					__( 'Sender email must contain one of the following domains: %s', 'newspack-newsletters' ),
					allowedDomains.join( ', ' )
			  )
			: '';

	return (
		<BaseControl
			id="newspack-newsletters__sender"
			help={ postStatus === 'future' && __( 'Unschedule this newsletter to edit sender info.', 'newspack-newsletters' ) }
			__nextHasNoMarginBottom
		>
			<VStack spacing={ 4 }>
				<strong className="newspack-newsletters__label">{ __( 'Sender', 'newspack-newsletters' ) }</strong>
				{ ( newsletterData?.senderEmail || newsletterData?.senderName ) && (
					<Notice status="success" isDismissible={ false }>
						{ __( 'Updated sender info fetched from ESP.', 'newspack-newsletters' ) }
					</Notice>
				) }
				<TextControl
					label={ __( 'Name', 'newspack-newsletters' ) }
					className="newspack-newsletters__name-textcontrol"
					value={ senderName }
					disabled={ inFlight || postStatus === 'future' }
					onChange={ value => updateMeta( { senderName: value } ) }
					placeholder={ __( 'The campaign’s sender name.', 'newspack-newsletters' ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ ! inFlight && null === allowedEmails && (
					<TextControl
						label={ __( 'Email', 'newspack-newsletters' ) }
						help={
							senderEmail && ! hasValidEmail( senderEmail )
								? __( 'Please enter a valid email address.', 'newspack-newsletters' )
								: validDomainsMessage
						}
						className={ senderEmailClasses }
						value={ senderEmail }
						type="email"
						disabled={ postStatus === 'future' }
						onChange={ value => updateMeta( { senderEmail: value } ) }
						placeholder={ __( 'The campaign’s sender email.', 'newspack-newsletters' ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) }
				{ Array.isArray( allowedEmails ) && (
					<>
						{ ! inFlight && ! allowedEmails.length && (
							<Notice status="warning" isDismissible={ false }>
								{ __( 'There are no verified email addresses.', 'newspack-newsletters' ) }
							</Notice>
						) }
						{ allowedEmails.length && (
							<SelectControl
								label={ __( 'Email', 'newspack-newsletters' ) }
								disabled={ inFlight || postStatus === 'future' }
								help={ __( 'Select a verified sender email.', 'newspack-newsletters' ) }
								value={ senderEmail || '' }
								onChange={ value => updateMeta( { senderEmail: value } ) }
								options={ [
									{
										label: __( '-- Select a sender email --', 'newspack-newsletters' ),
										value: '',
									},
								].concat(
									allowedEmails.map( email => ( {
										label: email,
										value: email,
									} ) )
								) }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						) }
						{ settingsUrl && (
							<Button
								disabled={ inFlight }
								href={ settingsUrl }
								size="small"
								target="_blank"
								variant="secondary"
								rel="noopener noreferrer"
							>
								{ __( 'Manage', 'newspack-newsletters' ) }
								<Icon icon={ external } size={ 14 } />
							</Button>
						) }
					</>
				) }
			</VStack>
		</BaseControl>
	);
};

export default Sender;
