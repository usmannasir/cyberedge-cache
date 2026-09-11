=== CyberEdge Cache ===
Contributors: cyberpanel
Tags: cache, edge cache, cdn, litespeed, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Safely connects WordPress cache decisions and durable purge events to CyberEdge.

== Description ==

CyberEdge Cache supplies conservative full-page cache signals and reliably queues whole-site purge events for a provisioned CyberEdge site.

The plugin is designed to coexist with LiteSpeed Cache for WordPress. When LiteSpeed page caching is active, LiteSpeed remains responsible for public cacheability. CyberEdge continues to protect private WordPress, REST, login, preview, cart, checkout, and account traffic. When LiteSpeed is absent, disabled, or installed only for optimization, CyberEdge can supply a conservative anonymous-HTML fallback policy.

This plugin requires server-side site credentials issued during CyberEdge provisioning. It does not independently create an edge account, change DNS, or deploy edge nodes.

== Installation ==

1. Complete site enrollment in the CyberEdge customer platform.
2. Install the `cyberedge-cache` directory in `wp-content/plugins/`.
3. Configure the site ID, HTTPS controller origin, and per-site purge secret in server-side `wp-config.php` as instructed during enrollment.
4. Activate CyberEdge Cache for the individual site.
5. Confirm the persistent `wp cyberedge deliver` worker is running at least once per minute.

Do not network-activate the plugin. Each multisite tenant needs separate credentials and explicit configuration.

== Frequently Asked Questions ==

= Do I have to install LiteSpeed Cache? =

No. CyberEdge Cache can safely provide a conservative public-page policy without it. If LiteSpeed Cache is already active, its more detailed cacheability decisions remain authoritative.

= Does this cache logged-in visitors or WooCommerce checkout pages? =

No. Authenticated, cookie-bearing, REST, cart, checkout, account, preview, search, error, and other private requests receive a cache veto.

= How are changed pages purged? =

WordPress mutations synchronously create a durable local outbox event. A background worker sends signed events to the site's CyberEdge controller. Delivery retries reuse the same event identity until the controller provides a valid acknowledgement.

== Changelog ==

= 0.3.0 =

* Add a branded WordPress dashboard with connection, worker, queue, and page-policy status.
* Add an on-demand live check for the public `X-CyberEdge-Cache` response header.
* Link directly to exact bandwidth, request, plan, invoice, and domain details in the customer workspace.
* Add a nonce-protected worldwide purge action and WordPress Site Health result.

= 0.2.0 =

* Preserve CyberEdge fallback caching when LiteSpeed Cache is installed but page caching is off.
* Observe LiteSpeed's final public purge-tag contract and deduplicate overlapping purge signals per request.
* Avoid global invalidation for pending and spam-only comment changes.
* Verify background worker scheduling during activation and in administrator health checks.
* Add automated compatibility checks against audited and current LiteSpeed Cache source.
