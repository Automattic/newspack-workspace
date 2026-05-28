<?php
/**
 * Newspack Newsletters Provider Usage Report class.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Campaign Monitor ESP Usage Report class
 *
 * This simple class holds the usage of the ESP for one day - usually yesterday.
 */
class Newspack_Newsletters_Service_Provider_Usage_Report {

	/**
	 * The number of emails sent.
	 *
	 * @var integer
	 */
	private $emails_sent = 0;

	/**
	 * The number of emails opened.
	 *
	 * @var integer
	 */
	private $opens = 0;

	/**
	 * The number of emails clicked.
	 *
	 * @var integer
	 */
	private $clicks = 0;

	/**
	 * The number of subscribers that unsubscribed.
	 *
	 * @var integer
	 */
	private $unsubscribes = 0;

	/**
	 * The number of new subscribers.
	 *
	 * @var integer
	 */
	private $subscribes = 0;

	/**
	 * The size of the contacts list.
	 *
	 * @var integer
	 */
	private $total_contacts = 0;

	/**
	 * The date of the report.
	 *
	 * @var string YYYY-MM-DD format.
	 */
	private $date;

	/**
	 * The properties allowed to be informed.
	 *
	 * @var array
	 */
	private $allowed_properties = [
		'emails_sent',
		'opens',
		'clicks',
		'unsubscribes',
		'subscribes',
		'total_contacts',
	];

	/**
	 * Magic method to set the properties. Only allowed properties will be set.
	 *
	 * @param string $name The name of the property.
	 * @param int    $value The value of the property.
	 */
	public function __set( $name, $value ) {
		if ( in_array( $name, $this->allowed_properties ) && is_int( $value ) ) {
			$this->$name = $value;
		}
	}

	/**
	 * Gets the value of a property.
	 *
	 * @param string $name The property name.
	 * @return int The property value.
	 */
	public function __get( $name ) {
		if ( in_array( $name, $this->allowed_properties ) ) {
			return $this->$name;
		}
	}

	/**
	 * Constructor.
	 *
	 * You can pass all the properties as an array or set them individually after the object is created.
	 *
	 * @param array $report_array An array of properties to set.
	 */
	public function __construct( $report_array = [] ) {
		foreach ( $report_array as $key => $value ) {
			$this->__set( $key, $value );
		}
	}

	/**
	 * Gets the report date. Yesterday by default.
	 *
	 * @return string
	 */
	public function get_date() {
		return $this->date ? $this->date : gmdate( 'Y-m-d', strtotime( '-1 day' ) );
	}

	/**
	 * Sets the report date. Use this if you want to set a date other than yesterday.
	 *
	 * @param string $date The date in YYYY-MM-DD format.
	 * @return bool True if the date was set, false otherwise.
	 */
	public function set_date( $date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$this->date = $date;
		return true;
	}

	/**
	 * Calculates and returns the growth rate of the contacts list.
	 *
	 * @return float The growth rate of the contacts list.
	 */
	public function get_growth_rate() {
		if ( 1 > $this->total_contacts ) {
			return (float) 0;
		}
		return (float) ( $this->subscribes - $this->unsubscribes ) / $this->total_contacts;
	}

	/**
	 * Gets this report as an array
	 *
	 * @return array The report as an array.
	 */
	public function to_array() {
		$array         = [];
		$array['date'] = $this->get_date();
		foreach ( $this->allowed_properties as $property ) {
			$array[ $property ] = $this->$property;
		}
		$array['growth_rate'] = $this->get_growth_rate();
		return $array;
	}
}
