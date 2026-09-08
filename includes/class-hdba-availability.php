<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The single source of truth for which booking dates/slots are currently valid for a given
 * product. Both the product-page rendering and the add-to-cart validation call into this same
 * class, so the two can never disagree about what's offered or silently accept a value the
 * other would have rejected. Mirrors class-hddts-availability.php's established pattern from
 * the Checkout Delivery Scheduler plugin, scoped per-product instead of site-wide.
 */
class HDBA_Availability
{

    public static function get_product_settings($product_id)
    {
        return array(
            'enabled'          => 'yes' === get_post_meta($product_id, '_hdba_enabled', true),
            'lead_days'        => max(0, (int) get_post_meta($product_id, '_hdba_lead_days', true)),
            'days_ahead'       => max(1, (int) (get_post_meta($product_id, '_hdba_days_ahead', true) ?: 14)),
            'available_days'   => array_map('absint', (array) get_post_meta($product_id, '_hdba_available_days', true)),
            'time_slots'       => (array) get_post_meta($product_id, '_hdba_time_slots', true),
            'capacity'         => max(1, (int) (get_post_meta($product_id, '_hdba_capacity', true) ?: 1)),
        );
    }

    public static function get_valid_dates($product_id)
    {
        $settings       = self::get_product_settings($product_id);
        $lead_days      = $settings['lead_days'];
        $days_ahead     = $settings['days_ahead'];
        $available_days = $settings['available_days'];

        $dates  = array();
        $cursor = strtotime((int) $lead_days . ' days', current_time('timestamp')); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local (site timezone) date math, not a data-storage timestamp.

        $checked   = 0;
        $max_check = ($days_ahead + 14) * 3;

        while (count($dates) < $days_ahead && $checked < $max_check) {
            $date_string = gmdate('Y-m-d', $cursor);
            $weekday     = (int) gmdate('w', $cursor);

            if (empty($available_days) || in_array($weekday, $available_days, true)) {
                $dates[] = array(
                    'value' => $date_string,
                    'label' => date_i18n(get_option('date_format'), $cursor),
                );
            }

            $cursor += DAY_IN_SECONDS;
            $checked++;
        }

        return $dates;
    }

    /**
     * A stable slug (sanitize_title of the label) is used as the stored value so relabeling a
     * slot in settings doesn't silently invalidate the exact string already stored on old
     * bookings the way a raw label match would.
     */
    public static function get_time_slots($product_id)
    {
        $settings = self::get_product_settings($product_id);
        $slots    = array();

        foreach ($settings['time_slots'] as $label) {
            $label = trim($label);
            if ('' === $label) {
                continue;
            }
            $slots[] = array(
                'value' => sanitize_title($label),
                'label' => $label,
            );
        }

        return $slots;
    }

    public static function is_valid_date($product_id, $date_string)
    {
        foreach (self::get_valid_dates($product_id) as $date) {
            if ($date['value'] === $date_string) {
                return true;
            }
        }
        return false;
    }

    public static function is_valid_slot($product_id, $slot_value)
    {
        foreach (self::get_time_slots($product_id) as $slot) {
            if ($slot['value'] === $slot_value) {
                return true;
            }
        }
        return false;
    }

    /**
     * How many spots remain at this exact date+slot, purely for display -- the real,
     * race-condition-safe check happens again inside HDBA_Repository::claim_slot() at order
     * completion, so this is advisory only and never itself the security boundary.
     */
    public static function get_remaining_capacity($product_id, $date_string, $slot_value)
    {
        $settings = self::get_product_settings($product_id);
        $used     = HDBA_Repository::count_active_for_slot($product_id, $date_string, $slot_value);
        return max(0, $settings['capacity'] - $used);
    }
}
