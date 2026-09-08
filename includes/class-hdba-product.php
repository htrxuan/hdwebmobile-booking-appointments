<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

final class HDBA_Product
{

    const NONCE_META = 'hdba_save_meta';

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
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'render_product_data_panel'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_meta'));
    }

    public function add_product_data_tab($tabs)
    {
        $tabs['hdba'] = array(
            'label'    => __('Booking', 'hdwebmobile-booking-appointments'),
            'target'   => 'hdba_product_data',
            'class'    => array('show_if_simple'),
            'priority' => 26,
        );
        return $tabs;
    }

    public function render_product_data_panel()
    {
        global $post;
        $product_id = $post->ID;
        $settings   = HDBA_Availability::get_product_settings($product_id);

        wp_nonce_field(self::NONCE_META, 'hdba_meta_nonce');

        $weekdays = array(
            0 => __('Sunday', 'hdwebmobile-booking-appointments'),
            1 => __('Monday', 'hdwebmobile-booking-appointments'),
            2 => __('Tuesday', 'hdwebmobile-booking-appointments'),
            3 => __('Wednesday', 'hdwebmobile-booking-appointments'),
            4 => __('Thursday', 'hdwebmobile-booking-appointments'),
            5 => __('Friday', 'hdwebmobile-booking-appointments'),
            6 => __('Saturday', 'hdwebmobile-booking-appointments'),
        );
        ?>
        <div id="hdba_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <p class="form-field">
                    <label for="hdba_enabled"><?php esc_html_e('Bookable service', 'hdwebmobile-booking-appointments'); ?></label>
                    <input type="checkbox" id="hdba_enabled" name="hdba_enabled" value="yes" <?php checked($settings['enabled']); ?> />
                    <span class="description"><?php esc_html_e('Customers pick a date and time slot before adding this product to their cart.', 'hdwebmobile-booking-appointments'); ?></span>
                </p>
                <p class="form-field">
                    <label for="hdba_lead_days"><?php esc_html_e('Minimum lead time (days)', 'hdwebmobile-booking-appointments'); ?></label>
                    <input type="number" min="0" id="hdba_lead_days" name="hdba_lead_days" value="<?php echo esc_attr($settings['lead_days']); ?>" class="short" />
                </p>
                <p class="form-field">
                    <label for="hdba_days_ahead"><?php esc_html_e('Days ahead to offer', 'hdwebmobile-booking-appointments'); ?></label>
                    <input type="number" min="1" id="hdba_days_ahead" name="hdba_days_ahead" value="<?php echo esc_attr($settings['days_ahead']); ?>" class="short" />
                </p>
                <p class="form-field">
                    <label><?php esc_html_e('Available days', 'hdwebmobile-booking-appointments'); ?></label>
                    <?php foreach ($weekdays as $value => $label) : ?>
                        <label style="margin-right:10px;display:inline-block;">
                            <input type="checkbox" name="hdba_available_days[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $settings['available_days'], true)); ?> />
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                    <span class="description"><?php esc_html_e('Leave all unchecked to allow every day.', 'hdwebmobile-booking-appointments'); ?></span>
                </p>
                <p class="form-field">
                    <label for="hdba_time_slots"><?php esc_html_e('Time slots', 'hdwebmobile-booking-appointments'); ?></label>
                    <textarea id="hdba_time_slots" name="hdba_time_slots" rows="4" style="width:50%;"><?php echo esc_textarea(implode("\n", $settings['time_slots'])); ?></textarea>
                    <span class="description"><?php esc_html_e('One time slot per line (e.g. "9:00 AM - 10:00 AM").', 'hdwebmobile-booking-appointments'); ?></span>
                </p>
                <p class="form-field">
                    <label for="hdba_capacity"><?php esc_html_e('Capacity per slot', 'hdwebmobile-booking-appointments'); ?></label>
                    <input type="number" min="1" id="hdba_capacity" name="hdba_capacity" value="<?php echo esc_attr($settings['capacity']); ?>" class="short" />
                    <span class="description"><?php esc_html_e('How many bookings can share the same date and time slot.', 'hdwebmobile-booking-appointments'); ?></span>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_product_meta($post_id)
    {
        if (!isset($_POST['hdba_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdba_meta_nonce'])), self::NONCE_META)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_hdba_enabled', isset($_POST['hdba_enabled']) ? 'yes' : 'no');
        update_post_meta($post_id, '_hdba_lead_days', isset($_POST['hdba_lead_days']) ? absint($_POST['hdba_lead_days']) : 0);
        update_post_meta($post_id, '_hdba_days_ahead', isset($_POST['hdba_days_ahead']) ? max(1, absint($_POST['hdba_days_ahead'])) : 14);
        update_post_meta($post_id, '_hdba_capacity', isset($_POST['hdba_capacity']) ? max(1, absint($_POST['hdba_capacity'])) : 1);

        $available_days = isset($_POST['hdba_available_days']) && is_array($_POST['hdba_available_days'])
            ? array_map('absint', wp_unslash($_POST['hdba_available_days']))
            : array();
        update_post_meta($post_id, '_hdba_available_days', $available_days);

        $raw_slots = isset($_POST['hdba_time_slots']) ? sanitize_textarea_field(wp_unslash($_POST['hdba_time_slots'])) : '';
        $slots     = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw_slots))));
        update_post_meta($post_id, '_hdba_time_slots', $slots);
    }

    public function is_bookable($product_id)
    {
        return 'yes' === get_post_meta($product_id, '_hdba_enabled', true);
    }
}
