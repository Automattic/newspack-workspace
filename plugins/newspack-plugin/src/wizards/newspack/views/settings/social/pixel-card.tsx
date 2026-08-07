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
import { useInstanceId } from '@wordpress/compose';
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
	const [ hasBlurred, setHasBlurred ] = useState( false );
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
	// More than one card renders per page, so the field and its error need ids of
	// their own.
	const instanceId = useInstanceId( PixelCard, 'newspack-pixel-card' );
	const fieldId = `${ instanceId }-pixel-id`;
	const errorId = `${ fieldId }-error`;
	const shownError = hasBlurred && validationError ? validationError : null;
	// `aria-errormessage` is unimplemented in WebKit, so the error is composed into
	// `aria-describedby` instead. `__help` is the id BaseControl gives the help
	// text, and keeping it preserves that association rather than replacing it.
	const describedBy = [ `${ fieldId }__help`, shownError ? errorId : null ].filter( Boolean ).join( ' ' );

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
					setHasBlurred( false );
					notify( message );
				},
			}
		).catch( bumpErrorNonce );
	};

	const close = () => {
		setDraft( settings.pixel_id ?? '' );
		setIsOpen( false );
		setIsEnabling( false );
		setHasBlurred( false );
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
			return { level: 'error' as const, text: __( 'Missing pixel ID', 'newspack-plugin' ) };
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
			aria-expanded={ isOpen }
			aria-label={ isOpen ? cancelLabel : editLabel }
			disabled={ isFetching }
			accessibleWhenDisabled
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
			aria-expanded={ isOpen }
			aria-label={ isOpen ? cancelLabel : enableLabel }
			isBusy={ ! isOpen && isFetching }
			disabled={ isFetching }
			accessibleWhenDisabled
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
				<VStack spacing={ 2 }>
					<TextControl
						id={ fieldId }
						value={ draft }
						label={ __( 'Pixel ID', 'newspack-plugin' ) }
						onChange={ ( value: string ) => {
							setDraft( value );
							setError( null );
						} }
						onBlur={ () => setHasBlurred( true ) }
						help={ renderHelp() }
						disabled={ isFetching }
						autoComplete="one-time-code"
						aria-invalid={ !! shownError }
						aria-describedby={ describedBy }
						withMargin={ false }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					{ shownError && (
						<p className="newspack-social-settings__field-error" id={ errorId }>
							{ shownError }
						</p>
					) }
				</VStack>
				<HStack justify="flex-start" spacing={ 2 }>
					<Button
						variant="primary"
						__next40pxDefaultSize
						isBusy={ isFetching }
						disabled={ isFetching || !! validationError || ( ! isEnabling && ! hasChanges ) }
						accessibleWhenDisabled
						// The button is disabled before the field has been blurred, so its
						// own reason is the only one exposed anywhere.
						description={ validationError ? __( 'Enter a valid pixel ID to continue.', 'newspack-plugin' ) : undefined }
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
							accessibleWhenDisabled
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
