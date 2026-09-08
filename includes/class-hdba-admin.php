<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

class HDBA_Admin
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
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
    }

    public function register_hub_tabs($tabs)
    {
        $tabs['booking-appointments'] = array(
            'label'  => __('Bookings', 'hdwebmobile-booking-appointments'),
            'order'  => 13,
            'render' => array($this, 'render_page'),
        );
        return $tabs;
    }

    public function render_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-booking-appointments'));
        }

        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-admin-list-table.php';

        $table = new HDBA_Admin_List_Table();
        $table->prepare_items();
        ?>
        <p><?php esc_html_e('Turn any simple product into a bookable service or appointment from its own Product Data > Booking tab. Every confirmed booking below is listed here for reference.', 'hdwebmobile-booking-appointments'); ?></p>
        <form method="get">
            <input type="hidden" name="page" value="hdwebmobile" />
            <input type="hidden" name="tab" value="booking-appointments" />
            <?php
            $table->search_box(__('Search date or slot', 'hdwebmobile-booking-appointments'), 'hdba-search');
            $table->display();
            ?>
        </form>
        <?php
    }
}
