/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Mailchimp Merge Tags.
 * https://mailchimp.com/help/all-the-merge-tags-cheat-sheet.
 */
export default [
	/**
	 * Campaigns.
	 */
	{
		tag: '*|ARCHIVE|*',
		label: __( 'Creates a "View this email in your browser link" to your campaign page.', 'newspack-newsletters' ),
	},
	{
		tag: '*|CAMPAIGN_UID|*',
		label: __( 'Displays the unique ID for your campaign.', 'newspack-newsletters' ),
	},
	{
		tag: '*|REWARDS|*',
		label: __( 'Adds the Referral badge to your campaign.', 'newspack-newsletters' ),
	},
	{
		tag: '*|REWARDS_TEXT|*',
		label: __( 'Adds a text-only version of the Rewards link.', 'newspack-newsletters' ),
	},
	{
		tag: '*|MC:TRANSLATE|*',
		label: __( 'Inserts links to translate your sent campaign into different languages.', 'newspack-newsletters' ),
	},
	{
		tag: '*|TRANSLATE:xx|*',
		label: __(
			"Adds a list of links to translate the content in your campaign. Replace xx with the code for the language your campaign is written in, and we'll display other available languages.",
			'newspack-newsletters'
		),
	},
	{
		tag: '*|MC_LANGUAGE|*',
		label: __(
			"Displays the language code for a particular subscriber. For example, if your subscriber's language is set to English, the merge tag output will display the code en.",
			'newspack-newsletters'
		),
	},
	{
		tag: '*|MC_LANGUAGE_LABEL|*',
		label: __(
			'Displays the plain-text language for a particular subscriber. All languages are written English, so if your subscriber\'s language is set to German we\'ll display "German" instead of Deutsch.',
			'newspack-newsletters'
		),
	},
	{
		tag: '*|DATE:X|*',
		label: __( 'Use to show the current date in a given format. Replace X with the format of your choice.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:RECENTX|*',
		label: __(
			'Displays a list of links to recent campaigns sent to the audience indicated. Replace X with the number of campaigns to show.',
			'newspack-newsletters'
		),
	},
	{
		tag: '*|MC:TOC|*',
		label: __( 'Creates a linked table of contents in your campaign.', 'newspack-newsletters' ),
	},
	{
		tag: '*|MC:TOC_TEXT|*',
		label: __( 'Creates a table of contents in your campaigns as plain-text.', 'newspack-newsletters' ),
	},
	{
		tag: '*|MC_PREVIEW_TEXT|*',
		label: __( 'Use this merge tag to generate preview text in a custom-coded campaign.', 'newspack-newsletters' ),
	},
	{
		tag: '*|POLL:RATING:x|* *|END:POLL|*',
		label: __( 'Creates a poll to record subscriber ratings of 1–10.', 'newspack-newsletters' ),
	},
	{
		tag: '*|SURVEY|* *|END:|*',
		label: __( 'Creates a one-question survey with a set number of responses that subscribers can choose from.', 'newspack-newsletters' ),
	},
	{
		tag: '*|PROMO_CODE:[$store_id=x, $rule_id=x, $code_id=x]|*',
		label: __(
			'Use this tag to include a promo code in a campaign. Replace the “x” variables in your Promo Code merge tag to specify what promo code to display.',
			'newspack-newsletters'
		),
	},
	/**
	 * Personalization.
	 */
	{
		tag: '*|FNAME|*',
		label: __( "Inserts your subscriber's first name if it's available in your audience.", 'newspack-newsletters' ),
		keywords: [ 'first name' ],
	},
	{
		tag: '*|LNAME|*',
		label: __( "Inserts your subscriber's last name if it's available in your audience.", 'newspack-newsletters' ),
		keywords: [ 'last name' ],
	},
	{
		tag: '*|EMAIL|*',
		label: __( "Inserts your subscriber's email address.", 'newspack-newsletters' ),
	},
	{
		tag: '*|PHONE|*',
		label: __( 'Inserts your subscriber’s phone number if it’s available in your audience.', 'newspack-newsletters' ),
	},
	{
		tag: '*|ADDRESS|*',
		label: __( 'Inserts your subscriber’s address if it’s available in your audience.', 'newspack-newsletters' ),
	},
	/**
	 * Email subject lines.
	 */
	{
		tag: '*|LIST:NAME|*',
		label: __( 'Inserts the name of your audience.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:COMPANY|*',
		label: __(
			"Inserts the name of your company or organization that's listed in the required email footer content for your audience.",
			'newspack-newsletters'
		),
	},
	{
		tag: '*|LIST:SUBSCRIBERS|*',
		label: __( 'Inserts the number of subscribers in your audience in plain-text.', 'newspack-newsletters' ),
	},
	{
		tag: '*|USER:COMPANY|*',
		label: __(
			'Inserts the company or organization name listed under Primary Account Contact info in your Mailchimp account.',
			'newspack-newsletters'
		),
	},
	{
		tag: '*|MC:DATE|*',
		label: __( 'Displays MM/DD/YYYY or DD/MM/YYYY based on your settings in your account Details.', 'newspack-newsletters' ),
	},
	{
		tag: '*|CURRENT_YEAR|*',
		label: __(
			'Displays the current year. This is great if you include a copyright date in your campaign, because it will update automatically every year.',
			'newspack-newsletters'
		),
	},
	/**
	 * Email footers.
	 */
	{
		tag: '*|UNSUB|*',
		label: __(
			"Gives your subscribers the opportunity to unsubscribe from your emails. (Required by law and Mailchimp's Terms of Use.).",
			'newspack-newsletters'
		),
	},
	{
		tag: '*|LIST:DESCRIPTION|*',
		label: __( "Inserts your audience's permission reminder", 'newspack-newsletters' ),
	},
	{
		tag: '*|HTML:LIST_ADDRESS_HTML|*',
		label: __(
			'Inserts in your mailing address and the "Add us to your address book" link that points to the vcard (.vcf) file with your address details.',
			'newspack-newsletters'
		),
	},
	{
		tag: '*|LIST:ADDRESS_VCARD|*',
		label: __( 'Inserts an "Add us to your address book" link to your campaign.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:ADDRESS_VCARD_HREF|*',
		label: __(
			"Inserts a text URL that points to your vcard (.vcf) file of your address details. Use this as a link's Web Address to create a linked version.",
			'newspack-newsletters'
		),
	},
	{
		tag: '*|LIST:NAME|*',
		label: __( 'Inserts the name of your audience.', 'newspack-newsletters' ),
	},
	{
		tag: '*|ABOUT_LIST|*',
		label: __( 'Creates a link to the About Your List page.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:UID|*',
		label: __( "Inserts your audience's unique ID from your audience's hosted forms.", 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:URL|*',
		label: __( 'Inserts the website URL set in the Required Email Footer Content for this audience.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:ADDRESS|*',
		label: __( 'Inserts your company or organization postal mailing address or P.O. Box as plain text.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:ADDRESSLINE|*',
		label: __( 'Inserts your mailing address as plain text on a single line.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:PHONE|*',
		label: __( 'Inserts your company or organization telephone number.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:COMPANY|*',
		label: __( 'Inserts your company or organization name.', 'newspack-newsletters' ),
	},
	{
		tag: '*|ABUSE_EMAIL|*',
		label: __( 'Inserts the email address located in the Required Email Footer Content for this audience.', 'newspack-newsletters' ),
	},
	{
		tag: '*|LIST:SUBSCRIBE|*',
		label: __( "Inserts the URL for your audience's hosted signup form.", 'newspack-newsletters' ),
	},
	{
		tag: '*|UPDATE_PROFILE|*',
		label: __( "Inserts a link to the contact's update profile page.", 'newspack-newsletters' ),
	},
	{
		tag: '*|FORWARD|*',
		label: __( "Inserts the URL to your audience's Forward to a Friend form.", 'newspack-newsletters' ),
	},
	/**
	 * Subscriber counts.
	 */
	{
		tag: '*|LIST:SUBSCRIBERS|*',
		label: __(
			'Displays a number. You can use this with a text blurb. For example, if you have 100 subscribers, and input "*|LIST:SUBSCRIBERS|* Happy Customers are currently enjoying our newsletters" in your campaign, we\'ll display "100 Happy Customers are currently enjoying our newsletters."',
			'newspack-newsletters'
		),
	},
	/**
	 * Social share.
	 */
	{
		tag: '*|TWITTER:FULLPROFILE|*',
		label: __(
			'Populates your campaign with your Twitter avatar, follower, tweet, and following counts; a follow link, and your latest tweets.',
			'newspack-newsletters'
		),
	},
	{
		tag: '*|TWITTER:PROFILE|*',
		label: __(
			"Populates your campaign with your Twitter avatar, follower, tweet, and following counts; and a follow link. Doesn't include your latest tweets.",
			'newspack-newsletters'
		),
	},
	{
		tag: '*|TWITTER:PROFILEURL|*',
		label: __( 'Displays your direct Twitter profile URL. For example: http://www.twitter.com/mailchimp.', 'newspack-newsletters' ),
	},
	{
		tag: '*|TWITTER:TWEETS2|*',
		label: __(
			"This lets you control the number of tweets to show in your campaign. Replace 2 with the number of tweets you'd like to display.",
			'newspack-newsletters'
		),
	},
	{
		tag: '*|TWITTER:PROFILE:TWITTERUSERNAME|*',
		label: __(
			'Can be used to insert multiple Twitter profiles in your Mailchimp campaign. Replace TWITTERUSERNAME with the Twitter display name of any profile you want to show in your campaign.',
			'newspack-newsletters'
		),
	},
	{
		tag: '*|TWITTER:TWEET|*',
		label: __( 'Adds a Tweet button to your campaign that allows subscribers to share your campaign page link.', 'newspack-newsletters' ),
	},
	{
		tag: '*|TWITTER:TWEET [$text=my custom text here]|*',
		label: __(
			'Includes your own custom text, as opposed to the subject line of your newsletter in your Tweet. Also includes a link to your campaign page.',
			'newspack-newsletters'
		),
	},
];
