/**
 * Decide where the auth form's "Go Back" button returns to.
 *
 * From the one-time-code step, a reader who has a password returns to the password
 * step so they don't have to retype their email (NPPM-3054). Every other case returns
 * to the email ("signin") step, including "Go Back" from the password step itself,
 * which lets the reader change their email.
 *
 * @param {string}  formAction        Current step: 'signin' | 'pwd' | 'otp' | 'success'.
 * @param {boolean} readerHasPassword Whether the reader has a password on file.
 * @return {'pwd'|'signin'} The step to navigate back to.
 */
export const getBackTarget = ( formAction, readerHasPassword ) => ( formAction === 'otp' && readerHasPassword ? 'pwd' : 'signin' );
