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

/**
 * Whether "email me a code" should reuse a one-time code already sent in this session
 * instead of requesting a new one from the server.
 *
 * A reader who already requested a code and returned to the password step can choose the
 * code again; reusing it shows the code-entry step without restarting the resend cooldown
 * or stranding the code already in their inbox (NPPM-3054). The resend button always
 * requests a new code, so it never reuses. The signal is a per-session "a code was sent"
 * flag, not the persistent np_otp_hash cookie (which lingers ~29 minutes across sessions
 * and would make a genuine first request skip the send).
 *
 * @param {boolean} isSendCodeButton Whether the "email me a code" button was clicked, not resend.
 * @param {boolean} codeAlreadySent  Whether a one-time code has already been sent in this session.
 * @return {boolean} True to reuse the existing code and skip the server request.
 */
export const shouldReuseActiveCode = ( isSendCodeButton, codeAlreadySent ) => isSendCodeButton && codeAlreadySent;
