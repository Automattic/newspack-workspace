import '../../shared/js/public-path';

import { createRoot, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Stack, VisuallyHidden } from '@wordpress/ui';

import Router from '../../../packages/components/src/proxied-imports/router';
import { fetchInitialCheck, type InitialCheck, type InstallState } from './api';
import NarrowLayout from './components/narrow-layout';
import SetupHeader from './components/setup-header';
import { STEPS, type SetupViewProps, type StepKey } from './steps';
import Start from './views/start';
import SiteDetails from './views/site-details';
import Design from './views/design';
import Done from './views/done';
import './style.scss';

const { HashRouter, Redirect, Route, Switch, useHistory, useLocation } = Router;

const VIEWS: Record< StepKey, ( props: SetupViewProps ) => JSX.Element > = {
	start: Start,
	'site-details': SiteDetails,
	design: Design,
	done: Done,
};

type Loaded = { initialCheck: InitialCheck; installState: InstallState };

const indexOf = ( key: StepKey ) => STEPS.findIndex( step => step.key === key );

const redirectFor = ( key: StepKey, installed: boolean, setupDone: boolean ): string | null => {
	if ( ! installed ) {
		return key === 'start' ? null : '/';
	}
	if ( key === 'start' ) {
		return '/site-details';
	}
	if ( key === 'done' && ! setupDone ) {
		return '/design';
	}
	return null;
};

function Shell( { initialCheck, installState: initialInstallState }: Loaded ) {
	const history = useHistory();
	const { pathname } = useLocation();
	const [ installState, setInstallState ] = useState< InstallState >( initialInstallState );
	const [ setupDone, setSetupDone ] = useState( () => !! window.newspack_aux_data?.has_completed_setup );
	const [ furthest, setFurthest ] = useState( () => ( initialInstallState.complete ? indexOf( 'site-details' ) : 0 ) );
	const canvasRef = useRef< HTMLDivElement >( null );
	const shownPath = useRef< string | null >( null );

	const current = STEPS.find( step => step.path === pathname )?.key ?? 'start';
	const redirect = redirectFor( current, installState.complete, setupDone );
	const reached = redirect ? furthest : Math.max( furthest, indexOf( current ) );
	const reachable: StepKey[] =
		installState.complete && current !== 'done'
			? STEPS.filter( ( step, index ) => index <= reached && step.key !== 'start' && step.key !== 'done' ).map( step => step.key )
			: [];

	useEffect( () => {
		if ( redirect ) {
			return;
		}
		setFurthest( reached );
		const previous = shownPath.current;
		shownPath.current = pathname;
		if ( previous === null || previous === pathname ) {
			return;
		}
		window.scrollTo( 0, 0 );
		canvasRef.current?.querySelector< HTMLElement >( 'h2' )?.focus( { preventScroll: true } );
	}, [ pathname, redirect, reached ] );

	const goTo = useCallback(
		( key: StepKey ) => {
			const step = STEPS.find( item => item.key === key );
			if ( step ) {
				history.push( step.path );
			}
		},
		[ history ]
	);

	const completeSetup = useCallback( () => {
		setSetupDone( true );
		goTo( 'done' );
	}, [ goTo ] );

	const viewProps: SetupViewProps = { initialCheck, installState, setInstallState, goTo, completeSetup };

	return (
		<>
			<SetupHeader current={ current } reachable={ reachable } onGo={ goTo } />
			<div className="setup-main">
				{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the VisuallyHidden children through @wordpress/ui's render prop. */ }
				<VisuallyHidden render={ <h1 /> }>{ __( 'Newspack setup', 'newspack-plugin' ) }</VisuallyHidden>
				<div className="setup-canvas" ref={ canvasRef }>
					<Stack direction="column" gap="2xl">
						{ window.newspack_aux_data?.has_completed_setup && (
							<NarrowLayout>
								<Notice status="info" isDismissible={ false } spokenMessage="">
									{ __( 'Heads up! The setup has already been completed. No need to run it again.', 'newspack-plugin' ) }
								</Notice>
							</NarrowLayout>
						) }
						<Switch>
							{ STEPS.map( ( { key, path } ) => {
								const View = VIEWS[ key ];
								return (
									<Route key={ key } exact path={ path }>
										{ redirect ? <Redirect to={ redirect } /> : <View { ...viewProps } /> }
									</Route>
								);
							} ) }
							<Redirect to="/" />
						</Switch>
					</Stack>
				</div>
			</div>
		</>
	);
}

export function SetupWizard() {
	const [ loaded, setLoaded ] = useState< Loaded | null >( null );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		fetchInitialCheck()
			.then( ( { install_state, ...initialCheck } ) => setLoaded( { initialCheck, installState: install_state } ) )
			.catch( () => setFailed( true ) );
	}, [] );

	if ( failed ) {
		return (
			<div className="setup-canvas">
				<Notice status="error" isDismissible={ false } politeness="polite">
					{ __( 'Could not load the setup. Reload the page to try again.', 'newspack-plugin' ) }
				</Notice>
			</div>
		);
	}

	if ( ! loaded ) {
		return null;
	}

	return (
		<HashRouter hashType="slash">
			<Shell { ...loaded } />
		</HashRouter>
	);
}

const rootElement = document.getElementById( 'newspack-setup-wizard' );
if ( rootElement ) {
	createRoot( rootElement ).render( <SetupWizard /> );
}
