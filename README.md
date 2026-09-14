# CyberEdge WordPress integration

This plugin delivers **whole-site** purge events to the controller. It preserves native LiteSpeed Cache for WordPress (LSCWP) cacheability decisions when its page cache is active and supplies a conservative public HTML fallback when that page cache is unavailable. It does not implement ESI or claim the same tag dependency graph as LSCWP.

## One-click customer connection

Install and activate the plugin, then open **Tools → CyberEdge Cache → Connect to CyberEdge**. The plugin creates a PKCE security proof, sends the administrator to the CyberEdge platform to sign in or create an account, preserves the journey through plan and domain setup, and returns to the exact WordPress callback after explicit approval. A five-minute authorization code is exchanged server-to-server and can be used only once. The per-domain purge credential is encrypted at rest with AES-256-GCM using the site's WordPress authentication salt; it is never placed in the browser URL or rendered in the dashboard.

If WordPress cannot save the temporary proof, the return proof expires, the connection service cannot respond, or the local protected connection cannot be saved, the failure page explains the problem and links back to the plugin. A failed temporary-proof write stops before the platform handoff. Administrators can select **Retry connection** to submit a new nonce-protected request with fresh state and PKCE. Retrying never automatically resubmits an old authorization code or copies callback credentials into the retry form. Non-administrators must sign in with the required capability first.

Activation is allowed before enrollment. Once connected, every persisted purge schedules an immediate WordPress cron wake as well as the recurring retry worker. On managed origins, the system timer below remains the strongest delivery guarantee because fully cached traffic may not execute WordPress.

A genuinely new installation has no protected connection, server-managed credentials, retained connection history, or queued events. Ordinary preconnection WordPress changes do not create failed-purge alarms in that state. A separate non-secret history marker is retained once connection setup reaches protected storage, and valid legacy connections backfill it. Losing credentials afterwards is a repairable error, not a fresh installation. Invalid/unreadable history, failed history persistence, stranded events, and unreadable outboxes remain failures; successful retries never silently clear a retained operator alarm.

The dashboard distinguishes a saved connection from local delivery readiness. If initial queue creation, scheduling, or purge enqueue fails after approval, the private connection stays saved and WordPress returns to the dashboard with partial setup status. Repair delivery and retry a worldwide purge there; do not replay the authorization exchange. Passing local checks does not prove that every edge has completed a purge. WordPress notices remain visible outside the branded header through the standard notice anchor.

## Managed installation contract

Managed provisioning may inject these constants into server-side `wp-config.php`; they take precedence over a one-click connection:

```php
define('CYBEREDGE_SITE_ID', getenv('CYBEREDGE_SITE_ID'));
define('CYBEREDGE_CONTROLLER_URL', getenv('CYBEREDGE_CONTROLLER_URL'));
define('CYBEREDGE_PURGE_SECRET', getenv('CYBEREDGE_PURGE_SECRET'));
// Optional anonymous HTML TTL; default 300 seconds, maximum 3600; zero disables fallback.
define('CYBEREDGE_CACHE_TTL', 300);
// Optional server-enforced switch. When present, the dashboard cannot override it.
define('CYBEREDGE_CACHE_ENABLED', true);
```

The controller URL is an HTTPS origin, without a path, query, fragment, or embedded credentials. The site ID uses letters, digits, underscores, and hyphens. Generate a separate random purge secret of at least 32 bytes per site. Never place it in HTML, JavaScript, public configuration endpoints, command-line arguments, or access logs. It authorizes only this site's purge endpoint. Secret rotation is safe for queued events after the controller begins accepting the replacement secret. Controller/site changes require an explicit migration of old queued events; they are deliberately never sent to a different tenant or destination.

Activate per site with `wp plugin activate cyberedge-cache`. Network activation is rejected. Multisite requires separate provisioning and a matching `CYBEREDGE_BLOG_ID`; a single network-wide constant set is not sufficient for multiple tenants. Activation creates the `{prefix}cyberedge_purge_outbox` table. It enqueues an initial purge immediately when managed credentials already exist; otherwise the first purge is queued after one-click approval. Deactivation stops both WordPress schedules and keeps pending rows.

For managed origins, provision a system timer to run `wp --path=/origin/wordpress cyberedge deliver` at least once per minute as that site's operating-system user. The plugin also schedules an immediate loopback cron wake and a minute retry schedule. A system timer is still recommended because cache hits do not reach WordPress and hosts may disable loopback requests. Each worker handles at most ten ready events; provision additional worker runs or increase the tested worker capacity if the queue age rises. SQL compare-and-swap leases prevent simultaneous delivery of an active event. The controller must tolerate retries after a worker crashes after remote acceptance.

`wp cyberedge purge` durably enqueues a whole-site event. `wp cyberedge deliver` submits queued events and returns an error if any attempted delivery fails. `wp cyberedge status` returns queue depth and oldest enqueue time, without secrets. The installer/controller should monitor oldest-event age and worker success. A queue older than five minutes or a failed enqueue produces an administrator notice. A failed enqueue sets the persistent `cyberedge_purge_enqueue_failed` option: after fixing the database/configuration issue, enqueue a full purge, confirm acceptance, then clear that alarm with `wp option delete cyberedge_purge_enqueue_failed`.

Administrators can also open **Tools → CyberEdge Cache** to see configuration, delivery-worker, queue, page-policy, and live public cache-header status, disable or enable page caching, or queue a nonce-protected worldwide purge. A cache-state change preserves the connection and queues one worldwide purge; enabling fails closed unless that durable purge event is recorded first. WordPress Site Health reports local purge-delivery readiness. Exact confirmed bandwidth, requests, allowance, invoices, and per-domain state remain in the authenticated customer workspace; the plugin links there without copying potentially stale billing figures into WordPress. The page deliberately never renders controller credentials or the purge secret.

## Controller wire contract

```text
POST /v1/sites/{site_id}/purges
Content-Type: application/json
X-CyberEdge-Timestamp: <current epoch seconds>
X-CyberEdge-Signature: <lowercase hex HMAC-SHA256>

{"event_id":"<UUID v4>","scope":"site"}
```

The signing input is the decimal timestamp, one LF byte, and the **exact raw JSON body**. The HMAC key is the site's purge secret. Each retry signs with the current timestamp but reuses the persisted body and event ID. The controller accepts a maximum 300-second clock skew. It must verify the raw body before parsing, authenticate against the path's site, durably commit the event and revision, and return:

```json
{"event_id":"<same UUID>","site_id":"<same site>","revision":1,"state":"pending"}
```

Only HTTP 202 with a matching site/event, positive integer revision, and `pending` or `complete` state acknowledges delivery. `pending` means the controller owns the durable global-delivery obligation; it does **not** claim that every edge has completed the purge. Repeating the same event/payload must return the same revision. A changed payload under an existing event ID must return 409. The plugin keeps failures, retries with exponential delay capped near one hour, verifies the controller certificate, disables HTTP redirects, and never logs response bodies or signing secrets.

## What triggers an event

Published post creation, published content updates, published-to-unpublished transitions, scheduled publication, post deletion, public comment changes, menu and taxonomy changes, theme/customizer updates, WordPress upgrades, and WooCommerce product/variation/stock changes enqueue synchronously. Pending or spam-only comment changes do not invalidate the public cache. Separate application mutation events are retained even within one PHP request: a parallel worker could acknowledge an earlier event before a later mutation. Hooks run for dashboard, REST-backed mutations, WP-CLI, and scheduled tasks; the enqueue operation performs no HTTP request.

LSCWP exposes overlapping API, internal, and post-purge hooks for a single operation. Listening to all of them can enqueue multiple global events for one edit. CyberEdge therefore observes only LSCWP's final `litespeed_purge_tags` filter, leaves the tag list byte-for-byte unchanged, ignores private-only tag sets, and deduplicates repeated public invocations within the same request. Because full dependency semantics are not yet synchronized across origins and edges, an observed public tag set produces one `scope:site` event.

Hooks were checked against official LSCWP source at commit [`a4bd5fa11b77c95c660f003aec6a6c0d982cc9a8`](https://github.com/litespeedtech/lscache_wp/tree/a4bd5fa11b77c95c660f003aec6a6c0d982cc9a8), including [API registration](https://github.com/litespeedtech/lscache_wp/blob/a4bd5fa11b77c95c660f003aec6a6c0d982cc9a8/src/api.cls.php) and [purge dispatch, notifications, and final tag filtering](https://github.com/litespeedtech/lscache_wp/blob/a4bd5fa11b77c95c660f003aec6a6c0d982cc9a8/src/purge.cls.php). WordPress documents [post status transitions](https://developer.wordpress.org/reference/hooks/transition_post_status/), [menu updates](https://developer.wordpress.org/reference/hooks/wp_update_nav_menu/), and [comment insertion](https://developer.wordpress.org/reference/hooks/wp_insert_comment/). Product CRUD and stock notifications are emitted by [WooCommerce's official product data store](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php).

## Cache safety and limits

When LSCWP is loaded, CyberEdge still emits an explicit reverse-proxy cache policy because the origin server may consume LSCWP's internal cache header before CyberEdge receives the response. CyberEdge honors existing private/no-store/ESI and cookie-vary directives and adds a no-cache/no-store veto for authenticated requests, personalizing or unknown cookies, any query string, non-GET/HEAD methods, ranges, explicit request no-cache/no-store/zero-max-age directives, previews, search, errors, feeds, AJAX/REST, password-protected content, WooCommerce cart/checkout/account pages, and `DONOTCACHEPAGE`. Remaining anonymous HTML receives `X-LiteSpeed-Cache-Control: public,max-age=300` plus `Cache-Control: public,max-age=0,s-maxage=300`; the latter enables CyberEdge's shared cache on a PHP-generated response and does not give the browser a positive HTML max-age. If an origin page cache serves before PHP and consumes that policy, CyberEdge's native layer recognizes a strict allow-list of unambiguous public cache-hit markers and applies the vhost's 300-second fallback only after the same request/response privacy vetoes. Custom personalization that does not use supported cache-veto signals still requires application integration. Public fallback can be disabled with TTL zero.

The plugin has a narrow analytics/attribution cookie exception: exact `_ga`, `_gid`, `_gat`, `_gcl_au`, `_fbp`, and `_ga_` / `_gat_` followed only by one or more ASCII letters or digits. This candidate also permits exactly `sbjs_current`, `sbjs_current_add`, `sbjs_first`, `sbjs_first_add`, `sbjs_migrations`, `sbjs_session`, and `sbjs_udata`, which WooCommerce uses for [browser-side order attribution](https://woocommerce.com/document/order-attribution-tracking/). There is no arbitrary `sbjs_` prefix exception. The matching native edge policy must be deployed before those attribution cookies can receive edge HITs; older edge versions continue to bypass them safely.

Requests containing any other cookie still bypass the public cache. This includes WordPress authentication, commenter and password cookies, WooCommerce cart/session cookies, `_lscache_vary`, currency, language, consent, and custom application sessions. Raw cookie names are validated before PHP normalizes them; malformed or duplicate cookie pairs remain private. These analytics/attribution cookies must not be repurposed for server-side content personalization. Cart, checkout, account, REST/AJAX and authenticated requests remain private even when the exact attribution cookies are present.

This is deliberately more restrictive than LSCWP's origin cache: its [cookie exclusions](https://github.com/litespeedtech/lscache_wp/blob/a4bd5fa11b77c95c660f003aec6a6c0d982cc9a8/src/control.cls.php) are configured names, and its [vary handling](https://github.com/litespeedtech/lscache_wp/blob/a4bd5fa11b77c95c660f003aec6a6c0d982cc9a8/src/vary.cls.php) tracks login, commenter, password, and extension-defined states. CyberEdge does not copy those private/ESI variants into the shared public cache. This avoids treating arbitrary custom cookies as safe without an application-specific contract.

LSCWP finalizes response cacheability after `template_redirect`. CyberEdge therefore rechecks response vetoes on the official `litespeed_buffer_after` filter and after LSCWP's priority-zero shutdown handler, before WordPress flushes its output buffers. This final pass only downgrades cacheability: a late no-cache, private, ESI, custom vary, or Set-Cookie response cannot retain CyberEdge's earlier public shared-cache signal. Explicit zero, negative, or invalid origin lifetimes veto caching, and shorter positive lifetimes lower the shared TTL rather than being extended to the configured default. Standard `s-maxage` takes precedence over browser `max-age`, including CyberEdge's zero browser max-age. It leaves response bodies and application header callbacks unchanged.

The dashboard explains `HIT`, `MISS`, and intentional `BYPASS` responses using `X-CyberEdge-Cache-Reason`. Its public check omits WordPress login cookies. A normal browser refresh may send `Cache-Control: max-age=0`, while DevTools' **Disable cache** sends a no-cache request; CyberEdge honors those instructions. To test a normal visitor, use a clean session with Disable cache unchecked and navigate to the page rather than force-refreshing. A cold response may be a MISS before the next normal navigation becomes a HIT. A missing CyberEdge header is reported separately from an intentional bypass.

The public check has a 15-second deadline and re-enables the button after a result, timeout, or network failure. An HTTP error is reported as an error even if its response includes a cache header; it is not shown as a successful cache HIT.

This requires CyberEdge's hardened OpenLiteSpeed edge policy and request bypass rules. [Official OpenLiteSpeed documentation](https://docs.openlitespeed.org/config/reverseproxy/lscache/) describes the underlying reverse-proxy LSCache integration. Origin headers must survive to the edge, and edge storage and purges must remain scoped to the provisioned site's native virtual host. This plugin does not enable OpenLiteSpeed configuration or deploy edge nodes.

The outbox insert is durable once its SQL statement commits. WordPress mutation hooks and the original content update are not one database transaction: a process crash between those operations, a database outage, disabled plugin, or direct SQL content edits can miss a purge. Whole-site purge on activation and finite cache TTL bound recovery, but they do not provide transactional change capture. An origin/database change stream is needed if that stronger guarantee is required. Running multiple overlapping purge events is safe; precise dependency tags and bulk-event compaction are future capacity improvements.

## Isolated verification

Run `php tests/outbox_test.php` from the repository root with PHP 7.4+ and PDO SQLite. The fake WordPress environment uses a fresh SQLite file, executes the production outbox DML, closes and reopens connections, and injects HTTP responses. No external request or live WordPress installation is used. It covers mutation hooks, comment visibility, LSCWP event deduplication, CLI enqueue, persistence, lease exclusion and recovery, stale acknowledgement rejection, stable signed retries, wrong-site and redirect rejection, configuration boundaries, failed-insert alarms, and public/private cache policy. SQLite schema translation is limited to MySQL unsigned/index syntax; this is not a real WordPress/MySQL compatibility or browser test.

Set `LSCACHE_WP_ROOT` to a LiteSpeed Cache checkout and run `php tests/lscache_compatibility_test.php` to verify the exact external contracts CyberEdge depends on. The repository workflow checks the pinned audited LSCWP revision as well as the current upstream default branch so contract drift is visible before release.

Run `node tests/admin_status_test.js` to exercise the real dashboard handler for HIT, MISS, bypass reasons, missing headers, and network errors without sending external requests.

Run `php tests/cache_headers_http_test.php` for a loopback-only PHP HTTP server test of late cache-control, cookie, content-type, and vary vetoes. It exercises both finalization hooks using the production plugin code and verifies actual transmitted headers and unchanged bodies; it is not a substitute for testing a deployed WordPress/LiteSpeed installation.
