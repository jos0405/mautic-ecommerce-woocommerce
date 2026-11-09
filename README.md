# Mautic Ecommerce for WooCommerce

Connects a WooCommerce shop to the [Ecommerce Connector for Mautic](https://github.com/FriendlyDotCH/ecommerce-connector-for-mautic): sends orders, refunds, cancellations and the product catalog, signed with the connector's webhook secret.

Status: 0.5.1, in development.

## What it sends

| When | What | Mautic endpoint |
|------|------|-----------------|
| An order becomes processing or completed | The order: number, total, currency, paid date, billing email, shipping city, line items (SKU or variation/product ID, product ID, name, quantity, unit price incl. tax, main category), the coupon codes used, and the Mautic email link the shopper came from (`ct`, with consent) so Mautic credits that email and campaign | `/ecommerce/order` |
| A refund (partial or full) or a cancellation | The same order again, with `status`, `refunded_total` and `refunded_qty` per line; Mautic updates it | `/ecommerce/order` |
| A product is created, saved, changes stock, or is trashed/deleted | The product: ID, SKU, name, link, image, prices, categories and main category, variation IDs, stock, status, created date (for new arrivals in Mautic) | `/ecommerce/product` |
| Once a week, at night | All products again, in case Mautic missed a change (**Weekly full product sync**, on by default) | `/ecommerce/product` |
| A known shopper views a product | `viewed_product` with the product | `/ecommerce/event` |
| A known shopper changes their cart | `added_to_cart` with the cart contents, total and a restore-cart link, 60 seconds after the last change | `/ecommerce/event` |
| A known shopper opens checkout | `started_checkout` with the cart | `/ecommerce/event` |

Unpaid orders (pending, on-hold, failed) are not sent. Contacts are never created in Mautic; orders are linked to existing contacts by billing email.

**Main category**: the primary category from Yoast SEO or Rank Math when set, otherwise the first category other than "Uncategorized". Change it with the `mautic_ecommerce_main_category` filter (`$name`, `$productId`).

## Storefront events

Off by default: switch on **Send storefront events** here, and **Record storefront events** in Mautic. Mautic then runs the abandoned cart, checkout and browsing campaigns (with `mautic:ecommerce:abandonment` in its cron).

* **Who**: only known shoppers: logged-in customers, guests once they entered their email at checkout, and guests who arrived from a link in a Mautic email. For those, the link's `ct` parameter is kept in the WooCommerce session as received and sent with their events; Mautic identifies the email's recipient from the random tracking hash in it, never from the contact ID in it, which anyone can change. A forwarded email therefore attributes the reader's visit to the original recipient, as Mautic's own tracking does. Anonymous visitors are not sent, and are not linked through Mautic's tracking cookie, because a visitor can change that cookie to name someone else's contact.
* **Consent**: by default, events are sent only with *marketing* consent from a consent plugin that supports the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) (for example Complianz or CookieYes). Choose "Always" only if the shop handles consent another way. Do Not Track and Global Privacy Control always stop events.
* **Restore-cart links**: each cart and checkout event carries a link (`?mautic_restore_cart=…`) that rebuilds that cart for 14 days; Mautic's `{ecommerce_cart_url}` token uses it. The link stores product IDs and quantities only.
* **Caching**: product views are recorded when WordPress renders the page. Page caches usually skip logged-in shoppers and never cache cart or checkout, but cached product pages for guests send no views.

Sending runs in the background through WooCommerce's Action Scheduler (Tools → Scheduled Actions, group `mautic-ecommerce`), so checkout and product saves never wait for Mautic. On low-traffic shops, run WP-Cron from a real cron job so queued sends are not delayed.

## Coupons from Mautic

With **Allow Mautic to create coupons** ticked (off by default), Mautic's **Issue coupon** campaign action creates coupons at `POST /wp-json/mautic-ecommerce/v1/coupon`; the setting shows this shop's exact URL, which goes into Mautic as **Shop coupon endpoint**. Each coupon is single-use, for the contact's email only (WooCommerce's *Allowed emails*), individual use, expires at the end of its last day (shop time), and is marked with the email in `_mautic_ecommerce_coupon_email`. Mautic chooses the code, value, minimum spend, sale-item and free-shipping options.

Requests must carry Mautic's signature (HMAC-SHA256 of the body with the webhook secret, `X-Mautic-Ecommerce-Signature`) and a timestamp less than 5 minutes old. The same code sent again for the same email answers as created (a retry); for another email it is refused. Veto coupons with the `mautic_ecommerce_coupon_allowed` filter (`$allowed`, `$data`), for example above a maximum value. Orders send their coupon codes, so Mautic knows which coupons were used.

## Setup

1. In Mautic, install the Ecommerce Connector (1.7.0 or later; coupons need 1.8.0), activate it and set a webhook secret.
2. Install and activate this plugin.
3. In WooCommerce → Settings → **Mautic**: the Mautic URL, the same webhook secret, and a store name (default `woocommerce`; it becomes the order source and catalog name in Mautic).
4. Click **Test connection** under *Connection and sync*. It sends a signed request and says whether Mautic accepted the secret.
5. Send the catalog and past orders once with **Send all products** and **Send past orders** (optionally from a date). Both run in the background, 100 at a time; reload the page to see the progress. A sync that stopped on an error resumes where it stopped when started again. From the command line:

   ```bash
   wp mautic-ecommerce sync products
   wp mautic-ecommerce sync orders --since=2025-01-01
   ```

   Mautic never triggers campaigns for orders placed more than a day before they arrive, so the order import sends no emails.

After that, changes are sent as they happen. As a safety net for changes Mautic missed (Mautic down, a wrong secret for a while), all products are sent again once a week: at a time picked once per site between 01:00 and 05:00 site time, on a random weekday, so shops sharing one Mautic do not sync at the same moment. The settings page shows the next run; switch it off with **Weekly full product sync**. It is scheduled when an administrator opens the WordPress admin and removed when the plugin is deactivated. Resending unchanged products changes nothing in Mautic and triggers no product alerts.

`wp mautic-ecommerce status` shows the settings, `wp mautic-ecommerce test` tests the connection. Mautic answers every request with 204; requests it rejects (wrong secret, invalid data) are explained in Mautic's log.

## Development

The tests run inside a real WordPress with WooCommerce (wp-phpunit) against their own database, which they empty on every run: never use a shop's database. Outgoing requests go to a fake Mautic and never leave the machine.

Create `tests/config.local.php` (not committed) for your machine; environment variables work too (see `tests/wp-tests-config.php`):

```php
<?php
return [
    'abspath'     => '/path/to/wordpress',
    'woocommerce' => '/path/to/wordpress/wp-content/plugins/woocommerce',
    'db_name'     => 'woo_test',
    'db_user'     => 'woo_test',
    'db_password' => '…',
];
```

```bash
composer install
composer test
```

## Requirements

WordPress 6.5+, WooCommerce 8.0+ (HPOS compatible), PHP 8.1+.

## License

GPL-3.0-or-later
