<?php
/**
 * Plugin Name:       StormSale Affiliate for WooCommerce
 * Plugin URI:        https://stormsale.io/
 * Description:       Tracks affiliate referral clicks across the cart and checkout lifecycle, then posts encrypted, ledger-verified conversions from completed WooCommerce orders to the merchant's StormSale smart campaign.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            StreamPay
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stormsale-affiliate
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STORMSALE_VERSION', '1.0.0' );
define( 'STORMSALE_PLUGIN_FILE', __FILE__ );
define( 'STORMSALE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STORMSALE_GATEWAY_URL', 'https://api.stormsale.io/v1/conversions' );

require_once STORMSALE_PLUGIN_DIR . 'includes/class-stormsale-stats.php';
require_once STORMSALE_PLUGIN_DIR . 'includes/class-stormsale-settings.php';
require_once STORMSALE_PLUGIN_DIR . 'includes/class-stormsale-crypto.php';
require_once STORMSALE_PLUGIN_DIR . 'includes/class-stormsale-client.php';
require_once STORMSALE_PLUGIN_DIR . 'includes/class-stormsale-referral.php';
require_once STORMSALE_PLUGIN_DIR . 'includes/class-stormsale-conversion.php';
require_once STORMSALE_PLUGIN_DIR . 'includes/class-stormsale-order-admin.php';
require_once STORMSALE_PLUGIN_DIR . 'includes/class-stormsale-plugin.php';

function stormsale_log( $level, $message, array $context = array() ) {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	try {
		$logger = wc_get_logger();
		$logger->{$level}( $message, array_merge( array( 'source' => 'stormsale-affiliate' ), $context ) );
	} catch ( Throwable $e ) {
		return;
	}
}

register_activation_hook( __FILE__, array( 'StormSale_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'StormSale_Plugin', 'instance' ) );
