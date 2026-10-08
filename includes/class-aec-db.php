<?php
/**
 * Table names and small shared query helpers.
 */

defined( 'ABSPATH' ) || exit;

class AEC_DB {

	/**
	 * Fully qualified table name for one of the plugin's tables.
	 *
	 * @param string $name retailers|plans|rates|bills|leads
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aec_' . $name;
	}

	public static function retailers() {
		return self::table( 'retailers' );
	}

	public static function plans() {
		return self::table( 'plans' );
	}

	public static function rates() {
		return self::table( 'rates' );
	}

	public static function bills() {
		return self::table( 'bills' );
	}

	public static function leads() {
		return self::table( 'leads' );
	}

	/**
	 * The states and territories the calculator knows about.
	 *
	 * WA and NT are regulated single-retailer markets, so they are listed but
	 * carry a warning on the front end rather than being hidden.
	 *
	 * @return array
	 */
	public static function states() {
		return array(
			'NSW' => 'New South Wales',
			'VIC' => 'Victoria',
			'QLD' => 'Queensland',
			'SA'  => 'South Australia',
			'ACT' => 'Australian Capital Territory',
			'TAS' => 'Tasmania',
			'NT'  => 'Northern Territory',
			'WA'  => 'Western Australia',
		);
	}

	/**
	 * Tariff structures the calculator supports.
	 *
	 * @return array
	 */
	public static function tariff_types() {
		return array(
			'single'    => __( 'Single rate (flat)', 'aec-energy-compare' ),
			'tou'       => __( 'Time of use (peak / shoulder / off-peak)', 'aec-energy-compare' ),
			'demand'    => __( 'Single rate + demand charge', 'aec-energy-compare' ),
			'tou_demand'=> __( 'Time of use + demand charge', 'aec-energy-compare' ),
			'block'     => __( 'Block / stepped rate', 'aec-energy-compare' ),
		);
	}

	/**
	 * Rate groups stored in the rates table.
	 *
	 * @return array
	 */
	public static function rate_groups() {
		return array(
			'single'          => __( 'Single / anytime usage', 'aec-energy-compare' ),
			'peak'            => __( 'Peak usage', 'aec-energy-compare' ),
			'shoulder'        => __( 'Shoulder usage', 'aec-energy-compare' ),
			'offpeak'         => __( 'Off-peak usage', 'aec-energy-compare' ),
			'controlled_load' => __( 'Controlled load', 'aec-energy-compare' ),
			'gas_usage'       => __( 'Gas usage', 'aec-energy-compare' ),
			'feedin'          => __( 'Solar feed-in tariff', 'aec-energy-compare' ),
			'demand'          => __( 'Demand charge', 'aec-energy-compare' ),
		);
	}

	/**
	 * How a block threshold is measured.
	 *
	 * @return array
	 */
	public static function block_periods() {
		return array(
			'daily'      => __( 'Per day', 'aec-energy-compare' ),
			'monthly'    => __( 'Per month', 'aec-energy-compare' ),
			'quarterly'  => __( 'Per quarter', 'aec-energy-compare' ),
			'annual'     => __( 'Per year', 'aec-energy-compare' ),
			'bill_period'=> __( 'Per billing period', 'aec-energy-compare' ),
		);
	}

	/**
	 * Number of days a block period covers in a year.
	 *
	 * @param string $period
	 * @param int    $billing_days Length of the customer's own billing period.
	 * @return float Multiplier that turns a per-period threshold into an annual one.
	 */
	public static function period_multiplier( $period, $billing_days = 91 ) {
		switch ( $period ) {
			case 'daily':
				return 365.0;
			case 'monthly':
				return 12.0;
			case 'quarterly':
				return 4.0;
			case 'bill_period':
				$billing_days = max( 1, (int) $billing_days );
				return 365.0 / $billing_days;
			case 'annual':
			default:
				return 1.0;
		}
	}
}
