<?php
/**
 * Useful functions.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Configuration_Managers;

defined( 'ABSPATH' ) || exit;

define( 'NEWSPACK_API_NAMESPACE', 'newspack/v1' );
define( 'NEWSPACK_API_NAMESPACE_V2', 'newspack/v2' );
define( 'NEWSPACK_API_URL', get_site_url() . '/wp-json/' . NEWSPACK_API_NAMESPACE );

/**
 * Clean variables using sanitize_text_field. Arrays are cleaned recursively.
 * Non-scalar values are ignored.
 *
 * @param string|array $var Data to sanitize.
 * @return string|array
 */
function newspack_clean( $var ) {
	if ( is_array( $var ) ) {
		return array_map( 'Newspack\newspack_clean', $var );
	} else {
		return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
	}
}

/**
 * Converts a string (e.g. 'yes' or 'no') to a bool.
 *
 * @param string $string String to convert.
 * @return bool
 */
function newspack_string_to_bool( $string ) {
	return is_bool( $string ) ? $string : ( 'yes' === $string || 1 === $string || 'true' === $string || '1' === $string );
}

/**
 * Best human-readable label for a user.
 *
 * Walks an ordered list of strategies and returns the first non-empty result.
 * Strategies: 'display_name', 'first_name', 'last_name', 'full_name', 'email', 'login'.
 *
 * Default cascade: display_name → full_name → login. Email is never used by
 * default to avoid leaking addresses; pass an explicit cascade to include it.
 * A real (non-email) label always wins. An email-valued candidate is only used
 * as a last resort and reduced to its local part (the bit before '@') unless the
 * strategy is explicitly 'email', so an email display_name/login never leaks in full.
 * For just the display name, pass `[ 'display_name' ]`.
 * For first name only, pass `[ 'first_name' ]`.
 *
 * @param \WP_User|int|null $user    User object or user ID.
 * @param string[]          $cascade Ordered strategies to try.
 * @return string
 */
function newspack_get_user_display_label( $user, $cascade = [ 'display_name', 'full_name', 'login' ] ) {
	if ( is_numeric( $user ) ) {
		$user = \get_userdata( (int) $user );
	}
	if ( ! $user instanceof \WP_User ) {
		return '';
	}
	$email_fallback = '';
	foreach ( $cascade as $strategy ) {
		$candidate = '';
		switch ( $strategy ) {
			case 'display_name':
				$candidate = trim( (string) $user->display_name );
				break;
			case 'first_name':
				$candidate = trim( (string) $user->first_name );
				break;
			case 'last_name':
				$candidate = trim( (string) $user->last_name );
				break;
			case 'full_name':
				$candidate = trim( $user->first_name . ' ' . $user->last_name );
				break;
			case 'email':
				$candidate = (string) $user->user_email;
				break;
			case 'login':
				$candidate = (string) $user->user_login;
				break;
		}
		if ( '' === $candidate ) {
			continue;
		}
		// A real label always wins. An email-valued display_name/login is held back
		// as a last resort, reduced to its local part so the full address never leaks.
		// The first email-shaped candidate in the cascade wins the fallback slot.
		if ( 'email' !== $strategy && is_email( $candidate ) ) {
			if ( '' === $email_fallback ) {
				$email_fallback = (string) strstr( $candidate, '@', true );
			}
			continue;
		}
		return $candidate;
	}
	return $email_fallback;
}

/**
 * Currencies options, copied from WooCommerce.
 * https://github.com/woocommerce/woocommerce/blob/trunk/includes/wc-core-functions.php
 */
function newspack_get_currencies_options() {
	$currencies       = [
		'AED' => __( 'United Arab Emirates dirham', 'newspack' ),
		'AFN' => __( 'Afghan afghani', 'newspack' ),
		'ALL' => __( 'Albanian lek', 'newspack' ),
		'AMD' => __( 'Armenian dram', 'newspack' ),
		'ANG' => __( 'Netherlands Antillean guilder', 'newspack' ),
		'AOA' => __( 'Angolan kwanza', 'newspack' ),
		'ARS' => __( 'Argentine peso', 'newspack' ),
		'AUD' => __( 'Australian dollar', 'newspack' ),
		'AWG' => __( 'Aruban florin', 'newspack' ),
		'AZN' => __( 'Azerbaijani manat', 'newspack' ),
		'BAM' => __( 'Bosnia and Herzegovina convertible mark', 'newspack' ),
		'BBD' => __( 'Barbadian dollar', 'newspack' ),
		'BDT' => __( 'Bangladeshi taka', 'newspack' ),
		'BGN' => __( 'Bulgarian lev', 'newspack' ),
		'BHD' => __( 'Bahraini dinar', 'newspack' ),
		'BIF' => __( 'Burundian franc', 'newspack' ),
		'BMD' => __( 'Bermudian dollar', 'newspack' ),
		'BND' => __( 'Brunei dollar', 'newspack' ),
		'BOB' => __( 'Bolivian boliviano', 'newspack' ),
		'BRL' => __( 'Brazilian real', 'newspack' ),
		'BSD' => __( 'Bahamian dollar', 'newspack' ),
		'BTC' => __( 'Bitcoin', 'newspack' ),
		'BTN' => __( 'Bhutanese ngultrum', 'newspack' ),
		'BWP' => __( 'Botswana pula', 'newspack' ),
		'BYR' => __( 'Belarusian ruble (old)', 'newspack' ),
		'BYN' => __( 'Belarusian ruble', 'newspack' ),
		'BZD' => __( 'Belize dollar', 'newspack' ),
		'CAD' => __( 'Canadian dollar', 'newspack' ),
		'CDF' => __( 'Congolese franc', 'newspack' ),
		'CHF' => __( 'Swiss franc', 'newspack' ),
		'CLP' => __( 'Chilean peso', 'newspack' ),
		'CNY' => __( 'Chinese yuan', 'newspack' ),
		'COP' => __( 'Colombian peso', 'newspack' ),
		'CRC' => __( 'Costa Rican col&oacute;n', 'newspack' ),
		'CUC' => __( 'Cuban convertible peso', 'newspack' ),
		'CUP' => __( 'Cuban peso', 'newspack' ),
		'CVE' => __( 'Cape Verdean escudo', 'newspack' ),
		'CZK' => __( 'Czech koruna', 'newspack' ),
		'DJF' => __( 'Djiboutian franc', 'newspack' ),
		'DKK' => __( 'Danish krone', 'newspack' ),
		'DOP' => __( 'Dominican peso', 'newspack' ),
		'DZD' => __( 'Algerian dinar', 'newspack' ),
		'EGP' => __( 'Egyptian pound', 'newspack' ),
		'ERN' => __( 'Eritrean nakfa', 'newspack' ),
		'ETB' => __( 'Ethiopian birr', 'newspack' ),
		'EUR' => __( 'Euro', 'newspack' ),
		'FJD' => __( 'Fijian dollar', 'newspack' ),
		'FKP' => __( 'Falkland Islands pound', 'newspack' ),
		'GBP' => __( 'Pound sterling', 'newspack' ),
		'GEL' => __( 'Georgian lari', 'newspack' ),
		'GGP' => __( 'Guernsey pound', 'newspack' ),
		'GHS' => __( 'Ghana cedi', 'newspack' ),
		'GIP' => __( 'Gibraltar pound', 'newspack' ),
		'GMD' => __( 'Gambian dalasi', 'newspack' ),
		'GNF' => __( 'Guinean franc', 'newspack' ),
		'GTQ' => __( 'Guatemalan quetzal', 'newspack' ),
		'GYD' => __( 'Guyanese dollar', 'newspack' ),
		'HKD' => __( 'Hong Kong dollar', 'newspack' ),
		'HNL' => __( 'Honduran lempira', 'newspack' ),
		'HRK' => __( 'Croatian kuna', 'newspack' ),
		'HTG' => __( 'Haitian gourde', 'newspack' ),
		'HUF' => __( 'Hungarian forint', 'newspack' ),
		'IDR' => __( 'Indonesian rupiah', 'newspack' ),
		'ILS' => __( 'Israeli new shekel', 'newspack' ),
		'IMP' => __( 'Manx pound', 'newspack' ),
		'INR' => __( 'Indian rupee', 'newspack' ),
		'IQD' => __( 'Iraqi dinar', 'newspack' ),
		'IRR' => __( 'Iranian rial', 'newspack' ),
		'IRT' => __( 'Iranian toman', 'newspack' ),
		'ISK' => __( 'Icelandic kr&oacute;na', 'newspack' ),
		'JEP' => __( 'Jersey pound', 'newspack' ),
		'JMD' => __( 'Jamaican dollar', 'newspack' ),
		'JOD' => __( 'Jordanian dinar', 'newspack' ),
		'JPY' => __( 'Japanese yen', 'newspack' ),
		'KES' => __( 'Kenyan shilling', 'newspack' ),
		'KGS' => __( 'Kyrgyzstani som', 'newspack' ),
		'KHR' => __( 'Cambodian riel', 'newspack' ),
		'KMF' => __( 'Comorian franc', 'newspack' ),
		'KPW' => __( 'North Korean won', 'newspack' ),
		'KRW' => __( 'South Korean won', 'newspack' ),
		'KWD' => __( 'Kuwaiti dinar', 'newspack' ),
		'KYD' => __( 'Cayman Islands dollar', 'newspack' ),
		'KZT' => __( 'Kazakhstani tenge', 'newspack' ),
		'LAK' => __( 'Lao kip', 'newspack' ),
		'LBP' => __( 'Lebanese pound', 'newspack' ),
		'LKR' => __( 'Sri Lankan rupee', 'newspack' ),
		'LRD' => __( 'Liberian dollar', 'newspack' ),
		'LSL' => __( 'Lesotho loti', 'newspack' ),
		'LYD' => __( 'Libyan dinar', 'newspack' ),
		'MAD' => __( 'Moroccan dirham', 'newspack' ),
		'MDL' => __( 'Moldovan leu', 'newspack' ),
		'MGA' => __( 'Malagasy ariary', 'newspack' ),
		'MKD' => __( 'Macedonian denar', 'newspack' ),
		'MMK' => __( 'Burmese kyat', 'newspack' ),
		'MNT' => __( 'Mongolian t&ouml;gr&ouml;g', 'newspack' ),
		'MOP' => __( 'Macanese pataca', 'newspack' ),
		'MRU' => __( 'Mauritanian ouguiya', 'newspack' ),
		'MUR' => __( 'Mauritian rupee', 'newspack' ),
		'MVR' => __( 'Maldivian rufiyaa', 'newspack' ),
		'MWK' => __( 'Malawian kwacha', 'newspack' ),
		'MXN' => __( 'Mexican peso', 'newspack' ),
		'MYR' => __( 'Malaysian ringgit', 'newspack' ),
		'MZN' => __( 'Mozambican metical', 'newspack' ),
		'NAD' => __( 'Namibian dollar', 'newspack' ),
		'NGN' => __( 'Nigerian naira', 'newspack' ),
		'NIO' => __( 'Nicaraguan c&oacute;rdoba', 'newspack' ),
		'NOK' => __( 'Norwegian krone', 'newspack' ),
		'NPR' => __( 'Nepalese rupee', 'newspack' ),
		'NZD' => __( 'New Zealand dollar', 'newspack' ),
		'OMR' => __( 'Omani rial', 'newspack' ),
		'PAB' => __( 'Panamanian balboa', 'newspack' ),
		'PEN' => __( 'Sol', 'newspack' ),
		'PGK' => __( 'Papua New Guinean kina', 'newspack' ),
		'PHP' => __( 'Philippine peso', 'newspack' ),
		'PKR' => __( 'Pakistani rupee', 'newspack' ),
		'PLN' => __( 'Polish z&#x142;oty', 'newspack' ),
		'PRB' => __( 'Transnistrian ruble', 'newspack' ),
		'PYG' => __( 'Paraguayan guaran&iacute;', 'newspack' ),
		'QAR' => __( 'Qatari riyal', 'newspack' ),
		'RON' => __( 'Romanian leu', 'newspack' ),
		'RSD' => __( 'Serbian dinar', 'newspack' ),
		'RUB' => __( 'Russian ruble', 'newspack' ),
		'RWF' => __( 'Rwandan franc', 'newspack' ),
		'SAR' => __( 'Saudi riyal', 'newspack' ),
		'SBD' => __( 'Solomon Islands dollar', 'newspack' ),
		'SCR' => __( 'Seychellois rupee', 'newspack' ),
		'SDG' => __( 'Sudanese pound', 'newspack' ),
		'SEK' => __( 'Swedish krona', 'newspack' ),
		'SGD' => __( 'Singapore dollar', 'newspack' ),
		'SHP' => __( 'Saint Helena pound', 'newspack' ),
		'SLL' => __( 'Sierra Leonean leone', 'newspack' ),
		'SOS' => __( 'Somali shilling', 'newspack' ),
		'SRD' => __( 'Surinamese dollar', 'newspack' ),
		'SSP' => __( 'South Sudanese pound', 'newspack' ),
		'STN' => __( 'S&atilde;o Tom&eacute; and Pr&iacute;ncipe dobra', 'newspack' ),
		'SYP' => __( 'Syrian pound', 'newspack' ),
		'SZL' => __( 'Swazi lilangeni', 'newspack' ),
		'THB' => __( 'Thai baht', 'newspack' ),
		'TJS' => __( 'Tajikistani somoni', 'newspack' ),
		'TMT' => __( 'Turkmenistan manat', 'newspack' ),
		'TND' => __( 'Tunisian dinar', 'newspack' ),
		'TOP' => __( 'Tongan pa&#x2bb;anga', 'newspack' ),
		'TRY' => __( 'Turkish lira', 'newspack' ),
		'TTD' => __( 'Trinidad and Tobago dollar', 'newspack' ),
		'TWD' => __( 'New Taiwan dollar', 'newspack' ),
		'TZS' => __( 'Tanzanian shilling', 'newspack' ),
		'UAH' => __( 'Ukrainian hryvnia', 'newspack' ),
		'UGX' => __( 'Ugandan shilling', 'newspack' ),
		'USD' => __( 'United States (US) dollar', 'newspack' ),
		'UYU' => __( 'Uruguayan peso', 'newspack' ),
		'UZS' => __( 'Uzbekistani som', 'newspack' ),
		'VEF' => __( 'Venezuelan bol&iacute;var', 'newspack' ),
		'VES' => __( 'Bol&iacute;var soberano', 'newspack' ),
		'VND' => __( 'Vietnamese &#x111;&#x1ed3;ng', 'newspack' ),
		'VUV' => __( 'Vanuatu vatu', 'newspack' ),
		'WST' => __( 'Samoan t&#x101;l&#x101;', 'newspack' ),
		'XAF' => __( 'Central African CFA franc', 'newspack' ),
		'XCD' => __( 'East Caribbean dollar', 'newspack' ),
		'XOF' => __( 'West African CFA franc', 'newspack' ),
		'XPF' => __( 'CFP franc', 'newspack' ),
		'YER' => __( 'Yemeni rial', 'newspack' ),
		'ZAR' => __( 'South African rand', 'newspack' ),
		'ZMW' => __( 'Zambian kwacha', 'newspack' ),
	];
	$currency_options = [
		[
			'value' => null,
			'label' => __( '-- Select --', 'newspack' ),
		],
	];
	foreach ( $currencies as $code => $currency ) {
		$currency_options[] = [
			'value' => $code,
			'label' => html_entity_decode( $currency ),
		];
	}
	return $currency_options;
}

/**
 * Currencies options, copied from WooCommerce.
 * https://github.com/woocommerce/woocommerce/blob/727ccd0dd3/plugins/woocommerce/i18n/countries.php
 */
function newspack_get_countries() {
	$countries         = [
		'AF' => __( 'Afghanistan', 'newspack' ),
		'AX' => __( 'Åland Islands', 'newspack' ),
		'AL' => __( 'Albania', 'newspack' ),
		'DZ' => __( 'Algeria', 'newspack' ),
		'AS' => __( 'American Samoa', 'newspack' ),
		'AD' => __( 'Andorra', 'newspack' ),
		'AO' => __( 'Angola', 'newspack' ),
		'AI' => __( 'Anguilla', 'newspack' ),
		'AQ' => __( 'Antarctica', 'newspack' ),
		'AG' => __( 'Antigua and Barbuda', 'newspack' ),
		'AR' => __( 'Argentina', 'newspack' ),
		'AM' => __( 'Armenia', 'newspack' ),
		'AW' => __( 'Aruba', 'newspack' ),
		'AU' => __( 'Australia', 'newspack' ),
		'AT' => __( 'Austria', 'newspack' ),
		'AZ' => __( 'Azerbaijan', 'newspack' ),
		'BS' => __( 'Bahamas', 'newspack' ),
		'BH' => __( 'Bahrain', 'newspack' ),
		'BD' => __( 'Bangladesh', 'newspack' ),
		'BB' => __( 'Barbados', 'newspack' ),
		'BY' => __( 'Belarus', 'newspack' ),
		'BE' => __( 'Belgium', 'newspack' ),
		'PW' => __( 'Belau', 'newspack' ),
		'BZ' => __( 'Belize', 'newspack' ),
		'BJ' => __( 'Benin', 'newspack' ),
		'BM' => __( 'Bermuda', 'newspack' ),
		'BT' => __( 'Bhutan', 'newspack' ),
		'BO' => __( 'Bolivia', 'newspack' ),
		'BQ' => __( 'Bonaire, Saint Eustatius and Saba', 'newspack' ),
		'BA' => __( 'Bosnia and Herzegovina', 'newspack' ),
		'BW' => __( 'Botswana', 'newspack' ),
		'BV' => __( 'Bouvet Island', 'newspack' ),
		'BR' => __( 'Brazil', 'newspack' ),
		'IO' => __( 'British Indian Ocean Territory', 'newspack' ),
		'BN' => __( 'Brunei', 'newspack' ),
		'BG' => __( 'Bulgaria', 'newspack' ),
		'BF' => __( 'Burkina Faso', 'newspack' ),
		'BI' => __( 'Burundi', 'newspack' ),
		'KH' => __( 'Cambodia', 'newspack' ),
		'CM' => __( 'Cameroon', 'newspack' ),
		'CA' => __( 'Canada', 'newspack' ),
		'CV' => __( 'Cape Verde', 'newspack' ),
		'KY' => __( 'Cayman Islands', 'newspack' ),
		'CF' => __( 'Central African Republic', 'newspack' ),
		'TD' => __( 'Chad', 'newspack' ),
		'CL' => __( 'Chile', 'newspack' ),
		'CN' => __( 'China', 'newspack' ),
		'CX' => __( 'Christmas Island', 'newspack' ),
		'CC' => __( 'Cocos (Keeling) Islands', 'newspack' ),
		'CO' => __( 'Colombia', 'newspack' ),
		'KM' => __( 'Comoros', 'newspack' ),
		'CG' => __( 'Congo (Brazzaville)', 'newspack' ),
		'CD' => __( 'Congo (Kinshasa)', 'newspack' ),
		'CK' => __( 'Cook Islands', 'newspack' ),
		'CR' => __( 'Costa Rica', 'newspack' ),
		'HR' => __( 'Croatia', 'newspack' ),
		'CU' => __( 'Cuba', 'newspack' ),
		'CW' => __( 'Cura&ccedil;ao', 'newspack' ),
		'CY' => __( 'Cyprus', 'newspack' ),
		'CZ' => __( 'Czech Republic', 'newspack' ),
		'DK' => __( 'Denmark', 'newspack' ),
		'DJ' => __( 'Djibouti', 'newspack' ),
		'DM' => __( 'Dominica', 'newspack' ),
		'DO' => __( 'Dominican Republic', 'newspack' ),
		'EC' => __( 'Ecuador', 'newspack' ),
		'EG' => __( 'Egypt', 'newspack' ),
		'SV' => __( 'El Salvador', 'newspack' ),
		'GQ' => __( 'Equatorial Guinea', 'newspack' ),
		'ER' => __( 'Eritrea', 'newspack' ),
		'EE' => __( 'Estonia', 'newspack' ),
		'ET' => __( 'Ethiopia', 'newspack' ),
		'FK' => __( 'Falkland Islands', 'newspack' ),
		'FO' => __( 'Faroe Islands', 'newspack' ),
		'FJ' => __( 'Fiji', 'newspack' ),
		'FI' => __( 'Finland', 'newspack' ),
		'FR' => __( 'France', 'newspack' ),
		'GF' => __( 'French Guiana', 'newspack' ),
		'PF' => __( 'French Polynesia', 'newspack' ),
		'TF' => __( 'French Southern Territories', 'newspack' ),
		'GA' => __( 'Gabon', 'newspack' ),
		'GM' => __( 'Gambia', 'newspack' ),
		'GE' => __( 'Georgia', 'newspack' ),
		'DE' => __( 'Germany', 'newspack' ),
		'GH' => __( 'Ghana', 'newspack' ),
		'GI' => __( 'Gibraltar', 'newspack' ),
		'GR' => __( 'Greece', 'newspack' ),
		'GL' => __( 'Greenland', 'newspack' ),
		'GD' => __( 'Grenada', 'newspack' ),
		'GP' => __( 'Guadeloupe', 'newspack' ),
		'GU' => __( 'Guam', 'newspack' ),
		'GT' => __( 'Guatemala', 'newspack' ),
		'GG' => __( 'Guernsey', 'newspack' ),
		'GN' => __( 'Guinea', 'newspack' ),
		'GW' => __( 'Guinea-Bissau', 'newspack' ),
		'GY' => __( 'Guyana', 'newspack' ),
		'HT' => __( 'Haiti', 'newspack' ),
		'HM' => __( 'Heard Island and McDonald Islands', 'newspack' ),
		'HN' => __( 'Honduras', 'newspack' ),
		'HK' => __( 'Hong Kong', 'newspack' ),
		'HU' => __( 'Hungary', 'newspack' ),
		'IS' => __( 'Iceland', 'newspack' ),
		'IN' => __( 'India', 'newspack' ),
		'ID' => __( 'Indonesia', 'newspack' ),
		'IR' => __( 'Iran', 'newspack' ),
		'IQ' => __( 'Iraq', 'newspack' ),
		'IE' => __( 'Ireland', 'newspack' ),
		'IM' => __( 'Isle of Man', 'newspack' ),
		'IL' => __( 'Israel', 'newspack' ),
		'IT' => __( 'Italy', 'newspack' ),
		'CI' => __( 'Ivory Coast', 'newspack' ),
		'JM' => __( 'Jamaica', 'newspack' ),
		'JP' => __( 'Japan', 'newspack' ),
		'JE' => __( 'Jersey', 'newspack' ),
		'JO' => __( 'Jordan', 'newspack' ),
		'KZ' => __( 'Kazakhstan', 'newspack' ),
		'KE' => __( 'Kenya', 'newspack' ),
		'KI' => __( 'Kiribati', 'newspack' ),
		'KW' => __( 'Kuwait', 'newspack' ),
		'KG' => __( 'Kyrgyzstan', 'newspack' ),
		'LA' => __( 'Laos', 'newspack' ),
		'LV' => __( 'Latvia', 'newspack' ),
		'LB' => __( 'Lebanon', 'newspack' ),
		'LS' => __( 'Lesotho', 'newspack' ),
		'LR' => __( 'Liberia', 'newspack' ),
		'LY' => __( 'Libya', 'newspack' ),
		'LI' => __( 'Liechtenstein', 'newspack' ),
		'LT' => __( 'Lithuania', 'newspack' ),
		'LU' => __( 'Luxembourg', 'newspack' ),
		'MO' => __( 'Macao', 'newspack' ),
		'MK' => __( 'North Macedonia', 'newspack' ),
		'MG' => __( 'Madagascar', 'newspack' ),
		'MW' => __( 'Malawi', 'newspack' ),
		'MY' => __( 'Malaysia', 'newspack' ),
		'MV' => __( 'Maldives', 'newspack' ),
		'ML' => __( 'Mali', 'newspack' ),
		'MT' => __( 'Malta', 'newspack' ),
		'MH' => __( 'Marshall Islands', 'newspack' ),
		'MQ' => __( 'Martinique', 'newspack' ),
		'MR' => __( 'Mauritania', 'newspack' ),
		'MU' => __( 'Mauritius', 'newspack' ),
		'YT' => __( 'Mayotte', 'newspack' ),
		'MX' => __( 'Mexico', 'newspack' ),
		'FM' => __( 'Micronesia', 'newspack' ),
		'MD' => __( 'Moldova', 'newspack' ),
		'MC' => __( 'Monaco', 'newspack' ),
		'MN' => __( 'Mongolia', 'newspack' ),
		'ME' => __( 'Montenegro', 'newspack' ),
		'MS' => __( 'Montserrat', 'newspack' ),
		'MA' => __( 'Morocco', 'newspack' ),
		'MZ' => __( 'Mozambique', 'newspack' ),
		'MM' => __( 'Myanmar', 'newspack' ),
		'NA' => __( 'Namibia', 'newspack' ),
		'NR' => __( 'Nauru', 'newspack' ),
		'NP' => __( 'Nepal', 'newspack' ),
		'NL' => __( 'Netherlands', 'newspack' ),
		'NC' => __( 'New Caledonia', 'newspack' ),
		'NZ' => __( 'New Zealand', 'newspack' ),
		'NI' => __( 'Nicaragua', 'newspack' ),
		'NE' => __( 'Niger', 'newspack' ),
		'NG' => __( 'Nigeria', 'newspack' ),
		'NU' => __( 'Niue', 'newspack' ),
		'NF' => __( 'Norfolk Island', 'newspack' ),
		'MP' => __( 'Northern Mariana Islands', 'newspack' ),
		'KP' => __( 'North Korea', 'newspack' ),
		'NO' => __( 'Norway', 'newspack' ),
		'OM' => __( 'Oman', 'newspack' ),
		'PK' => __( 'Pakistan', 'newspack' ),
		'PS' => __( 'Palestinian Territory', 'newspack' ),
		'PA' => __( 'Panama', 'newspack' ),
		'PG' => __( 'Papua New Guinea', 'newspack' ),
		'PY' => __( 'Paraguay', 'newspack' ),
		'PE' => __( 'Peru', 'newspack' ),
		'PH' => __( 'Philippines', 'newspack' ),
		'PN' => __( 'Pitcairn', 'newspack' ),
		'PL' => __( 'Poland', 'newspack' ),
		'PT' => __( 'Portugal', 'newspack' ),
		'PR' => __( 'Puerto Rico', 'newspack' ),
		'QA' => __( 'Qatar', 'newspack' ),
		'RE' => __( 'Reunion', 'newspack' ),
		'RO' => __( 'Romania', 'newspack' ),
		'RU' => __( 'Russia', 'newspack' ),
		'RW' => __( 'Rwanda', 'newspack' ),
		'BL' => __( 'Saint Barth&eacute;lemy', 'newspack' ),
		'SH' => __( 'Saint Helena', 'newspack' ),
		'KN' => __( 'Saint Kitts and Nevis', 'newspack' ),
		'LC' => __( 'Saint Lucia', 'newspack' ),
		'MF' => __( 'Saint Martin (French part)', 'newspack' ),
		'SX' => __( 'Saint Martin (Dutch part)', 'newspack' ),
		'PM' => __( 'Saint Pierre and Miquelon', 'newspack' ),
		'VC' => __( 'Saint Vincent and the Grenadines', 'newspack' ),
		'SM' => __( 'San Marino', 'newspack' ),
		'ST' => __( 'S&atilde;o Tom&eacute; and Pr&iacute;ncipe', 'newspack' ),
		'SA' => __( 'Saudi Arabia', 'newspack' ),
		'SN' => __( 'Senegal', 'newspack' ),
		'RS' => __( 'Serbia', 'newspack' ),
		'SC' => __( 'Seychelles', 'newspack' ),
		'SL' => __( 'Sierra Leone', 'newspack' ),
		'SG' => __( 'Singapore', 'newspack' ),
		'SK' => __( 'Slovakia', 'newspack' ),
		'SI' => __( 'Slovenia', 'newspack' ),
		'SB' => __( 'Solomon Islands', 'newspack' ),
		'SO' => __( 'Somalia', 'newspack' ),
		'ZA' => __( 'South Africa', 'newspack' ),
		'GS' => __( 'South Georgia/Sandwich Islands', 'newspack' ),
		'KR' => __( 'South Korea', 'newspack' ),
		'SS' => __( 'South Sudan', 'newspack' ),
		'ES' => __( 'Spain', 'newspack' ),
		'LK' => __( 'Sri Lanka', 'newspack' ),
		'SD' => __( 'Sudan', 'newspack' ),
		'SR' => __( 'Suriname', 'newspack' ),
		'SJ' => __( 'Svalbard and Jan Mayen', 'newspack' ),
		'SZ' => __( 'Swaziland', 'newspack' ),
		'SE' => __( 'Sweden', 'newspack' ),
		'CH' => __( 'Switzerland', 'newspack' ),
		'SY' => __( 'Syria', 'newspack' ),
		'TW' => __( 'Taiwan', 'newspack' ),
		'TJ' => __( 'Tajikistan', 'newspack' ),
		'TZ' => __( 'Tanzania', 'newspack' ),
		'TH' => __( 'Thailand', 'newspack' ),
		'TL' => __( 'Timor-Leste', 'newspack' ),
		'TG' => __( 'Togo', 'newspack' ),
		'TK' => __( 'Tokelau', 'newspack' ),
		'TO' => __( 'Tonga', 'newspack' ),
		'TT' => __( 'Trinidad and Tobago', 'newspack' ),
		'TN' => __( 'Tunisia', 'newspack' ),
		'TR' => __( 'Turkey', 'newspack' ),
		'TM' => __( 'Turkmenistan', 'newspack' ),
		'TC' => __( 'Turks and Caicos Islands', 'newspack' ),
		'TV' => __( 'Tuvalu', 'newspack' ),
		'UG' => __( 'Uganda', 'newspack' ),
		'UA' => __( 'Ukraine', 'newspack' ),
		'AE' => __( 'United Arab Emirates', 'newspack' ),
		'GB' => __( 'United Kingdom (UK)', 'newspack' ),
		'US' => __( 'United States (US)', 'newspack' ),
		'UM' => __( 'United States (US) Minor Outlying Islands', 'newspack' ),
		'UY' => __( 'Uruguay', 'newspack' ),
		'UZ' => __( 'Uzbekistan', 'newspack' ),
		'VU' => __( 'Vanuatu', 'newspack' ),
		'VA' => __( 'Vatican', 'newspack' ),
		'VE' => __( 'Venezuela', 'newspack' ),
		'VN' => __( 'Vietnam', 'newspack' ),
		'VG' => __( 'Virgin Islands (British)', 'newspack' ),
		'VI' => __( 'Virgin Islands (US)', 'newspack' ),
		'WF' => __( 'Wallis and Futuna', 'newspack' ),
		'EH' => __( 'Western Sahara', 'newspack' ),
		'WS' => __( 'Samoa', 'newspack' ),
		'YE' => __( 'Yemen', 'newspack' ),
		'ZM' => __( 'Zambia', 'newspack' ),
		'ZW' => __( 'Zimbabwe', 'newspack' ),
	];
	$countries_options = [
		[
			'value' => null,
			'label' => __( '-- Select --', 'newspack' ),
		],
	];
	foreach ( $countries as $code => $country ) {
		$countries_options[] = [
			'value' => $code,
			'label' => $country,
		];
	}
	return $countries_options;
}

/**
 * Default currency per country, copied from WooCommerce.
 * https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/i18n/locale-info.php
 *
 * @return array<string,string> ISO country code to ISO currency code.
 */
function newspack_get_country_currencies() {
	return [
		'AD' => 'EUR',
		'AE' => 'AED',
		'AF' => 'AFN',
		'AG' => 'XCD',
		'AI' => 'XCD',
		'AL' => 'ALL',
		'AM' => 'AMD',
		'AO' => 'AOA',
		'AR' => 'ARS',
		'AS' => 'USD',
		'AT' => 'EUR',
		'AU' => 'AUD',
		'AW' => 'AWG',
		'AX' => 'EUR',
		'AZ' => 'AZN',
		'BA' => 'BAM',
		'BB' => 'BBD',
		'BD' => 'BDT',
		'BE' => 'EUR',
		'BF' => 'XOF',
		'BG' => 'EUR',
		'BH' => 'BHD',
		'BI' => 'BIF',
		'BJ' => 'XOF',
		'BL' => 'EUR',
		'BM' => 'BMD',
		'BN' => 'BND',
		'BO' => 'BOB',
		'BQ' => 'USD',
		'BR' => 'BRL',
		'BS' => 'BSD',
		'BT' => 'BTN',
		'BW' => 'BWP',
		'BY' => 'BYN',
		'BZ' => 'BZD',
		'CA' => 'CAD',
		'CC' => 'AUD',
		'CD' => 'CDF',
		'CF' => 'XAF',
		'CG' => 'XAF',
		'CH' => 'CHF',
		'CI' => 'XOF',
		'CK' => 'NZD',
		'CL' => 'CLP',
		'CM' => 'XAF',
		'CN' => 'CNY',
		'CO' => 'COP',
		'CR' => 'CRC',
		'CU' => 'CUC',
		'CV' => 'CVE',
		'CW' => 'ANG',
		'CX' => 'AUD',
		'CY' => 'EUR',
		'CZ' => 'CZK',
		'DE' => 'EUR',
		'DG' => 'USD',
		'DJ' => 'DJF',
		'DK' => 'DKK',
		'DM' => 'XCD',
		'DO' => 'DOP',
		'DZ' => 'DZD',
		'EA' => 'EUR',
		'EC' => 'USD',
		'EE' => 'EUR',
		'EG' => 'EGP',
		'EH' => 'MAD',
		'ER' => 'ERN',
		'ES' => 'EUR',
		'ET' => 'ETB',
		'FI' => 'EUR',
		'FJ' => 'FJD',
		'FK' => 'FKP',
		'FM' => 'USD',
		'FO' => 'DKK',
		'FR' => 'EUR',
		'GA' => 'XAF',
		'GB' => 'GBP',
		'GD' => 'XCD',
		'GE' => 'GEL',
		'GF' => 'EUR',
		'GG' => 'GBP',
		'GH' => 'GHS',
		'GI' => 'GIP',
		'GL' => 'DKK',
		'GM' => 'GMD',
		'GN' => 'GNF',
		'GP' => 'EUR',
		'GQ' => 'XAF',
		'GR' => 'EUR',
		'GT' => 'GTQ',
		'GU' => 'USD',
		'GW' => 'XOF',
		'GY' => 'GYD',
		'HK' => 'HKD',
		'HN' => 'HNL',
		'HR' => 'EUR',
		'HT' => 'USD',
		'HU' => 'HUF',
		'IC' => 'EUR',
		'ID' => 'IDR',
		'IE' => 'EUR',
		'IL' => 'ILS',
		'IM' => 'GBP',
		'IN' => 'INR',
		'IO' => 'USD',
		'IQ' => 'IQD',
		'IR' => 'IRR',
		'IS' => 'ISK',
		'IT' => 'EUR',
		'JE' => 'GBP',
		'JM' => 'JMD',
		'JO' => 'JOD',
		'JP' => 'JPY',
		'KE' => 'KES',
		'KG' => 'KGS',
		'KH' => 'KHR',
		'KI' => 'AUD',
		'KM' => 'KMF',
		'KN' => 'XCD',
		'KP' => 'KPW',
		'KR' => 'KRW',
		'KW' => 'KWD',
		'KY' => 'KYD',
		'KZ' => 'KZT',
		'LA' => 'LAK',
		'LB' => 'LBP',
		'LC' => 'XCD',
		'LI' => 'CHF',
		'LK' => 'LKR',
		'LR' => 'LRD',
		'LS' => 'LSL',
		'LT' => 'EUR',
		'LU' => 'EUR',
		'LV' => 'EUR',
		'LY' => 'LYD',
		'MA' => 'MAD',
		'MC' => 'EUR',
		'MD' => 'MDL',
		'ME' => 'EUR',
		'MF' => 'EUR',
		'MG' => 'MGA',
		'MH' => 'USD',
		'MK' => 'MKD',
		'ML' => 'XOF',
		'MM' => 'MMK',
		'MN' => 'MNT',
		'MO' => 'MOP',
		'MP' => 'USD',
		'MQ' => 'EUR',
		'MR' => 'MRU',
		'MS' => 'XCD',
		'MT' => 'EUR',
		'MU' => 'MUR',
		'MV' => 'MVR',
		'MW' => 'MWK',
		'MX' => 'MXN',
		'MY' => 'MYR',
		'MZ' => 'MZN',
		'NA' => 'NAD',
		'NC' => 'XPF',
		'NE' => 'XOF',
		'NF' => 'AUD',
		'NG' => 'NGN',
		'NI' => 'NIO',
		'NL' => 'EUR',
		'NO' => 'NOK',
		'NP' => 'NPR',
		'NR' => 'AUD',
		'NU' => 'NZD',
		'NZ' => 'NZD',
		'OM' => 'OMR',
		'PA' => 'USD',
		'PE' => 'PEN',
		'PF' => 'XPF',
		'PG' => 'PGK',
		'PH' => 'PHP',
		'PK' => 'PKR',
		'PL' => 'PLN',
		'PM' => 'EUR',
		'PN' => 'NZD',
		'PR' => 'USD',
		'PS' => 'JOD',
		'PT' => 'EUR',
		'PW' => 'USD',
		'PY' => 'PYG',
		'QA' => 'QAR',
		'RE' => 'EUR',
		'RO' => 'RON',
		'RS' => 'RSD',
		'RU' => 'RUB',
		'RW' => 'RWF',
		'SA' => 'SAR',
		'SB' => 'SBD',
		'SC' => 'SCR',
		'SD' => 'SDG',
		'SE' => 'SEK',
		'SG' => 'SGD',
		'SH' => 'SHP',
		'SI' => 'EUR',
		'SJ' => 'NOK',
		'SK' => 'EUR',
		'SL' => 'SLL',
		'SM' => 'EUR',
		'SN' => 'XOF',
		'SO' => 'SOS',
		'SR' => 'SRD',
		'SS' => 'SSP',
		'ST' => 'STN',
		'SV' => 'USD',
		'SX' => 'ANG',
		'SY' => 'SYP',
		'SZ' => 'SZL',
		'TC' => 'USD',
		'TD' => 'XAF',
		'TG' => 'XOF',
		'TH' => 'THB',
		'TJ' => 'TJS',
		'TK' => 'NZD',
		'TL' => 'USD',
		'TM' => 'TMT',
		'TN' => 'TND',
		'TO' => 'TOP',
		'TR' => 'TRY',
		'TT' => 'TTD',
		'TV' => 'AUD',
		'TW' => 'TWD',
		'TZ' => 'TZS',
		'UA' => 'UAH',
		'UG' => 'UGX',
		'UM' => 'USD',
		'US' => 'USD',
		'UY' => 'UYU',
		'UZ' => 'UZS',
		'VA' => 'EUR',
		'VC' => 'XCD',
		'VE' => 'VES',
		'VG' => 'USD',
		'VI' => 'USD',
		'VN' => 'VND',
		'VU' => 'VUV',
		'WF' => 'XPF',
		'WS' => 'WST',
		'XK' => 'EUR',
		'YE' => 'YER',
		'YT' => 'EUR',
		'ZA' => 'ZAR',
		'ZM' => 'ZMW',
		'ZW' => 'USD',
	];
}


/**
 * Determine Google Site Kit availability.
 *
 * @return bool True if available, false otherwise.
 */
function google_site_kit_available() {
	return get_option( 'googlesitekit_has_connected_admins' ) && in_array( 'analytics', get_option( 'googlesitekit_active_modules', [] ) );
}

/**
 * Determine if a plugin is active. Similar to WP core `is_plugin_active` but is available immediately.
 *
 * @param string $plugin_file `plugin-directory/plugin-file.php` path to the plugin file.
 * @return bool
 */
function is_plugin_active( string $plugin_file ) {
	return in_array( $plugin_file, get_option( 'active_plugins' ), true );
}

/**
 * Flush rendered pages from the page cache, so a change to site-wide output reaches
 * readers now rather than when their cached pages expire.
 *
 * Batcache stores rendered pages in the object cache's `batcache` group and has no
 * purge for "every page showing X", so the whole group goes. Without a persistent
 * object cache there are no rendered pages to strand, and a flush would only discard
 * the current request's own cache.
 */
function flush_page_cache() {
	if ( ! wp_using_ext_object_cache() ) {
		return;
	}
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'batcache' );
	} else {
		wp_cache_flush();
	}
}

/**
 * Parse a hex color into its 0-255 channels.
 *
 * Accepts #RGB, #RRGGBB and #RRGGBBAA (the alpha pair is stripped), with or
 * without the leading #, case-insensitively. Unparseable input returns null.
 *
 * @param string $hex Hexadecimal color.
 *
 * @return int[]|null [ $r, $g, $b ] as 0..255 integers, or null when unparseable.
 */
function newspack_parse_hex_channels( $hex ): ?array {
	$hex = ltrim( trim( (string) $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	} elseif ( 8 === strlen( $hex ) ) {
		$hex = substr( $hex, 0, 6 );
	}
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return null;
	}

	return [
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	];
}

/**
 * Compute the soft-clamped APCA screen luminance (Y) of a hex color.
 *
 * Unparseable input is treated as white (luminance 1.0) so callers fall back
 * to black text.
 *
 * @param string $hex Hexadecimal color.
 *
 * @return float Soft-clamped luminance in the 0..1 range.
 */
function newspack_get_apca_luminance( $hex ): float {
	$channels = newspack_parse_hex_channels( $hex );
	if ( null === $channels ) {
		return 1.0;
	}

	list( $r, $g, $b ) = array_map(
		function ( $channel ) {
			return $channel / 255;
		},
		$channels
	);

	$y = 0.2126729 * pow( $r, 2.4 ) + 0.7151522 * pow( $g, 2.4 ) + 0.0721750 * pow( $b, 2.4 );

	if ( $y <= 0.022 ) {
		$y += pow( 0.022 - $y, 1.414 );
	}

	return $y;
}

/**
 * Compute the APCA lightness contrast (Lc) of text on a background.
 *
 * Positive values are dark text on a lighter background; negative values are
 * light text on a darker background. Both luminances must already be
 * soft-clamped.
 *
 * @param float $background_y Soft-clamped background luminance.
 * @param float $text_y       Soft-clamped text luminance.
 *
 * @return float The Lc value.
 */
function newspack_get_apca_contrast( float $background_y, float $text_y ): float {
	if ( abs( $background_y - $text_y ) < 0.0005 ) {
		return 0.0;
	}

	if ( $background_y > $text_y ) {
		$sapc = ( pow( $background_y, 0.56 ) - pow( $text_y, 0.57 ) ) * 1.14;
		return $sapc < 0.1 ? 0.0 : ( $sapc - 0.027 ) * 100;
	}

	$sapc = ( pow( $background_y, 0.65 ) - pow( $text_y, 0.62 ) ) * 1.14;
	return $sapc > -0.1 ? 0.0 : ( $sapc + 0.027 ) * 100;
}

/**
 * Pick either black or white text, whichever reads better on the given background.
 *
 * Scores pure black and pure white as text against the background and returns
 * whichever reads better by APCA: the greater lightness contrast (Lc), ties to
 * black. The constants are the SA98G set from apca-w3 0.1.9.
 *
 * Keep in sync with: Newspack_Blocks::get_color_for_contrast() and getColorForContrast() in
 * donate/utils.ts (newspack-blocks), newspack_get_color_contrast() (newspack-theme),
 * getContrastColor() in contrast-color.js (packages/components), Newspack_Popups_Model::contrast_text_color()
 * (newspack-popups) and Newspack_Listings\Curated_List_Block\contrast_text_color() (newspack-listings).
 * The block theme's Newspack_Block_Theme\Contrast::get_text_color() makes the same pick (it leaves the property unset for unparseable input).
 *
 * @param string $hex Hexadecimal background color (#RGB, #RRGGBB or #RRGGBBAA, with or without #).
 *
 * @return string Either 'black' or 'white' (literal CSS color keywords).
 */
function newspack_get_color_contrast( $hex ): string {
	$background_y = newspack_get_apca_luminance( $hex );
	$black_lc     = newspack_get_apca_contrast( $background_y, newspack_get_apca_luminance( '#000000' ) );
	$white_lc     = newspack_get_apca_contrast( $background_y, newspack_get_apca_luminance( '#ffffff' ) );

	return abs( $white_lc ) > abs( $black_lc ) ? 'white' : 'black';
}

/**
 * Adjust a hexidecimal colour value to lighten or darken it.
 *
 * @param  string $hex Hexidecimal value of the color to adjust.
 * @param  string $steps Number of 'steps' to adjust the hexidecimal value's brightness.
 * @return string Updated hexidecimal value.
 */
function newspack_adjust_brightness( $hex, $steps ) {

	$steps = max( -255, min( 255, $steps ) );

	$hex = str_replace( '#', '', $hex );
	if ( 3 == strlen( $hex ) ) {
		$hex = str_repeat( substr( $hex, 0, 1 ), 2 ) . str_repeat( substr( $hex, 1, 1 ), 2 ) . str_repeat( substr( $hex, 2, 1 ), 2 );
	}

	// Split into three parts: R, G and B.
	$color_parts = str_split( $hex, 2 );
	$new_shade   = '#';

	foreach ( $color_parts as $color ) {
		$color      = hexdec( $color ); // Convert to decimal.
		$color      = max( 0, min( 255, $color + $steps ) ); // Adjust color.
		$new_shade .= str_pad( dechex( $color ), 2, '0', STR_PAD_LEFT ); // Make two char hex code.
	}

	return $new_shade;
}


/**
 * Get theme primary and secondary colors or defaults if none present.
 *
 * @return array An array containing primary and secondary colors.
 */
function newspack_get_theme_colors() {
	$default_primary_color   = function_exists( 'newspack_get_primary_color' ) ? newspack_get_primary_color() : '#003da5';
	$default_secondary_color = function_exists( 'newspack_get_secondary_color' ) ? newspack_get_secondary_color() : '#666666';
	$primary_color           = get_theme_mod( 'primary_color_hex', $default_primary_color );
	$secondary_color         = get_theme_mod( 'secondary_color_hex', $default_secondary_color );

	return [
		'primary_color'        => $primary_color,
		'primary_text_color'   => newspack_get_color_contrast( $primary_color ),
		'primary_variation'    => newspack_adjust_brightness( $primary_color, -40 ),
		'secondary_color'      => $secondary_color,
		'secondary_text_color' => newspack_get_color_contrast( $secondary_color ),
		'secondary_variation'  => newspack_adjust_brightness( $secondary_color, -40 ),
	];
}

/**
 * Get block and html markup for social media services.
 *
 * @param string $color The color to use for the social media icons.
 *
 * @return array An array containing block and html markup for social media services.
 */
function newspack_get_social_markup( $color = 'white' ) {
	$cm               = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'wordpress_seo' );
	$has_social_icons = false;
	$social_urls      = [];
	$markup           = [
		'block_markup' => '<!-- wp:social-links {"iconColor":"primary-text","iconColorValue":"primary-text","className":"is-style-filled-primary-text","style":{"spacing":{"padding":{"right":"0","left":"4px"}}},"layout":{"type":"flex","flexWrap":"nowrap"}} --><ul class="wp-block-social-links has-icon-color is-style-filled-primary-text" style="padding-right:0;padding-left:4px">',
		'html_markup'  => '',
	];

	if ( ! is_wp_error( $cm ) ) {
		$social_urls = [
			'facebook'  => $cm->get_option( 'facebook_site' ),
			'twitter'   => $cm->get_option( 'twitter_site' ),
			'instagram' => $cm->get_option( 'instagram_url' ),
			'linkedin'  => $cm->get_option( 'linkedin_url' ),
			'youtube'   => $cm->get_option( 'youtube_url' ),
		];
	}

	foreach ( $social_urls as $service => $url ) {
		if ( ! empty( $url ) && ! is_wp_error( $url ) ) {
			$markup['block_markup'] .= '<!-- wp:social-link {"url":"' . esc_url( $url ) . '","service":"' . esc_attr( $service ) . '"} /-->';
			$markup['html_markup']  .= '
				<table align="left" border="0" cellpadding="0" cellspacing="0" role="presentation" style="float:none;display:inline-table;">
					<tbody>
						<tr class="social-element">
							<td style="padding:0;vertical-align:middle;">
								<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:transparent;border-radius:999px;width:24px;">
									<tbody>
										<tr>
											<td style="padding:8px;padding-left:' . ( $has_social_icons ? '8px' : '0px' ) . ';font-size:0;height:24px;vertical-align:middle;width:24px;">
												<a href="' . esc_url( $url ) . '" target="_blank"><img alt="" height="24" src="*SITE_URL*/wp-content/plugins/newspack-newsletters/assets/' . $color . '-' . $service . '.png" style="border-radius:999px;display:block;" width="24"></a>
											</td>
										</tr>
									</tbody>
								</table>
							</td>
						</tr>
					</tbody>
				</table>
				<!--[if mso | IE]></td><td><![endif]-->';
			$has_social_icons = true;
		}
	}

	$markup['block_markup'] .= '</ul><!-- /wp:social-links -->';

	return $markup;
}
