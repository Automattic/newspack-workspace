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
 * Pick either white or black, whatever has sufficient contrast with the color being passed to it.
 * (Copied from the Newspack theme: https://github.com/Automattic/newspack-theme/blob/6dc4e89a65c465abdd207d990e313921f2972a9a/newspack-theme/inc/template-functions.php#L547)
 *
 * @param  string $hex Hexidecimal value of the color to adjust.
 *
 * @return string Either black or white hexidecimal values.
 *
 * @ref https://stackoverflow.com/questions/1331591/given-a-background-color-black-or-white-text
 */
function newspack_get_color_contrast( $hex ) {
	// hex RGB.
	$r1 = hexdec( substr( $hex, 1, 2 ) );
	$g1 = hexdec( substr( $hex, 3, 2 ) );
	$b1 = hexdec( substr( $hex, 5, 2 ) );
	// Black RGB.
	$black_color    = '#000';
	$r2_black_color = hexdec( substr( $black_color, 1, 2 ) );
	$g2_black_color = hexdec( substr( $black_color, 3, 2 ) );
	$b2_black_color = hexdec( substr( $black_color, 5, 2 ) );
	// Calc contrast ratio.
	$l1             = 0.2126 * pow( $r1 / 255, 2.2 ) +
		0.7152 * pow( $g1 / 255, 2.2 ) +
		0.0722 * pow( $b1 / 255, 2.2 );
	$l2             = 0.2126 * pow( $r2_black_color / 255, 2.2 ) +
		0.7152 * pow( $g2_black_color / 255, 2.2 ) +
		0.0722 * pow( $b2_black_color / 255, 2.2 );
	$contrast_ratio = 0;
	if ( $l1 > $l2 ) {
		$contrast_ratio = (int) ( ( $l1 + 0.05 ) / ( $l2 + 0.05 ) );
	} else {
		$contrast_ratio = (int) ( ( $l2 + 0.05 ) / ( $l1 + 0.05 ) );
	}
	if ( $contrast_ratio > 5 ) {
		// If contrast is more than 5, return black color.
		return 'black';
	} else {
		// if not, return white color.
		return 'white';
	}
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
