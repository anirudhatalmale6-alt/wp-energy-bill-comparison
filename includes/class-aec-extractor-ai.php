<?php
/**
 * AI extraction via the Claude Messages API.
 *
 * The request is built with the WordPress HTTP API rather than the official
 * Anthropic PHP SDK on purpose: this is a distributable plugin that has to run
 * on shared hosting where Composer is not available and where a vendored
 * autoloader would collide with other plugins shipping the same library.
 * `wp_remote_post()` is the supported HTTP client in that environment.
 *
 * Two things make the output usable rather than plausible:
 *
 *   - `output_config.format` pins the response to a JSON schema, so there is
 *     no prose to parse and no retry-on-bad-JSON loop.
 *   - the schema allows null everywhere and the prompt forbids guessing, so an
 *     unreadable figure comes back empty for the customer to fill in instead
 *     of arriving as a confident wrong number.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Extractor_AI {

	const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

	/** Request limits published for PDF document input. */
	const MAX_PDF_BYTES = 31457280; // 32 MB request ceiling, less headroom.
	const MAX_PDF_PAGES = 100;

	/**
	 * Is the AI path configured?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) AEC_Settings::get( 'anthropic_api_key' ) );
	}

	/**
	 * Extract bill fields.
	 *
	 * @param array $input text (string), pdf_path (string), page_count (int)
	 * @return array|WP_Error fields, low_confidence, notes, usage
	 */
	public static function extract( $input ) {
		$api_key = trim( (string) AEC_Settings::get( 'anthropic_api_key' ) );
		if ( '' === $api_key ) {
			return new WP_Error( 'aec_ai_no_key', __( 'No Claude API key has been saved, so AI extraction is switched off.', 'aec-energy-compare' ) );
		}

		$content = self::build_content( $input );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$settings = AEC_Settings::all();

		$body = array(
			'model'      => $settings['anthropic_model'],
			'max_tokens' => 16000,
			'system'     => array(
				array(
					'type'          => 'text',
					'text'          => self::system_prompt(),
					// The instructions are identical on every upload, so caching
					// them turns the bulk of the input into a cache read.
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'output_config' => array(
				'effort' => $settings['anthropic_effort'],
				'format' => array(
					'type'   => 'json_schema',
					'schema' => self::schema(),
				),
			),
		);

		$headers = array(
			'x-api-key'         => $api_key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);

		// Claude's safety classifiers can decline a request outright. Opting in
		// to the server-side fallback means a decline is answered by another
		// model in the same call instead of failing the customer's upload.
		if ( ! empty( $settings['anthropic_fallbacks'] ) ) {
			$body['fallbacks']          = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 180,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'aec_ai_http',
				sprintf(
					/* translators: %s: error text */
					__( 'The reading service could not be reached: %s', 'aec-energy-compare' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$detail = '';
			if ( is_array( $json ) && isset( $json['error']['message'] ) ) {
				$detail = $json['error']['message'];
			}
			return new WP_Error(
				'aec_ai_status_' . $code,
				sprintf(
					/* translators: 1: HTTP status, 2: error text */
					__( 'The reading service returned status %1$d. %2$s', 'aec-energy-compare' ),
					$code,
					$detail
				)
			);
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'aec_ai_bad_body', __( 'The reading service sent a response that could not be read.', 'aec-energy-compare' ) );
		}

		// Check why generation stopped before looking at the content: a refusal
		// comes back as a successful request with nothing usable in it.
		$stop = isset( $json['stop_reason'] ) ? $json['stop_reason'] : '';

		if ( 'refusal' === $stop ) {
			return new WP_Error( 'aec_ai_refusal', __( 'The reading service declined to process this document. Please enter your bill details by hand.', 'aec-energy-compare' ) );
		}

		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'aec_ai_truncated', __( 'The document was too long to finish reading. Please upload just the pages with your usage and rates on them.', 'aec-energy-compare' ) );
		}

		$text = '';
		foreach ( (array) ( $json['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'aec_ai_empty', __( 'The reading service returned nothing for this document.', 'aec-energy-compare' ) );
		}

		$parsed = json_decode( $text, true );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'aec_ai_bad_json', __( 'The extracted bill details could not be read.', 'aec-energy-compare' ) );
		}

		return array(
			'fields'         => self::normalise( $parsed ),
			'low_confidence' => isset( $parsed['low_confidence_fields'] ) && is_array( $parsed['low_confidence_fields'] )
				? array_map( 'strval', $parsed['low_confidence_fields'] )
				: array(),
			'notes'          => isset( $parsed['notes'] ) ? (string) $parsed['notes'] : '',
			'model'          => isset( $json['model'] ) ? $json['model'] : $settings['anthropic_model'],
			'usage'          => isset( $json['usage'] ) ? $json['usage'] : array(),
		);
	}

	/**
	 * Build the user content blocks: either the extracted text, or the PDF
	 * itself when there was no text layer to extract.
	 *
	 * @param array $input
	 * @return array|WP_Error
	 */
	protected static function build_content( $input ) {
		$text = isset( $input['text'] ) ? trim( (string) $input['text'] ) : '';
		$path = isset( $input['pdf_path'] ) ? (string) $input['pdf_path'] : '';

		if ( '' !== $text ) {
			return array(
				array(
					'type' => 'text',
					'text' => "Here is the text of an Australian energy bill:\n\n<bill>\n" . $text . "\n</bill>\n\n"
						. 'Extract the fields defined by the schema. Leave anything you cannot read as null.',
				),
			);
		}

		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'aec_ai_no_input', __( 'There was nothing to read in the uploaded file.', 'aec-energy-compare' ) );
		}

		$size = (int) filesize( $path );
		if ( $size > self::MAX_PDF_BYTES ) {
			return new WP_Error( 'aec_ai_too_big', __( 'That PDF is too large to read. Please upload a smaller file or just the pages with your usage on them.', 'aec-energy-compare' ) );
		}

		$pages = isset( $input['page_count'] ) ? (int) $input['page_count'] : 1;
		if ( $pages > self::MAX_PDF_PAGES ) {
			return new WP_Error( 'aec_ai_too_many_pages', __( 'That PDF has too many pages to read. Please upload just the pages with your usage and rates on them.', 'aec-energy-compare' ) );
		}

		$bytes = file_get_contents( $path );
		if ( false === $bytes ) {
			return new WP_Error( 'aec_ai_unreadable', __( 'The uploaded file could not be opened.', 'aec-energy-compare' ) );
		}

		// The document block goes before the instruction: a document placed
		// after the question is noticeably worse at this kind of extraction.
		return array(
			array(
				'type'   => 'document',
				'source' => array(
					'type'       => 'base64',
					'media_type' => 'application/pdf',
					'data'       => base64_encode( $bytes ),
				),
			),
			array(
				'type' => 'text',
				'text' => 'This is a scanned Australian energy bill with no text layer. '
					. 'Read it and extract the fields defined by the schema. Leave anything you cannot read clearly as null.',
			),
		);
	}

	/**
	 * The extraction instructions.
	 *
	 * @return string
	 */
	public static function system_prompt() {
		return <<<'PROMPT'
You read Australian residential electricity and gas bills and return the billing data on them.

Your output feeds a price comparison calculator, so a wrong number is worse than a missing one. If a value is not printed on the bill, is illegible, or you are not sure which figure is the right one, return null for it and add its field path to low_confidence_fields. Never estimate, never infer a figure from another figure, and never fill a field from general knowledge of what a typical bill looks like.

WHICH NUMBERS TO TAKE
- Take usage and charges for the current billing period only. Bills carry comparison material that must be ignored: year-on-year graphs, "same time last year", "average daily usage", neighbourhood comparisons, running annual totals, and the usage history table at the back.
- If the bill covers more than one meter or more than one property, use the meter the usage summary is for and note it in notes.
- Ignore balance brought forward, payments received, concessions, rebates, and late fees when reading total_amount. total_amount is the charge for this billing period: the figure labelled "new charges", "total this bill", or "total amount due" when there is no previous balance. If the only available figure includes an older balance, return null and say so in notes.

UNITS
- All usage_* electricity figures are kWh. Gas usage is MJ.
- Every rate must be returned in cents per unit, to the precision printed. A bill showing $0.3245 per kWh is 32.45. A supply charge of $1.0890 per day is 108.90 cents per day.
- Rates on Australian bills are normally inclusive of GST. Return the figure as printed; do not add or remove GST.
- If a gas bill shows only cubic metres, multiply by the heating value printed on the same bill to get MJ, and list usage.gas_mj in low_confidence_fields. If no heating value is printed, return null.

FIELD NOTES
- fuel: electricity or gas. An NMI and kWh mean electricity; a MIRN or DPI and MJ mean gas.
- retailer_name: the company billing the customer, not the distributor or network operator. A bill lists both; the distributor appears next to fault or emergency numbers.
- plan_name: only if the bill names the plan or offer. Many bills do not. Do not use the tariff code as the plan name.
- nmi is 10 or 11 characters and only appears on electricity bills. mirn is 10 or 11 digits and only appears on gas bills. Return null for whichever does not apply.
- postcode and state are the supply address, not the postal address, when the bill shows both.
- billing_days: the number of days the bill covers. Take it from the bill if printed; otherwise compute it from period_start and period_end inclusive of both days.
- Dates are day-first on Australian bills. Return them as YYYY-MM-DD.
- usage.peak_kwh, usage.shoulder_kwh, usage.offpeak_kwh: only on a time-of-use bill. On a single flat-rate bill leave all three null and fill usage.total_kwh.
- usage.total_kwh: total consumption for the period, excluding controlled load and excluding solar export.
- usage.controlled_load_kwh: separately metered circuits, sometimes labelled dedicated circuit or off-peak hot water. This is not the same as off-peak usage on a time-of-use tariff.
- usage.solar_export_kwh: energy exported to the grid. Do not confuse it with solar generated or self-consumed.
- usage.demand_kw: only when the bill shows a demand or maximum demand charge, in kW or kVA. Return the kW figure.
- rates.*: the rates the customer is currently charged, from the charges table. Where a rate is stepped into blocks, return the first block's rate and describe the blocks in notes.

notes: at most three short sentences, only for things that affect how the numbers should be read. Leave it empty if there is nothing worth saying.
PROMPT;
	}

	/**
	 * The JSON schema the response is pinned to.
	 *
	 * @return array
	 */
	public static function schema() {
		$nullable_number = array(
			'anyOf' => array(
				array( 'type' => 'number' ),
				array( 'type' => 'null' ),
			),
		);
		$nullable_string = array(
			'anyOf' => array(
				array( 'type' => 'string' ),
				array( 'type' => 'null' ),
			),
		);
		$nullable_int = array(
			'anyOf' => array(
				array( 'type' => 'integer' ),
				array( 'type' => 'null' ),
			),
		);

		$usage_keys = array(
			'peak_kwh',
			'shoulder_kwh',
			'offpeak_kwh',
			'total_kwh',
			'controlled_load_kwh',
			'solar_export_kwh',
			'gas_mj',
			'demand_kw',
		);
		$usage_props = array();
		foreach ( $usage_keys as $key ) {
			$usage_props[ $key ] = $nullable_number;
		}

		$rate_keys = array(
			'supply_cents_per_day',
			'single_cents',
			'peak_cents',
			'shoulder_cents',
			'offpeak_cents',
			'controlled_load_cents',
			'gas_cents_per_mj',
			'feedin_cents',
			'demand_cents_per_kw_per_day',
		);
		$rate_props = array();
		foreach ( $rate_keys as $key ) {
			$rate_props[ $key ] = $nullable_number;
		}

		return array(
			'type'       => 'object',
			'properties' => array(
				'fuel'                  => array(
					'anyOf' => array(
						array(
							'type' => 'string',
							'enum' => array( 'electricity', 'gas' ),
						),
						array( 'type' => 'null' ),
					),
				),
				'retailer_name'         => $nullable_string,
				'plan_name'             => $nullable_string,
				'nmi'                   => $nullable_string,
				'mirn'                  => $nullable_string,
				'postcode'              => $nullable_string,
				'state'                 => array(
					'anyOf' => array(
						array(
							'type' => 'string',
							'enum' => array_keys( AEC_DB::states() ),
						),
						array( 'type' => 'null' ),
					),
				),
				'period_start'          => $nullable_string,
				'period_end'            => $nullable_string,
				'billing_days'          => $nullable_int,
				'total_amount'          => $nullable_number,
				'usage'                 => array(
					'type'                 => 'object',
					'properties'           => $usage_props,
					'required'             => $usage_keys,
					'additionalProperties' => false,
				),
				'rates'                 => array(
					'type'                 => 'object',
					'properties'           => $rate_props,
					'required'             => $rate_keys,
					'additionalProperties' => false,
				),
				'low_confidence_fields' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'notes'                 => array( 'type' => 'string' ),
			),
			'required'   => array(
				'fuel',
				'retailer_name',
				'plan_name',
				'nmi',
				'mirn',
				'postcode',
				'state',
				'period_start',
				'period_end',
				'billing_days',
				'total_amount',
				'usage',
				'rates',
				'low_confidence_fields',
				'notes',
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Strip nulls and coerce types so the result matches what the rest of the
	 * plugin expects from the local extractor.
	 *
	 * @param array $parsed
	 * @return array
	 */
	protected static function normalise( $parsed ) {
		$out = array();

		$strings = array( 'fuel', 'retailer_name', 'plan_name', 'nmi', 'mirn', 'postcode', 'state', 'period_start', 'period_end' );
		foreach ( $strings as $key ) {
			if ( isset( $parsed[ $key ] ) && null !== $parsed[ $key ] && '' !== trim( (string) $parsed[ $key ] ) ) {
				$out[ $key ] = trim( (string) $parsed[ $key ] );
			}
		}

		if ( isset( $out['state'] ) ) {
			$out['state'] = strtoupper( $out['state'] );
		}
		if ( isset( $out['postcode'] ) ) {
			$out['postcode'] = preg_replace( '/\D/', '', $out['postcode'] );
			if ( 4 !== strlen( $out['postcode'] ) ) {
				unset( $out['postcode'] );
			}
		}

		if ( isset( $parsed['billing_days'] ) && null !== $parsed['billing_days'] ) {
			$days = (int) $parsed['billing_days'];
			if ( $days > 0 && $days <= 400 ) {
				$out['billing_days'] = $days;
			}
		}

		if ( isset( $parsed['total_amount'] ) && null !== $parsed['total_amount'] ) {
			$out['total_amount'] = round( (float) $parsed['total_amount'], 2 );
		}

		foreach ( array( 'usage', 'rates' ) as $group ) {
			if ( ! isset( $parsed[ $group ] ) || ! is_array( $parsed[ $group ] ) ) {
				continue;
			}
			foreach ( $parsed[ $group ] as $key => $value ) {
				if ( null === $value || '' === $value ) {
					continue;
				}
				$out[ $group ][ sanitize_key( $key ) ] = (float) $value;
			}
		}

		return $out;
	}
}
