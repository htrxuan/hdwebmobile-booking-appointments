<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class HDBA_Admin_List_Table extends \WP_List_Table
{

    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'booking',
            'plural'   => 'bookings',
            'ajax'     => false,
        ));
    }

    public function get_columns()
    {
        return array(
            'product_id'   => __('Product', 'hdwebmobile-booking-appointments'),
            'customer_id'  => __('Customer', 'hdwebmobile-booking-appointments'),
            'booking_date' => __('Date', 'hdwebmobile-booking-appointments'),
            'time_slot'    => __('Time slot', 'hdwebmobile-booking-appointments'),
            'status'       => __('Status', 'hdwebmobile-booking-appointments'),
            'order_id'     => __('Order', 'hdwebmobile-booking-appointments'),
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'booking_date' => array('booking_date', true),
            'status'       => array('status', false),
        );
    }

    protected function extra_tablenav($which)
    {
        if ('top' !== $which) {
            return;
        }

        // Read-only filter param, same as core WP_List_Table screens -- no state
        // change occurs from reading it, so nonce verification doesn't apply here.
        $current_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $statuses        = array(
            'all'       => __('All statuses', 'hdwebmobile-booking-appointments'),
            'pending'   => __('Pending', 'hdwebmobile-booking-appointments'),
            'confirmed' => __('Confirmed', 'hdwebmobile-booking-appointments'),
            'cancelled' => __('Cancelled', 'hdwebmobile-booking-appointments'),
        );
        ?>
        <div class="alignleft actions">
            <select name="status">
                <?php foreach ($statuses as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_status, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter', 'hdwebmobile-booking-appointments'), '', 'filter_action', false); ?>
        </div>
        <?php
    }

    public function column_product_id($item)
    {
        $product = wc_get_product($item->product_id);
        return $product ? esc_html($product->get_name()) : esc_html__('(no longer available)', 'hdwebmobile-booking-appointments');
    }

    public function column_customer_id($item)
    {
        $user = get_user_by('id', $item->customer_id);
        return $user ? esc_html($user->display_name) : esc_html__('(guest)', 'hdwebmobile-booking-appointments');
    }

    public function column_status($item)
    {
        $labels = array(
            'pending'   => __('Pending', 'hdwebmobile-booking-appointments'),
            'confirmed' => __('Confirmed', 'hdwebmobile-booking-appointments'),
            'cancelled' => __('Cancelled', 'hdwebmobile-booking-appointments'),
        );
        $label = isset($labels[$item->status]) ? $labels[$item->status] : $item->status;
        return sprintf('<span class="hdba-status-%s">%s</span>', esc_attr($item->status), esc_html($label));
    }

    public function column_order_id($item)
    {
        if (!$item->order_id) {
            return '&mdash;';
        }
        $order = wc_get_order($item->order_id);
        if (!$order) {
            return '&mdash;';
        }
        return sprintf(
            '<a href="%s">#%s</a>',
            esc_url($order->get_edit_order_url()),
            esc_html($order->get_order_number())
        );
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'booking_date':
                return esc_html(date_i18n(get_option('date_format'), strtotime($item->booking_date)));
            case 'time_slot':
                return esc_html($item->time_slot);
            default:
                return '';
        }
    }

    public function prepare_items()
    {
        $per_page = 20;
        $paged    = $this->get_pagenum();

        // Read-only filter/search/sort params for this list table -- same pattern as core
        // WP_List_Table screens; nothing here changes state, so no nonce is needed.
        $status  = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search  = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'booking_date'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order   = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $result = HDBA_Repository::get_for_list_table(array(
            'status'   => $status,
            's'        => $search,
            'per_page' => $per_page,
            'paged'    => $paged,
            'orderby'  => $orderby,
            'order'    => $order,
        ));

        $this->items = $result['items'];

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        $this->set_pagination_args(array(
            'total_items' => $result['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil($result['total'] / $per_page),
        ));
    }
}
