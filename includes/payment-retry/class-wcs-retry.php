<?php
/**
 * An instance of a failed payment retry.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WCS_Retry
 * @category	Class
 * @author		Prospress
 * @since		2.1
 */

class WCS_Retry {

	/* the retry's ID */
	protected $id;

	/* the renewal order to which the retry relates */
	protected $order_id;

	/* the status of this retry */
	protected $status;

	/* the date/time in UTC timezone on which this retry was run */
	protected $date_gmt;

	/* an instance of the retry rules (WCS_Retry_Rule by default) applied for this retry */
	protected $rule;

	/* the raw retry rules applied for this retry */
	protected $rule_raw;

	/**
	 * Get the Renewal Order which this retry was run for
	 *
	 * @return null
	 */
	public function __construct( $args ) {
		$this->id       = isset( $args['id'] ) ? $args['id'] : 0;
		$this->order_id = $args['order_id'];
		$this->status   = isset( $args['status'] ) ? $args['status'] : 'pending';
		$this->date_gmt = isset( $args['date_gmt'] ) ? $args['date_gmt'] : gmdate( 'Y-m-d H:i:s' );
		$this->rule_raw = isset( $args['rule_raw'] ) ? $args['rule_raw'] : array();
	}

	/**
	 * Get the Renewal Order which this retry was run for
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get the ID of the renewal order which this retry was run for
	 *
	 * @return int
	 */
	public function get_order_id() {
		return $this->order_id;
	}

	/**
	 * Get the Renewal Order which this retry was run for
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Get the date in the site's timezone when this retry was recorded
	 *
	 * @return string
	 */
	public function get_date() {
		return get_date_from_gmt( $this->date_gmt );
	}

	/**
	 * Get the date in GMT/UTC timezone when this retry was recorded
	 *
	 * @return string
	 */
	public function get_date_gmt() {
		return $this->date_gmt;
	}

	/**
	 * Get the timestamp (in GMT/UTC timezone) when this retry was recorded
	 *
	 * @return string
	 */
	public function get_time() {
		return wcs_date_to_time( $this->get_date_gmt() );
	}

	/**
	 * Get an instance of the retry rule applied for this retry
	 *
	 * @return WCS_Retry_Rule
	 */
	public function get_rule() {

		if ( null === $this->rule ) {
			$rule_class = WCS_Retry_Manager::rules()->get_rule_class();
			$this->rule = new $rule_class( $this->rule_raw );
		}

		return $this->rule;
	}
}