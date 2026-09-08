<?php

namespace htrxuan\hdba;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Closes CVE-2026-2931 (CVSS 8.8, authenticated IDOR/mass-assignment in a competing booking
 * plugin) by construction. That plugin let a low-privileged customer reset ANY WordPress
 * user's password -- including an administrator's -- because its "update my booking profile"
 * endpoint accepted a client-supplied `externalId` field that was trusted as the WordPress
 * user id to sync the password onto, with no check that it still pointed at the requester's
 * own account.
 *
 * This plugin has no equivalent field, ever. `customer_id` on a booking row is written in
 * exactly ONE place -- claim_slot() below, called only from class-hdba-order.php's order-
 * completion hook, using $order->get_customer_id() -- never a value read from any request.
 * There is no "update booking" method anywhere in this class that accepts a customer_id
 * parameter, so there is no code path, present or future, that could ever let a booking be
 * reassigned to (or created against) a WordPress user id supplied by the client. And this
 * plugin never calls wp_set_password() or touches WordPress passwords in any way at all --
 * a customer's password is WordPress core's own Account Details screen, full stop.
 *
 * claim_slot() is also the fix for the separate "overbooking via a race condition" class of
 * bug (the same shape as CVE-2026-1932's missing-authorization-on-a-status-update-endpoint,
 * generalized to capacity): claiming a slot means inserting at the first free slot_index in
 * [0, capacity) for that exact (product, date, time), and the UNIQUE KEY on (product_id,
 * booking_date, time_slot, slot_index) means two concurrent claims can never both succeed at
 * the same index -- the loser's INSERT fails and the caller retries at the next index or gives
 * up once every index has been tried, exactly mirroring the atomic-uniqueness pattern already
 * used for license keys in class-hdlic-repository.php. `slot_index` is nullable specifically so
 * cancel() (below) can null it out on cancellation -- MySQL/InnoDB never treats two NULLs as
 * colliding in a unique index, so a cancelled booking's index becomes claimable again, while a
 * cancelled row itself is kept (not deleted) for audit history.
 *
 * Direct queries against a custom table are unavoidable here -- there is no WP API for this
 * data -- so DirectDatabaseQuery/NoCaching advisories are expected and accepted for this
 * class, matching standard practice for custom-table plugins.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class HDBA_Repository
{

    const STATUS_PENDING   = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED = 'cancelled';

    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'hdba_bookings';
    }

    public static function get_schema_sql()
    {
        global $wpdb;
        $table           = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            order_item_id BIGINT UNSIGNED DEFAULT NULL,
            booking_date DATE NOT NULL,
            time_slot VARCHAR(100) NOT NULL,
            slot_index INT UNSIGNED DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slot_claim (product_id, booking_date, time_slot, slot_index),
            UNIQUE KEY order_item_id (order_item_id),
            KEY customer_id (customer_id)
        ) {$charset_collate};";
    }

    public static function count_active_for_slot($product_id, $booking_date, $time_slot)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE product_id = %d AND booking_date = %s AND time_slot = %s AND status IN (%s, %s)',
            self::get_table_name(),
            $product_id,
            $booking_date,
            $time_slot,
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED
        ));
    }

    /**
     * The ONLY place a booking row is ever created, and the ONLY place customer_id is ever
     * written. Tries sequential slot_index values starting from the current active count,
     * relying on the UNIQUE KEY to make each individual attempt atomic -- see the class
     * docblock above. Returns the created row, or null once $capacity slots are all taken
     * (never fabricates a booking beyond capacity).
     */
    public static function claim_slot($product_id, $customer_id, $order_id, $order_item_id, $booking_date, $time_slot, $capacity)
    {
        global $wpdb;
        $table = self::get_table_name();
        $capacity = max(1, (int) $capacity);

        // Always search from index 0 -- not from the current active count -- so an index
        // freed by a cancellation (see cancel() below) is found and reused rather than
        // permanently lost. A duplicate-key failure on an index means either it's genuinely
        // occupied by another active booking, or someone else just won that exact index in a
        // race; either way the loop simply tries the next one until an insert succeeds or
        // every index up to capacity has been exhausted.
        for ($index = 0; $index < $capacity; $index++) {
            $inserted = $wpdb->insert(
                $table,
                array(
                    'customer_id'    => $customer_id,
                    'product_id'     => $product_id,
                    'order_id'       => $order_id,
                    'order_item_id'  => $order_item_id,
                    'booking_date'   => $booking_date,
                    'time_slot'      => $time_slot,
                    'slot_index'     => $index,
                    'status'         => self::STATUS_CONFIRMED,
                    'created_at'     => current_time('mysql'),
                ),
                array('%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s')
            );

            if (false !== $inserted) {
                return self::find($wpdb->insert_id);
            }
            // Duplicate-key error on this index -- someone else claimed it first; try the next.
        }

        return null;
    }

    public static function find($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::get_table_name(), (int) $id));
    }

    public static function find_by_order_item($order_item_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_item_id = %d', self::get_table_name(), (int) $order_item_id));
    }

    /**
     * Ownership is enforced entirely by this query's WHERE clause -- always call with
     * get_current_user_id(), never a value taken from the request.
     */
    public static function find_for_customer($customer_id)
    {
        global $wpdb;
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return array();
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE customer_id = %d ORDER BY booking_date DESC, id DESC',
            self::get_table_name(),
            $customer_id
        ));
    }

    /**
     * The only booking mutation a customer can ever trigger. Callers (class-hdba-myaccount.php)
     * must verify ownership BEFORE calling this -- this method itself does not re-check, matching
     * every other admin/customer-action repository method in this suite -- but it only ever
     * changes `status` and `slot_index`, never `customer_id` or any other field. slot_index is
     * nulled out (not left as-is) so the freed spot becomes claimable again -- see the class
     * docblock and the schema comment on slot_index for why NULL is what makes that safe.
     */
    public static function cancel($id)
    {
        global $wpdb;
        return false !== $wpdb->update(
            self::get_table_name(),
            array('status' => self::STATUS_CANCELLED, 'slot_index' => null),
            array('id' => (int) $id),
            array('%s', '%d'),
            array('%d')
        );
    }

    /**
     * @param array $args { status, product_id, s (search by date/slot), per_page, paged, orderby, order }
     * @return array { items: array, total: int }
     */
    public static function get_for_list_table(array $args)
    {
        global $wpdb;

        $where  = array('1=1');
        $params = array();

        if (!empty($args['status']) && 'all' !== $args['status']) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['s'])) {
            $where[]  = '(booking_date LIKE %s OR time_slot LIKE %s)';
            $like     = '%' . $wpdb->esc_like($args['s']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $allowed_orderby = array('booking_date', 'status', 'created_at');
        $orderby         = in_array($args['orderby'] ?? '', $allowed_orderby, true) ? $args['orderby'] : 'booking_date';
        $order           = 'ASC' === strtoupper($args['order'] ?? '') ? 'ASC' : 'DESC';

        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $paged    = max(1, (int) ($args['paged'] ?? 1));
        $offset   = ($paged - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT COUNT(*) FROM %i WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(self::get_table_name()), $params)
        ));

        $items = $wpdb->get_results($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            "SELECT * FROM %i WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(self::get_table_name()), $params, array($per_page, $offset))
        ));

        return array(
            'items' => $items,
            'total' => $total,
        );
    }
}
