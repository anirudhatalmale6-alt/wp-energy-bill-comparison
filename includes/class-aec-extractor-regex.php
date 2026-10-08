<?php
/**
 * Rule-based extraction from the text layer of a bill.
 *
 * This is the cheap path: no API call, no cost, instant. It handles the fields
 * that Australian bills label consistently (NMI, MIRN, kWh and MJ totals,
 * supply charge, amount due). Anything it is not sure about is returned as
 * null with a low confidence, so the AI pass or the customer fills it in. The
 * one rule that matters: never guess.
 *
 * The patterns are deliberately grouped per field so a new retailer layout is
 * a few extra lines rather than a rewrite.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Extractor_Regex {

	/**
	 * @param string $text Bill text.
	 * @return array fields, confidence, fuel
	 */
	public static function extract( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return array(
				'fields'     => array(),
				'confidence' => array(),
			);
		}

		$flat = self::flatten( $text );

		$fields     = array();
		$confidence = array();

		$set = function ( $key, $value, $score ) use ( &$fields, &$confidence ) {
			if ( null === $value || '' === $value ) {
				return;
			}
			$fields[ $key ]     = $value;
			$confidence[ $key ] = $score;
		};

		// --- Fuel -----------------------------------------------------------
		$fuel = self::detect_fuel( $flat );
		if ( $fuel ) {
			$set( 'fuel', $fuel['value'], $fuel['score'] );
		}

		// --- Retailer -------------------------------------------------------
		$retailer = AEC_Retailers::match_in_text( $text );
		if ( $retailer ) {
			$set( 'retailer_name', $retailer['name'], 'high' );
		}

		// --- Identifiers ----------------------------------------------------
		$nmi = self::first_match(
			$flat,
			array(
				'/\bNMI\b[^0-9A-Z]{0,20}([0-9]{10}[0-9A-Z]?)/i',
				'/National\s+Meter(?:ing)?\s+Identifier[^0-9A-Z]{0,20}([0-9]{10}[0-9A-Z]?)/i',
			)
		);
		$set( 'nmi', $nmi, $nmi ? 'high' : 'low' );

		$mirn = self::first_match(
			$flat,
			array(
				'/\b(?:MIRN|DPI)\b[^0-9]{0,20}([0-9]{10,11})/i',
				'/Meter\s+Installation\s+Registration\s+Number[^0-9]{0,20}([0-9]{10,11})/i',
			)
		);
		$set( 'mirn', $mirn, $mirn ? 'high' : 'low' );

		// --- Address / postcode --------------------------------------------
		$state_codes = implode( '|', array_keys( AEC_DB::states() ) );
		if ( preg_match( '/\b(' . $state_codes . ')\s+(\d{4})\b/i', $flat, $m ) ) {
			$set( 'state', strtoupper( $m[1] ), 'high' );
			$set( 'postcode', $m[2], 'high' );
		} else {
			$pc = self::first_match( $flat, array( '/\bpost\s*code\b[^0-9]{0,10}(\d{4})\b/i' ) );
			if ( $pc ) {
				$set( 'postcode', $pc, 'medium' );
			}
		}

		// --- Billing period --------------------------------------------------
		$period = self::detect_period( $flat );
		if ( $period ) {
			$set( 'period_start', $period['start'], $period['score'] );
			$set( 'period_end', $period['end'], $period['score'] );
			$set( 'billing_days', $period['days'], $period['score'] );
		} else {
			$days = self::first_match( $flat, array( '/(\d{2,3})\s*days?\b/i' ) );
			if ( $days && (int) $days >= 20 && (int) $days <= 400 ) {
				$set( 'billing_days', (int) $days, 'medium' );
			}
		}

		// --- Usage -----------------------------------------------------------
		$set_usage = function ( $key, $value, $score ) use ( &$fields, &$confidence ) {
			if ( null === $value ) {
				return;
			}
			if ( ! isset( $fields['usage'] ) ) {
				$fields['usage'] = array();
			}
			$fields['usage'][ $key ]     = $value;
			$confidence[ 'usage.' . $key ] = $score;
		};

		$peak = self::labelled_quantity( $flat, array( 'peak(?!\s*demand)', 'peak\s+usage', 'peak\s+consumption' ), 'kwh' );
		$set_usage( 'peak_kwh', $peak, null === $peak ? 'low' : 'medium' );

		$shoulder = self::labelled_quantity( $flat, array( 'shoulder' ), 'kwh' );
		$set_usage( 'shoulder_kwh', $shoulder, null === $shoulder ? 'low' : 'medium' );

		$offpeak = self::labelled_quantity( $flat, array( 'off[\s\-]?peak' ), 'kwh' );
		$set_usage( 'offpeak_kwh', $offpeak, null === $offpeak ? 'low' : 'medium' );

		$controlled = self::labelled_quantity( $flat, array( 'controlled\s+load(?:\s*\d)?', 'dedicated\s+circuit', 'off\s*peak\s*hot\s*water' ), 'kwh' );
		$set_usage( 'controlled_load_kwh', $controlled, null === $controlled ? 'low' : 'medium' );

		$solar = self::labelled_quantity( $flat, array( 'solar\s+(?:export|exported|generation\s+exported)', 'feed[\s\-]?in', 'exported\s+to\s+(?:the\s+)?grid', 'electricity\s+exported' ), 'kwh' );
		$set_usage( 'solar_export_kwh', $solar, null === $solar ? 'low' : 'medium' );

		$total_kwh = self::labelled_quantity( $flat, array( 'total\s+(?:electricity\s+)?(?:usage|consumption)', 'total\s+kwh', 'your\s+total\s+usage' ), 'kwh' );
		if ( null === $total_kwh ) {
			// Fall back to the largest kWh figure that is not a rate.
			$total_kwh = self::largest_quantity( $flat, 'kwh' );
			$set_usage( 'total_kwh', $total_kwh, null === $total_kwh ? 'low' : 'low' );
		} else {
			$set_usage( 'total_kwh', $total_kwh, 'high' );
		}

		$gas_mj = self::labelled_quantity( $flat, array( 'total\s+(?:gas\s+)?(?:usage|consumption)', 'total\s+mj', 'gas\s+used' ), 'mj' );
		if ( null === $gas_mj ) {
			$gas_mj = self::largest_quantity( $flat, 'mj' );
			$set_usage( 'gas_mj', $gas_mj, null === $gas_mj ? 'low' : 'low' );
		} else {
			$set_usage( 'gas_mj', $gas_mj, 'high' );
		}

		$demand = self::labelled_quantity( $flat, array( 'demand', 'max(?:imum)?\s+demand' ), 'kw' );
		$set_usage( 'demand_kw', $demand, null === $demand ? 'low' : 'medium' );

		// --- Rates -----------------------------------------------------------
		$set_rate = function ( $key, $value, $score ) use ( &$fields, &$confidence ) {
			if ( null === $value ) {
				return;
			}
			if ( ! isset( $fields['rates'] ) ) {
				$fields['rates'] = array();
			}
			$fields['rates'][ $key ]      = $value;
			$confidence[ 'rates.' . $key ] = $score;
		};

		$supply = self::detect_supply_charge( $flat );
		if ( $supply ) {
			$set_rate( 'supply_cents_per_day', $supply['value'], $supply['score'] );
		}

		$fit = self::labelled_rate( $flat, array( 'feed[\s\-]?in\s+tariff', 'solar\s+feed[\s\-]?in', 'export\s+rate' ) );
		$set_rate( 'feedin_cents', $fit, null === $fit ? 'low' : 'medium' );

		$peak_rate = self::labelled_rate( $flat, array( 'peak(?!\s*demand)' ) );
		$set_rate( 'peak_cents', $peak_rate, null === $peak_rate ? 'low' : 'low' );

		$offpeak_rate = self::labelled_rate( $flat, array( 'off[\s\-]?peak' ) );
		$set_rate( 'offpeak_cents', $offpeak_rate, null === $offpeak_rate ? 'low' : 'low' );

		// --- Totals -----------------------------------------------------------
		$total = self::detect_total( $flat );
		if ( $total ) {
			$set( 'total_amount', $total['value'], $total['score'] );
		}

		// --- Plan name --------------------------------------------------------
		$plan = self::first_match(
			$flat,
			array(
				'/\b(?:your\s+)?(?:plan|offer|product)\s*(?:name)?\s*[:\-]\s*([A-Za-z0-9][A-Za-z0-9 &\'\-\.\+]{2,60})/i',
				'/\btariff\s*(?:name)?\s*[:\-]\s*([A-Za-z0-9][A-Za-z0-9 &\'\-\.\+]{2,60})/i',
			)
		);
		if ( $plan ) {
			$set( 'plan_name', trim( preg_replace( '/\s{2,}/', ' ', $plan ) ), 'medium' );
		}

		return array(
			'fields'     => $fields,
			'confidence' => $confidence,
		);
	}

	/**
	 * Collapse the text to one line per original line with single spaces, so
	 * patterns do not have to care how many spaces a layout engine emitted.
	 *
	 * @param string $text
	 * @return string
	 */
	protected static function flatten( $text ) {
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n+/', "\n", $text );
		return trim( $text );
	}

	/**
	 * @param string $flat
	 * @return array|null value, score
	 */
	protected static function detect_fuel( $flat ) {
		$elec = 0;
		$gas  = 0;

		$elec += preg_match_all( '/\bkWh\b/i', $flat );
		$elec += 3 * preg_match_all( '/\bNMI\b/i', $flat );
		$elec += preg_match_all( '/\belectricity\b/i', $flat );

		$gas += preg_match_all( '/\bMJ\b/', $flat );
		$gas += 3 * preg_match_all( '/\b(?:MIRN|DPI)\b/i', $flat );
		$gas += preg_match_all( '/\bnatural\s+gas\b/i', $flat );

		if ( 0 === $elec && 0 === $gas ) {
			return null;
		}

		$winner = ( $gas > $elec ) ? 'gas' : 'electricity';
		$margin = abs( $gas - $elec );
		$score  = ( $margin >= 3 ) ? 'high' : 'medium';

		return array(
			'value' => $winner,
			'score' => $score,
		);
	}

	/**
	 * Billing period as two dates plus a day count.
	 *
	 * @param string $flat
	 * @return array|null
	 */
	protected static function detect_period( $flat ) {
		$date = '(\d{1,2}[\/\-\s](?:\d{1,2}|[A-Za-z]{3,9})[\/\-\s]\d{2,4})';

		$patterns = array(
			'/(?:billing|service|supply|usage)\s*period[^0-9A-Za-z]{0,12}' . $date . '\s*(?:to|-|–|until)\s*' . $date . '/i',
			'/\bfrom\s+' . $date . '\s+to\s+' . $date . '/i',
			'/' . $date . '\s*(?:to|-|–)\s*' . $date . '/',
		);

		foreach ( $patterns as $i => $pattern ) {
			if ( ! preg_match( $pattern, $flat, $m ) ) {
				continue;
			}
			$start = self::parse_date( $m[1] );
			$end   = self::parse_date( $m[2] );
			if ( ! $start || ! $end ) {
				continue;
			}
			$days = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1;
			if ( $days < 20 || $days > 400 ) {
				continue;
			}
			return array(
				'start' => $start,
				'end'   => $end,
				'days'  => $days,
				// A labelled period is trustworthy; two bare dates sitting next
				// to each other could be anything.
				'score' => ( $i < 2 ) ? 'high' : 'low',
			);
		}

		return null;
	}

	/**
	 * @param string $value
	 * @return string|null Y-m-d
	 */
	public static function parse_date( $value ) {
		$value = trim( (string) $value );
		$value = str_replace( array( '/', '.' ), '-', $value );

		// Australian bills are day-first, which strtotime gets wrong for
		// slash-separated dates, so the numeric form is handled explicitly.
		if ( preg_match( '/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $value, $m ) ) {
			$day   = (int) $m[1];
			$month = (int) $m[2];
			$year  = (int) $m[3];
			if ( $year < 100 ) {
				$year += 2000;
			}
			if ( $month > 12 && $day <= 12 ) {
				// Clearly month-first after all.
				list( $day, $month ) = array( $month, $day );
			}
			if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
				return null;
			}
			return sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}

		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	/**
	 * Find a quantity sitting near one of a set of labels.
	 *
	 * @param string $flat
	 * @param array  $labels Regex fragments.
	 * @param string $unit   kwh|mj|kw
	 * @return float|null
	 */
	protected static function labelled_quantity( $flat, $labels, $unit ) {
		$unit_pattern = array(
			'kwh' => 'kWh',
			'mj'  => 'MJ',
			'kw'  => 'kW',
		);
		$u = isset( $unit_pattern[ $unit ] ) ? $unit_pattern[ $unit ] : 'kWh';

		// kW must not swallow kWh.
		$suffix = ( 'kw' === $unit ) ? '(?:kW)(?!h)' : '(?:' . $u . ')';
		$number = '([0-9][0-9,]*(?:\.[0-9]+)?)';

		foreach ( $labels as $label ) {
			$tries = array(
				// label ... number unit
				'/' . $label . '[^\n]{0,60}?' . $number . '\s*' . $suffix . '/i',
				// number unit ... label (table laid out right to left)
				'/' . $number . '\s*' . $suffix . '[^\n]{0,40}?' . $label . '/i',
			);
			foreach ( $tries as $pattern ) {
				if ( preg_match( $pattern, $flat, $m ) ) {
					$value = (float) str_replace( ',', '', $m[1] );
					if ( $value > 0 && $value < 10000000 ) {
						return $value;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Largest plain quantity of a unit anywhere in the document, skipping any
	 * figure that is part of a rate (c/kWh, $/MJ).
	 *
	 * @param string $flat
	 * @param string $unit
	 * @return float|null
	 */
	protected static function largest_quantity( $flat, $unit ) {
		$u      = ( 'mj' === $unit ) ? 'MJ' : 'kWh';
		$number = '([0-9][0-9,]*(?:\.[0-9]+)?)';

		if ( ! preg_match_all( '/' . $number . '\s*' . $u . '\b/i', $flat, $all, PREG_SET_ORDER ) ) {
			return null;
		}

		$best = null;
		foreach ( $all as $m ) {
			$value = (float) str_replace( ',', '', $m[1] );
			if ( $value <= 0 || $value > 10000000 ) {
				continue;
			}
			if ( null === $best || $value > $best ) {
				$best = $value;
			}
		}

		return $best;
	}

	/**
	 * Find a cents-per-unit rate near a label. Handles both "32.45c" and
	 * "$0.3245" wording.
	 *
	 * @param string $flat
	 * @param array  $labels
	 * @return float|null Cents.
	 */
	protected static function labelled_rate( $flat, $labels ) {
		foreach ( $labels as $label ) {
			if ( preg_match( '/' . $label . '[^\n]{0,70}?([0-9]+(?:\.[0-9]+)?)\s*c(?:ents)?\s*(?:\/|per\s*)?(?:kWh|MJ)/i', $flat, $m ) ) {
				return (float) $m[1];
			}
			if ( preg_match( '/' . $label . '[^\n]{0,70}?\$\s*([0-9]+\.[0-9]+)\s*(?:\/|per\s*)?(?:kWh|MJ)/i', $flat, $m ) ) {
				return round( (float) $m[1] * 100, 4 );
			}
		}
		return null;
	}

	/**
	 * Daily supply charge, normalised to cents per day.
	 *
	 * @param string $flat
	 * @return array|null
	 */
	protected static function detect_supply_charge( $flat ) {
		$labels = 'supply\s+charge|daily\s+supply|service\s+(?:to\s+property|charge)|fixed\s+charge|daily\s+charge';

		if ( preg_match( '/(?:' . $labels . ')[^\n]{0,70}?([0-9]+(?:\.[0-9]+)?)\s*c(?:ents)?\s*(?:\/|per\s*)?day/i', $flat, $m ) ) {
			return array(
				'value' => (float) $m[1],
				'score' => 'high',
			);
		}
		if ( preg_match( '/(?:' . $labels . ')[^\n]{0,70}?\$\s*([0-9]+\.[0-9]+)\s*(?:\/|per\s*)?day/i', $flat, $m ) ) {
			return array(
				'value' => round( (float) $m[1] * 100, 4 ),
				'score' => 'high',
			);
		}

		return null;
	}

	/**
	 * Amount due for this bill.
	 *
	 * @param string $flat
	 * @return array|null
	 */
	protected static function detect_total( $flat ) {
		// "Total amount due" is the wrong figure whenever the account carries a
		// previous balance, a credit or a concession: the comparison needs what
		// this period's energy cost, so the new-charges line is tried first.
		$ordered = array(
			array( '/(?:new|current)\s+charges(?:\s+this\s+bill)?[^0-9\-]{0,30}\$?\s*([0-9][0-9,]*\.[0-9]{2})/i', 'high' ),
			array( '/total\s+(?:new\s+)?charges[^0-9\-]{0,30}\$?\s*([0-9][0-9,]*\.[0-9]{2})/i', 'high' ),
			array( '/total\s+this\s+(?:bill|period|account)[^0-9\-]{0,30}\$?\s*([0-9][0-9,]*\.[0-9]{2})/i', 'high' ),
			array( '/charges\s+for\s+this\s+(?:bill|period)[^0-9\-]{0,30}\$?\s*([0-9][0-9,]*\.[0-9]{2})/i', 'high' ),
			array( '/total\s+(?:amount\s+)?(?:due|payable)[^0-9\-]{0,30}\$?\s*([0-9][0-9,]*\.[0-9]{2})/i', 'due' ),
			array( '/amount\s+due[^0-9\-]{0,30}\$?\s*([0-9][0-9,]*\.[0-9]{2})/i', 'due' ),
			array( '/\btotal\b[^0-9\-]{0,20}\$?\s*([0-9][0-9,]*\.[0-9]{2})/i', 'medium' ),
		);

		// A brought-forward balance means the amount due is not this period's
		// charge, so a figure taken from it has to be flagged for checking.
		$has_prior_balance = (bool) preg_match(
			'/(balance\s+(?:brought\s+forward|from\s+previous)|previous\s+balance|opening\s+balance|arrears)/i',
			$flat
		);

		foreach ( $ordered as $pair ) {
			list( $pattern, $score ) = $pair;
			if ( ! preg_match( $pattern, $flat, $m ) ) {
				continue;
			}

			$value = (float) str_replace( ',', '', $m[1] );
			if ( $value <= 0 || $value >= 100000 ) {
				continue;
			}

			if ( 'due' === $score ) {
				$score = $has_prior_balance ? 'low' : 'high';
			}

			return array(
				'value' => $value,
				'score' => $score,
			);
		}

		return null;
	}

	/**
	 * First capture across a list of patterns.
	 *
	 * @param string $subject
	 * @param array  $patterns
	 * @return string|null
	 */
	protected static function first_match( $subject, $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $subject, $m ) && isset( $m[1] ) && '' !== trim( $m[1] ) ) {
				return trim( $m[1] );
			}
		}
		return null;
	}
}
