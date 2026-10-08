<?php
/**
 * Retailer CRUD.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Retailers {

	/**
	 * @param array $args name, phone, website, logo_url, bill_keywords, is_active
	 * @return int|WP_Error Retailer id.
	 */
	public static function save( $args ) {
		global $wpdb;

		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$name = isset( $args['name'] ) ? trim( sanitize_text_field( $args['name'] ) ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'aec_retailer_name', __( 'A retailer needs a name.', 'aec-energy-compare' ) );
		}

		$slug = isset( $args['slug'] ) && '' !== trim( (string) $args['slug'] )
			? sanitize_title( $args['slug'] )
			: sanitize_title( $name );
		$slug = self::unique_slug( $slug, $id );

		$now  = current_time( 'mysql' );
		$data = array(
			'name'          => $name,
			'slug'          => $slug,
			'phone'         => isset( $args['phone'] ) ? sanitize_text_field( $args['phone'] ) : '',
			'website'       => isset( $args['website'] ) ? esc_url_raw( $args['website'] ) : '',
			'logo_url'      => isset( $args['logo_url'] ) ? esc_url_raw( $args['logo_url'] ) : '',
			'bill_keywords' => isset( $args['bill_keywords'] ) ? sanitize_textarea_field( $args['bill_keywords'] ) : '',
			'is_active'     => empty( $args['is_active'] ) ? 0 : 1,
			'updated_at'    => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( $id > 0 ) {
			$wpdb->update( AEC_DB::retailers(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		$data['created_at'] = $now;
		$formats[]          = '%s';

		$wpdb->insert( AEC_DB::retailers(), $data, $formats );

		if ( ! $wpdb->insert_id ) {
			return new WP_Error( 'aec_retailer_insert', __( 'The retailer could not be saved.', 'aec-energy-compare' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Make a slug unique, ignoring the row being edited.
	 *
	 * @param string $slug
	 * @param int    $ignore_id
	 * @return string
	 */
	protected static function unique_slug( $slug, $ignore_id = 0 ) {
		global $wpdb;

		$slug = $slug ? $slug : 'retailer';
		$base = $slug;
		$i    = 2;

		while ( true ) {
			$found = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . AEC_DB::retailers() . ' WHERE slug = %s AND id <> %d LIMIT 1',
					$slug,
					$ignore_id
				)
			);
			if ( ! $found ) {
				return $slug;
			}
			$slug = $base . '-' . $i;
			$i++;
		}
	}

	/**
	 * @param int $id
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . AEC_DB::retailers() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * @param array $args active_only, search, orderby, order, limit, offset
	 * @return array
	 */
	public static function all( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'active_only' => false,
				'search'      => '',
				'orderby'     => 'name',
				'order'       => 'ASC',
				'limit'       => 0,
				'offset'      => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['active_only'] ) {
			$where[] = 'is_active = 1';
		}

		if ( '' !== $args['search'] ) {
			$where[]  = 'name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$allowed_orderby = array( 'name', 'id', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
		$order           = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$sql = 'SELECT * FROM ' . AEC_DB::retailers() . ' WHERE ' . implode( ' AND ', $where )
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
		return $rows ? $rows : array();
	}

	/**
	 * @param array $args Same shape as all(), minus paging.
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$args   = wp_parse_args( $args, array( 'active_only' => false, 'search' => '' ) );
		$where  = array( '1=1' );
		$params = array();

		if ( $args['active_only'] ) {
			$where[] = 'is_active = 1';
		}
		if ( '' !== $args['search'] ) {
			$where[]  = 'name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$sql = 'SELECT COUNT(*) FROM ' . AEC_DB::retailers() . ' WHERE ' . implode( ' AND ', $where );
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Delete a retailer. Plans belonging to it are deactivated rather than
	 * destroyed, so an accidental delete does not silently lose tariff data.
	 *
	 * @param int $id
	 * @return int Number of plans deactivated.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return 0;
		}

		$deactivated = (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . AEC_DB::plans() . ' SET is_active = 0, updated_at = %s WHERE retailer_id = %d',
				current_time( 'mysql' ),
				$id
			)
		);

		$wpdb->delete( AEC_DB::retailers(), array( 'id' => $id ), array( '%d' ) );

		return $deactivated;
	}

	/**
	 * id => name map, handy for select fields.
	 *
	 * @param bool $active_only
	 * @return array
	 */
	public static function options( $active_only = false ) {
		$out = array();
		foreach ( self::all( array( 'active_only' => $active_only ) ) as $row ) {
			$out[ (int) $row['id'] ] = $row['name'];
		}
		return $out;
	}

	/**
	 * Match a retailer against free text pulled off a bill.
	 *
	 * Checks the retailer name first, then any extra keywords the admin has
	 * recorded (trading names, billing entity names, support line numbers).
	 *
	 * @param string $text
	 * @return array|null
	 */
	public static function match_in_text( $text ) {
		if ( '' === trim( (string) $text ) ) {
			return null;
		}

		$haystack = strtolower( $text );
		$best     = null;
		$best_len = 0;

		foreach ( self::all( array( 'active_only' => true ) ) as $row ) {
			$needles = array( $row['name'] );
			if ( ! empty( $row['bill_keywords'] ) ) {
				$needles = array_merge( $needles, preg_split( '/[\r\n,]+/', $row['bill_keywords'] ) );
			}

			foreach ( $needles as $needle ) {
				$needle = strtolower( trim( (string) $needle ) );
				if ( strlen( $needle ) < 3 ) {
					continue;
				}
				// Longest match wins, so "Origin Energy" beats a stray "Origin".
				if ( false !== strpos( $haystack, $needle ) && strlen( $needle ) > $best_len ) {
					$best     = $row;
					$best_len = strlen( $needle );
				}
			}
		}

		return $best;
	}
}
