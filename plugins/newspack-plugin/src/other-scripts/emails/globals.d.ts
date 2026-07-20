/**
 * Ambient declarations for the `emails` entry. Global-script form
 * (no top-level imports/exports) so every declaration lands in the global scope.
 */

/**
 * A single email config, as returned by Emails::get_email_configs()
 * (includes/emails/class-emails.php). Only the subset used by this entry is declared.
 */
interface NewspackEmailConfig {
	editor_notice?: string;
	from_name?: string;
	from_email?: string;
	available_placeholders?: {
		label: string;
		template: string;
	}[];
}

/**
 * Data localized by Emails (includes/emails/class-emails.php).
 */
declare const newspack_emails: {
	current_user_email: string;
	configs: Record< string, NewspackEmailConfig >;
	email_config_name_meta: string;
	from_name: string;
	from_email: string;
	reply_to_email: string;
};
