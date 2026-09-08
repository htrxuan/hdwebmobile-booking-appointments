<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

class HDBA_Activator
{

    public static function activate()
    {
        if (!self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(HDBA_PLUGIN_FILE));
            set_transient('hdba_wc_missing_notice', true, 30);
            return;
        }

        self::maybe_upgrade_db();

        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-myaccount.php';
        add_rewrite_endpoint(HDBA_MyAccount::ENDPOINT, EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }

    public static function is_woocommerce_active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce');
    }

    public static function maybe_upgrade_db()
    {
        if (get_option('hdba_db_version') === HDBA_DB_VERSION) {
            return;
        }

        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-repository.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(HDBA_Repository::get_schema_sql());

        update_option('hdba_db_version', HDBA_DB_VERSION);
    }

    public static function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HDBA_PLUGIN_FILE, true);
        }
    }
}
