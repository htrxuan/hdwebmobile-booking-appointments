<?php

/**
 * Plugin Name: HDWebmobile Booking & Appointments
 * Plugin URI: https://hdwebmobile.com/plugins/hdwebmobile-booking-appointments/
 * Description: Sell bookable services and appointments through WooCommerce. A booking's owner is set exactly once, server-side, at purchase and never accepted from any later request -- and this plugin never touches a WordPress password.
 * Version: 1.0.0
 * Author: htrxuan - Han Tran
 * Author URI: https://hdwebmobile.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hdwebmobile-booking-appointments
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('HDBA_VERSION', '1.0.0');
define('HDBA_DB_VERSION', '1.0.0');
define('HDBA_PLUGIN_FILE', __FILE__);
define('HDBA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HDBA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-activator.php';

register_activation_hook(HDBA_PLUGIN_FILE, array(HDBA_Activator::class, 'activate'));
add_action('before_woocommerce_init', array(HDBA_Activator::class, 'declare_hpos_compatibility'));

add_action('plugins_loaded', function () {
    require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-core.php';
    HDBA_Core::get_instance();
});

add_filter('plugin_action_links_' . plugin_basename(HDBA_PLUGIN_FILE), function ($links) {
    $donate_link = '<a href="https://paypal.me/htrxuan/20" target="_blank" style="color:#d54e21;font-weight:bold;">' . __('Donate', 'hdwebmobile-booking-appointments') . '</a>';
    array_unshift($links, $donate_link);
    return $links;
});
