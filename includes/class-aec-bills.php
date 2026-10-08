<?php
/**
 * Stored bills: the record of one upload and what was read off it.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Bills {

	/**
	 * Create a bill record.
	 *
	 * @param array $args
	 * @return array|WP_Error The stored row.
	 */
	public static function create( $args ) {
		global $wpdb;

		$now   = current_time( 'mysql' );
		$token = self::new_token();

		$data = array(
			'token'         => $token,
			'status'        => 'extracted',
			'extractor'     => isset( $args['extractor'] ) ? sanitize_text_field( $args['extractor'] ) : '',
			'file_path'     => isset( $args['file_path'] ) ? (string) $args['file_path'] : '',
			'original_name' => isset( $args['original_name'] ) ? sanitize_file_name( $args['original_name'] ) : '',
			'ip'            => self::hashed_ip(),
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		$wpdb->insert( AEC_DB::bills(), $data );

		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'aec_bill_insert', __( 'The bill could not be saved.', 'aec-energy-compare' ) );
		}

		$updated = self::update_fields(
			$id,
			isset( $args['fields'] ) ? $args['fields'] : array(),
			isset( $args['confidence'] ) ? $args['confidence'] : array(),
			isset( $args['raw_text'] ) ? $args['raw_text'] : ''
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return self::get_by_id( $id );
	}

	/**
	 * Write the extracted field set onto a bill.
	 *
	 * @param int    $id
	 * @param array  $fields
	 * @param array  $confidence
	 * @param string $raw_text
	 * @return true|WP_Error
	 */
	public static function update_fields( $id, $fields, $confidence = array(), $raw_text = null ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return new WP_Error( 'aec_bill_id', __( 'Unknown bill.', 'aec-energy-compare' ) );
		}

		$fuel = ( isset( $fields['fuel'] ) && 'gas' === $fields['fuel'] ) ? 'gas' : 'electricity';

		$usage = array();
		if ( isset( $fields['usage'] ) && is_array( $fields['usage'] ) ) {
			foreach ( $fields['usage'] as $key => $value ) {
				if ( null === $value || '' === $value ) {
					continue;
				}
				$usage[ sanitize_key( $key ) ] = (float) $value;
			}
		}

		$rates = array();
		if ( isset( $fields['rates'] ) && is_array( $fields['rates'] ) ) {
			foreach ( $fields['rates'] as $key => $value ) {
				if ( null === $value || '' === $value ) {
					continue;
				}
				$rates[ sanitize_key( $key ) ] = (float) $value;
			}
		}

		$data = array(
			'fuel'            => $fuel,
			'retailer_name'   => isset( $fields['retailer_name'] ) ? sanitize_text_field( $fields['retailer_name'] ) : '',
			'plan_name'       => isset( $fields['plan_name'] ) ? sanitize_text_field( $fields['plan_name'] ) : '',
			'nmi'             => isset( $fields['nmi'] ) ? sanitize_text_field( $fields['nmi'] ) : '',
			'mirn'            => isset( $fields['mirn'] ) ? sanitize_text_field( $fields['mirn'] ) : '',
			'postcode'        => isset( $fields['postcode'] ) ? preg_replace( '/\D/', '', (string) $fields['postcode'] ) : '',
			'state'           => isset( $fields['state'] ) ? strtoupper( sanitize_text_field( $fields['state'] ) ) : '',
			'period_start'    => isset( $fields['period_start'] ) ? self::date_or_null( $fields['period_start'] ) : null,
			'period_end'      => isset( $fields['period_end'] ) ? self::date_or_null( $fields['period_end'] ) : null,
			'billing_days'    => isset( $fields['billing_days'] ) ? max( 0, (int) $fields['billing_days'] ) : 0,
			'total_amount'    => ( isset( $fields['total_amount'] ) && '' !== $fields['total_amount'] ) ? (float) $fields['total_amount'] : null,
			'usage_json'      => wp_json_encode( $usage ),
			'rates_json'      => wp_json_encode( $rates ),
			'confidence_json' => wp_json_encode( is_array( $confidence ) ? $confidence : array() ),
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( null !== $raw_text ) {
			$data['raw_text'] = AEC_Settings::get( 'retain_raw_text' ) ? (string) $raw_text : '';
		}

		$wpdb->update( AEC_DB::bills(), $data, array( 'id' => $id ) );

		return true;
	}

	/**
	 * @param int    $id
	 * @param string $status
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update(
			AEC_DB::bills(),
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Delete the stored PDF, keeping the extracted data.
	 *
	 * @param int $id
	 */
	public static function discard_file( $id ) {
		global $wpdb;

		$row = self::get_by_id( $id );
		if ( ! $row ) {
			return;
		}

		if ( ! empty( $row['file_path'] ) && file_exists( $row['file_path'] ) ) {
			// Only ever unlink inside the plugin's own directory.
			$base = wp_normalize_path( AEC_Install::upload_dir() );
			$file = wp_normalize_path( $row['file_path'] );
			if ( 0 === strpos( $file, trailingslashit( $base ) ) ) {
				@unlink( $file );
			}
		}

		$wpdb->update(
			AEC_DB::bills(),
			array(
				'file_path'  => '',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * @param int $id
	 * @return array|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . AEC_DB::bills() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param string $token
	 * @return array|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . AEC_DB::bills() . ' WHERE token = %s', $token ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Decode the JSON columns and build a profile the calculator can take.
	 *
	 * @param array $row
	 * @return array
	 */
	protected static function hydrate( $row ) {
		$row['usage']      = self::decode( $row['usage_json'] );
		$row['rates']      = self::decode( $row['rates_json'] );
		$row['confidence'] = self::decode( $row['confidence_json'] );
		$row['profile']    = array(
			'fuel'         => $row['fuel'],
			'state'        => $row['state'],
			'postcode'     => $row['postcode'],
			'billing_days' => (int) $row['billing_days'],
			'total_amount' => ( null === $row['total_amount'] ) ? null : (float) $row['total_amount'],
			'usage'        => $row['usage'],
		);
		return $row;
	}

	/**
	 * @param string $json
	 * @return array
	 */
	protected static function decode( $json ) {
		$out = json_decode( (string) $json, true );
		return is_array( $out ) ? $out : array();
	}

	/**
	 * @param string $value
	 * @return string|null
	 */
	protected static function date_or_null( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		$parsed = AEC_Extractor_Regex::parse_date( $value );
		return $parsed ? $parsed : null;
	}

	/**
	 * A token the customer's browser can carry between the upload, review and
	 * results steps without exposing the row id.
	 *
	 * @return string
	 */
	protected static function new_token() {
		return wp_generate_password( 40, false, false );
	}

	/**
	 * Store a salted hash of the IP rather than the address itself: it still
	 * works for rate limiting and abuse checks without keeping personal data.
	 *
	 * @return string
	 */
	protected static function hashed_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if ( '' === $ip ) {
			return '';
		}
		return wp_hash( $ip );
	}

	/**
	 * Admin listing.
	 *
	 * @param array $args status, fuel, search, limit, offset, orderby, order
	 * @return array
	 */
	public static function all( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'  => '',
				'fuel'    => '',
				'search'  => '',
				'limit'   => 20,
				'offset'  => 0,
				'orderby' => 'created_at',
				'order'   => 'DESC',
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['fuel'] ) {
			$where[]  = 'fuel = %s';
			$params[] = $args['fuel'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(retailer_name LIKE %s OR plan_name LIKE %s OR postcode LIKE %s OR nmi LIKE %s OR mirn LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		$allowed = array( 'created_at', 'id', 'total_amount', 'fuel' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$sql = 'SELECT * FROM ' . AEC_DB::bills() . ' WHERE ' . implode( ' AND ', $where )
			. " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::hydrate( $row );
		}

		return $out;
	}

	/**
	 * @param array $args
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$args   = wp_parse_args( $args, array( 'status' => '', 'fuel' => '', 'search' => '' ) );
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['fuel'] ) {
			$where[]  = 'fuel = %s';
			$params[] = $args['fuel'];
		}
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(retailer_name LIKE %s OR plan_name LIKE %s OR postcode LIKE %s OR nmi LIKE %s OR mirn LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		$sql = 'SELECT COUNT(*) FROM ' . AEC_DB::bills() . ' WHERE ' . implode( ' AND ', $where );
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @param int $id
	 */
	public static function delete( $id ) {
		global $wpdb;
		self::discard_file( $id );
		$wpdb->delete( AEC_DB::bills(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete bills older than the retention window, except ones attached to a
	 * lead. Those are business records and are left to the admin to remove.
	 *
	 * @return int Number removed.
	 */
	public static function purge_expired() {
		global $wpdb;

		$days = (int) AEC_Settings::get( 'bill_retention_days' );
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT b.id FROM ' . AEC_DB::bills() . ' b'
				. ' LEFT JOIN ' . AEC_DB::leads() . ' l ON l.bill_id = b.id'
				. ' WHERE b.created_at < %s AND l.id IS NULL',
				$cutoff
			)
		);

		foreach ( (array) $ids as $id ) {
			self::delete( (int) $id );
		}

		return count( (array) $ids );
	}
}
