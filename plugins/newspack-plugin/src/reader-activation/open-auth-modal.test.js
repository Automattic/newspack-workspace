/**
 * The public `openAuthModal` normally refuses to open for a logged-in reader and
 * reports success instead. `skipAuthenticatedCheck` is the documented way past
 * that, for the one case where being logged in is not the same as being done:
 * the content gate's verification prompt, whose reader is authenticated but
 * unverified and still owes an OTP.
 *
 * Lives in its own file so the module mock does not reach the rest of the
 * reader-activation suite.
 */

jest.mock( '../reader-activation-auth/auth-modal.js', () => ( {
	openAuthModal: jest.fn(),
	getModalContainer: jest.fn(),
	SIGN_IN_MODAL_HASHES: [],
} ) );

import { openAuthModal } from './index';
import { openAuthModal as openModal } from '../reader-activation-auth/auth-modal.js';

describe( 'openAuthModal() for a logged-in reader', () => {
	beforeEach( () => {
		openModal.mockClear();
		window.newspack_ras_config = { is_logged_in: true };
		window.newspack_reader_activation_labels = {
			signin: { title: 'Sign in' },
			register: { title: 'Register' },
		};
	} );

	it( 'reports success without opening the modal by default', () => {
		const onSuccess = jest.fn();
		openAuthModal( { onSuccess } );
		expect( openModal ).not.toHaveBeenCalled();
		expect( onSuccess ).toHaveBeenCalled();
	} );

	it( 'opens the modal and leaves onSuccess to it when the check is skipped', () => {
		const onSuccess = jest.fn();
		openAuthModal( { onSuccess, skipAuthenticatedCheck: true, initialState: 'otp' } );
		expect( onSuccess ).not.toHaveBeenCalled();
		expect( openModal ).toHaveBeenCalledWith( expect.objectContaining( { skipAuthenticatedCheck: true, initialState: 'otp' } ) );
	} );
} );
