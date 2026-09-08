# HDWebmobile Booking & Appointments

Sell bookable services and appointments through WooCommerce, with a per-slot capacity that can never be oversold.

- **WordPress.org:** https://wordpress.org/plugins/hdwebmobile-booking-appointments/
- **Requires:** WordPress 6.9+, WooCommerce, PHP 7.4+
- **License:** GPLv2 or later

## Description

HDWebmobile Booking & Appointments turns any simple WooCommerce product into a bookable service. Customers pick an available date and time slot on the product page; the booking is confirmed the moment their order completes, and they can view or cancel it later under My Account.

## Why this plugin exists

A competing booking plugin (Amelia) had an authenticated IDOR/mass-assignment vulnerability (CVE-2026-2931, CVSS 8.8): its "update my booking profile" endpoint accepted a client-supplied field linking the booking record to a WordPress user id, with no check that it still pointed at the requester's own account -- letting a low-privileged customer reset the password of *any* WordPress user, including an administrator. This plugin closes that vulnerability class by construction:

* A booking's owner is written in exactly one place -- at order completion, from the order's own customer id -- and never again. There is no "update booking" code path anywhere in this plugin that accepts a customer id, user id, or any similar field from a request.
* This plugin never touches a WordPress password. There is no code path here that calls `wp_set_password()` or anything like it, at all -- a customer's password remains entirely WordPress core's own Account Details screen.
* A customer can only ever see or cancel their own bookings, scoped by their logged-in account at the database query itself -- never by an id taken from the request.
* Per-slot capacity is enforced with an atomic, race-condition-safe claim (a database uniqueness constraint), so two customers can never both be confirmed into the same slot beyond its configured capacity. Cancelling a booking correctly frees its slot for reuse, while the cancelled row itself is kept for audit history.

## Features

* Turn any simple product into a bookable service from its own Product Data > Booking tab
* Configurable available days, time slots, lead time, and how many days ahead to offer
* Per-slot capacity -- a slot stops being offered once it's full
* Booking is confirmed automatically when the order is marked Completed
* A "Bookings" tab under My Account to view and cancel upcoming bookings
* If a slot fills up between add-to-cart and checkout, the order still completes normally and the admin is emailed to help reschedule -- no booking is ever double-booked

## Limitations (v1)

* Simple products only -- no variable-product support
* No staff/resource assignment -- a slot's capacity is a single shared number, not per-staff-member
* No calendar sync (Google Calendar, iCal, etc.)

## Installation

1. Upload the plugin to `/wp-content/plugins/hdwebmobile-booking-appointments`, or install through the WordPress plugins screen.
2. Activate the plugin. WooCommerce must already be installed and active.
3. Edit a simple product, open its new "Booking" tab under Product Data, and configure availability.

## License

GPLv2 or later. See [LICENSE](LICENSE).
