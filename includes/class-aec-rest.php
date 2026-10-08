<?php
/**
 * Front-end REST endpoints.
 *
 * Three steps: upload the bill, compare the (possibly corrected) figures, send
 * the enquiry. Each is a separate request so the customer can edit what was
 * read without re-uploading.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Rest {

	const NS = 'aec/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_upload' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/compare',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_compare' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/lead',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_lead' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
			)
		);
	}

	/**
	 * Open to visitors, but the request must carry a REST nonce. wp.apiFetch
	 * and the bundled script both send one.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public static function public_permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'aec_bad_nonce',
				__( 'Your session has expired. Please reload the page and try again.', 'aec-energy-compare' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Cheap per-visitor throttle. Uploads cost money once the AI reader is on,
	 * so this is a cost control as much as an abuse control.
	 *
	 * @param string $bucket
	 * @param int    $limit
	 * @param int    $window Seconds.
	 * @return true|WP_Error
	 */
	protected static function throttle( $bucket, $limit, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key = 'aec_rl_' . $bucket . '_' . substr( md5( $ip ), 0, 16 );

		$hits = (int) get_transient( $key );
		if ( $hits >= $limit ) {
			return new WP_Error(
				'aec_rate_limited',
				__( 'That is a few too many attempts in a short time. Please wait a minute and try again.', 'aec-energy-compare' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $hits + 1, $window );

		return true;
	}

	/**
	 * Step 1: take the PDF, read it, store it.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_upload( $request ) {
		$throttled = self::throttle( 'upload', 8, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$files = $request->get_file_params();
		$file  = isset( $files['bill'] ) ? $files['bill'] : null;

		$stored = AEC_Extractor::accept_upload( $file );
		if ( is_wp_error( $stored ) ) {
			return self::error( $stored, 400 );
		}

		// Reading a PDF and waiting on the API both take time; give the request
		// room rather than letting it die half way through.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		wp_raise_memory_limit( 'image' );

		$result = AEC_Extractor::run( $stored['path'], $stored['pages'] );

		if ( is_wp_error( $result ) ) {
			// Nothing was read, so keeping the file serves no purpose.
			$base = wp_normalize_path( AEC_Install::upload_dir() );
			if ( 0 === strpos( wp_normalize_path( $stored['path'] ), trailingslashit( $base ) ) ) {
				@unlink( $stored['path'] );
			}
			return self::error( $result, 422 );
		}

		$bill = AEC_Bills::create(
			array(
				'fields'        => $result['fields'],
				'confidence'    => $result['confidence'],
				'raw_text'      => $result['raw_text'],
				'extractor'     => $result['extractor'],
				'file_path'     => $stored['path'],
				'original_name' => $stored['original_name'],
			)
		);

		if ( is_wp_error( $bill ) ) {
			return self::error( $bill, 500 );
		}

		if ( ! AEC_Settings::get( 'retain_pdf' ) ) {
			// The figures are what the site needs; the document itself is the
			// sensitive part, so by default it goes as soon as it is read.
			AEC_Bills::discard_file( (int) $bill['id'] );
		}

		return new WP_REST_Response(
			array(
				'token'      => $bill['token'],
				'fields'     => self::present_fields( $bill ),
				'confidence' => $bill['confidence'],
				'check'      => AEC_Extractor::fields_to_check( $result['fields'], $result['confidence'] ),
				'warnings'   => $result['warnings'],
				'ai_notes'   => $result['ai_notes'],
				'extractor'  => $result['extractor'],
			),
			200
		);
	}

	/**
	 * Step 2: compare, using whatever the customer confirmed on screen.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_compare( $request ) {
		$throttled = self::throttle( 'compare', 60, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$token = (string) $request->get_param( 'token' );
		$bill  = $token ? AEC_Bills::get_by_token( $token ) : null;

		$fields = $request->get_param( 'fields' );
		$fields = is_array( $fields ) ? $fields : array();

		if ( ! $bill && ! $fields ) {
			return self::error(
				new WP_Error( 'aec_compare_nothing', __( 'There is nothing to compare yet.', 'aec-energy-compare' ) ),
				400
			);
		}

		$profile = $bill ? $bill['profile'] : array();
		$profile = self::apply_overrides( $profile, $fields );

		if ( $bill ) {
			// Keep the corrected figures: the review screen is the customer
			// telling us the extractor was wrong, which is worth recording.
			AEC_Bills::update_fields(
				(int) $bill['id'],
				array_merge(
					array(
						'fuel'          => $profile['fuel'],
						'state'         => $profile['state'],
						'postcode'      => $profile['postcode'],
						'billing_days'  => $profile['billing_days'],
						'total_amount'  => $profile['total_amount'],
						'usage'         => $profile['usage'],
						'retailer_name' => $bill['retailer_name'],
						'plan_name'     => $bill['plan_name'],
						'nmi'           => $bill['nmi'],
						'mirn'          => $bill['mirn'],
						'period_start'  => $bill['period_start'],
						'period_end'    => $bill['period_end'],
					),
					array()
				),
				$bill['confidence']
			);
			AEC_Bills::set_status( (int) $bill['id'], 'compared' );
		}

		$comparison = AEC_Calculator::compare( $profile );

		$blocking = array_intersect( $comparison['profile']['notes'], array( 'no_electricity_usage', 'no_gas_usage' ) );
		if ( $blocking ) {
			return self::error(
				new WP_Error(
					'aec_compare_no_usage',
					AEC_Calculator::note_label( reset( $blocking ) )
				),
				422
			);
		}

		return new WP_REST_Response( self::present_comparison( $comparison, $bill ), 200 );
	}

	/**
	 * Step 3: store the enquiry.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_lead( $request ) {
		$throttled = self::throttle( 'lead', 10, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$token = (string) $request->get_param( 'token' );
		$bill  = $token ? AEC_Bills::get_by_token( $token ) : null;

		$lead = AEC_Leads::create(
			array(
				'bill_id'           => $bill ? (int) $bill['id'] : 0,
				'name'              => $request->get_param( 'name' ),
				'email'             => $request->get_param( 'email' ),
				'phone'             => $request->get_param( 'phone' ),
				'preferred_contact' => $request->get_param( 'preferred_contact' ),
				'selected_plan_id'  => $request->get_param( 'plan_id' ),
				'estimated_saving'  => $request->get_param( 'estimated_saving' ),
				'message'           => $request->get_param( 'message' ),
				'consent'           => $request->get_param( 'consent' ),
				'consent_text'      => AEC_Settings::get( 'consent_text' ),
			)
		);

		if ( is_wp_error( $lead ) ) {
			return self::error( $lead, 422 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Thanks. We have your details and will be in touch shortly.', 'aec-energy-compare' ),
			),
			200
		);
	}

	/**
	 * Fold customer corrections into the profile taken off the bill.
	 *
	 * @param array $profile
	 * @param array $fields
	 * @return array
	 */
	protected static function apply_overrides( $profile, $fields ) {
		$profile = wp_parse_args(
			$profile,
			array(
				'fuel'         => 'electricity',
				'state'        => '',
				'postcode'     => '',
				'billing_days' => 0,
				'total_amount' => null,
				'usage'        => array(),
			)
		);

		if ( isset( $fields['fuel'] ) ) {
			$profile['fuel'] = ( 'gas' === $fields['fuel'] ) ? 'gas' : 'electricity';
		}
		if ( isset( $fields['state'] ) ) {
			$state = strtoupper( sanitize_text_field( $fields['state'] ) );
			if ( array_key_exists( $state, AEC_DB::states() ) ) {
				$profile['state'] = $state;
			}
		}
		if ( isset( $fields['postcode'] ) ) {
			$profile['postcode'] = preg_replace( '/\D/', '', (string) $fields['postcode'] );
		}
		if ( isset( $fields['billing_days'] ) && '' !== $fields['billing_days'] ) {
			$profile['billing_days'] = max( 0, min( 400, (int) $fields['billing_days'] ) );
		}
		if ( array_key_exists( 'total_amount', $fields ) ) {
			$profile['total_amount'] = ( '' === $fields['total_amount'] || null === $fields['total_amount'] )
				? null
				: (float) $fields['total_amount'];
		}

		if ( isset( $fields['usage'] ) && is_array( $fields['usage'] ) ) {
			$allowed = array(
				'peak_kwh',
				'shoulder_kwh',
				'offpeak_kwh',
				'total_kwh',
				'controlled_load_kwh',
				'solar_export_kwh',
				'gas_mj',
				'demand_kw',
			);
			foreach ( $allowed as $key ) {
				if ( ! array_key_exists( $key, $fields['usage'] ) ) {
					continue;
				}
				$value = $fields['usage'][ $key ];
				if ( '' === $value || null === $value ) {
					unset( $profile['usage'][ $key ] );
					continue;
				}
				$profile['usage'][ $key ] = max( 0, (float) $value );
			}
		}

		// If the postcode is known but the state is not, the postcode's leading
		// digit narrows it down well enough to filter plans.
		if ( '' === $profile['state'] && '' !== $profile['postcode'] ) {
			$profile['state'] = self::state_from_postcode( $profile['postcode'] );
		}

		return $profile;
	}

	/**
	 * Map a postcode to its state.
	 *
	 * @param string $postcode
	 * @return string
	 */
	public static function state_from_postcode( $postcode ) {
		$pc = (int) preg_replace( '/\D/', '', (string) $postcode );
		if ( $pc <= 0 ) {
			return '';
		}

		$ranges = array(
			array( 1000, 2599, 'NSW' ),
			array( 2600, 2618, 'ACT' ),
			array( 2619, 2899, 'NSW' ),
			array( 2900, 2920, 'ACT' ),
			array( 2921, 2999, 'NSW' ),
			array( 3000, 3999, 'VIC' ),
			array( 4000, 4999, 'QLD' ),
			array( 5000, 5799, 'SA' ),
			array( 6000, 6797, 'WA' ),
			array( 7000, 7799, 'TAS' ),
			array( 800, 899, 'NT' ),
		);

		foreach ( $ranges as $range ) {
			if ( $pc >= $range[0] && $pc <= $range[1] ) {
				return $range[2];
			}
		}

		return '';
	}

	/**
	 * Shape a bill row for the review screen.
	 *
	 * @param array $bill
	 * @return array
	 */
	protected static function present_fields( $bill ) {
		return array(
			'fuel'          => $bill['fuel'],
			'retailer_name' => $bill['retailer_name'],
			'plan_name'     => $bill['plan_name'],
			'nmi'           => $bill['nmi'],
			'mirn'          => $bill['mirn'],
			'postcode'      => $bill['postcode'],
			'state'         => $bill['state'],
			'period_start'  => $bill['period_start'],
			'period_end'    => $bill['period_end'],
			'billing_days'  => (int) $bill['billing_days'],
			'total_amount'  => ( null === $bill['total_amount'] ) ? '' : (float) $bill['total_amount'],
			'usage'         => $bill['usage'],
			'rates'         => $bill['rates'],
		);
	}

	/**
	 * Shape a comparison for the results screen.
	 *
	 * @param array      $comparison
	 * @param array|null $bill
	 * @return array
	 */
	protected static function present_comparison( $comparison, $bill ) {
		$symbol = (string) AEC_Settings::get( 'currency_symbol' );

		$money = function ( $value ) use ( $symbol ) {
			if ( null === $value ) {
				return null;
			}
			$abs = number_format( abs( (float) $value ), 2 );
			return ( $value < 0 ? '-' : '' ) . $symbol . $abs;
		};

		$results = array();
		foreach ( $comparison['results'] as $row ) {
			$flags = array();
			foreach ( $row['flags'] as $flag ) {
				$label = AEC_Calculator::flag_label( $flag );
				if ( '' !== $label ) {
					$flags[] = array(
						'code'  => $flag,
						'label' => $label,
					);
				}
			}

			$lines = array();
			foreach ( $row['lines'] as $line ) {
				$lines[] = array(
					'label'  => $line['label'],
					'detail' => $line['detail'],
					'amount' => $money( $line['amount'] ),
				);
			}

			$results[] = array(
				'rank'                    => $row['rank'],
				'plan_id'                 => $row['plan_id'],
				'plan_name'               => $row['plan_name'],
				'retailer_name'           => $row['retailer_name'],
				'retailer_logo'           => $row['retailer_logo'],
				'plan_url'                => $row['plan_url'],
				'tariff_type'             => $row['tariff_type'],
				'green_percent'           => $row['green_percent'],
				'contract_months'         => $row['contract_months'],
				'exit_fee'                => $row['exit_fee'] > 0 ? $money( $row['exit_fee'] ) : null,
				'signup_credit'           => $row['signup_credit'] > 0 ? $money( $row['signup_credit'] ) : null,
				'conditional_discount'    => $row['conditional_discount'],
				'conditional_label'       => $row['conditional_discount_label'],
				'annual_cost'             => $money( $row['annual_cost'] ),
				'annual_cost_raw'         => $row['annual_cost'],
				'annual_cost_conditional' => $money( $row['annual_cost_conditional'] ),
				'first_year_cost'         => $money( $row['first_year_cost'] ),
				'annual_saving'           => $money( $row['annual_saving'] ),
				'annual_saving_raw'       => $row['annual_saving'],
				'notes'                   => $row['notes'],
				'estimated'               => $row['estimated'],
				'flags'                   => $flags,
				'lines'                   => $lines,
			);
		}

		$notes = array();
		foreach ( $comparison['profile']['notes'] as $note ) {
			$label = AEC_Calculator::note_label( $note );
			if ( '' !== $label ) {
				$notes[] = $label;
			}
		}

		return array(
			'token'          => $bill ? $bill['token'] : '',
			'fuel'           => $comparison['profile']['fuel'],
			'billing_days'   => $comparison['profile']['billing_days'],
			'current_annual' => $money( $comparison['profile']['current_annual'] ),
			'current_bill'   => $money( $comparison['profile']['current_bill'] ),
			'plans_tested'   => $comparison['plans_tested'],
			'results'        => $results,
			'notes'          => $notes,
			'skipped'        => $comparison['skipped'],
			'rank_by'        => $comparison['rank_by'],
		);
	}

	/**
	 * Turn a WP_Error into a REST error with a status code attached.
	 *
	 * @param WP_Error $error
	 * @param int      $status
	 * @return WP_Error
	 */
	protected static function error( $error, $status ) {
		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => $status )
		);
	}
}
