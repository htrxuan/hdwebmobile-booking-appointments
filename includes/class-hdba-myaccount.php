<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only lookup this class performs is find_for_customer(get_current_user_id()) -- always
 * the currently-authenticated user's own id, never a value taken from the request -- so a
 * customer can only ever see bookings made on their own account. The cancel handler re-checks
 * ownership explicitly before calling HDBA_Repository::cancel(), which itself only ever
 * changes `status`, never `customer_id`.
 */
final class HDBA_MyAccount
{

    const ENDPOINT     = 'bookings';
    const NONCE_CANCEL = 'hdba_cancel_booking';

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
        add_action('init', array($this, 'add_endpoint'));
        add_filter('query_vars', array($this, 'add_query_var'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array($this, 'render_endpoint_content'));
        add_action('admin_post_hdba_cancel_booking', array($this, 'handle_cancel'));
    }

    public function add_endpoint()
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function add_query_var($vars)
    {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    public function add_menu_item($items)
    {
        $new_items = array();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ('orders' === $key) {
                $new_items[self::ENDPOINT] = __('Bookings', 'hdwebmobile-booking-appointments');
            }
        }
        if (!isset($new_items[self::ENDPOINT])) {
            $new_items[self::ENDPOINT] = __('Bookings', 'hdwebmobile-booking-appointments');
        }
        return $new_items;
    }

    public function render_endpoint_content()
    {
        $bookings = HDBA_Repository::find_for_customer(get_current_user_id());

        echo '<h2>' . esc_html__('Bookings', 'hdwebmobile-booking-appointments') . '</h2>';

        if (empty($bookings)) {
            echo '<p>' . esc_html__('You don\'t have any bookings yet.', 'hdwebmobile-booking-appointments') . '</p>';
            return;
        }

        $labels = array(
            HDBA_Repository::STATUS_PENDING   => __('Pending', 'hdwebmobile-booking-appointments'),
            HDBA_Repository::STATUS_CONFIRMED => __('Confirmed', 'hdwebmobile-booking-appointments'),
            HDBA_Repository::STATUS_CANCELLED => __('Cancelled', 'hdwebmobile-booking-appointments'),
        );

        echo '<table class="woocommerce-table woocommerce-table--bookings shop_table shop_table_responsive my_account_bookings">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'hdwebmobile-booking-appointments') . '</th>';
        echo '<th>' . esc_html__('Date', 'hdwebmobile-booking-appointments') . '</th>';
        echo '<th>' . esc_html__('Time slot', 'hdwebmobile-booking-appointments') . '</th>';
        echo '<th>' . esc_html__('Status', 'hdwebmobile-booking-appointments') . '</th>';
        echo '<th>&nbsp;</th>';
        echo '</tr></thead><tbody>';

        foreach ($bookings as $booking) {
            $product = wc_get_product($booking->product_id);
            $label   = isset($labels[$booking->status]) ? $labels[$booking->status] : $booking->status;

            echo '<tr>';
            printf('<td>%s</td>', $product ? esc_html($product->get_name()) : esc_html__('(no longer available)', 'hdwebmobile-booking-appointments'));
            printf('<td>%s</td>', esc_html(date_i18n(get_option('date_format'), strtotime($booking->booking_date))));
            printf('<td>%s</td>', esc_html($booking->time_slot));
            printf('<td>%s</td>', esc_html($label));
            echo '<td>';
            if (HDBA_Repository::STATUS_CONFIRMED === $booking->status) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="hdba_cancel_booking" />';
                printf('<input type="hidden" name="booking_id" value="%d" />', esc_attr($booking->id));
                wp_nonce_field(self::NONCE_CANCEL . '_' . $booking->id, 'hdba_cancel_nonce');
                echo '<button type="submit" class="woocommerce-button button wp-element-button">' . esc_html__('Cancel', 'hdwebmobile-booking-appointments') . '</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function handle_cancel()
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('You must be logged in to do this.', 'hdwebmobile-booking-appointments'));
        }

        $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
        check_admin_referer(self::NONCE_CANCEL . '_' . $booking_id, 'hdba_cancel_nonce');

        $booking = HDBA_Repository::find($booking_id);

        if ($booking && (int) $booking->customer_id === get_current_user_id() && HDBA_Repository::STATUS_CONFIRMED === $booking->status) {
            HDBA_Repository::cancel($booking_id);
        }
        // Silently no-ops for a booking that isn't found, isn't owned by this user, or is
        // already cancelled -- never reveals which case it was, matching the pattern of not
        // leaking whether an id exists at all.

        wp_safe_redirect(wc_get_endpoint_url(self::ENDPOINT, '', wc_get_page_permalink('myaccount')));
        exit;
    }
}
