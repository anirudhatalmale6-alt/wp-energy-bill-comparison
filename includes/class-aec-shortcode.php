<?php
/**
 * The [aec_compare] shortcode and its assets.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Shortcode {

	/** @var bool Whether the shortcode has rendered on this request. */
	protected static $rendered = false;

	public static function init() {
		add_shortcode( 'aec_compare', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style(
			'aec-public',
			AEC_URL . 'assets/css/aec-public.css',
			array(),
			AEC_VERSION
		);

		wp_register_script(
			'aec-public',
			AEC_URL . 'assets/js/aec-public.js',
			array(),
			AEC_VERSION,
			true
		);

		/*
		 * The config has to be attached here, while wp_enqueue_scripts is still
		 * running. Attaching it later - from inside the shortcode, which runs
		 * during the_content - enqueues the file but silently drops the data on
		 * WordPress 7.x, leaving the script with no endpoint and no nonce.
		 * Registration is cheap and happens whether or not the shortcode is on
		 * the page; the data only ships if something enqueues the handle.
		 */
		wp_localize_script( 'aec-public', 'AEC_CONFIG', self::config() );
	}

	/**
	 * Everything the front-end script needs from PHP.
	 *
	 * @return array
	 */
	public static function config() {
		$settings = AEC_Settings::all();

		return array(
			'root'     => esc_url_raw( rest_url( AEC_Rest::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'maxMb'    => (int) $settings['max_upload_mb'],
			'currency' => $settings['currency_symbol'],
			'strings'  => array(
				'uploading'   => __( 'Uploading your bill...', 'aec-energy-compare' ),
				'reading'     => __( 'Reading your bill. This usually takes 10 to 30 seconds.', 'aec-energy-compare' ),
				'comparing'   => __( 'Comparing plans...', 'aec-energy-compare' ),
				'sending'     => __( 'Sending...', 'aec-energy-compare' ),
				'genericFail' => __( 'Something went wrong. Please try again.', 'aec-energy-compare' ),
				'tooBig'      => __( 'That file is too large.', 'aec-energy-compare' ),
				'notPdf'      => __( 'Please choose a PDF file.', 'aec-energy-compare' ),
				'noResults'   => __( 'No plans matched your details yet. Check your postcode, or get in touch and we will look into it for you.', 'aec-energy-compare' ),
				'perYear'     => __( 'per year', 'aec-energy-compare' ),
				'saving'      => __( 'Estimated saving', 'aec-energy-compare' ),
				'costsMore'   => __( 'Costs more', 'aec-energy-compare' ),
				'details'     => __( 'See how this is worked out', 'aec-energy-compare' ),
				'choose'      => __( 'Choose this plan', 'aec-energy-compare' ),
				'estimate'    => __( 'Estimate', 'aec-energy-compare' ),
				'fieldCheck'  => _x( 'check', 'badge on a field the reader was unsure about', 'aec-energy-compare' ),
				'bestValue'   => __( 'Best value', 'aec-energy-compare' ),
				'yourBill'    => __( 'Your bill', 'aec-energy-compare' ),
				'perYearNow'  => __( 'That is per year', 'aec-energy-compare' ),
				'cheapest'    => __( 'Cheapest found', 'aec-energy-compare' ),
				'annualCost'  => __( 'Estimated annual cost', 'aec-energy-compare' ),
				'withCond'    => __( 'With the %s%% conditional discount', 'aec-energy-compare' ),
				'planDetails' => __( 'Plan details', 'aec-energy-compare' ),
				'noFixedTerm' => __( 'No fixed term', 'aec-energy-compare' ),
				'monthTerm'   => __( '%d month contract', 'aec-energy-compare' ),
				'exitFee'     => __( 'Exit fee %s', 'aec-energy-compare' ),
				'credit'      => __( '%s sign-up credit', 'aec-energy-compare' ),
				'greenPower'  => __( '%d%% GreenPower', 'aec-energy-compare' ),
				'youPicked'   => __( 'You picked %1$s %2$s.', 'aec-energy-compare' ),
				'savingLine'  => __( 'Estimated saving of %s a year.', 'aec-energy-compare' ),
				'leadPrompt'  => __( 'Leave your details and we will take it from here.', 'aec-energy-compare' ),
				'sendDetails' => __( 'Send my details', 'aec-energy-compare' ),
				'tariffs'     => array(
					'single'     => __( 'Single rate', 'aec-energy-compare' ),
					'tou'        => __( 'Time of use', 'aec-energy-compare' ),
					'demand'     => __( 'Single rate + demand', 'aec-energy-compare' ),
					'tou_demand' => __( 'Time of use + demand', 'aec-energy-compare' ),
					'block'      => __( 'Block rate', 'aec-energy-compare' ),
				),
			),
		);
	}

	/**
	 * @param array $atts fuel, state, title
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'fuel'  => '',
				'state' => '',
				'title' => '',
			),
			$atts,
			'aec_compare'
		);

		wp_enqueue_style( 'aec-public' );
		wp_enqueue_script( 'aec-public' );

		$settings = AEC_Settings::all();

		// A second instance on the same page would fight over element ids.
		if ( self::$rendered ) {
			return '<p class="aec-notice aec-notice--warn">'
				. esc_html__( 'The comparison form can only appear once on a page.', 'aec-energy-compare' )
				. '</p>';
		}
		self::$rendered = true;

		$context = array(
			'atts'     => $atts,
			'settings' => $settings,
			'states'   => AEC_DB::states(),
		);

		return self::template( 'compare-form.php', $context );
	}

	/**
	 * Render a template, letting the active theme override it from
	 * `aec-energy-compare/<name>`.
	 *
	 * @param string $name
	 * @param array  $context
	 * @return string
	 */
	public static function template( $name, $context = array() ) {
		$override = locate_template( array( 'aec-energy-compare/' . $name ) );
		$file     = $override ? $override : AEC_DIR . 'templates/' . $name;

		if ( ! file_exists( $file ) ) {
			return '';
		}

		// Templates read $atts, $settings and $states.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $context, EXTR_SKIP );

		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Inline the accent colour so the form picks up the site's branding without
	 * needing a build step.
	 *
	 * @return string
	 */
	public static function accent_style() {
		$colour = AEC_Settings::get( 'accent_colour' );
		return 'style="--aec-accent: ' . esc_attr( $colour ) . ';"';
	}
}
