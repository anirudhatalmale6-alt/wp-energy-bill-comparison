<?php
/**
 * Upload handling and extraction orchestration.
 *
 * Decides which extractor runs, merges their results, and records which fields
 * the customer should be asked to check.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Extractor {

	/**
	 * Accept an uploaded file and move it into the private bill directory.
	 *
	 * @param array $file One entry from $_FILES.
	 * @return array|WP_Error path, original_name, size, pages
	 */
	public static function accept_upload( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) ) {
			return new WP_Error( 'aec_upload_missing', __( 'No file was received. Please choose your bill and try again.', 'aec-energy-compare' ) );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'aec_upload_error', self::upload_error_message( (int) $file['error'] ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'aec_upload_invalid', __( 'That upload could not be verified. Please try again.', 'aec-energy-compare' ) );
		}

		$max_bytes = (int) AEC_Settings::get( 'max_upload_mb' ) * 1024 * 1024;
		$size      = (int) ( $file['size'] ?? filesize( $file['tmp_name'] ) );

		if ( $size <= 0 ) {
			return new WP_Error( 'aec_upload_empty', __( 'That file is empty.', 'aec-energy-compare' ) );
		}
		if ( $size > $max_bytes ) {
			return new WP_Error(
				'aec_upload_too_big',
				sprintf(
					/* translators: %d: size in megabytes */
					__( 'That file is larger than %d MB. Please upload a smaller PDF.', 'aec-energy-compare' ),
					(int) AEC_Settings::get( 'max_upload_mb' )
				)
			);
		}

		// Trust the bytes, not the extension or the browser-supplied type.
		if ( ! AEC_PDF::is_pdf( $file['tmp_name'] ) ) {
			return new WP_Error( 'aec_upload_not_pdf', __( 'That does not look like a PDF. Please upload your bill as a PDF file.', 'aec-energy-compare' ) );
		}

		if ( AEC_PDF::is_encrypted( $file['tmp_name'] ) ) {
			return new WP_Error( 'aec_upload_encrypted', __( 'That PDF is password protected, so it cannot be read. Please save an unprotected copy and upload that.', 'aec-energy-compare' ) );
		}

		AEC_Install::protect_upload_dir();
		$dir = AEC_Install::upload_dir();

		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'aec_upload_dir', __( 'The server could not store the uploaded file. Please contact us so we can look into it.', 'aec-energy-compare' ) );
		}

		// The stored name carries no customer information and is not guessable.
		$name = 'bill-' . gmdate( 'Ymd' ) . '-' . wp_generate_password( 20, false, false ) . '.pdf';
		$path = trailingslashit( $dir ) . $name;

		if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) {
			return new WP_Error( 'aec_upload_move', __( 'The uploaded file could not be saved. Please try again.', 'aec-energy-compare' ) );
		}

		// 0600 would be ideal but breaks on hosts running PHP as a different
		// user to the web server; 0644 plus the directory deny is the portable
		// compromise, and the file is deleted after extraction by default.
		@chmod( $path, 0644 );

		return array(
			'path'          => $path,
			'original_name' => isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : 'bill.pdf',
			'size'          => $size,
			'pages'         => AEC_PDF::page_count( $path ),
		);
	}

	/**
	 * Run extraction over a stored PDF.
	 *
	 * @param string $path
	 * @param int    $pages
	 * @return array|WP_Error fields, confidence, extractor, raw_text, warnings
	 */
	public static function run( $path, $pages = 1 ) {
		$mode     = AEC_Settings::get( 'extraction_mode' );
		$warnings = array();

		$pdf  = AEC_PDF::extract_text( $path );
		$text = $pdf['text'];

		$local = array(
			'fields'     => array(),
			'confidence' => array(),
		);

		if ( 'ai_only' !== $mode && '' !== $text ) {
			$local = AEC_Extractor_Regex::extract( $text );
		}

		$used = ( 'ai_only' === $mode ) ? array() : array( 'local' );

		$need_ai = false;
		if ( 'ai_only' === $mode ) {
			$need_ai = true;
		} elseif ( 'local_then_ai' === $mode ) {
			$need_ai = ! self::is_complete( $local['fields'], $local['confidence'] );
		}

		$ai_result = null;

		if ( $need_ai ) {
			if ( ! AEC_Extractor_AI::is_configured() ) {
				if ( 'ai_only' === $mode ) {
					return new WP_Error( 'aec_ai_unconfigured', __( 'Bill reading is not set up yet. Please enter your details by hand.', 'aec-energy-compare' ) );
				}
				$warnings[] = __( 'AI reading is not configured, so only the basic reader was used.', 'aec-energy-compare' );
			} else {
				$ai_result = AEC_Extractor_AI::extract(
					array(
						'text'       => $text,
						'pdf_path'   => $path,
						'page_count' => $pages,
					)
				);

				if ( is_wp_error( $ai_result ) ) {
					// A failed AI call is not fatal when the local reader got
					// something: the customer confirms the figures anyway.
					if ( ! $local['fields'] ) {
						return $ai_result;
					}
					$warnings[] = $ai_result->get_error_message();
					$ai_result  = null;
				} else {
					$used[] = 'ai';
				}
			}
		}

		if ( ! $local['fields'] && ! $ai_result ) {
			return new WP_Error(
				'aec_extract_nothing',
				__( 'Nothing could be read from that PDF. It may be a scan or a photo. Please enter your bill details by hand.', 'aec-energy-compare' )
			);
		}

		$merged = self::merge( $local, $ai_result );

		if ( 'none' === $pdf['method'] && ! $ai_result ) {
			$warnings[] = __( 'That PDF has no readable text layer, so most fields will need filling in.', 'aec-energy-compare' );
		}

		return array(
			'fields'     => $merged['fields'],
			'confidence' => $merged['confidence'],
			'extractor'  => implode( '+', $used ) ? implode( '+', $used ) : 'none',
			'pdf_method' => $pdf['method'],
			'raw_text'   => $text,
			'ai_notes'   => $ai_result && isset( $ai_result['notes'] ) ? $ai_result['notes'] : '',
			'warnings'   => $warnings,
		);
	}

	/**
	 * Did the local reader get enough, confidently enough, to skip the API call?
	 *
	 * @param array $fields
	 * @param array $confidence
	 * @return bool
	 */
	public static function is_complete( $fields, $confidence ) {
		if ( empty( $fields['fuel'] ) ) {
			return false;
		}

		$required = array( 'fuel', 'billing_days', 'total_amount' );
		foreach ( $required as $key ) {
			if ( ! isset( $fields[ $key ] ) ) {
				return false;
			}
			if ( 'high' !== ( $confidence[ $key ] ?? 'low' ) ) {
				return false;
			}
		}

		if ( 'gas' === $fields['fuel'] ) {
			return isset( $fields['usage']['gas_mj'] ) && 'high' === ( $confidence['usage.gas_mj'] ?? 'low' );
		}

		$tou = 0;
		foreach ( array( 'peak_kwh', 'shoulder_kwh', 'offpeak_kwh' ) as $key ) {
			if ( isset( $fields['usage'][ $key ] ) && 'low' !== ( $confidence[ 'usage.' . $key ] ?? 'low' ) ) {
				$tou++;
			}
		}
		if ( $tou >= 2 ) {
			return true;
		}

		return isset( $fields['usage']['total_kwh'] ) && 'high' === ( $confidence['usage.total_kwh'] ?? 'low' );
	}

	/**
	 * Merge the two extractors.
	 *
	 * The AI reader sees the layout of the page, so where both produced a value
	 * it wins. Anything it flagged as uncertain drops to low confidence so the
	 * review screen highlights it.
	 *
	 * @param array      $local
	 * @param array|null $ai
	 * @return array fields, confidence
	 */
	public static function merge( $local, $ai ) {
		$fields     = isset( $local['fields'] ) ? $local['fields'] : array();
		$confidence = isset( $local['confidence'] ) ? $local['confidence'] : array();

		if ( ! $ai || empty( $ai['fields'] ) ) {
			return array(
				'fields'     => $fields,
				'confidence' => $confidence,
			);
		}

		$low = array_fill_keys( isset( $ai['low_confidence'] ) ? $ai['low_confidence'] : array(), true );

		foreach ( $ai['fields'] as $key => $value ) {
			if ( in_array( $key, array( 'usage', 'rates' ), true ) && is_array( $value ) ) {
				foreach ( $value as $sub => $sub_value ) {
					$path                   = $key . '.' . $sub;
					$fields[ $key ][ $sub ] = $sub_value;
					$confidence[ $path ]    = isset( $low[ $path ] ) ? 'low' : 'high';
				}
				continue;
			}
			$fields[ $key ]     = $value;
			$confidence[ $key ] = isset( $low[ $key ] ) ? 'low' : 'high';
		}

		return array(
			'fields'     => $fields,
			'confidence' => $confidence,
		);
	}

	/**
	 * Which fields should the review screen ask the customer to check?
	 *
	 * @param array $fields
	 * @param array $confidence
	 * @return array Field paths.
	 */
	public static function fields_to_check( $fields, $confidence ) {
		$fuel  = isset( $fields['fuel'] ) ? $fields['fuel'] : 'electricity';
		$check = array();

		$wanted = array( 'fuel', 'postcode', 'state', 'billing_days', 'total_amount' );
		$wanted[] = ( 'gas' === $fuel ) ? 'usage.gas_mj' : 'usage.total_kwh';

		foreach ( $wanted as $path ) {
			$value = self::dig( $fields, $path );
			if ( null === $value || '' === $value ) {
				$check[] = $path;
				continue;
			}
			if ( 'high' !== ( $confidence[ $path ] ?? 'low' ) ) {
				$check[] = $path;
			}
		}

		return array_values( array_unique( $check ) );
	}

	/**
	 * Read a dotted path out of a nested array.
	 *
	 * @param array  $data
	 * @param string $path
	 * @return mixed|null
	 */
	public static function dig( $data, $path ) {
		$parts = explode( '.', $path );
		$node  = $data;
		foreach ( $parts as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return null;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * @param int $code PHP upload error code.
	 * @return string
	 */
	protected static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'That file is too large for this server to accept.', 'aec-energy-compare' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Please try again.', 'aec-energy-compare' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was chosen.', 'aec-energy-compare' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not store the upload. Please contact us so we can look into it.', 'aec-energy-compare' );
			default:
				return __( 'The upload failed. Please try again.', 'aec-energy-compare' );
		}
	}
}
