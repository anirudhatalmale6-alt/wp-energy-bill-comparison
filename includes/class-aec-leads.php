<?php
/**
 * Lead capture.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Leads {

	/**
	 * @param array $args bill_id, name, email, phone, preferred_contact,
	 *                    selected_plan_id, estimated_saving, message, consent
	 * @return int|WP_Error Lead id.
	 */
	public static function create( $args ) {
		global $wpdb;

		$name  = isset( $args['name'] ) ? trim( sanitize_text_field( $args['name'] ) ) : '';
		$email = isset( $args['email'] ) ? trim( sanitize_email( $args['email'] ) ) : '';
		$phone = isset( $args['phone'] ) ? trim( sanitize_text_field( $args['phone'] ) ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'aec_lead_name', __( 'Please tell us your name.', 'aec-energy-compare' ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'aec_lead_email', __( 'Please enter a valid email address.', 'aec-energy-compare' ) );
		}
		if ( AEC_Settings::get( 'lead_require_phone' ) && '' === $phone ) {
			return new WP_Error( 'aec_lead_phone', __( 'Please enter a phone number.', 'aec-energy-compare' ) );
		}
		if ( empty( $args['consent'] ) ) {
			return new WP_Error( 'aec_lead_consent', __( 'Please tick the box to say we can contact you.', 'aec-energy-compare' ) );
		}

		$now = current_time( 'mysql' );

		$data = array(
			'bill_id'           => isset( $args['bill_id'] ) ? (int) $args['bill_id'] : 0,
			'name'              => $name,
			'email'             => $email,
			'phone'             => $phone,
			'preferred_contact' => isset( $args['preferred_contact'] ) ? sanitize_key( $args['preferred_contact'] ) : '',
			'selected_plan_id'  => isset( $args['selected_plan_id'] ) ? (int) $args['selected_plan_id'] : 0,
			'estimated_saving'  => ( isset( $args['estimated_saving'] ) && '' !== $args['estimated_saving'] )
				? (float) $args['estimated_saving']
				: null,
			'message'           => isset( $args['message'] ) ? sanitize_textarea_field( $args['message'] ) : '',
			'consent'           => 1,
			// Record the wording that was actually on screen, not today's
			// setting: the consent text can be edited after the fact and the
			// record has to show what the customer agreed to.
			'consent_text'      => isset( $args['consent_text'] ) && '' !== $args['consent_text']
				? sanitize_textarea_field( $args['consent_text'] )
				: (string) AEC_Settings::get( 'consent_text' ),
			'status'            => 'new',
			'ip'                => isset( $_SERVER['REMOTE_ADDR'] ) ? wp_hash( (string) $_SERVER['REMOTE_ADDR'] ) : '',
			'created_at'        => $now,
		);

		$wpdb->insert( AEC_DB::leads(), $data );

		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'aec_lead_insert', __( 'Your details could not be saved. Please try again.', 'aec-energy-compare' ) );
		}

		if ( $data['bill_id'] ) {
			AEC_Bills::set_status( $data['bill_id'], 'converted' );
		}

		self::notify( $id );

		/**
		 * Fires after a lead is stored.
		 *
		 * @param int   $id
		 * @param array $data
		 */
		do_action( 'aec_lead_created', $id, $data );

		return $id;
	}

	/**
	 * Email the site's nominated address, if one is set.
	 *
	 * @param int $id
	 */
	protected static function notify( $id ) {
		$to = trim( (string) AEC_Settings::get( 'lead_notify_email' ) );
		if ( '' === $to ) {
			return;
		}

		$recipients = array_filter( array_map( 'trim', explode( ',', $to ) ), 'is_email' );
		if ( ! $recipients ) {
			return;
		}

		$lead = self::get( $id );
		if ( ! $lead ) {
			return;
		}

		$plan = $lead['selected_plan_id'] ? AEC_Plans::get( (int) $lead['selected_plan_id'], false ) : null;
		$bill = $lead['bill_id'] ? AEC_Bills::get_by_id( (int) $lead['bill_id'] ) : null;

		$lines = array(
			sprintf( __( 'Name: %s', 'aec-energy-compare' ), $lead['name'] ),
			sprintf( __( 'Email: %s', 'aec-energy-compare' ), $lead['email'] ),
			sprintf( __( 'Phone: %s', 'aec-energy-compare' ), $lead['phone'] ? $lead['phone'] : '-' ),
			sprintf( __( 'Preferred contact: %s', 'aec-energy-compare' ), $lead['preferred_contact'] ? $lead['preferred_contact'] : '-' ),
		);

		if ( $plan ) {
			$retailer = AEC_Retailers::get( (int) $plan['retailer_id'] );
			$lines[]  = sprintf(
				__( 'Chosen plan: %1$s - %2$s', 'aec-energy-compare' ),
				$retailer ? $retailer['name'] : '',
				$plan['name']
			);
		}

		if ( null !== $lead['estimated_saving'] ) {
			$lines[] = sprintf(
				__( 'Estimated annual saving: %s', 'aec-energy-compare' ),
				AEC_Settings::get( 'currency_symbol' ) . number_format( (float) $lead['estimated_saving'], 2 )
			);
		}

		if ( $bill ) {
			$lines[] = sprintf( __( 'Fuel: %s', 'aec-energy-compare' ), $bill['fuel'] );
			$lines[] = sprintf( __( 'Current retailer: %s', 'aec-energy-compare' ), $bill['retailer_name'] ? $bill['retailer_name'] : '-' );
			$lines[] = sprintf( __( 'Postcode: %s', 'aec-energy-compare' ), $bill['postcode'] ? $bill['postcode'] : '-' );
		}

		if ( ! empty( $lead['message'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Message:', 'aec-energy-compare' );
			$lines[] = $lead['message'];
		}

		$lines[] = '';
		$lines[] = sprintf(
			__( 'View in the dashboard: %s', 'aec-energy-compare' ),
			admin_url( 'admin.php?page=aec-leads' )
		);

		wp_mail(
			$recipients,
			sprintf(
				/* translators: 1: site name, 2: lead name */
				__( '[%1$s] New energy comparison enquiry from %2$s', 'aec-energy-compare' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				$lead['name']
			),
			implode( "\n", $lines )
		);
	}

	/**
	 * @param int $id
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . AEC_DB::leads() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * @param array $args status, search, limit, offset, orderby, order
	 * @return array
	 */
	public static function all( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'  => '',
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
			$where[]  = 'l.status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(l.name LIKE %s OR l.email LIKE %s OR l.phone LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like ) );
		}

		$allowed = array( 'created_at', 'id', 'name', 'estimated_saving' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? 'l.' . $args['orderby'] : 'l.created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$sql = 'SELECT l.*, p.name AS plan_name, r.name AS retailer_name, b.fuel, b.postcode'
			. ' FROM ' . AEC_DB::leads() . ' l'
			. ' LEFT JOIN ' . AEC_DB::plans() . ' p ON p.id = l.selected_plan_id'
			. ' LEFT JOIN ' . AEC_DB::retailers() . ' r ON r.id = p.retailer_id'
			. ' LEFT JOIN ' . AEC_DB::bills() . ' b ON b.id = l.bill_id'
			. ' WHERE ' . implode( ' AND ', $where )
			. " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * @param array $args
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$args   = wp_parse_args( $args, array( 'status' => '', 'search' => '' ) );
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like ) );
		}

		$sql = 'SELECT COUNT(*) FROM ' . AEC_DB::leads() . ' WHERE ' . implode( ' AND ', $where );
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @param int    $id
	 * @param string $status new|contacted|switched|closed
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$allowed = array( 'new', 'contacted', 'switched', 'closed' );
		$status  = sanitize_key( $status );
		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}
		$wpdb->update( AEC_DB::leads(), array( 'status' => $status ), array( 'id' => (int) $id ) );
	}

	/**
	 * @param int $id
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( AEC_DB::leads(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * CSV export of the whole lead table.
	 *
	 * @return string
	 */
	public static function to_csv() {
		$rows = self::all( array( 'limit' => 10000 ) );

		$handle = fopen( 'php://temp', 'r+' );
		fputcsv(
			$handle,
			array( 'ID', 'Date', 'Name', 'Email', 'Phone', 'Preferred contact', 'Fuel', 'Postcode', 'Retailer', 'Plan', 'Estimated saving', 'Status', 'Message' )
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$handle,
				array(
					$row['id'],
					$row['created_at'],
					$row['name'],
					$row['email'],
					$row['phone'],
					$row['preferred_contact'],
					$row['fuel'],
					$row['postcode'],
					$row['retailer_name'],
					$row['plan_name'],
					$row['estimated_saving'],
					$row['status'],
					$row['message'],
				)
			);
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}
}
