# Mautic Ecommerce for WooCommerce

Connects a WooCommerce shop to the [Ecommerce Connector for Mautic](https://github.com/FriendlyDotCH/ecommerce-connector-for-mautic): sends orders, refunds, cancellations and the product catalog, signed with the connector's webhook secret.

Status: 0.1.0, in development. Storefront events (product views, cart, checkout) follow in 0.2.

## What it sends

| When | What | Mautic endpoint |
|------|------|-----------------|
| An order becomes processing or completed | The order: number, total, currency, paid date, billing email, shipping city, line items (SKU or variation/product ID, product ID, name, quantity, unit price incl. tax, main category) | `/ecommerce/order` |
| A refund (partial or full) or a cancellation | The same order again, with `status`, `refunded_total` and `refunded_qty` per line; Mautic updates it | `/ecommerce/order` |
| A product is created, saved, changes stock, or is trashed/deleted | The product: ID, SKU, name, link, image, prices, categories and main category, variation IDs, stock, status | `/ecommerce/product` |
| A known shopper views a product | `viewed_product` with the product | `/ecommerce/event` |
| A known shopper changes their cart | `added_to_cart` with the cart contents, total and a restore-cart link, 60 seconds after the last change | `/ecommerce/event` |
| A known shopper opens checkout | `started_checkout` with the cart | `/ecommerce/event` |

Unpaid orders (pending, on-hold, failed) are not sent. Contacts are never created in Mautic; orders are linked to existing contacts by billing email.

**Main category**: the primary category from Yoast SEO or Rank Math when set, otherwise the first category other than "Uncategorized". Change it with the `mautic_ecommerce_main_category` filter (`$name`, `$productId`).

## Storefront events

Off by default: switch on **Send storefront events** here, and **Record storefront events** in Mautic. Mautic then runs the abandoned cart, checkout and browsing campaigns (with `mautic:ecommerce:abandonment` in its cron).

* **Who**: only shoppers whose email is known: logged-in customers, or guests once they entered their email at checkout. Anonymous visitors are not sent, and are not linked through Mautic's tracking cookie, because a visitor can change that cookie to name someone else's contact.
* **Consent**: by default, events are sent only with *marketing* consent from a consent plugin that supports the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) (for example Complianz or CookieYes). Choose "Always" only if the shop handles consent another way. Do Not Track and Global Privacy Control always stop events.
* **Restore-cart links**: each cart and checkout event carries a link (`?mautic_restore_cart=…`) that rebuilds that cart for 14 days; Mautic's `{ecommerce_cart_url}` token uses it. The link stores product IDs and quantities only.
* **Caching**: product views are recorded when WordPress renders the page. Page caches usually skip logged-in shoppers and never cache cart or checkout, but cached product pages for guests send no views.

Sending runs in the background through WooCommerce's Action Scheduler (Tools → Scheduled Actions, group `mautic-ecommerce`), so checkout and product saves never wait for Mautic. On low-traffic shops, run WP-Cron from a real cron job so queued sends are not delayed.

## Setup

1. In Mautic, install the Ecommerce Connector (1.7.0 or later), activate it and set a webhook secret.
2. Install and activate this plugin.
3. In WooCommerce → Settings → **Mautic**: the Mautic URL, the same webhook secret, and a store name (default `woocommerce`; it becomes the order source and catalog name in Mautic).
4. Send the catalog and past orders once:

   ```bash
   wp mautic-ecommerce sync products
   wp mautic-ecommerce sync orders --since=2025-01-01
   ```

   Mautic never triggers campaigns for orders placed more than a day before they arrive, so the order import sends no emails.

`wp mautic-ecommerce status` shows the settings. Mautic answers every request with 204; requests it rejects (wrong secret, invalid data) are explained in Mautic's log.

## Requirements

WordPress 6.5+, WooCommerce 8.0+ (HPOS compatible), PHP 8.1+.

## License

GPL-3.0-or-later
