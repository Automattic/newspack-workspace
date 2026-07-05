/**
 * Extract a human-readable message from a caught value.
 *
 * `apiFetch` rejects with a plain `{ message, code }` object (not an
 * `Error` instance), so this reads `message` structurally rather than
 * gating on `instanceof Error`.
 *
 * @param error The caught value (typed `unknown`).
 * @return The message string, or `undefined` when none is present.
 */
export function errorMessage( error: unknown ): string | undefined {
	if ( error && typeof error === 'object' && 'message' in error ) {
		const message = error.message;
		return typeof message === 'string' ? message : undefined;
	}
	return undefined;
}
