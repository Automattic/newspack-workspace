/* globals newspack_ras_config, newspack_reader_data, gform */

window.newspack_ras_config = window.newspack_ras_config || {};

import Store from './store.js';
import { getPendingCheckout, setPendingCheckout } from './checkout.js';
import { EVENTS, on, off, emit } from './events.js';
import { getCookie, setCookie, generateID, debugLog } from './utils.js';
import overlays from './overlays.js';
import segments from './segments.js';
import initAnalytics from './analytics.js';
import setupArticleViewsAggregates from './article-view.js';
import setupEngagement from './engagement.js';
import initSubscriptionTiersForm from './subscription-tiers-form.js';
import { openAuthModal as _openAuthModal } from '../reader-activation-auth/auth-modal.js';
import { getApiNonce, hydrateSession } from './session.js';

/**
 * Reader Activation Library.
 */
export const store = Store();

/**
 * Dispatch reader activity.
 *
 * @param {string} action    Action name.
 * @param {Object} data      Data object.
 * @param {number} timestamp Timestamp. (optional)
 *
 * @return {Object} Activity.
 */
export function dispatchActivity( action, data, timestamp = 0 ) {
	const activity = { action, data, timestamp: timestamp || Date.now() };
	store.add( 'activity', activity );
	emit( EVENTS.activity, activity );
	return activity;
}

/**
 * Get all activities from a given action.
 *
 * @param {string} action
 *
 * @return {Array} Activities.
 */
export function getActivities( action ) {
	const activities = store.get( 'activity' ) || [];
	if ( ! action ) {
		return activities;
	}
	return activities.filter( activity => activity.action === action );
}

/**
 * Get all unique activities from a given action by a given iteratee.
 *
 * @param {string}          action   Action name.
 * @param {string|Function} iteratee Iteratee or a data key.
 *
 * @return {Array} Unique activities.
 */
export function getUniqueActivitiesBy( action, iteratee ) {
	const activities = getActivities( action );
	const unique = [];
	const seen = {};
	for ( const activity of activities ) {
		const value = typeof iteratee === 'function' ? iteratee( activity ) : activity.data[ iteratee ];
		if ( ! seen[ value ] ) {
			unique.push( activity );
			seen[ value ] = true;
		}
	}
	return unique;
}

/**
 * Reader functions.
 */

/**
 * Set the current reader.
 *
 * @param {string} email Email.
 */
export function setReaderEmail( email ) {
	if ( ! email ) {
		return;
	}
	const reader = getReader();
	reader.email = email;
	store.set( 'reader', reader, false );
	emit( EVENTS.reader, reader );
}

/**
 * Set whether the current reader is authenticated.
 *
 * @param {boolean} authenticated Whether the current reader is authenticated. Default is true.
 */
export function setAuthenticated( authenticated = true ) {
	const reader = store.get( 'reader' ) || {};
	if ( ! reader.email ) {
		throw new Error( 'Reader email not set' );
	}
	reader.authenticated = Boolean( authenticated );
	store.set( 'reader', reader, false );
	emit( EVENTS.reader, reader );
}

/**
 * Detect whether the current reader is authenticated.
 */
export function refreshAuthentication() {
	const email = getCookie( 'np_auth_reader' );
	if ( email ) {
		setReaderEmail( email );
		setAuthenticated( true );
	} else {
		setReaderEmail( getCookie( 'np_auth_intention' ) );
	}
}

/**
 * Get the current reader.
 *
 * @return {Object} Reader data.
 */
export function getReader() {
	return store.get( 'reader' ) || {};
}

/**
 * Whether the current reader has a valid email link attached to the session.
 *
 * @return {boolean} Whether the current reader has a valid email link attached to the session.
 */
export function hasAuthLink() {
	const reader = getReader();
	const otpHash = getCookie( 'np_otp_hash' );
	return !! ( reader?.email && otpHash );
}

const authStrategies = [ 'pwd', 'link' ];

/**
 * Start the authentication modal with an optional custom callback.
 *
 * @param {Object} config Config.
 */
export function openAuthModal( config = {} ) {
	config = {
		onSuccess: null,
		onDismiss: null,
		onError: null,
		initialState: null,
		skipSuccess: false,
		skipNewslettersSignup: false,
		labels: {
			signin: {
				title: window.newspack_reader_activation_labels.signin.title,
			},
			register: {
				title: window.newspack_reader_activation_labels.register.title,
			},
		},
		content: null,
		trigger: null,
		closeOnSuccess: true,
		...config,
	};

	if ( newspack_ras_config.is_logged_in ) {
		if ( config.onSuccess && typeof config.onSuccess === 'function' ) {
			config.onSuccess();
		}
		return;
	}
	_openAuthModal( config );
}

/**
 * Start the newsletter signup modal with an optional custom callback.
 *
 * @param {Object} config Config.
 */
export function openNewslettersSignupModal( config = {} ) {
	// Set default config.
	config = {
		...{
			onSuccess: null,
			onDismiss: null,
			onError: null,
			initialState: null,
			skipSuccess: false,
			labels: {},
			content: null,
			closeOnSuccess: true,
		},
		...config,
	};

	if ( readerActivation?._openNewslettersSignupModal ) {
		readerActivation._openNewslettersSignupModal( config );
	} else {
		console.warn( 'Newsletters signup modal not available' ); // eslint-disable-line no-console
		if ( config?.onError && typeof config.onError === 'function' ) {
			config.onError();
		}
	}
}

/**
 * Get the reader's OTP hash for the current authentication request.
 *
 * @return {string} OTP hash.
 */
export function getOTPHash() {
	return getCookie( 'np_otp_hash' );
}

/**
 * OTP timer storage key.
 */
const OTP_TIMER_STORAGE_KEY = 'newspack_otp_timer';

/**
 * Set the OTP timer to the current time.
 */
export function setOTPTimer() {
	localStorage.setItem( OTP_TIMER_STORAGE_KEY, Math.floor( Date.now() / 1000 ) );
}

/**
 * Clear the OTP timer.
 */
export function clearOTPTimer() {
	localStorage.removeItem( OTP_TIMER_STORAGE_KEY );
}

/**
 * Get the time remaining for the OTP timer.
 *
 * @return {number} Time remaining in seconds
 */
export function getOTPTimeRemaining() {
	const timer = localStorage.getItem( OTP_TIMER_STORAGE_KEY );
	if ( ! timer ) {
		return 0;
	}
	const timeRemaining = newspack_ras_config.otp_rate_interval - ( Math.floor( Date.now() / 1000 ) - timer );
	if ( ! timeRemaining ) {
		clearOTPTimer();
	}
	return timeRemaining > 0 ? timeRemaining : 0;
}

/**
 * Authenticate reader using an OTP code.
 *
 * @param {number} code OTP code.
 *
 * @return {Promise} Promise.
 */
export function authenticateOTP( code ) {
	return new Promise( ( resolve, reject ) => {
		const hash = getOTPHash();
		const email = getReader()?.email;
		if ( ! hash ) {
			return reject( { message: 'Code has expired', expired: true } );
		}
		if ( ! email ) {
			return reject( { message: 'You must provide an email' } );
		}
		if ( ! code ) {
			return reject( { message: 'Invalid code' } );
		}
		fetch( '', {
			method: 'POST',
			headers: {
				Accept: 'application/json',
			},
			body: new URLSearchParams( {
				action: newspack_ras_config.otp_auth_action,
				email,
				hash,
				code,
			} ),
		} )
			.then( response => response.json() )
			.then( ( { success, message, data } ) => {
				const payload = {
					...data,
					email,
					authenticated: !! success,
					message,
				};
				setAuthenticated( !! success );
				if ( success ) {
					resolve( payload );
				} else {
					reject( payload );
				}
			} );
	} );
}

/**
 * Set the reader preferred authentication strategy.
 *
 * @param {string} strategy Authentication strategy.
 *
 * @return {string} Reader preferred authentication strategy.
 */
export function setAuthStrategy( strategy ) {
	if ( ! authStrategies.includes( strategy ) ) {
		throw new Error( 'Invalid authentication strategy' );
	}
	setCookie( 'np_auth_strategy', strategy );
	return strategy;
}

/**
 * Get the reader preferred authentication strategy.
 *
 * @return {string} Reader preferred authentication strategy.
 */
export function getAuthStrategy() {
	if ( getOTPHash() ) {
		return 'otp';
	}
	return getCookie( 'np_auth_strategy' );
}

/**
 * Ensure the client ID cookie is set.
 */
function fixClientID() {
	const clientIDCookieName = newspack_ras_config.cid_cookie;
	if ( ! getCookie( clientIDCookieName ) ) {
		setCookie( clientIDCookieName, generateID( 12 ) );
	}
}

/**
 * Push activities coming from the server.
 */
function pushActivities() {
	const activity = newspack_reader_data?.reader_activity || [];
	activity.forEach( ( { action, data } ) => dispatchActivity( action, data ) );
}

/**
 * Store the referrer.
 */
function setReferrer() {
	const normalize = hostname =>
		hostname
			.trim()
			.toLowerCase()
			.replace( /^www\./, '' );
	const referrer = document.referrer ? normalize( new URL( document.referrer ).hostname ) : '';
	if ( referrer && referrer !== normalize( window.location.hostname ) ) {
		store.set( 'referrer', referrer );
	} else {
		store.set( 'referrer', '' );
	}
}

/**
 * Listen to cookie changes to detect authentication.
 */
function attachAuthCookiesListener() {
	// If the reader is already authenticated, bail.
	if ( getCookie( 'np_auth_reader' ) ) {
		return;
	}
	const interval = setInterval( () => {
		const reader = getReader();
		const intentionCookie = getCookie( 'np_auth_intention' );
		if ( intentionCookie && reader.email !== intentionCookie ) {
			setReaderEmail( intentionCookie );
		} else {
			const authCookie = getCookie( 'np_auth_reader' );
			if ( authCookie ) {
				setReaderEmail( authCookie );
				setAuthenticated( true );
				hydrateSession();
				clearInterval( interval );
			}
		}
	}, 1000 );
}

/**
 * Set the reader as newsletter subscriber once a newsletter form is submitted.
 */
function attachNewsletterFormListener() {
	const newspackForms = [ '.newspack-newsletters-subscribe' ];

	// newspack-subscribe-form is a generic class that can be added to any 3rd party form. Once it's submitted, we consider the reader a newsletter subscriber.
	const thirdPartyForms = [ '.mc4wp-form', '.newspack-subscribe-form' ];

	const attachHandler = ( el, eventToListenTo = 'submit' ) => {
		const form = 'FORM' === el.tagName ? el : el.querySelector( 'form' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( eventToListenTo, () => {
			store.set( 'is_newsletter_subscriber', true );
		} );
	};

	const gformIds = [];

	// For third-party forms, set reader data on form submit. Also detect Gravity Forms.
	document.querySelectorAll( thirdPartyForms.join( ',' ) ).forEach( el => {
		// If the form id stats with gform_ and it has a data-formid prop, it's a gravity form.
		const isGravityForm = el.id.startsWith( 'gform_' ) && el.hasAttribute( 'data-formid' );
		if ( isGravityForm ) {
			gformIds.push( parseInt( el.getAttribute( 'data-formid' ) ) );
			return;
		}
		// If not a gravity form, just attach the handler.
		attachHandler( el );
	} );

	// First-party forms success event listener.
	document.querySelectorAll( newspackForms.join( ',' ) ).forEach( el => attachHandler( el, 'newspack-newsletters-subscribe-success' ) );

	// Gravity forms handlers.
	document.addEventListener( 'gform/post_render', event => {
		if ( window.gform?.utils?.addAsyncFilter && gformIds.includes( event.detail?.formId ) ) {
			gform.utils.addAsyncFilter( 'gform/submission/pre_submission', async data => {
				store.set( 'is_newsletter_subscriber', true );
				return data;
			} );
		}
	} );
}

/**
 * Acquire a reCAPTCHA v2 invisible token.
 *
 * Renders a temporary invisible widget, executes it, and resolves
 * with the token. Cleans up the widget container after completion.
 *
 * @todo Consider adding an in-flight guard to coalesce concurrent calls,
 * since each invocation renders a separate widget and the reCAPTCHA API
 * may not handle multiple simultaneous invisible widgets well.
 *
 * @param {string} siteKey reCAPTCHA site key.
 * @return {Promise<string>} Resolves with the reCAPTCHA token.
 */
function acquireV2InvisibleToken( siteKey ) {
	return new Promise( function ( resolve, reject ) {
		const container = document.createElement( 'div' );
		container.style.display = 'none';
		document.body.appendChild( container );

		let settled = false;
		const timeout = setTimeout( function () {
			if ( ! settled ) {
				settled = true;
				container.remove();
				reject( new Error( 'reCAPTCHA timed out.' ) );
			}
		}, 30000 );

		function settle( fn, value ) {
			if ( settled ) {
				return;
			}
			settled = true;
			clearTimeout( timeout );
			container.remove();
			fn( value );
		}

		try {
			const widgetId = window.grecaptcha.render( container, {
				sitekey: siteKey,
				size: 'invisible',
				isolated: true,
				callback( token ) {
					settle( resolve, token );
				},
				'error-callback'() {
					settle( reject, new Error( 'reCAPTCHA challenge failed.' ) );
				},
				'expired-callback'() {
					settle( reject, new Error( 'reCAPTCHA token expired.' ) );
				},
			} );
			window.grecaptcha.execute( widgetId );
		} catch ( err ) {
			settle( reject, err );
		}
	} );
}

/**
 * Register a reader via a frontend integration.
 *
 * @param {string} email                  Reader email address.
 * @param {string} integrationId          Registered integration ID.
 * @param {Object} profileFields          Optional profile fields: { first_name, last_name, metadata }.
 * @param {Object} profileFields.metadata Optional arbitrary key-value pairs to store as user meta.
 * @return {Promise} Resolves with reader data on success, rejects with error on failure.
 */
function register( email, integrationId, profileFields = {} ) {
	const config = newspack_ras_config?.frontend_registration_integrations || {};
	const integration = config[ integrationId ];

	if ( ! integration ) {
		return Promise.reject( new Error( 'Unknown integration: ' + integrationId ) );
	}

	if ( ! email || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
		return Promise.reject( new Error( 'Invalid email address.' ) );
	}

	if ( ! newspack_ras_config?.frontend_registration_url ) {
		return Promise.reject( new Error( 'Registration is not available.' ) );
	}

	const body = {
		npe: email,
		integration_id: integrationId,
		integration_key: integration.key,
		first_name: profileFields.first_name || '',
		last_name: profileFields.last_name || '',
		metadata: profileFields.metadata || {},
	};

	// Acquire reCAPTCHA token if configured, using the appropriate version flow.
	const captchaSiteKey = newspack_ras_config?.captcha_site_key;
	const captchaVersion = newspack_ras_config?.captcha_version;
	let captchaPromise;

	if ( captchaSiteKey ) {
		if ( ! window.grecaptcha ) {
			return Promise.reject( new Error( 'reCAPTCHA is configured but not loaded.' ) );
		}
		if ( captchaVersion === 'v3' ) {
			captchaPromise = new Promise( function ( resolve, reject ) {
				window.grecaptcha.ready( function () {
					window.grecaptcha
						.execute( captchaSiteKey, {
							action: 'integration_registration',
						} )
						.then( resolve )
						.catch( reject );
				} );
			} );
		} else if ( captchaVersion && captchaVersion.substring( 0, 2 ) === 'v2' ) {
			captchaPromise = new Promise( function ( resolve, reject ) {
				window.grecaptcha.ready( function () {
					acquireV2InvisibleToken( captchaSiteKey ).then( resolve ).catch( reject );
				} );
			} );
		} else {
			captchaPromise = Promise.resolve( '' );
		}
	} else {
		captchaPromise = Promise.resolve( '' );
	}

	return captchaPromise
		.then( function ( token ) {
			if ( token ) {
				body[ 'g-recaptcha-response' ] = token;
			}
			const headers = { 'Content-Type': 'application/json' };
			const nonce = getApiNonce();
			if ( nonce ) {
				headers[ 'X-WP-Nonce' ] = nonce;
			}
			return fetch( newspack_ras_config.frontend_registration_url, {
				method: 'POST',
				headers,
				credentials: 'same-origin',
				body: JSON.stringify( body ),
			} );
		} )
		.then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					const error = new Error( data.message || 'Registration failed.' );
					error.code = data.code;
					throw error;
				}
				return data;
			} );
		} )
		.then( function ( data ) {
			const readerEmail = data.email || email;
			const reader = {
				...( store.get( 'reader' ) || {} ),
				email: readerEmail,
				authenticated: true,
			};
			store.set( 'reader', reader, false );
			emit( EVENTS.reader, reader );
			dispatchActivity( 'reader_registered', {
				email: readerEmail,
				integration_id: integrationId,
				status: data.status || 'created',
			} );
			return data;
		} )
		.catch( function ( error ) {
			dispatchActivity( 'reader_registration_failed', {
				email,
				integration_id: integrationId,
				error: error.code || 'network_error',
			} );
			throw error;
		} );
}

const readerActivation = {
	store,
	overlays,
	segments,
	on,
	off,
	dispatchActivity,
	getActivities,
	getUniqueActivitiesBy,
	setReaderEmail,
	setAuthenticated,
	refreshAuthentication,
	getReader,
	openNewslettersSignupModal,
	hasAuthLink,
	getOTPHash,
	setOTPTimer,
	clearOTPTimer,
	getOTPTimeRemaining,
	authenticateOTP,
	setAuthStrategy,
	getAuthStrategy,
	setPendingCheckout,
	getPendingCheckout,
	debugLog,
	register,
	...( newspack_ras_config.is_ras_enabled && { openAuthModal } ),
};

/**
 * Handle a push to the newspackRAS array.
 *
 * @param {...Array|Function} args Array to dispatchActivity reader activity or
 *                                 function to callback with the
 *                                 readerActivation object.
 */
function handlePush( ...args ) {
	args.forEach( arg => {
		if ( Array.isArray( arg ) && typeof arg[ 0 ] === 'string' ) {
			dispatchActivity( ...arg );
		} else if ( typeof arg === 'function' ) {
			arg( readerActivation );
		} else {
			console.warn( 'Invalid newspackRAS.push argument', arg ); // eslint-disable-line no-console
		}
	} );
}

/**
 * Initialize.
 */
function init() {
	const data = newspack_ras_config;
	const initialEmail = data?.authenticated_email || getCookie( 'np_auth_intention' );
	const authenticated = !! data?.authenticated_email;
	const currentReader = getReader();
	const reader = { email: initialEmail || currentReader?.email, authenticated };
	if ( currentReader?.email !== reader?.email || currentReader?.authenticated !== reader?.authenticated ) {
		store.set( 'reader', reader, false );
	}
	emit( EVENTS.reader, reader );
	initAnalytics( readerActivation );
	initSubscriptionTiersForm( readerActivation );
	fixClientID();
	setupArticleViewsAggregates( readerActivation );
	setupEngagement( readerActivation );
	attachAuthCookiesListener();
	attachNewsletterFormListener();
	pushActivities();
	setReferrer();

	window.newspackReaderActivation = readerActivation;

	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.forEach( arg => handlePush( arg ) );
	window.newspackRAS.push = handlePush;

	// Rehydrate after all synchronous strategy registrations, including
	// those from third parties via newspackRAS.push().
	store.rehydrate();

	window.newspackRASInitialized = true;
}

if ( ! window.newspackRASInitialized ) {
	init();
}

export default readerActivation;
