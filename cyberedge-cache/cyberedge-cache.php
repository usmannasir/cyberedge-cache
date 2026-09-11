<?php
/**
 * Plugin Name: CyberEdge Cache
 * Plugin URI: https://github.com/usmannasir/cyberedge-cache
 * Description: Durable site purge delivery to CyberEdge and conservative public page cache signals.
 * Version: 0.4.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: CyberPanel
 * Author URI: https://cyberpanel.net/
 * License: GPL-3.0-or-later
 * Text Domain: cyberedge-cache
 */
defined( 'ABSPATH' ) || exit;

final class CyberEdge_Config {
    const OPTION = 'cyberedge_connection_v1';
    public $site_id;
    public $controller;
    private $secret;

    public function __construct( $site_id, $controller, $secret ) {
        $url = parse_url( $controller );
        if ( ! is_string( $site_id ) || ! preg_match( '/\A[A-Za-z0-9_-]{1,100}\z/', $site_id ) ||
            ! is_string( $secret ) || strlen( $secret ) < 32 || strlen( $controller ) > 255 || ! filter_var( $controller, FILTER_VALIDATE_URL ) ||
            ! $url || ( $url['scheme'] ?? '' ) !== 'https' || empty( $url['host'] ) ||
            isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ||
            ! in_array( $url['path'] ?? '', array( '', '/' ), true ) ) {
            throw new InvalidArgumentException( 'CyberEdge requires a site ID, HTTPS controller origin, and a secret of at least 32 bytes.' );
        }
        $this->site_id = $site_id;
        $this->controller = rtrim( $controller, '/' );
        $this->secret = $secret;
    }

    public static function load() {
        $names = array( 'CYBEREDGE_SITE_ID', 'CYBEREDGE_CONTROLLER_URL', 'CYBEREDGE_PURGE_SECRET' );
        $defined = array_filter( $names, 'defined' );
        if ( count( $defined ) === count( $names ) ) {
            if ( is_multisite() && ( ! defined( 'CYBEREDGE_BLOG_ID' ) || (int) CYBEREDGE_BLOG_ID !== get_current_blog_id() ) ) {
                throw new InvalidArgumentException( 'CyberEdge must be configured separately for this WordPress site.' );
            }
            return new self( CYBEREDGE_SITE_ID, CYBEREDGE_CONTROLLER_URL, CYBEREDGE_PURGE_SECRET );
        }
        if ( count( $defined ) ) {
            throw new InvalidArgumentException( 'CyberEdge server configuration is incomplete.' );
        }
        $stored = get_option( self::OPTION );
        if ( ! is_array( $stored ) || ( $stored['version'] ?? null ) !== 1 || ! function_exists( 'openssl_decrypt' ) ) {
            throw new InvalidArgumentException( 'CyberEdge is not connected yet.' );
        }
        $key = hash( 'sha256', wp_salt( 'auth' ) . "\ncyberedge-connection-v1", true );
        $nonce = base64_decode( $stored['nonce'] ?? '', true );
        $tag = base64_decode( $stored['tag'] ?? '', true );
        $ciphertext = base64_decode( $stored['ciphertext'] ?? '', true );
        if ( strlen( (string) $nonce ) !== 12 || strlen( (string) $tag ) !== 16 || $ciphertext === false ) {
            throw new InvalidArgumentException( 'The saved CyberEdge connection is invalid.' );
        }
        $plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'cyberedge-connection-v1' );
        $value = json_decode( (string) $plaintext, true );
        if ( ! is_array( $value ) || ! isset( $value['site_id'], $value['controller'], $value['secret'] ) ) {
            throw new InvalidArgumentException( 'The saved CyberEdge connection cannot be opened.' );
        }
        return new self( $value['site_id'] ?? null, $value['controller'] ?? null, $value['secret'] ?? null );
    }

    public static function store( $site_id, $controller, $secret ) {
        $config = new self( $site_id, $controller, $secret );
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            throw new RuntimeException( 'OpenSSL is required to protect the CyberEdge connection.' );
        }
        $nonce = random_bytes( 12 );
        $tag = '';
        $key = hash( 'sha256', wp_salt( 'auth' ) . "\ncyberedge-connection-v1", true );
        $plaintext = wp_json_encode( array( 'site_id' => $config->site_id, 'controller' => $config->controller, 'secret' => $secret ) );
        $ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'cyberedge-connection-v1' );
        if ( $ciphertext === false || strlen( $tag ) !== 16 || ! update_option( self::OPTION, array(
            'version' => 1, 'nonce' => base64_encode( $nonce ), 'tag' => base64_encode( $tag ),
            'ciphertext' => base64_encode( $ciphertext ),
        ), false ) ) {
            throw new RuntimeException( 'CyberEdge could not save the protected connection.' );
        }
        return $config;
    }

    public static function managed_by_constants() {
        return defined( 'CYBEREDGE_SITE_ID' ) || defined( 'CYBEREDGE_CONTROLLER_URL' ) || defined( 'CYBEREDGE_PURGE_SECRET' );
    }

    public function signature( $timestamp, $body ) {
        return hash_hmac( 'sha256', (string) $timestamp . "\n" . $body, $this->secret );
    }

    public function endpoint() {
        return $this->controller . '/v1/sites/' . rawurlencode( $this->site_id ) . '/purges';
    }
}

/** SQL writes are synchronous; remote delivery is exclusively a cron/CLI job. */
final class CyberEdge_Outbox {
    private $db;
    private $table;

    public function __construct( $db ) {
        $this->db = $db;
        $this->table = $db->prefix . 'cyberedge_purge_outbox';
    }

    public function install() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $this->db->get_charset_collate();
        dbDelta( "CREATE TABLE {$this->table} (
            event_id char(36) NOT NULL,
            site_id varchar(128) NOT NULL,
            controller varchar(255) NOT NULL,
            body text NOT NULL,
            reason varchar(80) NOT NULL,
            created_at bigint unsigned NOT NULL,
            available_at bigint unsigned NOT NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            lease_token char(36) NOT NULL DEFAULT '',
            lease_until bigint unsigned NOT NULL DEFAULT 0,
            last_error varchar(40) NOT NULL DEFAULT '',
            PRIMARY KEY  (event_id),
            KEY ready (available_at,lease_until)
        ) $collate;" );
    }

    public function enqueue( $config, $reason, $now ) {
        $id = wp_generate_uuid4();
        $body = wp_json_encode( array( 'event_id' => $id, 'scope' => 'site' ) );
        $ok = $this->db->insert( $this->table, array(
            'event_id' => $id, 'site_id' => $config->site_id, 'controller' => $config->controller,
            'body' => $body, 'reason' => substr( $reason, 0, 80 ),
            'created_at' => $now, 'available_at' => $now + 1,
        ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' ) );
        if ( $ok !== 1 ) {
            throw new RuntimeException( 'outbox_insert_failed' );
        }
        return $id;
    }

    public function claim( $config, $now ) {
        // Bound rows to their original tenant and destination, even after reconfiguration.
        $row = $this->db->get_row( $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE site_id=%s AND controller=%s AND available_at<=%d AND lease_until<=%d ORDER BY created_at,event_id LIMIT 1",
            $config->site_id, $config->controller, $now, $now
        ) );
        // WordPress installations may use case-insensitive MySQL collations.
        if ( ! $row || $row->site_id !== $config->site_id || $row->controller !== $config->controller ) {
            return null;
        }
        $token = wp_generate_uuid4();
        $changed = $this->db->query( $this->db->prepare(
            "UPDATE {$this->table} SET lease_token=%s,lease_until=%d,attempts=attempts+1 WHERE event_id=%s AND lease_until<=%d",
            $token, $now + 90, $row->event_id, $now
        ) );
        if ( $changed !== 1 ) {
            return null;
        }
        $row->lease_token = $token;
        $row->attempts = (int) $row->attempts + 1;
        return $row;
    }

    public function acknowledge( $row ) {
        return $this->db->query( $this->db->prepare(
            "DELETE FROM {$this->table} WHERE event_id=%s AND lease_token=%s", $row->event_id, $row->lease_token
        ) ) === 1;
    }

    public function retry( $row, $now, $error ) {
        $delay = min( 3600, 10 * ( 2 ** min( 9, $row->attempts - 1 ) ) ) + wp_rand( 0, 5 );
        $this->db->query( $this->db->prepare(
            "UPDATE {$this->table} SET available_at=%d,lease_until=0,lease_token='',last_error=%s WHERE event_id=%s AND lease_token=%s",
            $now + $delay, $error, $row->event_id, $row->lease_token
        ) );
    }

    public function status() {
        $row = $this->db->get_row( "SELECT COUNT(*) AS pending,MIN(created_at) AS oldest FROM {$this->table}" );
        if ( ! $row ) {
            throw new RuntimeException( 'outbox_read_failed' );
        }
        return array( 'pending' => (int) $row->pending, 'oldest' => $row->oldest === null ? null : (int) $row->oldest );
    }
}

final class CyberEdge_Cache {
    const CRON = 'cyberedge_deliver_purges';
    const WAKE_CRON = 'cyberedge_deliver_purges_now';
    const PLATFORM = 'https://platform.cyberpersons.com';
    const CONNECT_TRANSIENT = 'cyberedge_connect_pending_v1';
    private $outbox;
    private $config;
    private $http;
    private $clock;
    private $lscache_purge_queued = false;
    private $admin_page_hook = '';
    private $worker_woken = false;

    public function __construct( $outbox, $config = null, $http = 'wp_remote_post', $clock = 'time' ) {
        $this->outbox = $outbox;
        $this->config = $config;
        $this->http = $http;
        $this->clock = $clock;
    }

    private function configuration() {
        return $this->config ?: CyberEdge_Config::load();
    }

    public function register() {
        add_action( 'transition_post_status', array( $this, 'post_transition' ), 100, 3 );
        add_action( 'deleted_post', array( $this, 'post_deleted' ), 100, 2 );
        foreach ( array(
            'wp_update_nav_menu', 'wp_delete_nav_menu', 'created_term', 'edited_term', 'delete_term',
            'set_object_terms', 'switch_theme', 'customize_save_after',
            'upgrader_process_complete', 'automatic_updates_complete',
            'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_delete_product',
            'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'woocommerce_delete_product_variation',
            'woocommerce_product_set_stock', 'woocommerce_variation_set_stock',
            'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status',
        ) as $hook ) {
            add_action( $hook, array( $this, 'mutation' ), 100, 0 );
        }
        add_action( 'wp_insert_comment', array( $this, 'comment_inserted' ), 100, 2 );
        add_action( 'edit_comment', array( $this, 'comment_edited' ), 100, 1 );
        add_action( 'transition_comment_status', array( $this, 'comment_transition' ), 100, 3 );
        add_action( 'deleted_comment', array( $this, 'comment_deleted' ), 100, 2 );
        // LSCWP applies this filter to its final public purge-tag set. Observing
        // that one contract avoids duplicate events from its overlapping API,
        // internal, and post-purge hooks while leaving its tags untouched.
        add_filter( 'litespeed_purge_tags', array( $this, 'purge_tags' ), 100, 2 );
        add_action( self::CRON, array( $this, 'deliver' ) );
        add_action( self::WAKE_CRON, array( $this, 'deliver' ) );
        add_filter( 'cron_schedules', array( $this, 'schedules' ) );
        add_action( 'init', array( $this, 'ensure_schedule' ) );
        add_action( 'template_redirect', array( $this, 'cache_headers' ), PHP_INT_MAX );
        add_filter( 'rest_post_dispatch', array( $this, 'rest_headers' ), PHP_INT_MAX, 3 );
        add_action( 'admin_notices', array( $this, 'admin_notice' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
        add_action( 'admin_post_cyberedge_purge', array( $this, 'manual_purge' ) );
        add_action( 'admin_post_cyberedge_connect_start', array( $this, 'connect_start' ) );
        add_action( 'admin_post_cyberedge_connect_callback', array( $this, 'connect_callback' ) );
        add_filter( 'site_status_tests', array( $this, 'site_health_tests' ) );
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'cyberedge purge', function () {
                $id = $this->enqueue( 'wp_cli' );
                if ( ! $id ) { WP_CLI::error( 'CyberEdge could not persist the purge event.' ); }
                WP_CLI::success( 'Queued CyberEdge purge ' . $id );
            } );
            WP_CLI::add_command( 'cyberedge deliver', function () {
                $result = $this->deliver();
                WP_CLI::log( wp_json_encode( $result ) );
                if ( $result['failed'] ) { WP_CLI::error( 'Purge delivery failed; events remain queued.' ); }
            } );
            WP_CLI::add_command( 'cyberedge status', function () {
                WP_CLI::log( wp_json_encode( $this->outbox->status() ) );
            } );
        }
    }

    public function post_transition( $new, $old, $post ) {
        if ( ( $new === 'publish' || $old === 'publish' ) && $post->post_type !== 'revision' ) {
            $this->enqueue( 'post_status_or_content' );
        }
    }

    public function post_deleted( $id, $post ) {
        if ( $post && $post->post_type !== 'revision' ) { $this->enqueue( 'deleted_post' ); }
    }

    public function mutation() { $this->enqueue( current_filter() ); }

    public function comment_inserted( $id, $comment ) {
        if ( $comment && (string) $comment->comment_approved === '1' ) {
            $this->enqueue( 'wp_insert_comment' );
        }
    }

    public function comment_edited( $id ) {
        $comment = get_comment( $id );
        if ( $comment && (string) $comment->comment_approved === '1' ) {
            $this->enqueue( 'edit_comment' );
        }
    }

    public function comment_transition( $new, $old, $comment ) {
        if ( $new !== $old && ( $new === 'approved' || $old === 'approved' ) ) {
            $this->enqueue( 'transition_comment_status' );
        }
    }

    public function comment_deleted( $id, $comment ) {
        if ( $comment && (string) $comment->comment_approved === '1' ) {
            $this->enqueue( 'deleted_comment' );
        }
    }

    public function purge_tags( $tags, $private ) {
        if ( ! $private && ! empty( $tags ) && ! $this->lscache_purge_queued ) {
            if ( $this->enqueue( 'litespeed_public_purge_tags' ) ) {
                $this->lscache_purge_queued = true;
            }
        }
        return $tags;
    }

    public function enqueue( $reason ) {
        try {
            // Do not deduplicate in request memory: another worker can finish before a later mutation.
            $id = $this->outbox->enqueue( $this->configuration(), $reason, call_user_func( $this->clock ) );
            $this->wake_worker();
            return $id;
        } catch ( Throwable $error ) {
            update_option( 'cyberedge_purge_enqueue_failed', 1, false );
            error_log( 'CyberEdge: unable to persist a purge. Check server configuration and the outbox database.' );
            return false;
        }
    }

    private function wake_worker() {
        if ( $this->worker_woken ) { return; }
        $this->worker_woken = true;
        wp_schedule_single_event( time(), self::WAKE_CRON );
        if ( function_exists( 'spawn_cron' ) ) { spawn_cron( time() ); }
    }

    public function deliver() {
        $result = array( 'accepted' => 0, 'failed' => 0 );
        try { $config = $this->configuration(); }
        catch ( Throwable $error ) { $result['failed']++; return $result; }
        // A prior insert failure is a persistent operator alarm, never silently cleared by a worker.
        for ( $i = 0; $i < 10; $i++ ) {
            $now = call_user_func( $this->clock );
            try { $row = $this->outbox->claim( $config, $now ); }
            catch ( Throwable $error ) { $result['failed']++; break; }
            if ( ! $row ) { break; }
            $error = 'invalid_acknowledgement';
            try {
                $response = call_user_func( $this->http, $config->endpoint(), array(
                    'timeout' => 5, 'redirection' => 0, 'sslverify' => true, 'blocking' => true,
                    'limit_response_size' => 4096,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'X-CyberEdge-Timestamp' => (string) $now,
                        'X-CyberEdge-Signature' => $config->signature( $now, $row->body ),
                    ),
                    'body' => $row->body, 'data_format' => 'body',
                ) );
                if ( is_wp_error( $response ) ) { $error = 'transport_failure'; }
                else {
                    $code = wp_remote_retrieve_response_code( $response );
                    $ack = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( $code === 202 && is_array( $ack ) && ( $ack['event_id'] ?? null ) === $row->event_id &&
                        ( $ack['site_id'] ?? null ) === $row->site_id && is_int( $ack['revision'] ?? null ) && $ack['revision'] > 0 &&
                        in_array( $ack['state'] ?? null, array( 'pending', 'complete' ), true ) ) {
                        if ( $this->outbox->acknowledge( $row ) ) { $result['accepted']++; continue; }
                        $error = 'local_acknowledgement_failed';
                    } elseif ( $code !== 202 ) { $error = 'http_' . (int) $code; }
                }
            } catch ( Throwable $exception ) { $error = 'transport_exception'; }
            $this->outbox->retry( $row, call_user_func( $this->clock ), $error );
            $result['failed']++;
        }
        return $result;
    }

    public function schedules( $schedules ) {
        $schedules['cyberedge_minute'] = array( 'interval' => 60, 'display' => 'CyberEdge every minute' );
        return $schedules;
    }

    public function ensure_schedule() {
        if ( wp_next_scheduled( self::CRON ) ) { return true; }
        return wp_schedule_event( time() + 60, 'cyberedge_minute', self::CRON ) !== false;
    }

    /** LSCWP can be installed for optimization while its page cache is off. */
    private function lscache_controls_page_cache() {
        return defined( 'LSCWP_V' ) && defined( 'LITESPEED_ON' ) && LITESPEED_ON &&
            ( ! defined( 'LITESPEED_DISABLE_ALL' ) || ! LITESPEED_DISABLE_ALL );
    }

    /** Public fallback only without LSCWP. Existing application controls remain authoritative. */
    public function cache_policy() {
        $status = http_response_code();
        if ( $status !== false && $status !== 200 ) { return 'private,no-cache,no-store'; }
        foreach ( headers_list() as $header ) {
            if ( stripos( $header, 'Set-Cookie:' ) === 0 ||
                ( preg_match( '/^(?:X-LiteSpeed-Cache-Control|Cache-Control):/i', $header ) && preg_match( '/\b(?:private|no-cache|no-store)\b|esi\s*=\s*on/i', $header ) ) ||
                ( stripos( $header, 'Content-Type:' ) === 0 && stripos( $header, 'text/html' ) === false ) ||
                ( stripos( $header, 'Vary:' ) === 0 && preg_match( '/\A\s*accept-encoding\s*\z/i', substr( $header, 5 ) ) !== 1 ) ) {
                return 'private,no-cache,no-store';
            }
        }
        if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ||
            ! empty( $_COOKIE ) || ! empty( $_SERVER['HTTP_COOKIE'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ||
            ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['PHP_AUTH_USER'] ) ||
            ! empty( $_SERVER['QUERY_STRING'] ) || is_user_logged_in() || is_admin() ||
            is_preview() || is_search() || is_404() || is_feed() || is_trackback() || post_password_required() ||
            ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ||
            ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ||
            ( function_exists( 'is_account_page' ) && is_account_page() ) ||
            ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
            return 'private,no-cache,no-store';
        }
        if ( $this->lscache_controls_page_cache() ) { return null; }
        $ttl = defined( 'CYBEREDGE_CACHE_TTL' ) ? (int) CYBEREDGE_CACHE_TTL : 300;
        return $ttl > 0 ? 'public,max-age=' . min( 3600, $ttl ) : 'no-cache,no-store';
    }

    public function cache_headers() {
        try { $this->configuration(); } catch ( Throwable $error ) { return; }
        $policy = $this->cache_policy();
        if ( $policy === null ) { return; }
        if ( strpos( $policy, 'no-store' ) !== false ) { do_action( 'litespeed_control_set_nocache', 'CyberEdge private request' ); }
        if ( ! headers_sent() ) {
            // Append, never erase an application's earlier private/no-store veto.
            header( 'X-LiteSpeed-Cache-Control: ' . $policy, false );
            if ( strpos( $policy, 'no-store' ) !== false ) { header( 'Cache-Control: private, no-store', false ); }
        }
    }

    /** REST skips template_redirect; also covers a customized REST URL prefix. */
    public function rest_headers( $response, $server = null, $request = null ) {
        try { $this->configuration(); } catch ( Throwable $error ) { return $response; }
        do_action( 'litespeed_control_set_nocache', 'CyberEdge REST request' );
        $response->header( 'X-LiteSpeed-Cache-Control', 'private,no-cache,no-store' );
        $response->header( 'Cache-Control', 'private,no-cache,no-store' );
        return $response;
    }

    public function admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        try {
            $this->configuration();
            $status = $this->outbox->status();
            $bad = get_option( 'cyberedge_purge_enqueue_failed' ) || ! wp_next_scheduled( self::CRON ) ||
                ( $status['oldest'] !== null && time() - $status['oldest'] > 300 );
        } catch ( Throwable $error ) { $bad = true; }
        if ( $bad ) {
            echo '<div class="notice notice-error"><p>CyberEdge needs attention. Open <a href="' . esc_url( admin_url( 'tools.php?page=cyberedge-cache' ) ) . '">Tools → CyberEdge Cache</a> to connect or inspect purge delivery. Cached pages may remain stale until their TTL expires.</p></div>';
        }
    }

    public function admin_menu() {
        $this->admin_page_hook = add_management_page(
            'CyberEdge Cache', 'CyberEdge Cache', 'manage_options', 'cyberedge-cache', array( $this, 'status_page' )
        );
    }

    public function admin_assets( $hook ) {
        if ( $hook !== $this->admin_page_hook ) { return; }
        wp_enqueue_style( 'cyberedge-cache-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), '0.4.0' );
        wp_enqueue_script( 'cyberedge-cache-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), '0.4.0', true );
        wp_localize_script( 'cyberedge-cache-admin', 'CyberEdgeCacheAdmin', array(
            'homeUrl' => home_url( '/' ),
            'cacheHeader' => 'X-CyberEdge-Cache',
        ) );
    }

    public function admin_bar( $bar ) {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $bar->add_node( array(
            'id' => 'cyberedge-cache',
            'title' => 'CyberEdge Cache',
            'href' => admin_url( 'tools.php?page=cyberedge-cache' ),
        ) );
    }

    public function status_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You are not allowed to manage CyberEdge Cache.' ); }
        $status = array( 'pending' => 0, 'oldest' => null );
        $configured = true;
        try {
            $this->configuration();
            $status = $this->outbox->status();
        } catch ( Throwable $error ) { $configured = false; }
        $scheduled = wp_next_scheduled( self::CRON ) !== false;
        $lscache = $this->lscache_controls_page_cache() ? 'Active; LiteSpeed controls public cacheability.' :
            'Not active; CyberEdge uses its conservative anonymous HTML policy.';
        $message = isset( $_GET['cyberedge_purge'] ) ? (string) $_GET['cyberedge_purge'] : '';
        echo '<div class="wrap cyberedge-admin"><header class="cyberedge-hero"><img src="' . esc_url( plugins_url( 'assets/cyberpanel-mark.svg', __FILE__ ) ) . '" alt="" width="56" height="56"><div><span>CYBERPANEL EDGE</span><h1>CyberEdge Cache</h1><p>Cache health, delivery status, and safe worldwide invalidation for this WordPress site.</p></div></header>';
        if ( $message === 'queued' ) { echo '<div class="notice notice-success"><p>A worldwide cache purge is queued.</p></div>'; }
        if ( $message === 'failed' ) { echo '<div class="notice notice-error"><p>The purge could not be queued. Check the database and server configuration.</p></div>'; }
        echo '<div class="cyberedge-grid">';
        echo '<section class="cyberedge-card"><span class="cyberedge-card-label">CONNECTION</span><strong>' . ( $configured ? 'Ready' : 'Needs attention' ) . '</strong><p>Server-side site configuration is ' . ( $configured ? 'available.' : 'missing or invalid.' ) . '</p></section>';
        echo '<section class="cyberedge-card"><span class="cyberedge-card-label">DELIVERY WORKER</span><strong>' . ( $scheduled ? 'Scheduled' : 'Not scheduled' ) . '</strong><p>Reliable purge delivery requires this worker plus the provisioned system timer.</p></section>';
        echo '<section class="cyberedge-card"><span class="cyberedge-card-label">PURGE QUEUE</span><strong>' . esc_html( (string) $status['pending'] ) . '</strong><p>' . ( $status['oldest'] === null ? 'No events are waiting.' : 'Oldest event: ' . esc_html( (string) max( 0, time() - $status['oldest'] ) ) . ' seconds ago.' ) . '</p></section>';
        echo '<section class="cyberedge-card"><span class="cyberedge-card-label">PAGE POLICY</span><strong>' . ( $this->lscache_controls_page_cache() ? 'LiteSpeed active' : 'CyberEdge fallback' ) . '</strong><p>' . esc_html( $lscache ) . '</p></section>';
        echo '</div><div class="cyberedge-columns"><section class="cyberedge-panel"><h2>Live cache status</h2><p>Check the public home page without WordPress login cookies. A first MISS may warm the page; check again to confirm a HIT.</p><div class="cyberedge-live-row"><button type="button" class="button button-primary" id="cyberedge-check-cache">Check cache status</button><strong id="cyberedge-cache-result" class="cyberedge-result" aria-live="polite">Not checked</strong></div><p class="description">Reads the customer-facing <code>X-CyberEdge-Cache</code> response header. No controller credential is sent.</p></section>';
        echo '<section class="cyberedge-panel"><h2>Bandwidth and domains</h2><p>Exact confirmed bandwidth, request usage, plan allowance, invoices, and per-domain serving state remain in your authenticated customer workspace.</p><a class="button button-secondary" href="https://platform.cyberpersons.com/edge/" target="_blank" rel="noopener noreferrer">View bandwidth usage →</a></section></div>';
        echo '<section class="cyberedge-panel cyberedge-purge"><h2>Purge worldwide</h2><p>Queue a durable whole-site purge after a deployment or when content must be invalidated immediately. Normal WordPress changes are already handled automatically.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="cyberedge_purge">';
        wp_nonce_field( 'cyberedge_purge' );
        submit_button( 'Purge CyberEdge cache worldwide' );
        echo '</form><p class="description">No purge secret or controller credential is shown on this page.</p></section>';
        echo '<section class="cyberedge-panel"><h2>' . ( $configured ? 'WordPress connection' : 'Connect to CyberEdge' ) . '</h2>';
        if ( CyberEdge_Config::managed_by_constants() ) {
            echo '<p>This site is managed by its server configuration. Contact the server administrator to change the connection.</p>';
        } else {
            echo '<p>' . ( $configured ? 'Reconnect this site if its domain enrollment or private credential has changed.' : 'Sign in, choose a plan, and approve this exact WordPress site. You will return here automatically.' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cyberedge_connect_start">';
            wp_nonce_field( 'cyberedge_connect_start' );
            submit_button( $configured ? 'Reconnect to CyberEdge' : 'Connect to CyberEdge' );
            echo '</form>';
        }
        if ( isset( $_GET['cyberedge_connected'] ) && $_GET['cyberedge_connected'] === 'yes' ) { echo '<p><strong>Connection complete.</strong> Automatic purge delivery is ready.</p>'; }
        echo '</section></div>';
    }

    private function base64url( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    private function connect_transient_key() {
        return self::CONNECT_TRANSIENT . '_' . (string) get_current_user_id();
    }

    public function connect_start() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You are not allowed to connect CyberEdge Cache.' ); }
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { wp_die( 'Use the CyberEdge connection button.' ); }
        check_admin_referer( 'cyberedge_connect_start' );
        if ( CyberEdge_Config::managed_by_constants() ) { wp_die( 'This site is managed by its server configuration.' ); }
        $site_url = home_url( '/' );
        $site = parse_url( $site_url );
        if ( ! $site || ( $site['scheme'] ?? '' ) !== 'https' || empty( $site['host'] ) || isset( $site['user'] ) || isset( $site['pass'] ) ) {
            wp_die( 'CyberEdge one-click connection requires the WordPress home URL to use HTTPS.' );
        }
        $callback = add_query_arg( 'action', 'cyberedge_connect_callback', admin_url( 'admin-post.php' ) );
        $verifier = $this->base64url( random_bytes( 32 ) );
        $state = $this->base64url( random_bytes( 32 ) );
        $pending = array( 'state' => $state, 'verifier' => $verifier, 'redirect_uri' => $callback, 'site_url' => $site_url );
        set_transient( $this->connect_transient_key(), $pending, 10 * MINUTE_IN_SECONDS );
        $url = add_query_arg( array(
            'state' => $state, 'code_challenge' => $this->base64url( hash( 'sha256', $verifier, true ) ),
            'code_challenge_method' => 'S256', 'site_url' => $site_url, 'redirect_uri' => $callback,
        ), self::PLATFORM . '/edge/wordpress/connect/' );
        wp_redirect( $url );
        exit;
    }

    public function connect_callback() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sign in as a WordPress administrator to finish the CyberEdge connection.' ); }
        $pending = get_transient( $this->connect_transient_key() );
        $code = isset( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '';
        $state = isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : '';
        $issuer = isset( $_GET['iss'] ) ? wp_unslash( $_GET['iss'] ) : '';
        if ( ! is_array( $pending ) || ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $code ) ||
            ! hash_equals( $pending['state'], $state ) || $issuer !== self::PLATFORM ) {
            wp_die( 'The CyberEdge connection expired or its security proof did not match. Start again.' );
        }
        $response = wp_remote_post( self::PLATFORM . '/edge/wordpress/exchange/', array(
            'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'blocking' => true, 'limit_response_size' => 8192,
            'headers' => array( 'Content-Type' => 'application/json' ), 'data_format' => 'body',
            'body' => wp_json_encode( array( 'code' => $code, 'code_verifier' => $pending['verifier'], 'redirect_uri' => $pending['redirect_uri'] ) ),
        ) );
        $body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 || ! is_array( $body ) ) {
            wp_die( 'CyberEdge could not confirm the connection. The code was not saved; start again.' );
        }
        $site_host = strtolower( (string) ( parse_url( $pending['site_url'], PHP_URL_HOST ) ?: '' ) );
        if ( ( $body['domain'] ?? null ) !== $site_host || ! is_string( $body['dashboard_url'] ?? null ) ||
            strpos( $body['dashboard_url'], self::PLATFORM . '/edge/domains/' ) !== 0 ) {
            wp_die( 'CyberEdge returned a connection for a different site.' );
        }
        try { CyberEdge_Config::store( $body['site_id'] ?? null, $body['controller_url'] ?? null, $body['purge_secret'] ?? null ); }
        catch ( Throwable $error ) { wp_die( 'CyberEdge could not protect the site connection locally.' ); }
        delete_transient( $this->connect_transient_key() );
        $this->outbox->install();
        $this->ensure_schedule();
        $this->enqueue( 'connected' );
        wp_safe_redirect( add_query_arg( 'cyberedge_connected', 'yes', admin_url( 'tools.php?page=cyberedge-cache' ) ) );
        exit;
    }

    public function manual_purge() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You are not allowed to purge CyberEdge Cache.' ); }
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { wp_die( 'Use the CyberEdge Cache purge form.' ); }
        check_admin_referer( 'cyberedge_purge' );
        $state = $this->enqueue( 'manual_admin_purge' ) ? 'queued' : 'failed';
        wp_safe_redirect( add_query_arg( 'cyberedge_purge', $state, admin_url( 'tools.php?page=cyberedge-cache' ) ) );
        exit;
    }

    public function site_health_tests( $tests ) {
        $tests['direct']['cyberedge_cache'] = array(
            'label' => 'CyberEdge Cache delivery',
            'test' => array( $this, 'site_health' ),
        );
        return $tests;
    }

    public function site_health() {
        $healthy = true;
        try {
            $this->configuration();
            $status = $this->outbox->status();
            $healthy = wp_next_scheduled( self::CRON ) !== false &&
                ! get_option( 'cyberedge_purge_enqueue_failed' ) &&
                ( $status['oldest'] === null || time() - $status['oldest'] <= 300 );
        } catch ( Throwable $error ) { $healthy = false; }
        return array(
            'label' => $healthy ? 'CyberEdge purge delivery is ready' : 'CyberEdge purge delivery needs attention',
            'status' => $healthy ? 'good' : 'critical',
            'badge' => array( 'label' => 'CyberEdge Cache', 'color' => $healthy ? 'blue' : 'red' ),
            'description' => '<p>' . ( $healthy ?
                'The site configuration, queue age, and WordPress delivery schedule passed their local checks.' :
                'Check Tools → CyberEdge Cache. Cached pages may remain stale until delivery recovers or their TTL expires.' ) . '</p>',
            'test' => 'cyberedge_cache',
        );
    }
}

function cyberedge_activate( $network_wide = false ) {
    if ( $network_wide ) { wp_die( 'Configure and activate CyberEdge separately for each WordPress site.' ); }
    global $wpdb;
    $outbox = new CyberEdge_Outbox( $wpdb );
    $outbox->install();
    $cache = new CyberEdge_Cache( $outbox );
    if ( ! $cache->ensure_schedule() ) { wp_die( 'CyberEdge could not schedule its purge delivery worker.' ); }
    try { CyberEdge_Config::load(); $configured = true; } catch ( Throwable $error ) { $configured = false; }
    if ( $configured && ! $cache->enqueue( 'activation' ) ) { wp_die( 'CyberEdge could not create its durable purge outbox.' ); }
}

register_activation_hook( __FILE__, 'cyberedge_activate' );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( CyberEdge_Cache::CRON ); wp_clear_scheduled_hook( CyberEdge_Cache::WAKE_CRON ); } );
global $wpdb;
$GLOBALS['cyberedge_cache'] = new CyberEdge_Cache( new CyberEdge_Outbox( $wpdb ) );
$GLOBALS['cyberedge_cache']->register();
