<?php
/**
 * Plan and tariff-rate CRUD, plus the plan/postcode matching rules.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Plans {

	/**
	 * Save a plan and, when supplied, replace its whole rate set.
	 *
	 * @param array $args Plan columns plus an optional `rates` array.
	 * @return int|WP_Error
	 */
	public static function save( $args ) {
		global $wpdb;

		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;

		$name = isset( $args['name'] ) ? trim( sanitize_text_field( $args['name'] ) ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'aec_plan_name', __( 'A plan needs a name.', 'aec-energy-compare' ) );
		}

		$retailer_id = isset( $args['retailer_id'] ) ? (int) $args['retailer_id'] : 0;
		if ( $retailer_id <= 0 || ! AEC_Retailers::get( $retailer_id ) ) {
			return new WP_Error( 'aec_plan_retailer', __( 'Pick the retailer this plan belongs to.', 'aec-energy-compare' ) );
		}

		$fuel  = ( isset( $args['fuel'] ) && 'gas' === $args['fuel'] ) ? 'gas' : 'electricity';
		$state = isset( $args['state'] ) ? strtoupper( sanitize_text_field( $args['state'] ) ) : '';
		if ( ! array_key_exists( $state, AEC_DB::states() ) ) {
			return new WP_Error( 'aec_plan_state', __( 'Pick the state this plan is sold in.', 'aec-energy-compare' ) );
		}

		$tariff = isset( $args['tariff_type'] ) ? sanitize_text_field( $args['tariff_type'] ) : 'single';
		if ( ! array_key_exists( $tariff, AEC_DB::tariff_types() ) ) {
			$tariff = 'single';
		}

		$now = current_time( 'mysql' );

		$data = array(
			'retailer_id'                => $retailer_id,
			'name'                       => $name,
			'fuel'                       => $fuel,
			'state'                      => $state,
			'distributor'                => isset( $args['distributor'] ) ? sanitize_text_field( $args['distributor'] ) : '',
			'postcodes'                  => isset( $args['postcodes'] ) ? sanitize_textarea_field( $args['postcodes'] ) : '',
			'tariff_type'                => $tariff,
			'daily_supply_cents'         => self::decimal( $args['daily_supply_cents'] ?? 0 ),
			'demand_cents'               => self::decimal( $args['demand_cents'] ?? 0 ),
			'demand_window'              => isset( $args['demand_window'] ) ? sanitize_text_field( $args['demand_window'] ) : '',
			'guaranteed_discount'        => self::decimal( $args['guaranteed_discount'] ?? 0 ),
			'conditional_discount'       => self::decimal( $args['conditional_discount'] ?? 0 ),
			'conditional_discount_label' => isset( $args['conditional_discount_label'] ) ? sanitize_text_field( $args['conditional_discount_label'] ) : '',
			'discount_applies_to'        => ( isset( $args['discount_applies_to'] ) && 'total' === $args['discount_applies_to'] ) ? 'total' : 'usage',
			'signup_credit'              => self::decimal( $args['signup_credit'] ?? 0 ),
			'contract_months'            => isset( $args['contract_months'] ) ? max( 0, (int) $args['contract_months'] ) : 0,
			'exit_fee'                   => self::decimal( $args['exit_fee'] ?? 0 ),
			'green_percent'              => isset( $args['green_percent'] ) ? max( 0, min( 100, (int) $args['green_percent'] ) ) : 0,
			'reference_price_pct'        => self::decimal( $args['reference_price_pct'] ?? 0 ),
			'plan_url'                   => isset( $args['plan_url'] ) ? esc_url_raw( $args['plan_url'] ) : '',
			'notes'                      => isset( $args['notes'] ) ? sanitize_textarea_field( $args['notes'] ) : '',
			'is_active'                  => empty( $args['is_active'] ) ? 0 : 1,
			'valid_from'                 => self::date_or_null( $args['valid_from'] ?? '' ),
			'valid_to'                   => self::date_or_null( $args['valid_to'] ?? '' ),
			'updated_at'                 => $now,
		);

		$formats = array(
			'%d', '%s', '%s', '%s', '%s', '%s', '%s',
			'%f', '%f', '%s',
			'%f', '%f', '%s', '%s',
			'%f', '%d', '%f', '%d', '%f',
			'%s', '%s', '%d', '%s', '%s', '%s',
		);

		if ( $id > 0 ) {
			$wpdb->update( AEC_DB::plans(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
		} else {
			$data['created_at'] = $now;
			$formats[]          = '%s';
			$wpdb->insert( AEC_DB::plans(), $data, $formats );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				return new WP_Error( 'aec_plan_insert', __( 'The plan could not be saved.', 'aec-energy-compare' ) );
			}
		}

		if ( isset( $args['rates'] ) && is_array( $args['rates'] ) ) {
			self::replace_rates( $id, $args['rates'] );
		}

		return $id;
	}

	/**
	 * Cast a posted number, tolerating thousands separators and stray symbols.
	 *
	 * @param mixed $value
	 * @return float
	 */
	protected static function decimal( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		$clean = preg_replace( '/[^0-9.\-]/', '', (string) $value );
		return '' === $clean ? 0.0 : (float) $clean;
	}

	/**
	 * @param string $value
	 * @return string|null Y-m-d or null.
	 */
	protected static function date_or_null( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	/**
	 * Replace every rate row for a plan.
	 *
	 * @param int   $plan_id
	 * @param array $rates
	 */
	public static function replace_rates( $plan_id, $rates ) {
		global $wpdb;

		$plan_id = (int) $plan_id;
		$wpdb->delete( AEC_DB::rates(), array( 'plan_id' => $plan_id ), array( '%d' ) );

		$groups  = AEC_DB::rate_groups();
		$periods = AEC_DB::block_periods();
		$order   = array();

		foreach ( $rates as $rate ) {
			$group = isset( $rate['rate_group'] ) ? sanitize_text_field( $rate['rate_group'] ) : '';
			if ( ! array_key_exists( $group, $groups ) ) {
				continue;
			}

			$rate_cents = self::decimal( $rate['rate_cents'] ?? 0 );
			$limit_raw  = isset( $rate['block_limit'] ) ? trim( (string) $rate['block_limit'] ) : '';
			$limit      = ( '' === $limit_raw ) ? null : self::decimal( $limit_raw );

			// A zero rate with no limit carries no information; skip the row so
			// an empty repeater line does not become a free tariff block.
			if ( 0.0 === $rate_cents && null === $limit ) {
				continue;
			}

			$period = isset( $rate['block_period'] ) ? sanitize_text_field( $rate['block_period'] ) : 'daily';
			if ( ! array_key_exists( $period, $periods ) ) {
				$period = 'daily';
			}

			$order[ $group ] = isset( $order[ $group ] ) ? $order[ $group ] + 1 : 1;

			$unit = isset( $rate['unit'] ) ? sanitize_text_field( $rate['unit'] ) : self::default_unit( $group );

			$wpdb->insert(
				AEC_DB::rates(),
				array(
					'plan_id'      => $plan_id,
					'rate_group'   => $group,
					'block_order'  => $order[ $group ],
					'block_limit'  => $limit,
					'block_period' => $period,
					'unit'         => $unit,
					'rate_cents'   => $rate_cents,
				),
				array( '%d', '%s', '%d', '%f', '%s', '%s', '%f' )
			);
		}
	}

	/**
	 * @param string $group
	 * @return string
	 */
	protected static function default_unit( $group ) {
		if ( 'gas_usage' === $group ) {
			return 'MJ';
		}
		if ( 'demand' === $group ) {
			return 'kW';
		}
		return 'kWh';
	}

	/**
	 * @param int  $id
	 * @param bool $with_rates
	 * @return array|null
	 */
	public static function get( $id, $with_rates = true ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . AEC_DB::plans() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		if ( $with_rates ) {
			$row['rates'] = self::rates_for( (int) $row['id'] );
		}

		return $row;
	}

	/**
	 * @param int $plan_id
	 * @return array Rate rows grouped by rate_group, each ordered by block_order.
	 */
	public static function rates_for( $plan_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . AEC_DB::rates() . ' WHERE plan_id = %d ORDER BY rate_group ASC, block_order ASC',
				(int) $plan_id
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['rate_group'] ][] = $row;
		}

		return $out;
	}

	/**
	 * Query plans.
	 *
	 * @param array $args fuel, state, retailer_id, tariff_type, postcode,
	 *                    active_only, valid_on, search, with_rates, limit, offset,
	 *                    orderby, order
	 * @return array
	 */
	public static function all( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'fuel'        => '',
				'state'       => '',
				'retailer_id' => 0,
				'tariff_type' => '',
				'postcode'    => '',
				'active_only' => false,
				'valid_on'    => '',
				'search'      => '',
				'with_rates'  => false,
				'limit'       => 0,
				'offset'      => 0,
				'orderby'     => 'name',
				'order'       => 'ASC',
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['fuel'] ) {
			$where[]  = 'p.fuel = %s';
			$params[] = $args['fuel'];
		}
		if ( '' !== $args['state'] ) {
			$where[]  = 'p.state = %s';
			$params[] = strtoupper( $args['state'] );
		}
		if ( $args['retailer_id'] > 0 ) {
			$where[]  = 'p.retailer_id = %d';
			$params[] = (int) $args['retailer_id'];
		}
		if ( '' !== $args['tariff_type'] ) {
			$where[]  = 'p.tariff_type = %s';
			$params[] = $args['tariff_type'];
		}
		if ( $args['active_only'] ) {
			$where[] = 'p.is_active = 1';
			$where[] = 'r.is_active = 1';
		}
		if ( '' !== $args['valid_on'] ) {
			$where[]  = '(p.valid_from IS NULL OR p.valid_from <= %s)';
			$params[] = $args['valid_on'];
			$where[]  = '(p.valid_to IS NULL OR p.valid_to >= %s)';
			$params[] = $args['valid_on'];
		}
		if ( '' !== $args['search'] ) {
			$where[]  = '(p.name LIKE %s OR r.name LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$allowed = array( 'name', 'id', 'created_at', 'daily_supply_cents', 'state', 'fuel' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? 'p.' . $args['orderby'] : 'p.name';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$sql = 'SELECT p.*, r.name AS retailer_name, r.logo_url AS retailer_logo, r.phone AS retailer_phone,'
			. ' r.website AS retailer_website'
			. ' FROM ' . AEC_DB::plans() . ' p'
			. ' LEFT JOIN ' . AEC_DB::retailers() . ' r ON r.id = p.retailer_id'
			. ' WHERE ' . implode( ' AND ', $where )
			. " ORDER BY {$orderby} {$order}";

		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['limit'];
			$params[] = (int) $args['offset'];
		}

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = $rows ? $rows : array();

		// Postcode filtering happens in PHP because the column holds a list of
		// values and ranges rather than one value per row.
		if ( '' !== $args['postcode'] ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $args ) {
						return self::postcode_matches( $row['postcodes'], $args['postcode'] );
					}
				)
			);
		}

		if ( $args['with_rates'] ) {
			foreach ( $rows as $i => $row ) {
				$rows[ $i ]['rates'] = self::rates_for( (int) $row['id'] );
			}
		}

		return $rows;
	}

	/**
	 * @param array $args Same filters as all().
	 * @return int
	 */
	public static function count( $args = array() ) {
		$args['limit']      = 0;
		$args['offset']     = 0;
		$args['with_rates'] = false;
		return count( self::all( $args ) );
	}

	/**
	 * Does a plan's postcode list cover a given postcode?
	 *
	 * An empty list means statewide, which is the common case.
	 *
	 * @param string $list     "2000-2234, 2250, 2300"
	 * @param string $postcode
	 * @return bool
	 */
	public static function postcode_matches( $list, $postcode ) {
		$list     = trim( (string) $list );
		$postcode = preg_replace( '/\D/', '', (string) $postcode );

		if ( '' === $list ) {
			return true;
		}
		if ( '' === $postcode ) {
			// No postcode to test against, so do not exclude the plan.
			return true;
		}

		$target = (int) $postcode;

		foreach ( preg_split( '/[\s,;\r\n]+/', $list ) as $token ) {
			$token = trim( $token );
			if ( '' === $token ) {
				continue;
			}
			if ( preg_match( '/^(\d{3,4})\s*[-–]\s*(\d{3,4})$/u', $token, $m ) ) {
				$low  = (int) $m[1];
				$high = (int) $m[2];
				if ( $low > $high ) {
					list( $low, $high ) = array( $high, $low );
				}
				if ( $target >= $low && $target <= $high ) {
					return true;
				}
				continue;
			}
			if ( (int) preg_replace( '/\D/', '', $token ) === $target ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int $id
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return;
		}
		$wpdb->delete( AEC_DB::rates(), array( 'plan_id' => $id ), array( '%d' ) );
		$wpdb->delete( AEC_DB::plans(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Duplicate a plan and its rates, so a near-identical tariff is one click.
	 *
	 * @param int $id
	 * @return int|WP_Error New plan id.
	 */
	public static function duplicate( $id ) {
		$plan = self::get( $id, true );
		if ( ! $plan ) {
			return new WP_Error( 'aec_plan_missing', __( 'That plan no longer exists.', 'aec-energy-compare' ) );
		}

		$flat = array();
		foreach ( $plan['rates'] as $group => $rows ) {
			foreach ( $rows as $row ) {
				$flat[] = array(
					'rate_group'   => $group,
					'block_limit'  => $row['block_limit'],
					'block_period' => $row['block_period'],
					'unit'         => $row['unit'],
					'rate_cents'   => $row['rate_cents'],
				);
			}
		}

		unset( $plan['id'], $plan['rates'] );
		$plan['name']      = sprintf( __( '%s (copy)', 'aec-energy-compare' ), $plan['name'] );
		$plan['is_active'] = 0;
		$plan['rates']     = $flat;

		return self::save( $plan );
	}
}
