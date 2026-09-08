<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

final class HDBA_Core
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    private function __clone()
    {
    }

    private function includes()
    {
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-repository.php';
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-availability.php';
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-product.php';
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-cart.php';
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-order.php';
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-myaccount.php';
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-admin.php';
    }

    private function init_hooks()
    {
        add_action('admin_notices', array($this, 'render_missing_woocommerce_notice'));
        add_action('admin_init', array(HDBA_Activator::class, 'maybe_upgrade_db'));

        if (!class_exists('WooCommerce')) {
            return;
        }

        HDBA_Product::get_instance();
        HDBA_Cart::get_instance();
        HDBA_Order::get_instance();
        HDBA_MyAccount::get_instance();

        // HDBA_Admin registers admin-menu/settings hooks itself, but this must load
        // unconditionally (not only when is_admin()) since it also owns the
        // hdwebmobile_hub_tabs registration used by the shared hub page.
        HDBA_Admin::get_instance();
    }

    public function render_missing_woocommerce_notice()
    {
        $screen = get_current_screen();
        if (!$screen || 'plugins' !== $screen->id) {
            return;
        }

        if (!get_transient('hdba_wc_missing_notice')) {
            return;
        }
        delete_transient('hdba_wc_missing_notice');
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php esc_html_e('HDWebmobile Booking & Appointments requires WooCommerce to be installed and active. The plugin has been deactivated.', 'hdwebmobile-booking-appointments'); ?>
            </p>
        </div>
        <?php
    }
}
