<?php
/**
 * Sample retailers and plans.
 *
 * These exist so the calculator can be exercised end to end before the real
 * tariff data is loaded. Every rate is a round placeholder, not a real offer,
 * and the plan notes say so on the front end.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Demo_Data {

	/**
	 * @return int Number of plans created.
	 */
	public static function install() {
		$note = __( 'Sample data only. Replace these rates with the retailer\'s current energy fact sheet before going live.', 'aec-energy-compare' );

		$retailers = array(
			'sample-one'   => __( 'Sample Energy', 'aec-energy-compare' ),
			'sample-two'   => __( 'Example Power', 'aec-energy-compare' ),
			'sample-three' => __( 'Placeholder Utilities', 'aec-energy-compare' ),
		);

		$ids = array();
		foreach ( $retailers as $slug => $name ) {
			$existing = self::retailer_by_slug( $slug );
			if ( $existing ) {
				$ids[ $slug ] = (int) $existing['id'];
				continue;
			}
			$id = AEC_Retailers::save(
				array(
					'name'      => $name,
					'slug'      => $slug,
					'is_active' => 1,
				)
			);
			if ( ! is_wp_error( $id ) ) {
				$ids[ $slug ] = (int) $id;
			}
		}

		$plans = array(
			array(
				'retailer' => 'sample-one',
				'name'     => __( 'Sample Saver (single rate)', 'aec-energy-compare' ),
				'fuel'     => 'electricity',
				'state'    => 'NSW',
				'tariff'   => 'single',
				'supply'   => 98.0,
				'rates'    => array(
					array( 'single', '', 'daily', 'kWh', 28.5 ),
					array( 'controlled_load', '', 'daily', 'kWh', 18.0 ),
					array( 'feedin', 10, 'daily', 'kWh', 6.0 ),
					array( 'feedin', '', 'daily', 'kWh', 3.0 ),
				),
				'extra'    => array( 'guaranteed_discount' => 2 ),
			),
			array(
				'retailer' => 'sample-two',
				'name'     => __( 'Example Flex (time of use)', 'aec-energy-compare' ),
				'fuel'     => 'electricity',
				'state'    => 'NSW',
				'tariff'   => 'tou',
				'supply'   => 105.0,
				'rates'    => array(
					array( 'peak', '', 'daily', 'kWh', 42.0 ),
					array( 'shoulder', '', 'daily', 'kWh', 26.0 ),
					array( 'offpeak', '', 'daily', 'kWh', 19.0 ),
					array( 'controlled_load', '', 'daily', 'kWh', 17.5 ),
					array( 'feedin', '', 'daily', 'kWh', 5.0 ),
				),
				'extra'    => array(
					'conditional_discount'       => 10,
					'conditional_discount_label' => __( 'pay on time', 'aec-energy-compare' ),
					'signup_credit'              => 50,
				),
			),
			array(
				'retailer' => 'sample-three',
				'name'     => __( 'Placeholder Blocks (stepped rate)', 'aec-energy-compare' ),
				'fuel'     => 'electricity',
				'state'    => 'NSW',
				'tariff'   => 'block',
				'supply'   => 92.5,
				'rates'    => array(
					array( 'single', 1000, 'quarterly', 'kWh', 25.0 ),
					array( 'single', 1000, 'quarterly', 'kWh', 31.0 ),
					array( 'single', '', 'quarterly', 'kWh', 36.0 ),
					array( 'feedin', '', 'daily', 'kWh', 4.0 ),
				),
			),
			array(
				'retailer' => 'sample-one',
				'name'     => __( 'Sample Gas Saver', 'aec-energy-compare' ),
				'fuel'     => 'gas',
				'state'    => 'NSW',
				'tariff'   => 'block',
				'supply'   => 75.0,
				'rates'    => array(
					array( 'gas_usage', 3000, 'quarterly', 'MJ', 3.2 ),
					array( 'gas_usage', 6000, 'quarterly', 'MJ', 2.6 ),
					array( 'gas_usage', '', 'quarterly', 'MJ', 2.1 ),
				),
			),
			array(
				'retailer' => 'sample-two',
				'name'     => __( 'Example Gas Flat', 'aec-energy-compare' ),
				'fuel'     => 'gas',
				'state'    => 'NSW',
				'tariff'   => 'single',
				'supply'   => 68.0,
				'rates'    => array(
					array( 'gas_usage', '', 'daily', 'MJ', 2.9 ),
				),
				'extra'    => array( 'guaranteed_discount' => 5 ),
			),
			// A second state, so the sample catalogue shows how state filtering
			// behaves rather than looking like it ignores it.
			array(
				'retailer' => 'sample-one',
				'name'     => __( 'Sample Saver Victoria', 'aec-energy-compare' ),
				'fuel'     => 'electricity',
				'state'    => 'VIC',
				'tariff'   => 'single',
				'supply'   => 101.0,
				'rates'    => array(
					array( 'single', '', 'daily', 'kWh', 27.9 ),
					array( 'controlled_load', '', 'daily', 'kWh', 17.0 ),
					array( 'feedin', '', 'daily', 'kWh', 4.9 ),
				),
			),
			array(
				'retailer' => 'sample-three',
				'name'     => __( 'Placeholder Gas Victoria', 'aec-energy-compare' ),
				'fuel'     => 'gas',
				'state'    => 'VIC',
				'tariff'   => 'block',
				'supply'   => 79.0,
				'rates'    => array(
					array( 'gas_usage', 4000, 'quarterly', 'MJ', 3.0 ),
					array( 'gas_usage', '', 'quarterly', 'MJ', 2.2 ),
				),
			),
		);

		$created = 0;

		foreach ( $plans as $plan ) {
			if ( ! isset( $ids[ $plan['retailer'] ] ) ) {
				continue;
			}

			// Clicking the button twice must not double the sample catalogue.
			if ( self::plan_exists( $ids[ $plan['retailer'] ], $plan['name'], $plan['fuel'] ) ) {
				continue;
			}

			$rates = array();
			foreach ( $plan['rates'] as $row ) {
				$rates[] = array(
					'rate_group'   => $row[0],
					'block_limit'  => $row[1],
					'block_period' => $row[2],
					'unit'         => $row[3],
					'rate_cents'   => $row[4],
				);
			}

			$args = array(
				'retailer_id'        => $ids[ $plan['retailer'] ],
				'name'               => $plan['name'],
				'fuel'               => $plan['fuel'],
				'state'              => $plan['state'],
				'tariff_type'        => $plan['tariff'],
				'daily_supply_cents' => $plan['supply'],
				'discount_applies_to'=> 'usage',
				'notes'              => $note,
				'is_active'          => 1,
				'rates'              => $rates,
			);

			if ( isset( $plan['extra'] ) ) {
				$args = array_merge( $args, $plan['extra'] );
			}

			$result = AEC_Plans::save( $args );
			if ( ! is_wp_error( $result ) ) {
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Is this sample plan already loaded?
	 *
	 * @param int    $retailer_id
	 * @param string $name
	 * @param string $fuel
	 * @return bool
	 */
	protected static function plan_exists( $retailer_id, $name, $fuel ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . AEC_DB::plans() . ' WHERE retailer_id = %d AND name = %s AND fuel = %s LIMIT 1',
				(int) $retailer_id,
				$name,
				$fuel
			)
		);
	}

	/**
	 * @param string $slug
	 * @return array|null
	 */
	protected static function retailer_by_slug( $slug ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . AEC_DB::retailers() . ' WHERE slug = %s', $slug ),
			ARRAY_A
		);
		return $row ? $row : null;
	}
}
