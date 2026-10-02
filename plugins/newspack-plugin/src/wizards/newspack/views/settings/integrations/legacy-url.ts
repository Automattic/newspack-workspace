/**
 * Maps a URL redirected from the old Audience > Integrations page onto the Settings tab.
 *
 * The old screen routed under `#/settings`, and links to it (newspack-manager's
 * Salesforce OAuth return among them) keep that fragment through the redirect.
 *
 * @param pathname Current pathname.
 * @param search   Current query string, including the leading `?`.
 * @param hash     Current fragment, including the leading `#`.
 * @return The URL to replace the current one with, or null when the request did not come from the old page.
 */
export function rewriteLegacyIntegrationsUrl( pathname: string, search: string, hash: string ): string | null {
	const params = new URLSearchParams( search );
	if ( ! params.has( 'legacy-integrations' ) ) {
		return null;
	}
	params.delete( 'legacy-integrations' );
	const suffix = hash.match( /^#\/settings(?=\/|\?|$)(.*)$/ )?.[ 1 ] ?? '';
	return `${ pathname }?${ params.toString() }#/integrations${ suffix }`;
}
