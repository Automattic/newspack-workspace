import { speak } from '@wordpress/a11y';
import { CheckboxControl, Notice, ProgressBar, TextControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { isURL } from '@wordpress/url';
import { Stack } from '@wordpress/ui';

import Button from '../../../../../packages/components/src/button';
import NarrowLayout from '../../components/narrow-layout';
import StepFooter from '../../components/step-footer';
import StepHeading from '../../components/step-heading';
import type { SetupViewProps } from '../../steps';
import ApproachCards from './approach-cards';
import InstallChecklist from './install-checklist';
import { useCountdown, useInstall, type Approach, type InstallFailures } from './use-install';

const COUNTDOWN_SECONDS = 5;
const ANNOUNCE_AFTER_FOCUS_MS = 300;

const isSiteUrl = ( value: string ) => /^https?:\/\/[^\s/]+\.[^\s]+/i.test( value ) && isURL( value );

const hostOf = ( value: string ) => {
	try {
		return new URL( value ).host;
	} catch {
		return value;
	}
};

const failureMessages = ( failures: InstallFailures, isMove: boolean ) => {
	const messages: string[] = [];
	if ( failures.plugins.length ) {
		messages.push(
			sprintf(
				/* translators: %s: comma-separated plugin names. */
				__( 'Couldn’t install %s.', 'newspack-plugin' ),
				failures.plugins.join( ', ' )
			)
		);
	}
	if ( failures.theme ) {
		messages.push( __( 'Couldn’t install the Newspack theme.', 'newspack-plugin' ) );
	}
	if ( failures.identity ) {
		messages.push( __( 'Couldn’t bring over the logo, icon, title and tagline.', 'newspack-plugin' ) );
	}
	if ( failures.items ) {
		messages.push(
			isMove
				? sprintf(
						/* translators: %d: number of articles. */
						_n( '%d article couldn’t be created.', '%d articles couldn’t be created.', failures.items, 'newspack-plugin' ),
						failures.items
				  )
				: sprintf(
						/* translators: %d: number of sample stories. */
						_n( '%d sample story couldn’t be created.', '%d sample stories couldn’t be created.', failures.items, 'newspack-plugin' ),
						failures.items
				  )
		);
	}
	if ( failures.homepage ) {
		messages.push( __( 'Couldn’t create the homepage.', 'newspack-plugin' ) );
	}
	return messages.join( ' ' );
};

export default function Start( { initialCheck, setInstallState, goTo }: SetupViewProps ) {
	const [ approach, setApproach ] = useState< Approach >( 'generated' );
	const [ withStories, setWithStories ] = useState( true );
	const [ siteUrl, setSiteUrl ] = useState( '' );
	const [ urlError, setUrlError ] = useState( '' );
	const [ invalidAttempts, setInvalidAttempts ] = useState( 0 );
	const urlRef = useRef< HTMLInputElement >( null );
	const headingRef = useRef< HTMLHeadingElement >( null );
	const previousStatus = useRef( 'idle' );
	const leaving = useRef( false );
	const [ held, setHeld ] = useState( false );
	const urlFieldId = useInstanceId( Start, 'setup-site-url' );
	const httpsNoticeId = useInstanceId( Start, 'setup-https' );

	const isMove = approach === 'import';
	const trimmedUrl = siteUrl.trim();
	const install = useInstall( { approach, withStories, siteUrl: trimmedUrl, plugins: initialCheck.plugins } );
	const { status, initError, save } = install;
	const fieldError = initError || urlError;
	const canContinue = ( status === 'done' && save === 'saved' ) || ( status === 'partial' && save !== 'saving' && save !== 'failed' );
	const counting = status === 'done' && save === 'saved' && ! held;

	let title: string = __( 'Installing…', 'newspack-plugin' );
	let lede: string = __( 'Keep this tab open. You can carry on as soon as everything is in.', 'newspack-plugin' );
	if ( status === 'done' ) {
		title = __( 'Your site is ready to set up', 'newspack-plugin' );
		lede = isMove
			? sprintf(
					/* translators: %s: address of the site being moved. */
					__( 'Everything from %s is in.', 'newspack-plugin' ),
					hostOf( trimmedUrl )
			  )
			: __( 'Everything is installed.', 'newspack-plugin' );
	} else if ( status === 'partial' ) {
		title = __( 'Almost there', 'newspack-plugin' );
		lede = __( 'Everything installed except a few items. Your site works without them.', 'newspack-plugin' );
	} else if ( status === 'blocked' ) {
		title = __( 'Installation stopped', 'newspack-plugin' );
		lede = __( 'Newspack needs these in place before you can continue.', 'newspack-plugin' );
	}

	const failureText = status === 'partial' || status === 'blocked' ? failureMessages( install.failures, isMove ) : '';

	const leave = () => {
		if ( leaving.current ) {
			return;
		}
		leaving.current = true;
		setInstallState( { approach, complete: true } );
		goTo( 'site-details' );
	};

	const onContinue = async () => {
		if ( save === 'saved' || ( await install.persist() ) ) {
			leave();
		}
	};

	const retrySave = async () => {
		await install.persist();
		headingRef.current?.focus();
	};

	const seconds = useCountdown( counting, COUNTDOWN_SECONDS, leave );

	const onInstall = () => {
		if ( isMove && ! isSiteUrl( trimmedUrl ) ) {
			setUrlError( __( 'Enter the full address of your current site, like https://example.com.', 'newspack-plugin' ) );
			setInvalidAttempts( current => current + 1 );
			return;
		}
		install.start();
	};

	useEffect( () => {
		urlRef.current?.setCustomValidity( fieldError );
	}, [ fieldError, isMove ] );

	useEffect( () => {
		if ( invalidAttempts ) {
			urlRef.current?.focus();
		}
	}, [ invalidAttempts ] );

	useEffect( () => {
		const previous = previousStatus.current;
		previousStatus.current = status;
		if ( status === previous ) {
			return;
		}
		if ( status === 'running' ) {
			headingRef.current?.focus();
		} else if ( status === 'idle' && initError ) {
			if ( isMove ) {
				urlRef.current?.focus();
			} else {
				headingRef.current?.focus();
				const timer = window.setTimeout( () => speak( initError, 'assertive' ), ANNOUNCE_AFTER_FOCUS_MS );
				return () => window.clearTimeout( timer );
			}
		} else if ( status === 'partial' || status === 'blocked' ) {
			speak( `${ title } ${ failureText }`, status === 'blocked' ? 'assertive' : 'polite' );
		}
	}, [ status, initError, isMove, title, lede, failureText ] );

	useEffect( () => {
		if ( ! counting ) {
			return;
		}
		speak(
			`${ sprintf(
				/* translators: %d: seconds before the next step opens. */
				_n( 'Continuing to Site details in %d second.', 'Continuing to Site details in %d seconds.', COUNTDOWN_SECONDS, 'newspack-plugin' ),
				COUNTDOWN_SECONDS
			) } ${ __( 'Press Tab to stay on this step.', 'newspack-plugin' ) } ${ title } ${ lede }`
		);
		const hold = () => setHeld( true );
		const onClick = ( event: MouseEvent ) => {
			if ( event.target instanceof Element && event.target.closest( '.setup-canvas' ) ) {
				hold();
			}
		};
		const onFocus = ( event: FocusEvent ) => {
			if ( event.target !== headingRef.current ) {
				hold();
			}
		};
		document.addEventListener( 'keydown', hold, true );
		document.addEventListener( 'click', onClick, true );
		document.addEventListener( 'focusin', onFocus, true );
		return () => {
			document.removeEventListener( 'keydown', hold, true );
			document.removeEventListener( 'click', onClick, true );
			document.removeEventListener( 'focusin', onFocus, true );
		};
	}, [ counting, title, lede ] );

	if ( status === 'idle' ) {
		return (
			<NarrowLayout
				footer={
					<StepFooter>
						<Button
							variant="primary"
							disabled={ ! initialCheck.is_ssl || ( isMove && ! trimmedUrl ) }
							accessibleWhenDisabled
							aria-describedby={ initialCheck.is_ssl ? undefined : httpsNoticeId }
							__next40pxDefaultSize
							onClick={ onInstall }
						>
							{ __( 'Install', 'newspack-plugin' ) }
						</Button>
					</StepFooter>
				}
			>
				<StepHeading
					headingRef={ headingRef }
					title={ __( 'Welcome to Newspack', 'newspack-plugin' ) }
					lede={ __( 'First we install what your site needs. It takes a couple of minutes.', 'newspack-plugin' ) }
				/>
				<Stack direction="column" gap="xl">
					{ ! initialCheck.is_ssl && (
						<div id={ httpsNoticeId }>
							<Notice status="error" isDismissible={ false } politeness="polite">
								{ __( 'This site does not use HTTPS. Newspack can’t be installed.', 'newspack-plugin' ) }
							</Notice>
						</div>
					) }
					<ApproachCards
						value={ approach }
						onChange={ value => {
							setApproach( value );
							setUrlError( '' );
							install.clearInitError();
						} }
					/>
					{ isMove && (
						<Stack direction="column" gap="sm">
							<TextControl
								ref={ urlRef }
								id={ urlFieldId }
								label={ __( 'Address of your current site', 'newspack-plugin' ) }
								help={ __( 'The site must be public, with its REST API turned on.', 'newspack-plugin' ) }
								placeholder="https://example.com"
								type="url"
								value={ siteUrl }
								aria-invalid={ fieldError ? true : undefined }
								aria-describedby={ fieldError ? `${ urlFieldId }__help ${ urlFieldId }__error` : `${ urlFieldId }__help` }
								onChange={ value => {
									setSiteUrl( value );
									setUrlError( '' );
									install.clearInitError();
								} }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							{ fieldError && (
								<div id={ `${ urlFieldId }__error` }>
									<Notice status="error" isDismissible={ false } spokenMessage="">
										{ fieldError }
									</Notice>
								</div>
							) }
						</Stack>
					) }
					{ ! isMove && (
						<CheckboxControl
							label={ __( 'Add sample stories', 'newspack-plugin' ) }
							help={ __( '40 placeholder articles, so you can see the design with content. Delete them any time.', 'newspack-plugin' ) }
							checked={ withStories }
							onChange={ setWithStories }
							__nextHasNoMarginBottom
						/>
					) }
				</Stack>
				{ initError && ! isMove && (
					<Notice status="error" isDismissible={ false } spokenMessage="">
						{ initError }
					</Notice>
				) }
			</NarrowLayout>
		);
	}

	return (
		<NarrowLayout
			footer={
				<StepFooter>
					<Button variant="primary" disabled={ ! canContinue } accessibleWhenDisabled __next40pxDefaultSize onClick={ onContinue }>
						{ counting
							? sprintf(
									/* translators: %d: seconds before the next step opens. */
									__( 'Continue (%d)', 'newspack-plugin' ),
									seconds
							  )
							: __( 'Continue', 'newspack-plugin' ) }
					</Button>
				</StepFooter>
			}
		>
			<StepHeading headingRef={ headingRef } title={ title } lede={ lede } />
			<Stack direction="column" gap="lg">
				{ status === 'running' && (
					<ProgressBar
						className="setup-progress"
						value={ install.progress }
						aria-label={ __( 'Installation progress', 'newspack-plugin' ) }
					/>
				) }
				<InstallChecklist rows={ install.rows } />
				{ failureText && (
					<Notice
						status={ status === 'blocked' ? 'error' : 'warning' }
						isDismissible={ false }
						spokenMessage=""
						actions={ [ { label: __( 'Try Again', 'newspack-plugin' ), onClick: install.retry } ] }
					>
						{ failureText }
					</Notice>
				) }
				{ save === 'failed' && (
					<Notice
						status="error"
						isDismissible={ false }
						actions={ [ { label: __( 'Try Again', 'newspack-plugin' ), onClick: retrySave } ] }
					>
						{ __( 'Couldn’t save the install.', 'newspack-plugin' ) }
					</Notice>
				) }
			</Stack>
		</NarrowLayout>
	);
}
