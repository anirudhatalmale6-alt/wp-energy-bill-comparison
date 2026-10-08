<?php
/**
 * Admin menus, form handling and asset loading.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'aec-dashboard';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'admin_post_aec_save_retailer', array( __CLASS__, 'save_retailer' ) );
		add_action( 'admin_post_aec_delete_retailer', array( __CLASS__, 'delete_retailer' ) );
		add_action( 'admin_post_aec_save_plan', array( __CLASS__, 'save_plan' ) );
		add_action( 'admin_post_aec_delete_plan', array( __CLASS__, 'delete_plan' ) );
		add_action( 'admin_post_aec_duplicate_plan', array( __CLASS__, 'duplicate_plan' ) );
		add_action( 'admin_post_aec_delete_bill', array( __CLASS__, 'delete_bill' ) );
		add_action( 'admin_post_aec_lead_status', array( __CLASS__, 'lead_status' ) );
		add_action( 'admin_post_aec_delete_lead', array( __CLASS__, 'delete_lead' ) );
		add_action( 'admin_post_aec_export_leads', array( __CLASS__, 'export_leads' ) );
		add_action( 'admin_post_aec_purge_bills', array( __CLASS__, 'purge_bills' ) );
		add_action( 'admin_post_aec_test_api', array( __CLASS__, 'test_api' ) );
		add_action( 'admin_post_aec_seed_demo', array( __CLASS__, 'seed_demo' ) );

		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Energy Comparison', 'aec-energy-compare' ),
			__( 'Energy Compare', 'aec-energy-compare' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'page_dashboard' ),
			'dashicons-chart-area',
			58
		);

		$pages = array(
			self::SLUG      => array( __( 'Dashboard', 'aec-energy-compare' ), 'page_dashboard' ),
			'aec-retailers' => array( __( 'Retailers', 'aec-energy-compare' ), 'page_retailers' ),
			'aec-plans'     => array( __( 'Plans & Tariffs', 'aec-energy-compare' ), 'page_plans' ),
			'aec-bills'     => array( __( 'Uploaded Bills', 'aec-energy-compare' ), 'page_bills' ),
			'aec-leads'     => array( __( 'Enquiries', 'aec-energy-compare' ), 'page_leads' ),
			'aec-settings'  => array( __( 'Settings', 'aec-energy-compare' ), 'page_settings' ),
		);

		foreach ( $pages as $slug => $meta ) {
			add_submenu_page(
				self::SLUG,
				$meta[0],
				$meta[0],
				self::CAP,
				$slug,
				array( __CLASS__, $meta[1] )
			);
		}
	}

	/**
	 * @param string $hook
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'aec-' ) && false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'aec-admin', AEC_URL . 'assets/css/aec-admin.css', array(), AEC_VERSION );
		wp_enqueue_script( 'aec-admin', AEC_URL . 'assets/js/aec-admin.js', array(), AEC_VERSION, true );
	}

	/* --- Views ----------------------------------------------------------- */

	public static function page_dashboard() {
		self::view( 'dashboard.php' );
	}

	public static function page_retailers() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
			$retailer = $id ? AEC_Retailers::get( $id ) : null;
			self::view( 'retailer-edit.php', array( 'retailer' => $retailer ) );
			return;
		}
		self::view( 'retailers.php' );
	}

	public static function page_plans() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
			$plan = $id ? AEC_Plans::get( $id, true ) : null;
			self::view( 'plan-edit.php', array( 'plan' => $plan ) );
			return;
		}
		self::view( 'plans.php' );
	}

	public static function page_bills() {
		$id = isset( $_GET['bill'] ) ? (int) $_GET['bill'] : 0;
		if ( $id ) {
			self::view( 'bill-detail.php', array( 'bill' => AEC_Bills::get_by_id( $id ) ) );
			return;
		}
		self::view( 'bills.php' );
	}

	public static function page_leads() {
		self::view( 'leads.php' );
	}

	public static function page_settings() {
		self::view( 'settings.php' );
	}

	/**
	 * @param string $name
	 * @param array  $context
	 */
	protected static function view( $name, $context = array() ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'aec-energy-compare' ) );
		}

		$file = AEC_DIR . 'admin/views/' . $name;
		if ( ! file_exists( $file ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'View missing.', 'aec-energy-compare' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $context, EXTR_SKIP );

		include $file;
	}

	/* --- Form handling --------------------------------------------------- */

	/**
	 * Shared guard for every admin-post handler.
	 *
	 * @param string $action Nonce action.
	 */
	protected static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aec-energy-compare' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Bounce back to a plugin page with a message code.
	 *
	 * @param string $page
	 * @param string $notice
	 * @param array  $extra
	 */
	protected static function redirect( $page, $notice = '', $extra = array() ) {
		$args = array_merge( array( 'page' => $page ), $extra );
		if ( '' !== $notice ) {
			$args['aec_notice'] = $notice;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save_retailer() {
		self::guard( 'aec_save_retailer' );

		$result = AEC_Retailers::save(
			array(
				'id'            => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
				'name'          => wp_unslash( $_POST['name'] ?? '' ),
				'slug'          => wp_unslash( $_POST['slug'] ?? '' ),
				'phone'         => wp_unslash( $_POST['phone'] ?? '' ),
				'website'       => wp_unslash( $_POST['website'] ?? '' ),
				'logo_url'      => wp_unslash( $_POST['logo_url'] ?? '' ),
				'bill_keywords' => wp_unslash( $_POST['bill_keywords'] ?? '' ),
				'is_active'     => isset( $_POST['is_active'] ) ? 1 : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			set_transient( 'aec_admin_error', $result->get_error_message(), 60 );
			self::redirect( 'aec-retailers', 'error' );
		}

		self::redirect( 'aec-retailers', 'retailer_saved' );
	}

	public static function delete_retailer() {
		self::guard( 'aec_delete_retailer' );

		$count = AEC_Retailers::delete( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );

		if ( $count > 0 ) {
			set_transient(
				'aec_admin_error',
				sprintf(
					/* translators: %d: number of plans */
					_n(
						'The retailer was removed. %d plan belonging to it was switched off rather than deleted.',
						'The retailer was removed. %d plans belonging to it were switched off rather than deleted.',
						$count,
						'aec-energy-compare'
					),
					$count
				),
				60
			);
		}

		self::redirect( 'aec-retailers', 'retailer_deleted' );
	}

	public static function save_plan() {
		self::guard( 'aec_save_plan' );

		$rates = array();
		if ( isset( $_POST['rates'] ) && is_array( $_POST['rates'] ) ) {
			foreach ( wp_unslash( $_POST['rates'] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rates[] = array(
					'rate_group'   => $row['rate_group'] ?? '',
					'block_limit'  => $row['block_limit'] ?? '',
					'block_period' => $row['block_period'] ?? 'daily',
					'unit'         => $row['unit'] ?? '',
					'rate_cents'   => $row['rate_cents'] ?? 0,
				);
			}
		}

		$args = array(
			'id'    => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'rates' => $rates,
		);

		$text_fields = array(
			'name',
			'fuel',
			'state',
			'distributor',
			'postcodes',
			'tariff_type',
			'daily_supply_cents',
			'demand_cents',
			'demand_window',
			'guaranteed_discount',
			'conditional_discount',
			'conditional_discount_label',
			'discount_applies_to',
			'signup_credit',
			'contract_months',
			'exit_fee',
			'green_percent',
			'reference_price_pct',
			'plan_url',
			'notes',
			'valid_from',
			'valid_to',
		);

		foreach ( $text_fields as $field ) {
			$args[ $field ] = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
		}

		$args['retailer_id'] = isset( $_POST['retailer_id'] ) ? (int) $_POST['retailer_id'] : 0;
		$args['is_active']   = isset( $_POST['is_active'] ) ? 1 : 0;

		$result = AEC_Plans::save( $args );

		if ( is_wp_error( $result ) ) {
			set_transient( 'aec_admin_error', $result->get_error_message(), 60 );
			self::redirect( 'aec-plans', 'error' );
		}

		self::redirect( 'aec-plans', 'plan_saved', array( 'action' => 'edit', 'id' => (int) $result ) );
	}

	public static function delete_plan() {
		self::guard( 'aec_delete_plan' );
		AEC_Plans::delete( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
		self::redirect( 'aec-plans', 'plan_deleted' );
	}

	public static function duplicate_plan() {
		self::guard( 'aec_duplicate_plan' );

		$new = AEC_Plans::duplicate( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );

		if ( is_wp_error( $new ) ) {
			set_transient( 'aec_admin_error', $new->get_error_message(), 60 );
			self::redirect( 'aec-plans', 'error' );
		}

		self::redirect( 'aec-plans', 'plan_duplicated', array( 'action' => 'edit', 'id' => (int) $new ) );
	}

	public static function delete_bill() {
		self::guard( 'aec_delete_bill' );
		AEC_Bills::delete( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
		self::redirect( 'aec-bills', 'bill_deleted' );
	}

	public static function purge_bills() {
		self::guard( 'aec_purge_bills' );

		$count = AEC_Bills::purge_expired();

		set_transient(
			'aec_admin_error',
			sprintf(
				/* translators: %d: number of bills */
				_n( '%d old bill was removed.', '%d old bills were removed.', $count, 'aec-energy-compare' ),
				$count
			),
			60
		);

		self::redirect( 'aec-bills' );
	}

	public static function lead_status() {
		self::guard( 'aec_lead_status' );
		AEC_Leads::set_status(
			isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			isset( $_POST['status'] ) ? $_POST['status'] : 'new'
		);
		self::redirect( 'aec-leads', 'lead_updated' );
	}

	public static function delete_lead() {
		self::guard( 'aec_delete_lead' );
		AEC_Leads::delete( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
		self::redirect( 'aec-leads', 'lead_deleted' );
	}

	public static function export_leads() {
		self::guard( 'aec_export_leads' );

		$csv = AEC_Leads::to_csv();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=energy-enquiries-' . gmdate( 'Y-m-d' ) . '.csv' );
		header( 'Content-Length: ' . strlen( $csv ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $csv;
		exit;
	}

	/**
	 * Send one tiny request so the admin finds out the key works here, now,
	 * rather than when the first customer uploads a bill.
	 */
	public static function test_api() {
		self::guard( 'aec_test_api' );

		$key = trim( (string) AEC_Settings::get( 'anthropic_api_key' ) );
		if ( '' === $key ) {
			set_transient( 'aec_admin_error', __( 'Save an API key first.', 'aec-energy-compare' ), 60 );
			self::redirect( 'aec-settings' );
		}

		$response = wp_remote_post(
			AEC_Extractor_AI::ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => AEC_Extractor_AI::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => AEC_Settings::get( 'anthropic_model' ),
						'max_tokens' => 16,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Reply with the single word: ready',
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient(
				'aec_admin_error',
				sprintf(
					/* translators: %s: error */
					__( 'Could not reach the API: %s', 'aec-energy-compare' ),
					$response->get_error_message()
				),
				60
			);
			self::redirect( 'aec-settings' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			set_transient(
				'aec_admin_error',
				sprintf(
					/* translators: %s: model id */
					__( 'The API key works. Model %s answered.', 'aec-energy-compare' ),
					isset( $body['model'] ) ? $body['model'] : AEC_Settings::get( 'anthropic_model' )
				),
				60
			);
		} else {
			set_transient(
				'aec_admin_error',
				sprintf(
					/* translators: 1: status, 2: message */
					__( 'The API answered with status %1$d: %2$s', 'aec-energy-compare' ),
					$code,
					isset( $body['error']['message'] ) ? $body['error']['message'] : ''
				),
				60
			);
		}

		self::redirect( 'aec-settings' );
	}

	/**
	 * Load a small set of worked example plans so the calculator can be tried
	 * before the real tariff data is entered. Rates are placeholders.
	 */
	public static function seed_demo() {
		self::guard( 'aec_seed_demo' );

		require_once AEC_DIR . 'includes/class-aec-demo-data.php';
		$count = AEC_Demo_Data::install();

		set_transient(
			'aec_admin_error',
			sprintf(
				/* translators: %d: number of plans */
				__( '%d sample plans were added. They are placeholders: edit or delete them before going live.', 'aec-energy-compare' ),
				$count
			),
			60
		);

		self::redirect( 'aec-plans' );
	}

	/**
	 * Render queued admin notices.
	 */
	public static function notices() {
		if ( ! isset( $_GET['page'] ) || 0 !== strpos( sanitize_key( $_GET['page'] ), 'aec-' ) ) {
			return;
		}

		$messages = array(
			'retailer_saved'   => __( 'Retailer saved.', 'aec-energy-compare' ),
			'retailer_deleted' => __( 'Retailer removed.', 'aec-energy-compare' ),
			'plan_saved'       => __( 'Plan saved.', 'aec-energy-compare' ),
			'plan_deleted'     => __( 'Plan removed.', 'aec-energy-compare' ),
			'plan_duplicated'  => __( 'Plan duplicated. The copy is switched off until you activate it.', 'aec-energy-compare' ),
			'bill_deleted'     => __( 'Bill removed.', 'aec-energy-compare' ),
			'lead_updated'     => __( 'Enquiry updated.', 'aec-energy-compare' ),
			'lead_deleted'     => __( 'Enquiry removed.', 'aec-energy-compare' ),
		);

		$notice = isset( $_GET['aec_notice'] ) ? sanitize_key( $_GET['aec_notice'] ) : '';

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $messages[ $notice ] )
			);
		}

		$detail = get_transient( 'aec_admin_error' );
		if ( $detail ) {
			delete_transient( 'aec_admin_error' );
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				'error' === $notice ? 'notice-error' : 'notice-info',
				esc_html( $detail )
			);
		}
	}

	/**
	 * Build a nonce-protected POST form with a single button.
	 *
	 * @param string $action
	 * @param array  $fields
	 * @param string $label
	 * @param string $class
	 * @param string $confirm
	 */
	public static function button_form( $action, $fields, $label, $class = 'button', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aec-inline-form"';
		if ( '' !== $confirm ) {
			echo ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"';
		}
		echo '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Simple pagination links.
	 *
	 * @param int   $total
	 * @param int   $per_page
	 * @param int   $current
	 * @param array $base_args
	 */
	public static function pagination( $total, $per_page, $current, $base_args ) {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages < 2 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %s: number of items */
				_n( '%s item', '%s items', $total, 'aec-energy-compare' ),
				number_format_i18n( $total )
			)
		) . '</span>';

		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'base'      => add_query_arg( array_merge( $base_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => max( 1, $current ),
				'total'     => $pages,
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
			)
		);

		echo '</div></div>';
	}
}
