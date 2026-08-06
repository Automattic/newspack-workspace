/**
 * Newspack > Settings > Social: tracking pixel card.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Notice as WPNotice, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Button, CardForm, TextControl } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { useErrorAnnouncement, useSocialCards } from './context';

type PixelCardProps = {
	title: string;
	description: string;
	namespace: string;
	path: string;
	validate: ( value: string ) => string | null;
	renderHelp: () => React.ReactNode;
};

const DEFAULTS: PixelData = { active: false, pixel_id: '' };

const PixelCard = ( { title, description, namespace, path, validate, renderHelp }: PixelCardProps ) => {
	const { wizardApiFetch, isFetching, errorMessage, setError, resetError } = useWizardApiFetch( namespace );
	const { notify } = useSocialCards();

	const [ settings, setSettings ] = useState< PixelData >( DEFAULTS );
	const [ draft, setDraft ] = useState< string >( '' );
	const [ isOpen, setIsOpen ] = useState( false );
	const [ isEnabling, setIsEnabling ] = useState( false );
	const [ hasTouched, setHasTouched ] = useState( false );
	const [ errorNonce, setErrorNonce ] = useState( 0 );

	const bumpErrorNonce = () => setErrorNonce( current => current + 1 );

	useErrorAnnouncement( errorMessage, errorNonce );

	useEffect( () => {
		wizardApiFetch< PixelData >(
			{ path },
			{
				onSuccess: ( res: PixelData ) => {
					setSettings( res );
					setDraft( res.pixel_id ?? '' );
				},
			}
		).catch( bumpErrorNonce );
	}, [] );

	const validationError = validate( draft );
	const hasChanges = draft !== settings.pixel_id;

	const save = ( data: PixelData, message: string ) => {
		resetError();
		return wizardApiFetch< PixelData >(
			{ path, method: 'POST', data, updateCacheMethods: [ 'GET' ] },
			{
				onSuccess: ( res: PixelData ) => {
					setSettings( res );
					setDraft( res.pixel_id ?? '' );
					setIsOpen( false );
					setIsEnabling( false );
					setHasTouched( false );
					notify( message );
				},
			}
		).catch( bumpErrorNonce );
	};

	const close = () => {
		setDraft( settings.pixel_id ?? '' );
		setIsOpen( false );
		setIsEnabling( false );
		setHasTouched( false );
		resetError();
	};

	const badge = ( () => {
		if ( errorMessage ) {
			return { level: 'error' as const, text: __( 'Error', 'newspack-plugin' ) };
		}
		if ( ! settings.active ) {
			return undefined;
		}
		if ( ! settings.pixel_id ) {
			return { level: 'warning' as const, text: __( 'Missing pixel ID', 'newspack-plugin' ) };
		}
		return { level: 'success' as const, text: __( 'Enabled', 'newspack-plugin' ) };
	} )();

	/* translators: %s: integration name (e.g. "Meta Pixel"). */
	const editLabel = sprintf( __( 'Edit %s', 'newspack-plugin' ), title );
	/* translators: %s: integration name (e.g. "Meta Pixel"). */
	const cancelLabel = sprintf( __( 'Cancel editing %s', 'newspack-plugin' ), title );
	/* translators: %s: integration name (e.g. "Meta Pixel"). */
	const enableLabel = sprintf( __( 'Enable %s', 'newspack-plugin' ), title );
	/* translators: %s: integration name (e.g. "Meta Pixel"). */
	const enabledMessage = sprintf( __( '%s enabled.', 'newspack-plugin' ), title );
	/* translators: %s: integration name (e.g. "Meta Pixel"). */
	const updatedMessage = sprintf( __( '%s updated.', 'newspack-plugin' ), title );
	/* translators: %s: integration name (e.g. "Meta Pixel"). */
	const disabledMessage = sprintf( __( '%s disabled.', 'newspack-plugin' ), title );

	const actions = settings.active ? (
		<Button
			variant="tertiary"
			size="compact"
			aria-label={ isOpen ? cancelLabel : editLabel }
			disabled={ isFetching }
			onClick={ () => ( isOpen ? close() : setIsOpen( true ) ) }
		>
			<span className="newspack-social-settings__toggle-label">
				<span className={ classnames( { 'is-visible': isOpen } ) }>{ __( 'Cancel', 'newspack-plugin' ) }</span>
				<span className={ classnames( { 'is-visible': ! isOpen } ) }>{ __( 'Edit', 'newspack-plugin' ) }</span>
			</span>
		</Button>
	) : (
		<Button
			variant={ isOpen ? 'tertiary' : 'secondary' }
			size="compact"
			aria-label={ isOpen ? cancelLabel : enableLabel }
			isBusy={ ! isOpen && isFetching }
			disabled={ isFetching }
			onClick={ () => {
				if ( isOpen ) {
					close();
					return;
				}
				setIsEnabling( true );
				setIsOpen( true );
			} }
		>
			<span className="newspack-social-settings__toggle-label">
				<span className={ classnames( { 'is-visible': isOpen } ) }>{ __( 'Cancel', 'newspack-plugin' ) }</span>
				<span className={ classnames( { 'is-visible': ! isOpen } ) }>{ __( 'Enable', 'newspack-plugin' ) }</span>
			</span>
		</Button>
	);

	return (
		<CardForm
			title={ title }
			description={ ! isOpen && errorMessage ? errorMessage : description }
			badge={ badge }
			actions={ actions }
			isOpen={ isOpen }
			onRequestClose={ close }
		>
			<VStack spacing={ 4 }>
				{ errorMessage && (
					<WPNotice status="error" isDismissible={ false } spokenMessage={ null }>
						{ errorMessage }
					</WPNotice>
				) }
				<TextControl
					value={ draft }
					label={ __( 'Pixel ID', 'newspack-plugin' ) }
					onChange={ ( value: string ) => {
						setDraft( value );
						setHasTouched( true );
						setError( null );
					} }
					help={ renderHelp() }
					disabled={ isFetching }
					autoComplete="one-time-code"
					withMargin={ false }
					__nextHasNoMarginBottom
				/>
				{ hasTouched && validationError && (
					<WPNotice status="error" isDismissible={ false } spokenMessage={ null }>
						{ validationError }
					</WPNotice>
				) }
				<HStack justify="flex-start" spacing={ 2 }>
					<Button
						variant="primary"
						__next40pxDefaultSize
						isBusy={ isFetching }
						disabled={ isFetching || !! validationError || ( ! isEnabling && ! hasChanges ) }
						onClick={ () => save( { active: true, pixel_id: draft.trim() }, isEnabling ? enabledMessage : updatedMessage ) }
					>
						{ isEnabling ? __( 'Enable', 'newspack-plugin' ) : __( 'Update', 'newspack-plugin' ) }
					</Button>
					{ ! isEnabling && (
						<Button
							variant="tertiary"
							__next40pxDefaultSize
							isDestructive
							isBusy={ isFetching }
							disabled={ isFetching }
							onClick={ () => save( { active: false, pixel_id: settings.pixel_id ?? '' }, disabledMessage ) }
						>
							{ __( 'Disable', 'newspack-plugin' ) }
						</Button>
					) }
				</HStack>
			</VStack>
		</CardForm>
	);
};

export default PixelCard;
