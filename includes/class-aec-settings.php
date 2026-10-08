<?php
/**
 * Plugin options, their defaults, and sanitisation.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Settings {

	const OPTION = 'aec_settings';

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Extraction.
			'extraction_mode'     => 'local_then_ai', // local_only | ai_only | local_then_ai
			'ai_provider'         => 'anthropic',
			'anthropic_api_key'   => '',
			'anthropic_model'     => 'claude-opus-5',
			'anthropic_effort'    => 'high',
			'anthropic_fallbacks' => 1,
			'max_upload_mb'       => 12,
			'retain_pdf'          => 0,
			'retain_raw_text'     => 1,
			'bill_retention_days' => 90,

			// Comparison.
			'rates_include_gst'   => 1,
			'results_count'       => 5,
			'rank_by'             => 'guaranteed', // guaranteed | conditional
			'tou_profile_peak'    => 35,
			'tou_profile_shoulder'=> 25,
			'tou_profile_offpeak' => 40,
			'show_estimated_tou'  => 1,

			// Leads.
			'lead_notify_email'   => '',
			'lead_require_phone'  => 0,
			'consent_text'        => 'I agree to be contacted about energy plans and accept the privacy policy.',
			'privacy_url'         => '',

			// Presentation.
			'currency_symbol'     => '$',
			'accent_colour'       => '#0b6b53',
			'intro_text'          => 'Upload your latest electricity or gas bill and we will work out what you could be paying instead.',
		);
	}

	/**
	 * Write any missing defaults without clobbering existing values.
	 */
	public static function install_defaults() {
		$current = get_option( self::OPTION );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( self::OPTION, array_merge( self::defaults(), $current ) );
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key
	 * @param mixed  $fallback
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $fallback;
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_setting(
			'aec_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitise a submitted settings array.
	 *
	 * @param mixed $input
	 * @return array
	 */
	public static function sanitize( $input ) {
		$existing = self::all();
		$out      = $existing;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$modes = array( 'local_only', 'ai_only', 'local_then_ai' );
		if ( isset( $input['extraction_mode'] ) && in_array( $input['extraction_mode'], $modes, true ) ) {
			$out['extraction_mode'] = $input['extraction_mode'];
		}

		if ( isset( $input['anthropic_api_key'] ) ) {
			$key = trim( (string) $input['anthropic_api_key'] );
			// An all-asterisk value means the admin left the masked field alone.
			if ( '' === $key || ! preg_match( '/^\*+$/', $key ) ) {
				$out['anthropic_api_key'] = sanitize_text_field( $key );
			}
		}

		$models = array( 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5' );
		if ( isset( $input['anthropic_model'] ) && in_array( $input['anthropic_model'], $models, true ) ) {
			$out['anthropic_model'] = $input['anthropic_model'];
		}

		$efforts = array( 'low', 'medium', 'high', 'xhigh', 'max' );
		if ( isset( $input['anthropic_effort'] ) && in_array( $input['anthropic_effort'], $efforts, true ) ) {
			$out['anthropic_effort'] = $input['anthropic_effort'];
		}

		foreach ( array( 'anthropic_fallbacks', 'retain_pdf', 'retain_raw_text', 'rates_include_gst', 'show_estimated_tou', 'lead_require_phone' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$out['max_upload_mb']       = max( 1, min( 30, (int) ( $input['max_upload_mb'] ?? 12 ) ) );
		$out['bill_retention_days'] = max( 0, min( 3650, (int) ( $input['bill_retention_days'] ?? 90 ) ) );
		$out['results_count']       = max( 1, min( 20, (int) ( $input['results_count'] ?? 5 ) ) );

		$out['rank_by'] = ( isset( $input['rank_by'] ) && 'conditional' === $input['rank_by'] ) ? 'conditional' : 'guaranteed';

		// The three TOU shares must add to 100, otherwise the estimate silently
		// invents or loses usage.
		$peak     = max( 0, min( 100, (int) ( $input['tou_profile_peak'] ?? 35 ) ) );
		$shoulder = max( 0, min( 100, (int) ( $input['tou_profile_shoulder'] ?? 25 ) ) );
		$offpeak  = max( 0, min( 100, (int) ( $input['tou_profile_offpeak'] ?? 40 ) ) );
		$total    = $peak + $shoulder + $offpeak;
		if ( 100 !== $total ) {
			if ( $total > 0 ) {
				$peak     = (int) round( $peak * 100 / $total );
				$shoulder = (int) round( $shoulder * 100 / $total );
				$offpeak  = 100 - $peak - $shoulder;
			} else {
				$peak     = 35;
				$shoulder = 25;
				$offpeak  = 40;
			}
			// add_settings_error() lives in wp-admin and is not loaded on every
			// request that can reach this sanitiser.
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION,
					'aec_tou_profile',
					__( 'The time-of-use profile percentages did not add up to 100, so they have been rescaled.', 'aec-energy-compare' ),
					'warning'
				);
			}
		}
		$out['tou_profile_peak']     = $peak;
		$out['tou_profile_shoulder'] = $shoulder;
		$out['tou_profile_offpeak']  = $offpeak;

		$out['lead_notify_email'] = isset( $input['lead_notify_email'] ) ? sanitize_text_field( $input['lead_notify_email'] ) : '';
		$out['consent_text']      = isset( $input['consent_text'] ) ? sanitize_textarea_field( $input['consent_text'] ) : '';
		$out['privacy_url']       = isset( $input['privacy_url'] ) ? esc_url_raw( $input['privacy_url'] ) : '';
		$out['currency_symbol']   = isset( $input['currency_symbol'] ) ? sanitize_text_field( $input['currency_symbol'] ) : '$';
		$out['intro_text']        = isset( $input['intro_text'] ) ? sanitize_textarea_field( $input['intro_text'] ) : '';

		$colour = isset( $input['accent_colour'] ) ? sanitize_hex_color( $input['accent_colour'] ) : '';
		$out['accent_colour'] = $colour ? $colour : '#0b6b53';

		return $out;
	}

	/**
	 * The default time-of-use split, as fractions that sum to 1.
	 *
	 * @return array
	 */
	public static function tou_profile() {
		$all = self::all();
		return array(
			'peak'     => $all['tou_profile_peak'] / 100,
			'shoulder' => $all['tou_profile_shoulder'] / 100,
			'offpeak'  => $all['tou_profile_offpeak'] / 100,
		);
	}
}
