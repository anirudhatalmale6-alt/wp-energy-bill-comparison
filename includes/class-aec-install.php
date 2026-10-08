<?php
/**
 * Schema creation, upgrades and the private upload directory.
 */

defined( 'ABSPATH' ) || exit;

class AEC_Install {

	const DB_OPTION = 'aec_db_version';

	/**
	 * Runs on activation.
	 */
	public static function activate() {
		self::install_schema();
		self::protect_upload_dir();
		AEC_Settings::install_defaults();
		update_option( self::DB_OPTION, AEC_DB_VERSION );
		// Rewrite rules are not used by this plugin, but the REST routes are
		// registered on init, so flushing keeps permalinks tidy on fresh sites.
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Re-run the schema when the plugin files were replaced without activating.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) === AEC_DB_VERSION ) {
			return;
		}
		self::install_schema();
		self::protect_upload_dir();
		AEC_Settings::install_defaults();
		update_option( self::DB_OPTION, AEC_DB_VERSION );
	}

	/**
	 * Absolute path of the directory uploaded bills are written to.
	 *
	 * @return string
	 */
	public static function upload_dir() {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'aec-bills';
	}

	/**
	 * Create the bill directory and make it as unreadable from the web as the
	 * server allows. Deleting the PDF after extraction (the default) is the
	 * real protection; this is the belt to that braces.
	 */
	public static function protect_upload_dir() {
		$dir = self::upload_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
		}

		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}
	}

	/**
	 * Create or update every table.
	 */
	public static function install_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$retailers = AEC_DB::retailers();
		$plans     = AEC_DB::plans();
		$rates     = AEC_DB::rates();
		$bills     = AEC_DB::bills();
		$leads     = AEC_DB::leads();

		$sql = array();

		$sql[] = "CREATE TABLE {$retailers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			website varchar(255) NOT NULL DEFAULT '',
			logo_url varchar(255) NOT NULL DEFAULT '',
			bill_keywords text NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY is_active (is_active)
		) {$charset};";

		$sql[] = "CREATE TABLE {$plans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			retailer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			fuel varchar(20) NOT NULL DEFAULT 'electricity',
			state varchar(10) NOT NULL DEFAULT '',
			distributor varchar(120) NOT NULL DEFAULT '',
			postcodes text NULL,
			tariff_type varchar(30) NOT NULL DEFAULT 'single',
			daily_supply_cents decimal(12,4) NOT NULL DEFAULT 0,
			demand_cents decimal(12,4) NOT NULL DEFAULT 0,
			demand_window varchar(190) NOT NULL DEFAULT '',
			guaranteed_discount decimal(7,3) NOT NULL DEFAULT 0,
			conditional_discount decimal(7,3) NOT NULL DEFAULT 0,
			conditional_discount_label varchar(190) NOT NULL DEFAULT '',
			discount_applies_to varchar(20) NOT NULL DEFAULT 'usage',
			signup_credit decimal(12,2) NOT NULL DEFAULT 0,
			contract_months int(11) NOT NULL DEFAULT 0,
			exit_fee decimal(12,2) NOT NULL DEFAULT 0,
			green_percent int(11) NOT NULL DEFAULT 0,
			reference_price_pct decimal(7,2) NOT NULL DEFAULT 0,
			plan_url varchar(255) NOT NULL DEFAULT '',
			notes text NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			valid_from date NULL,
			valid_to date NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY retailer_id (retailer_id),
			KEY fuel_state (fuel,state),
			KEY is_active (is_active)
		) {$charset};";

		$sql[] = "CREATE TABLE {$rates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rate_group varchar(30) NOT NULL DEFAULT 'single',
			block_order int(11) NOT NULL DEFAULT 1,
			block_limit decimal(14,3) NULL,
			block_period varchar(20) NOT NULL DEFAULT 'daily',
			unit varchar(10) NOT NULL DEFAULT 'kWh',
			rate_cents decimal(12,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY plan_id (plan_id),
			KEY plan_group (plan_id,rate_group,block_order)
		) {$charset};";

		$sql[] = "CREATE TABLE {$bills} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(64) NOT NULL DEFAULT '',
			fuel varchar(20) NOT NULL DEFAULT '',
			retailer_name varchar(190) NOT NULL DEFAULT '',
			plan_name varchar(190) NOT NULL DEFAULT '',
			nmi varchar(32) NOT NULL DEFAULT '',
			mirn varchar(32) NOT NULL DEFAULT '',
			postcode varchar(10) NOT NULL DEFAULT '',
			state varchar(10) NOT NULL DEFAULT '',
			period_start date NULL,
			period_end date NULL,
			billing_days int(11) NOT NULL DEFAULT 0,
			total_amount decimal(12,2) NULL,
			usage_json longtext NULL,
			rates_json longtext NULL,
			confidence_json longtext NULL,
			extractor varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'uploaded',
			raw_text longtext NULL,
			file_path varchar(255) NOT NULL DEFAULT '',
			original_name varchar(255) NOT NULL DEFAULT '',
			ip varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$leads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bill_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			preferred_contact varchar(30) NOT NULL DEFAULT '',
			selected_plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			estimated_saving decimal(12,2) NULL,
			message text NULL,
			consent tinyint(1) NOT NULL DEFAULT 0,
			consent_text text NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			ip varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY bill_id (bill_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
