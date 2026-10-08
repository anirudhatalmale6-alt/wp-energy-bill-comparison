<?php
/**
 * The comparison engine.
 *
 * Everything in here works in annual dollars. A bill covers an arbitrary
 * number of days, so the first thing that happens is the usage is scaled to a
 * 365-day year; every rate is then applied to that annual quantity. Rates are
 * stored in cents, costs are returned in dollars.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Calculator {

	const DAYS_PER_YEAR = 365;

	/**
	 * Normalise a customer profile taken off a bill (or edited by the customer)
	 * into annual quantities the costing functions can use.
	 *
	 * @param array $profile fuel, billing_days, usage[], total_amount
	 * @return array
	 */
	public static function normalise_profile( $profile ) {
		$fuel  = ( isset( $profile['fuel'] ) && 'gas' === $profile['fuel'] ) ? 'gas' : 'electricity';
		$days  = isset( $profile['billing_days'] ) ? (int) $profile['billing_days'] : 0;
		$notes = array();

		if ( $days <= 0 ) {
			$days    = 91;
			$notes[] = 'billing_days_assumed';
		}

		$factor = self::DAYS_PER_YEAR / $days;

		$usage = isset( $profile['usage'] ) && is_array( $profile['usage'] ) ? $profile['usage'] : array();
		$num   = function ( $key ) use ( $usage ) {
			if ( ! isset( $usage[ $key ] ) || '' === $usage[ $key ] || null === $usage[ $key ] ) {
				return null;
			}
			return (float) $usage[ $key ];
		};

		$peak     = $num( 'peak_kwh' );
		$shoulder = $num( 'shoulder_kwh' );
		$offpeak  = $num( 'offpeak_kwh' );
		$total    = $num( 'total_kwh' );
		$cl       = $num( 'controlled_load_kwh' );
		$solar    = $num( 'solar_export_kwh' );
		$gas      = $num( 'gas_mj' );
		$demand   = $num( 'demand_kw' );

		$tou_sum   = (float) $peak + (float) $shoulder + (float) $offpeak;
		$tou_known = ( null !== $peak || null !== $shoulder || null !== $offpeak ) && $tou_sum > 0;

		if ( $tou_known && null === $total ) {
			$total = $tou_sum;
		}

		if ( $tou_known && null !== $total && $total > 0 ) {
			// A total that disagrees with the time-of-use lines usually means the
			// total included controlled load, or one of the lines was misread.
			$gap = abs( $total - $tou_sum ) / $total;
			if ( $gap > 0.02 ) {
				$notes[] = 'tou_total_mismatch';
				$total    = $tou_sum;
			}
		}

		if ( 'electricity' === $fuel && ( null === $total || $total <= 0 ) && ! $tou_known ) {
			$notes[] = 'no_electricity_usage';
		}
		if ( 'gas' === $fuel && ( null === $gas || $gas <= 0 ) ) {
			$notes[] = 'no_gas_usage';
		}

		return array(
			'fuel'            => $fuel,
			'state'           => isset( $profile['state'] ) ? strtoupper( (string) $profile['state'] ) : '',
			'postcode'        => isset( $profile['postcode'] ) ? preg_replace( '/\D/', '', (string) $profile['postcode'] ) : '',
			'distributor'     => isset( $profile['distributor'] ) ? (string) $profile['distributor'] : '',
			'billing_days'    => $days,
			'factor'          => $factor,
			'tou_known'       => $tou_known,
			'has_solar'       => ( null !== $solar && $solar > 0 ),
			'annual'          => array(
				'peak'            => $tou_known ? (float) $peak * $factor : 0.0,
				'shoulder'        => $tou_known ? (float) $shoulder * $factor : 0.0,
				'offpeak'         => $tou_known ? (float) $offpeak * $factor : 0.0,
				'total_kwh'       => (float) $total * $factor,
				'controlled_load' => (float) $cl * $factor,
				'solar_export'    => (float) $solar * $factor,
				'gas_mj'          => (float) $gas * $factor,
			),
			'demand_kw'       => $demand,
			'current_annual'  => ( isset( $profile['total_amount'] ) && '' !== $profile['total_amount'] && null !== $profile['total_amount'] )
				? (float) $profile['total_amount'] * $factor
				: null,
			'current_bill'    => ( isset( $profile['total_amount'] ) && '' !== $profile['total_amount'] )
				? (float) $profile['total_amount']
				: null,
			'notes'           => $notes,
		);
	}

	/**
	 * Cost every eligible plan against a profile and rank the results.
	 *
	 * @param array $profile Raw profile (not yet normalised).
	 * @param array $args    limit, rank_by, plan_ids
	 * @return array
	 */
	public static function compare( $profile, $args = array() ) {
		$settings = AEC_Settings::all();

		$args = wp_parse_args(
			$args,
			array(
				'limit'    => (int) $settings['results_count'],
				'rank_by'  => $settings['rank_by'],
				'plan_ids' => array(),
			)
		);

		$norm = self::normalise_profile( $profile );

		$query = array(
			'fuel'        => $norm['fuel'],
			'active_only' => true,
			'with_rates'  => true,
			'valid_on'    => current_time( 'Y-m-d' ),
		);
		if ( '' !== $norm['state'] ) {
			$query['state'] = $norm['state'];
		}
		if ( '' !== $norm['postcode'] ) {
			$query['postcode'] = $norm['postcode'];
		}

		$plans = AEC_Plans::all( $query );

		if ( $args['plan_ids'] ) {
			$ids   = array_map( 'intval', (array) $args['plan_ids'] );
			$plans = array_values(
				array_filter(
					$plans,
					function ( $p ) use ( $ids ) {
						return in_array( (int) $p['id'], $ids, true );
					}
				)
			);
		}

		$results = array();
		$skipped = array();

		foreach ( $plans as $plan ) {
			$costed = self::cost_plan( $plan, $norm );
			if ( is_wp_error( $costed ) ) {
				$skipped[] = array(
					'plan_id'  => (int) $plan['id'],
					'plan'     => $plan['name'],
					'retailer' => $plan['retailer_name'],
					'reason'   => $costed->get_error_message(),
					'code'     => $costed->get_error_code(),
				);
				continue;
			}
			$results[] = $costed;
		}

		$rank_key = ( 'conditional' === $args['rank_by'] ) ? 'annual_cost_conditional' : 'annual_cost';

		usort(
			$results,
			function ( $a, $b ) use ( $rank_key ) {
				if ( $a[ $rank_key ] === $b[ $rank_key ] ) {
					return 0;
				}
				return ( $a[ $rank_key ] < $b[ $rank_key ] ) ? -1 : 1;
			}
		);

		$cheapest = $results ? $results[0][ $rank_key ] : null;

		foreach ( $results as $i => $row ) {
			$results[ $i ]['rank'] = $i + 1;
			if ( null !== $norm['current_annual'] ) {
				$results[ $i ]['annual_saving']             = round( $norm['current_annual'] - $row['annual_cost'], 2 );
				$results[ $i ]['annual_saving_conditional'] = round( $norm['current_annual'] - $row['annual_cost_conditional'], 2 );
			} else {
				$results[ $i ]['annual_saving']             = null;
				$results[ $i ]['annual_saving_conditional'] = null;
			}
			$results[ $i ]['vs_cheapest'] = ( null === $cheapest ) ? null : round( $row[ $rank_key ] - $cheapest, 2 );
		}

		$limited = ( $args['limit'] > 0 ) ? array_slice( $results, 0, (int) $args['limit'] ) : $results;

		return array(
			'profile'      => $norm,
			'rank_by'      => $args['rank_by'],
			'plans_tested' => count( $plans ),
			'results'      => $limited,
			'all_results'  => $results,
			'skipped'      => $skipped,
		);
	}

	/**
	 * Cost one plan for one normalised profile.
	 *
	 * @param array $plan With a `rates` map.
	 * @param array $norm Output of normalise_profile().
	 * @return array|WP_Error
	 */
	public static function cost_plan( $plan, $norm ) {
		$rates = isset( $plan['rates'] ) && is_array( $plan['rates'] ) ? $plan['rates'] : array();
		$days  = (int) $norm['billing_days'];
		$flags = array();
		$lines = array();

		$supply = ( (float) $plan['daily_supply_cents'] / 100 ) * self::DAYS_PER_YEAR;
		if ( $supply > 0 ) {
			$lines[] = array(
				'label'  => __( 'Daily supply charge', 'aec-energy-compare' ),
				'detail' => sprintf(
					/* translators: 1: cents per day, 2: number of days */
					__( '%1$s c/day x %2$d days', 'aec-energy-compare' ),
					self::trim_number( $plan['daily_supply_cents'] ),
					self::DAYS_PER_YEAR
				),
				'amount' => round( $supply, 2 ),
			);
		}

		$usage_cost = 0.0;

		if ( 'gas' === $norm['fuel'] ) {
			$blocks = isset( $rates['gas_usage'] ) ? $rates['gas_usage'] : array();
			if ( ! $blocks ) {
				return new WP_Error( 'aec_no_gas_rates', __( 'No gas usage rate is recorded for this plan.', 'aec-energy-compare' ) );
			}
			$step = self::step_cost( $blocks, $norm['annual']['gas_mj'], $days, 'MJ' );
			$usage_cost += $step['cost'];
			$lines       = array_merge( $lines, $step['lines'] );
			$flags       = array_merge( $flags, $step['flags'] );
		} else {
			$elec = self::cost_electricity_usage( $plan, $rates, $norm );
			if ( is_wp_error( $elec ) ) {
				return $elec;
			}
			$usage_cost += $elec['cost'];
			$lines       = array_merge( $lines, $elec['lines'] );
			$flags       = array_merge( $flags, $elec['flags'] );
		}

		// Demand charge, billed per kW of peak demand per day.
		$demand_cost = 0.0;
		$demand_rate = (float) $plan['demand_cents'];
		if ( ! $demand_rate && isset( $rates['demand'][0] ) ) {
			$demand_rate = (float) $rates['demand'][0]['rate_cents'];
		}
		if ( $demand_rate > 0 ) {
			if ( null === $norm['demand_kw'] || $norm['demand_kw'] <= 0 ) {
				// Leaving it out would make a demand plan look artificially cheap,
				// so the plan is flagged rather than quietly discounted.
				$flags[] = 'demand_unknown';
			} else {
				$demand_cost = ( $demand_rate / 100 ) * (float) $norm['demand_kw'] * self::DAYS_PER_YEAR;
				$lines[]     = array(
					'label'  => __( 'Demand charge', 'aec-energy-compare' ),
					'detail' => sprintf(
						/* translators: 1: cents, 2: kW, 3: days */
						__( '%1$s c/kW/day x %2$s kW x %3$d days', 'aec-energy-compare' ),
						self::trim_number( $demand_rate ),
						self::trim_number( $norm['demand_kw'] ),
						self::DAYS_PER_YEAR
					),
					'amount' => round( $demand_cost, 2 ),
				);
			}
		}

		$charges = $supply + $usage_cost + $demand_cost;

		// Discounts. "usage" excludes the supply charge, which is how Australian
		// energy fact sheets word it.
		$discount_base = ( 'total' === $plan['discount_applies_to'] ) ? $charges : ( $usage_cost + $demand_cost );

		$guaranteed = $discount_base * ( (float) $plan['guaranteed_discount'] / 100 );
		if ( $guaranteed > 0 ) {
			$lines[] = array(
				'label'  => sprintf(
					/* translators: %s: percentage */
					__( 'Guaranteed discount (%s%%)', 'aec-energy-compare' ),
					self::trim_number( $plan['guaranteed_discount'] )
				),
				'detail' => ( 'total' === $plan['discount_applies_to'] )
					? __( 'off the whole bill', 'aec-energy-compare' )
					: __( 'off usage charges', 'aec-energy-compare' ),
				'amount' => -round( $guaranteed, 2 ),
			);
		}

		$conditional = $discount_base * ( (float) $plan['conditional_discount'] / 100 );

		// Solar credit. Applied after discounts; a feed-in credit is not a
		// charge, so no discount is taken off it.
		$solar_credit = 0.0;
		if ( $norm['has_solar'] && $norm['annual']['solar_export'] > 0 ) {
			if ( isset( $rates['feedin'] ) && $rates['feedin'] ) {
				$step         = self::step_cost( $rates['feedin'], $norm['annual']['solar_export'], $days, 'kWh' );
				$solar_credit = $step['cost'];
				$lines[]      = array(
					'label'  => __( 'Solar feed-in credit', 'aec-energy-compare' ),
					'detail' => sprintf(
						/* translators: %s: kWh exported */
						__( '%s kWh exported per year', 'aec-energy-compare' ),
						self::trim_number( round( $norm['annual']['solar_export'] ) )
					),
					'amount' => -round( $solar_credit, 2 ),
				);
			} else {
				$flags[] = 'no_feedin_rate';
			}
		}

		$annual             = $charges - $guaranteed - $solar_credit;
		$annual_conditional = $charges - $guaranteed - $conditional - $solar_credit;

		$credit    = (float) $plan['signup_credit'];
		$year_one  = $annual - $credit;
		$year_one_c = $annual_conditional - $credit;

		return array(
			'plan_id'                     => (int) $plan['id'],
			'plan_name'                   => $plan['name'],
			'retailer_id'                 => (int) $plan['retailer_id'],
			'retailer_name'               => isset( $plan['retailer_name'] ) ? $plan['retailer_name'] : '',
			'retailer_logo'               => isset( $plan['retailer_logo'] ) ? $plan['retailer_logo'] : '',
			'retailer_phone'              => isset( $plan['retailer_phone'] ) ? $plan['retailer_phone'] : '',
			'plan_url'                    => $plan['plan_url'],
			'tariff_type'                 => $plan['tariff_type'],
			'green_percent'               => (int) $plan['green_percent'],
			'contract_months'             => (int) $plan['contract_months'],
			'exit_fee'                    => (float) $plan['exit_fee'],
			'signup_credit'               => $credit,
			'conditional_discount'        => (float) $plan['conditional_discount'],
			'conditional_discount_label'  => $plan['conditional_discount_label'],
			'notes'                       => $plan['notes'],
			'annual_charges'              => round( $charges, 2 ),
			'annual_cost'                 => round( $annual, 2 ),
			'annual_cost_conditional'     => round( $annual_conditional, 2 ),
			'first_year_cost'             => round( $year_one, 2 ),
			'first_year_cost_conditional' => round( $year_one_c, 2 ),
			'solar_credit'                => round( $solar_credit, 2 ),
			'lines'                       => $lines,
			'flags'                       => array_values( array_unique( $flags ) ),
			'estimated'                   => (bool) array_intersect( $flags, array( 'tou_estimated', 'demand_unknown', 'blocks_exhausted', 'controlled_load_merged' ) ),
		);
	}

	/**
	 * Electricity usage charges, choosing between flat and time-of-use costing
	 * based on which rate groups the plan actually has.
	 *
	 * @param array $plan
	 * @param array $rates
	 * @param array $norm
	 * @return array|WP_Error
	 */
	protected static function cost_electricity_usage( $plan, $rates, $norm ) {
		$days   = (int) $norm['billing_days'];
		$flags  = array();
		$lines  = array();
		$cost   = 0.0;
		$annual = $norm['annual'];

		$has_tou = ! empty( $rates['peak'] ) || ! empty( $rates['offpeak'] ) || ! empty( $rates['shoulder'] );
		$has_flat = ! empty( $rates['single'] );

		if ( ! $has_tou && ! $has_flat ) {
			return new WP_Error( 'aec_no_usage_rates', __( 'No usage rate is recorded for this plan.', 'aec-energy-compare' ) );
		}

		// Controlled load. If the customer has one and the plan does not price
		// it, that energy has to be billed somewhere, so it is folded into the
		// main usage and the plan is flagged.
		$cl_qty         = (float) $annual['controlled_load'];
		$cl_in_main     = 0.0;
		if ( $cl_qty > 0 ) {
			if ( ! empty( $rates['controlled_load'] ) ) {
				$step  = self::step_cost( $rates['controlled_load'], $cl_qty, $days, 'kWh' );
				$cost += $step['cost'];
				$lines = array_merge( $lines, self::relabel( $step['lines'], __( 'Controlled load', 'aec-energy-compare' ) ) );
				$flags = array_merge( $flags, $step['flags'] );
			} else {
				$cl_in_main = $cl_qty;
				$flags[]    = 'controlled_load_merged';
			}
		}

		if ( $has_tou ) {
			$peak     = $annual['peak'];
			$shoulder = $annual['shoulder'];
			$offpeak  = $annual['offpeak'];

			if ( ! $norm['tou_known'] ) {
				// The bill was single-rate, so the split is an assumption. Say so.
				$profile  = AEC_Settings::tou_profile();
				$total    = (float) $annual['total_kwh'];
				$peak     = $total * $profile['peak'];
				$shoulder = $total * $profile['shoulder'];
				$offpeak  = $total * $profile['offpeak'];
				$flags[]  = 'tou_estimated';
			}

			// Two-rate plans have no shoulder band; that usage is charged at peak.
			if ( empty( $rates['shoulder'] ) && $shoulder > 0 ) {
				$peak    += $shoulder;
				$shoulder = 0.0;
				$flags[]  = 'shoulder_charged_at_peak';
			}

			$peak += $cl_in_main;

			$bands = array(
				'peak'     => array( $peak, __( 'Peak usage', 'aec-energy-compare' ) ),
				'shoulder' => array( $shoulder, __( 'Shoulder usage', 'aec-energy-compare' ) ),
				'offpeak'  => array( $offpeak, __( 'Off-peak usage', 'aec-energy-compare' ) ),
			);

			$unpriced = 0.0;
			foreach ( $bands as $group => $band ) {
				list( $qty, $label ) = $band;
				if ( $qty <= 0 ) {
					continue;
				}
				if ( empty( $rates[ $group ] ) ) {
					$unpriced += $qty;
					continue;
				}
				$step  = self::step_cost( $rates[ $group ], $qty, $days, 'kWh' );
				$cost += $step['cost'];
				$lines = array_merge( $lines, self::relabel( $step['lines'], $label ) );
				$flags = array_merge( $flags, $step['flags'] );
			}

			if ( $unpriced > 0 ) {
				// Charge leftover energy at the plan's dearest usage rate rather
				// than giving it away, and flag the plan as an estimate.
				$fallback = self::highest_rate( $rates, array( 'peak', 'single', 'shoulder', 'offpeak' ) );
				if ( $fallback > 0 ) {
					$extra  = ( $fallback / 100 ) * $unpriced;
					$cost  += $extra;
					$lines[] = array(
						'label'  => __( 'Usage with no matching rate', 'aec-energy-compare' ),
						'detail' => sprintf(
							/* translators: 1: kWh, 2: cents */
							__( '%1$s kWh charged at the highest rate on this plan (%2$s c/kWh)', 'aec-energy-compare' ),
							self::trim_number( round( $unpriced ) ),
							self::trim_number( $fallback )
						),
						'amount' => round( $extra, 2 ),
					);
					$flags[] = 'unpriced_band';
				}
			}
		} else {
			$total = (float) $annual['total_kwh'];
			if ( $norm['tou_known'] ) {
				$total = $annual['peak'] + $annual['shoulder'] + $annual['offpeak'];
			}
			$total += $cl_in_main;

			$step  = self::step_cost( $rates['single'], $total, $days, 'kWh' );
			$cost += $step['cost'];
			$lines = array_merge( $lines, self::relabel( $step['lines'], __( 'Usage', 'aec-energy-compare' ) ) );
			$flags = array_merge( $flags, $step['flags'] );
		}

		return array(
			'cost'  => $cost,
			'lines' => $lines,
			'flags' => $flags,
		);
	}

	/**
	 * Apply an ordered set of stepped blocks to an annual quantity.
	 *
	 * `block_limit` is the size of that band for its stated period, matching how
	 * energy fact sheets read ("first 1000 kWh per quarter, then ..."). A blank
	 * limit on the last block means "everything after that".
	 *
	 * @param array  $blocks
	 * @param float  $quantity Annual quantity.
	 * @param int    $billing_days
	 * @param string $unit
	 * @return array cost, lines, flags
	 */
	public static function step_cost( $blocks, $quantity, $billing_days, $unit = 'kWh' ) {
		$quantity  = max( 0.0, (float) $quantity );
		$remaining = $quantity;
		$cost      = 0.0;
		$lines     = array();
		$flags     = array();
		$last_rate = 0.0;

		if ( ! $blocks ) {
			return array(
				'cost'  => 0.0,
				'lines' => array(),
				'flags' => array( 'no_blocks' ),
			);
		}

		foreach ( $blocks as $block ) {
			$rate      = (float) $block['rate_cents'];
			$last_rate = $rate;

			$limit_raw = $block['block_limit'];
			$unlimited = ( null === $limit_raw || '' === $limit_raw );

			if ( $unlimited ) {
				$qty = $remaining;
			} else {
				$band = (float) $limit_raw * AEC_DB::period_multiplier( $block['block_period'], $billing_days );
				$qty  = min( $remaining, $band );
			}

			if ( $qty > 0 ) {
				$amount    = ( $rate / 100 ) * $qty;
				$cost     += $amount;
				$remaining = max( 0.0, $remaining - $qty );
				$lines[]   = array(
					'label'  => '',
					'detail' => sprintf(
						/* translators: 1: quantity, 2: unit, 3: cents */
						__( '%1$s %2$s at %3$s c', 'aec-energy-compare' ),
						self::trim_number( round( $qty, 1 ) ),
						$unit,
						self::trim_number( $rate )
					),
					'amount' => round( $amount, 2 ),
				);
			}

			if ( $unlimited ) {
				$remaining = 0.0;
				break;
			}
		}

		if ( $remaining > 0.01 ) {
			// Every block had a ceiling and the customer used more than they add
			// up to. Charging the overflow at the final rate is the honest
			// reading; silently dropping it would understate the plan.
			$amount  = ( $last_rate / 100 ) * $remaining;
			$cost   += $amount;
			$lines[] = array(
				'label'  => '',
				'detail' => sprintf(
					/* translators: 1: quantity, 2: unit, 3: cents */
					__( '%1$s %2$s beyond the last block, charged at %3$s c', 'aec-energy-compare' ),
					self::trim_number( round( $remaining, 1 ) ),
					$unit,
					self::trim_number( $last_rate )
				),
				'amount' => round( $amount, 2 ),
			);
			$flags[] = 'blocks_exhausted';
		}

		return array(
			'cost'  => $cost,
			'lines' => $lines,
			'flags' => $flags,
		);
	}

	/**
	 * Put a group label on the first of a set of block lines.
	 *
	 * @param array  $lines
	 * @param string $label
	 * @return array
	 */
	protected static function relabel( $lines, $label ) {
		$out   = array();
		$first = true;
		foreach ( $lines as $line ) {
			if ( $first ) {
				$line['label'] = $label;
				$first         = false;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Highest cents rate across a set of rate groups.
	 *
	 * @param array $rates
	 * @param array $groups
	 * @return float
	 */
	protected static function highest_rate( $rates, $groups ) {
		$max = 0.0;
		foreach ( $groups as $group ) {
			if ( empty( $rates[ $group ] ) ) {
				continue;
			}
			foreach ( $rates[ $group ] as $row ) {
				$max = max( $max, (float) $row['rate_cents'] );
			}
		}
		return $max;
	}

	/**
	 * Format a number without trailing zeroes.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function trim_number( $value ) {
		$value = (float) $value;
		$out   = number_format( $value, 4, '.', ',' );
		$out   = rtrim( rtrim( $out, '0' ), '.' );
		return '' === $out ? '0' : $out;
	}

	/**
	 * Human text for a plan flag.
	 *
	 * @param string $flag
	 * @return string
	 */
	public static function flag_label( $flag ) {
		$map = array(
			'tou_estimated'            => __( 'Your bill is a single flat rate, so the peak / off-peak split on this plan is an estimate based on a typical household.', 'aec-energy-compare' ),
			'demand_unknown'           => __( 'This plan has a demand charge and your bill did not show a demand figure, so the real cost will be higher than shown.', 'aec-energy-compare' ),
			'blocks_exhausted'         => __( 'You use more than this plan\'s highest usage block covers; the extra has been charged at the last listed rate.', 'aec-energy-compare' ),
			'controlled_load_merged'   => __( 'This plan has no separate controlled load rate, so that usage has been charged at the normal rate.', 'aec-energy-compare' ),
			'shoulder_charged_at_peak' => __( 'This plan has no shoulder rate, so shoulder usage has been charged at the peak rate.', 'aec-energy-compare' ),
			'no_feedin_rate'           => __( 'No solar feed-in rate is recorded for this plan, so no export credit has been applied.', 'aec-energy-compare' ),
			'unpriced_band'            => __( 'Part of your usage has no matching rate on this plan and has been charged at the plan\'s highest rate.', 'aec-energy-compare' ),
			'no_blocks'                => __( 'A usage rate is missing from this plan.', 'aec-energy-compare' ),
		);
		return isset( $map[ $flag ] ) ? $map[ $flag ] : '';
	}

	/**
	 * Human text for a profile note.
	 *
	 * @param string $note
	 * @return string
	 */
	public static function note_label( $note ) {
		$map = array(
			'billing_days_assumed' => __( 'Your bill did not show how many days it covers, so a 91-day quarter has been assumed.', 'aec-energy-compare' ),
			'tou_total_mismatch'   => __( 'The total usage on your bill did not match the peak / shoulder / off-peak lines, so the individual lines have been used.', 'aec-energy-compare' ),
			'no_electricity_usage' => __( 'No electricity usage was found, so the comparison cannot be run yet. Please enter your usage in kWh.', 'aec-energy-compare' ),
			'no_gas_usage'         => __( 'No gas usage was found, so the comparison cannot be run yet. Please enter your usage in MJ.', 'aec-energy-compare' ),
		);
		return isset( $map[ $note ] ) ? $map[ $note ] : '';
	}
}
