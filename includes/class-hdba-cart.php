<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders and validates the date/time picker. This class never writes a booking row --
 * it only carries the customer's chosen date+slot as cart/order-item data (a plain string,
 * validated against HDBA_Availability every time it's read, never trusted). The actual
 * booking is claimed later, at order completion, in class-hdba-order.php -- see that file
 * and class-hdba-repository.php for where the real security guarantees live.
 */
final class HDBA_Cart
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
        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_fields'), 5);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 2);
        add_filter('woocommerce_get_item_data', array($this, 'get_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_order_line_item_meta'), 10, 4);
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
    }

    public function maybe_enqueue_assets()
    {
        if (is_product()) {
            wp_enqueue_style('hdba-frontend', HDBA_PLUGIN_URL . 'assets/css/hdba-frontend.css', array(), HDBA_VERSION);
        }
    }

    public function render_fields()
    {
        global $product;
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-product.php';
        if (!$product || !HDBA_Product::get_instance()->is_bookable($product->get_id())) {
            return;
        }

        $dates = HDBA_Availability::get_valid_dates($product->get_id());
        $slots = HDBA_Availability::get_time_slots($product->get_id());

        echo '<div class="hdba-booking-fields">';
        printf('<p><label for="hdba_booking_date">%s</label><br /><select id="hdba_booking_date" name="hdba_booking_date" required>', esc_html__('Appointment date', 'hdwebmobile-booking-appointments'));
        echo '<option value="">' . esc_html__('Select a date', 'hdwebmobile-booking-appointments') . '</option>';
        foreach ($dates as $date) {
            printf('<option value="%s">%s</option>', esc_attr($date['value']), esc_html($date['label']));
        }
        echo '</select></p>';

        printf('<p><label for="hdba_time_slot">%s</label><br /><select id="hdba_time_slot" name="hdba_time_slot" required>', esc_html__('Time slot', 'hdwebmobile-booking-appointments'));
        echo '<option value="">' . esc_html__('Select a time slot', 'hdwebmobile-booking-appointments') . '</option>';
        foreach ($slots as $slot) {
            printf('<option value="%s">%s</option>', esc_attr($slot['value']), esc_html($slot['label']));
        }
        echo '</select></p>';
        echo '</div>';
    }

    public function validate_add_to_cart($passed, $product_id, $quantity)
    {
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-product.php';
        if (!HDBA_Product::get_instance()->is_bookable($product_id)) {
            return $passed;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's own add-to-cart nonce/session flow is verified upstream (WC_Form_Handler / Store API CartController) before this filter fires; the date/slot values themselves are validated against HDBA_Availability below, not trusted as-is.
        $date = isset($_POST['hdba_booking_date']) ? sanitize_text_field(wp_unslash($_POST['hdba_booking_date'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
        $slot = isset($_POST['hdba_time_slot']) ? sanitize_text_field(wp_unslash($_POST['hdba_time_slot'])) : '';

        if (!HDBA_Availability::is_valid_date($product_id, $date) || !HDBA_Availability::is_valid_slot($product_id, $slot)) {
            wc_add_notice(__('Please choose a valid appointment date and time slot.', 'hdwebmobile-booking-appointments'), 'error');
            return false;
        }

        if (HDBA_Availability::get_remaining_capacity($product_id, $date, $slot) <= 0) {
            wc_add_notice(__('That time slot is fully booked. Please choose another.', 'hdwebmobile-booking-appointments'), 'error');
            return false;
        }

        return $passed;
    }

    public function add_cart_item_data($cart_item_data, $product_id)
    {
        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-product.php';
        if (!HDBA_Product::get_instance()->is_bookable($product_id)) {
            return $cart_item_data;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same upstream nonce coverage as validate_add_to_cart() above; values are re-validated against HDBA_Availability at order-completion time in class-hdba-order.php, never trusted as-is.
        $date = isset($_POST['hdba_booking_date']) ? sanitize_text_field(wp_unslash($_POST['hdba_booking_date'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
        $slot = isset($_POST['hdba_time_slot']) ? sanitize_text_field(wp_unslash($_POST['hdba_time_slot'])) : '';

        if ($date && $slot) {
            $cart_item_data['hdba_booking_date'] = $date;
            $cart_item_data['hdba_time_slot']    = $slot;
        }

        return $cart_item_data;
    }

    public function get_item_data($item_data, $cart_item)
    {
        if (empty($cart_item['hdba_booking_date']) || empty($cart_item['hdba_time_slot'])) {
            return $item_data;
        }

        $slots     = HDBA_Availability::get_time_slots($cart_item['product_id']);
        $slot_label = $cart_item['hdba_time_slot'];
        foreach ($slots as $slot) {
            if ($slot['value'] === $cart_item['hdba_time_slot']) {
                $slot_label = $slot['label'];
                break;
            }
        }

        $item_data[] = array(
            'key'   => __('Appointment date', 'hdwebmobile-booking-appointments'),
            'value' => date_i18n(get_option('date_format'), strtotime($cart_item['hdba_booking_date'])),
        );
        $item_data[] = array(
            'key'   => __('Time slot', 'hdwebmobile-booking-appointments'),
            'value' => $slot_label,
        );

        return $item_data;
    }

    /**
     * Fires for both classic and block-based checkout (Store API shares the same
     * order-line-item-creation code path). Only stamps hidden meta for class-hdba-order.php
     * to read later -- the actual booking claim happens at order completion, once the order
     * (and therefore a real order id) exists.
     */
    public function add_order_line_item_meta($item, $cart_item_key, $values, $order)
    {
        if (empty($values['hdba_booking_date']) || empty($values['hdba_time_slot'])) {
            return;
        }

        $item->add_meta_data('_hdba_booking_date', $values['hdba_booking_date'], true);
        $item->add_meta_data('_hdba_time_slot', $values['hdba_time_slot'], true);
    }
}
