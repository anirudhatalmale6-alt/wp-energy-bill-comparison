<?php
/**
 * Plugin Name:       Energy Bill Comparison
 * Plugin URI:        https://github.com/anirudhatalmale6-alt/aec-energy-compare
 * Description:       Upload an electricity or gas bill as a PDF, extract the usage and rates, and compare them against retailer plans stored in WordPress.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Anirudha Talmale
 * License:           GPL-2.0-or-later
 * Text Domain:       aec-energy-compare
 */

defined( 'ABSPATH' ) || exit;

define( 'AEC_VERSION', '0.4.0' );
define( 'AEC_FILE', __FILE__ );
define( 'AEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AEC_URL', plugin_dir_url( __FILE__ ) );
define( 'AEC_DB_VERSION', '5' );

require_once AEC_DIR . 'includes/class-aec-db.php';
require_once AEC_DIR . 'includes/class-aec-install.php';
require_once AEC_DIR . 'includes/class-aec-settings.php';
require_once AEC_DIR . 'includes/class-aec-retailers.php';
require_once AEC_DIR . 'includes/class-aec-plans.php';
require_once AEC_DIR . 'includes/class-aec-bills.php';
require_once AEC_DIR . 'includes/class-aec-leads.php';
require_once AEC_DIR . 'includes/class-aec-calculator.php';
require_once AEC_DIR . 'includes/class-aec-pdf.php';
require_once AEC_DIR . 'includes/class-aec-extractor-regex.php';
require_once AEC_DIR . 'includes/class-aec-extractor-ai.php';
require_once AEC_DIR . 'includes/class-aec-extractor.php';
require_once AEC_DIR . 'includes/class-aec-rest.php';
require_once AEC_DIR . 'includes/class-aec-shortcode.php';

if ( is_admin() ) {
	require_once AEC_DIR . 'admin/class-aec-admin.php';
}

register_activation_hook( __FILE__, array( 'AEC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AEC_Install', 'deactivate' ) );

/**
 * Boot the plugin once WordPress has loaded its own pluggables.
 */
function aec_bootstrap() {
	// A plugin updated by file copy never fires the activation hook, so the
	// schema check has to run on load as well.
	AEC_Install::maybe_upgrade();

	AEC_Settings::init();
	AEC_Rest::init();
	AEC_Shortcode::init();

	if ( is_admin() ) {
		AEC_Admin::init();
	}
}
add_action( 'plugins_loaded', 'aec_bootstrap' );

/**
 * Load translations.
 */
function aec_load_textdomain() {
	load_plugin_textdomain( 'aec-energy-compare', false, dirname( plugin_basename( AEC_FILE ) ) . '/languages' );
}
add_action( 'init', 'aec_load_textdomain' );
