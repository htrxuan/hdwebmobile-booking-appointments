<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims one booking slot per bookable line item the moment an order is marked completed.
 * customer_id is set here, once, from $order->get_customer_id() -- never from any request
 * field -- see class-hdba-repository.php's class docblock for the full CVE-2026-2931 story.
 * Idempotent by construction: guarded by an order-level meta flag and, as a hard backstop,
 * the UNIQUE KEY on order_item_id in the bookings table.
 */
final class HDBA_Order
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
        add_action('woocommerce_order_status_completed', array($this, 'confirm_bookings'));
    }

    public function confirm_bookings($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ('yes' === $order->get_meta('_hdba_bookings_confirmed')) {
            return;
        }

        require_once HDBA_PLUGIN_DIR . 'includes/class-hdba-product.php';
        $product_helper = HDBA_Product::get_instance();

        $shortages = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || !$product_helper->is_bookable($product->get_id())) {
                continue;
            }

            $date = $item->get_meta('_hdba_booking_date');
            $slot = $item->get_meta('_hdba_time_slot');
            if (!$date || !$slot) {
                continue;
            }

            // Already handled if find_by_order_item() finds a row (belt-and-braces alongside
            // the order-level meta flag above).
            if (HDBA_Repository::find_by_order_item($item->get_id())) {
                continue;
            }

            $settings = HDBA_Availability::get_product_settings($product->get_id());

            $booking = HDBA_Repository::claim_slot(
                $product->get_id(),
                $order->get_customer_id(),
                $order_id,
                $item->get_id(),
                $date,
                $slot,
                $settings['capacity']
            );

            if (!$booking) {
                $shortages[] = sprintf('%s (%s, %s)', $product->get_name(), $date, $slot);
            }
        }

        $order->update_meta_data('_hdba_bookings_confirmed', 'yes');
        $order->save();

        if (!empty($shortages)) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: comma-separated list of "Product Name (date, slot)" that could not be confirmed */
                    __('HDWebmobile Booking & Appointments: the following slot(s) filled up before this order completed and could not be confirmed -- %s. Contact the customer to reschedule.', 'hdwebmobile-booking-appointments'),
                    implode(', ', $shortages)
                )
            );
            $this->notify_admin_of_shortage($order, $shortages);
        }
    }

    private function notify_admin_of_shortage($order, $shortages)
    {
        $subject = sprintf(
            /* translators: %d: order ID */
            __('Booking slot conflict on order #%d', 'hdwebmobile-booking-appointments'),
            $order->get_id()
        );

        $body = sprintf(
            "Order #%d completed, but the following booking(s) could not be confirmed because the slot filled up first:\n\n%s\n\nPlease contact the customer to reschedule.",
            $order->get_id(),
            implode("\n", $shortages)
        );

        wp_mail(get_option('admin_email'), $subject, $body);
    }
}
