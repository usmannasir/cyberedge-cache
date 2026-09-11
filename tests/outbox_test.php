<?php
/** Isolated CLI tests. Uses SQLite for real persistence/CAS; no WordPress install or network. */
error_reporting( E_ALL );
$test_dir = sys_get_temp_dir() . '/cyberedge-php-' . bin2hex( random_bytes( 8 ) );
mkdir( $test_dir . '/wp-admin/includes', 0700, true );
file_put_contents( $test_dir . '/wp-admin/includes/upgrade.php', '<?php' );
define( 'ABSPATH', $test_dir . '/' );
define( 'CYBEREDGE_SITE_ID', 'site-test' );
define( 'CYBEREDGE_CONTROLLER_URL', 'https://controller.example.test' );
define( 'CYBEREDGE_PURGE_SECRET', str_repeat( 'secret-', 8 ) );
define( 'WP_CLI', true );
$hooks = array(); $hook_stack = array(); $options = array(); $scheduled = array(); $flags = array(); $http_calls = array(); $comments = array();
$admin_pages = array(); $assets = array( 'styles' => array(), 'scripts' => array(), 'localized' => array() );
$test_count = 0;

function check( $condition, $message ) {
    global $test_count;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    $test_count++;
}
function add_action( $hook, $callback, $priority = 10, $argc = 1 ) {
    $GLOBALS['hooks'][$hook][$priority][] = array( $callback, $argc );
}
function add_filter( $hook, $callback, $priority = 10, $argc = 1 ) { add_action( $hook, $callback, $priority, $argc ); }
function do_action( $hook, ...$args ) {
    $GLOBALS['hook_stack'][] = $hook;
    $callbacks = $GLOBALS['hooks'][$hook] ?? array(); ksort( $callbacks );
    foreach ( $callbacks as $group ) { foreach ( $group as $cb ) { call_user_func_array( $cb[0], array_slice( $args, 0, $cb[1] ) ); } }
    array_pop( $GLOBALS['hook_stack'] );
}
function apply_filters( $hook, $value, ...$args ) {
    $GLOBALS['hook_stack'][] = $hook;
    $callbacks = $GLOBALS['hooks'][$hook] ?? array(); ksort( $callbacks );
    foreach ( $callbacks as $group ) { foreach ( $group as $cb ) { $value = call_user_func_array( $cb[0], array_slice( array_merge( array( $value ), $args ), 0, $cb[1] ) ); } }
    array_pop( $GLOBALS['hook_stack'] ); return $value;
}
function current_filter() { return end( $GLOBALS['hook_stack'] ) ?: 'test'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_generate_uuid4() {
    $bytes = random_bytes( 16 ); $bytes[6] = chr( ( ord( $bytes[6] ) & 15 ) | 64 ); $bytes[8] = chr( ( ord( $bytes[8] ) & 63 ) | 128 );
    return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
}
function wp_rand( $min, $max ) { return $min; }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled'][$hook] ?? false; }
function wp_schedule_event( $time, $schedule, $hook ) { $GLOBALS['scheduled'][$hook] = $time; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['scheduled'][$hook] ); }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][$name] = $value; }
function get_option( $name ) { return $GLOBALS['options'][$name] ?? false; }
function get_comment( $id ) { return $GLOBALS['comments'][$id] ?? null; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function esc_html( $value ) { return htmlspecialchars( $value ); }
function current_user_can( $capability ) { return true; }
function add_management_page( $title, $menu, $capability, $slug, $callback ) {
    $GLOBALS['admin_pages'][$slug] = compact( 'title', 'menu', 'capability', 'callback' );
    return 'tools_page_' . $slug;
}
function plugins_url( $path, $file ) { return 'https://example.test/wp-content/plugins/cyberedge-cache/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://example.test/' . ltrim( $path, '/' ); }
function esc_url( $value ) { return $value; }
function wp_enqueue_style( $handle, $source, $dependencies, $version ) { $GLOBALS['assets']['styles'][$handle] = compact( 'source', 'dependencies', 'version' ); }
function wp_enqueue_script( $handle, $source, $dependencies, $version, $footer ) { $GLOBALS['assets']['scripts'][$handle] = compact( 'source', 'dependencies', 'version', 'footer' ); }
function wp_localize_script( $handle, $name, $value ) { $GLOBALS['assets']['localized'][$handle] = compact( 'name', 'value' ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function submit_button( $label ) { echo '<button type="submit">' . esc_html( $label ) . '</button>'; }
function is_user_logged_in() { return $GLOBALS['flags']['logged_in'] ?? false; }
function is_admin() { return $GLOBALS['flags']['admin'] ?? false; }
function is_preview() { return $GLOBALS['flags']['preview'] ?? false; }
function is_search() { return $GLOBALS['flags']['search'] ?? false; }
function is_404() { return $GLOBALS['flags']['404'] ?? false; }
function is_feed() { return $GLOBALS['flags']['feed'] ?? false; }
function is_trackback() { return false; }
function post_password_required() { return $GLOBALS['flags']['password'] ?? false; }
function wp_doing_ajax() { return $GLOBALS['flags']['ajax'] ?? false; }
function is_cart() { return $GLOBALS['flags']['cart'] ?? false; }
function is_checkout() { return $GLOBALS['flags']['checkout'] ?? false; }
function is_account_page() { return $GLOBALS['flags']['account'] ?? false; }
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_post( $url, $args ) { throw new RuntimeException( 'Unexpected network adapter invocation' ); }
class WP_CLI {
    public static $commands = array();
    public static function add_command( $name, $callback ) { self::$commands[$name] = $callback; }
    public static function error( $message ) { throw new RuntimeException( $message ); }
    public static function success( $message ) {}
    public static function log( $message ) {}
}

class TestWpdb {
    public $prefix = 'wp_';
    public $pdo;
    public $fail_insert = false;
    public function __construct( $path ) {
        $this->pdo = new PDO( 'sqlite:' . $path );
        $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    }
    public function get_charset_collate() { return ''; }
    public function prepare( $sql, ...$args ) {
        $i = 0;
        return preg_replace_callback( '/%[sd]/', function( $match ) use ( &$i, $args ) {
            $value = $args[$i++]; return $match[0] === '%d' ? (string) (int) $value : $this->pdo->quote( (string) $value );
        }, $sql );
    }
    public function get_row( $sql ) { return $this->pdo->query( $sql )->fetch( PDO::FETCH_OBJ ) ?: null; }
    public function query( $sql ) { return $this->pdo->exec( $sql ); }
    public function insert( $table, $data, $formats ) {
        if ( $this->fail_insert ) { return false; }
        $sql = 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')';
        $statement = $this->pdo->prepare( $sql ); $statement->execute( array_values( $data ) ); return $statement->rowCount();
    }
}
function dbDelta( $sql ) {
    // Only MySQL-specific unsigned/index declarations are translated; all production DML runs unchanged.
    $sql = preg_replace( '/,\s*KEY ready \(available_at,lease_until\)/', '', $sql );
    $sql = str_replace( ' unsigned', '', $sql );
    $GLOBALS['wpdb']->query( $sql );
}

$wpdb = new TestWpdb( $test_dir . '/outbox.sqlite' );
require __DIR__ . '/../cyberedge-cache/cyberedge-cache.php';
try {
    cyberedge_activate();
    $outbox = new CyberEdge_Outbox( $wpdb );
    check( $outbox->status()['pending'] === 1, 'Activation must durably enqueue a purge' );
    check( wp_next_scheduled( CyberEdge_Cache::CRON ) !== false, 'Activation schedules the retry worker' );
    check( isset( WP_CLI::$commands['cyberedge deliver'], WP_CLI::$commands['cyberedge purge'] ), 'CLI commands registered' );
    check( isset( $hooks['admin_menu'], $hooks['admin_enqueue_scripts'], $hooks['admin_bar_menu'],
        $hooks['admin_post_cyberedge_purge'], $hooks['site_status_tests'] ), 'Dashboard, manual purge, and Site Health hooks registered' );
    do_action( 'admin_menu' );
    check( isset( $admin_pages['cyberedge-cache'] ), 'CyberEdge dashboard is registered under WordPress Tools' );
    do_action( 'admin_enqueue_scripts', 'tools_page_cyberedge-cache' );
    check( isset( $assets['styles']['cyberedge-cache-admin'], $assets['scripts']['cyberedge-cache-admin'] ), 'Dashboard assets load only through registered WordPress assets' );
    check( $assets['localized']['cyberedge-cache-admin']['value']['cacheHeader'] === 'X-CyberEdge-Cache', 'Live check uses the branded customer header' );
    $bar = new class { public $nodes = array(); public function add_node( $node ) { $this->nodes[] = $node; } };
    do_action( 'admin_bar_menu', $bar );
    check( $bar->nodes[0]['href'] === 'https://example.test/wp-admin/tools.php?page=cyberedge-cache', 'Admin bar opens the CyberEdge dashboard' );
    ob_start(); $GLOBALS['cyberedge_cache']->status_page(); $dashboard = ob_get_clean();
    check( strpos( $dashboard, 'Live cache status' ) !== false && strpos( $dashboard, 'View bandwidth usage' ) !== false &&
        strpos( $dashboard, 'Purge CyberEdge cache worldwide' ) !== false, 'Dashboard exposes cache, bandwidth, and purge journeys' );
    check( strpos( $dashboard, CYBEREDGE_PURGE_SECRET ) === false && strpos( $dashboard, CYBEREDGE_CONTROLLER_URL ) === false,
        'Dashboard never renders controller credentials' );
    $health = $GLOBALS['cyberedge_cache']->site_health();
    check( $health['status'] === 'good' && $health['test'] === 'cyberedge_cache', 'Healthy local delivery is reported to Site Health' );
    $rest_response = new class {
        public $headers = array( 'Cache-Control' => 'public,max-age=300' );
        public function header( $name, $value ) { $this->headers[$name] = $value; }
    };
    $rest_vetoes = 0;
    add_action( 'litespeed_control_set_nocache', function () use ( &$rest_vetoes ) { $rest_vetoes++; } );
    check( apply_filters( 'rest_post_dispatch', $rest_response, null, null ) === $rest_response, 'REST keeps the same response object' );
    check( $rest_response->headers['Cache-Control'] === 'private,no-cache,no-store', 'REST vetoes an explicit public cache policy outside template_redirect' );
    check( $rest_response->headers['X-LiteSpeed-Cache-Control'] === 'private,no-cache,no-store', 'REST sets native cache veto' );
    check( $rest_vetoes === 1, 'REST notifies the active LiteSpeed plugin' );

    $before = $outbox->status()['pending'];
    do_action( 'transition_post_status', 'publish', 'future', (object) array( 'ID' => 1, 'post_type' => 'post' ) );
    do_action( 'transition_post_status', 'publish', 'publish', (object) array( 'ID' => 1, 'post_type' => 'post' ) );
    do_action( 'transition_post_status', 'draft', 'draft', (object) array( 'ID' => 2, 'post_type' => 'post' ) );
    check( $outbox->status()['pending'] === $before + 2, 'Scheduled publication and edits purge; drafts do not' );
    foreach ( array( 'wp_update_nav_menu', 'edited_term', 'upgrader_process_complete', 'automatic_updates_complete',
        'woocommerce_update_product', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock_status' ) as $hook ) {
        $before = $outbox->status()['pending']; do_action( $hook, 'ignored' );
        check( $outbox->status()['pending'] === $before + 1, 'Durable event for ' . $hook );
    }
    $approved = (object) array( 'comment_approved' => '1' );
    $pending = (object) array( 'comment_approved' => '0' );
    $before = $outbox->status()['pending'];
    do_action( 'wp_insert_comment', 10, $pending );
    do_action( 'wp_insert_comment', 11, $approved );
    $comments[12] = $pending; do_action( 'edit_comment', 12 );
    $comments[13] = $approved; do_action( 'edit_comment', 13 );
    do_action( 'transition_comment_status', 'spam', 'unapproved', $pending );
    do_action( 'transition_comment_status', 'approved', 'unapproved', $approved );
    do_action( 'transition_comment_status', 'spam', 'approved', $approved );
    do_action( 'deleted_comment', 14, $pending );
    do_action( 'deleted_comment', 15, $approved );
    check( $outbox->status()['pending'] === $before + 5, 'Only public comment changes enqueue purges' );
    foreach ( array( 'litespeed_purge', 'litespeed_purge_url', 'litespeed_purged_all_lscache',
        'litespeed_purged_single', 'litespeed_purged_link' ) as $hook ) {
        check( ! isset( $hooks[$hook] ), 'No duplicate listener on overlapping LSCWP hook ' . $hook );
    }
    $before = $outbox->status()['pending'];
    check( apply_filters( 'litespeed_purge_tags', array( 'original-tag' ), false ) === array( 'original-tag' ), 'LSCWP tag filter is unchanged' );
    check( apply_filters( 'litespeed_purge_tags', array( 'second-tag' ), false ) === array( 'second-tag' ), 'Repeated LSCWP tag filter is unchanged' );
    apply_filters( 'litespeed_purge_tags', array( 'private' ), true );
    apply_filters( 'litespeed_purge_tags', array(), false );
    check( $outbox->status()['pending'] === $before + 1, 'One request queues one global fallback for public LSCWP tags' );
    call_user_func( WP_CLI::$commands['cyberedge purge'] );
    check( $outbox->status()['pending'] === $before + 2, 'WP-CLI persists without network' );
    check( count( $http_calls ) === 0, 'No mutation or toolbar event calls HTTP' );

    // Reopen a separate connection to prove events persist outside the plugin/request instance.
    $db2 = new TestWpdb( $test_dir . '/outbox.sqlite' ); $store2 = new CyberEdge_Outbox( $db2 );
    check( $store2->status()['pending'] === $outbox->status()['pending'], 'Pending events survive connection restart' );
    $config = CyberEdge_Config::load(); $now = time() + 120;
    $claim = $outbox->claim( $config, $now );
    $next = $store2->claim( $config, $now );
    check( $claim && $next && $claim->event_id !== $next->event_id, 'Concurrent workers claim distinct rows' );
    $wpdb->query( 'DELETE FROM wp_cyberedge_purge_outbox' );
    $outbox->enqueue( $config, 'lease', $now );
    check( $outbox->claim( $config, $now ) === null, 'Short publication delay prevents immediate delivery' );
    $claim = $outbox->claim( $config, $now + 5 );
    check( $store2->claim( $config, $now + 5 ) === null, 'Active lease blocks another worker' );
    $reclaimed = $store2->claim( $config, $now + 96 );
    check( $reclaimed->event_id === $claim->event_id && $reclaimed->lease_token !== $claim->lease_token, 'Expired lease recovers after worker crash' );
    check( ! $outbox->acknowledge( $claim ) && $outbox->status()['pending'] === 1, 'Stale worker cannot delete a reclaimed event' );
    check( $store2->acknowledge( $reclaimed ), 'Current worker can acknowledge' );

    $clock = function () use ( &$now ) { return $now; };
    $mode = 'failure';
    $transport = function( $url, $args ) use ( &$mode, &$http_calls, $config ) {
        $http_calls[] = array( $url, $args );
        check( $url === 'https://controller.example.test/v1/sites/site-test/purges', 'Correct scoped endpoint' );
        check( $args['redirection'] === 0 && $args['sslverify'] === true && $args['timeout'] === 5, 'Strict HTTPS and no redirects' );
        $body = json_decode( $args['body'], true );
        check( array_keys( $body ) === array( 'event_id', 'scope' ) && $body['scope'] === 'site', 'Exact minimal controller contract' );
        $signature = hash_hmac( 'sha256', $args['headers']['X-CyberEdge-Timestamp'] . "\n" . $args['body'], CYBEREDGE_PURGE_SECRET );
        check( hash_equals( $signature, $args['headers']['X-CyberEdge-Signature'] ), 'Signature covers exact transmitted bytes' );
        check( strpos( json_encode( $args ), CYBEREDGE_PURGE_SECRET ) === false, 'Secret never transmitted' );
        if ( $mode === 'failure' ) { return new WP_Error(); }
        $ack = array( 'event_id' => $body['event_id'], 'site_id' => 'site-test', 'revision' => 1, 'state' => 'pending' );
        if ( $mode === 'wrong_site' ) { $ack['site_id'] = 'another-site'; }
        return array( 'response' => array( 'code' => $mode === 'redirect' ? 302 : 202 ), 'body' => json_encode( $ack ) );
    };
    $worker = new CyberEdge_Cache( $outbox, $config, $transport, $clock );
    $id = $worker->enqueue( 'retry' ); $now += 5;
    check( $worker->deliver() === array( 'accepted' => 0, 'failed' => 1 ), 'Transport failure stays queued' );
    check( $outbox->status()['pending'] === 1, 'Failure does not drop the durable event' );
    $first_call = $http_calls[count( $http_calls ) - 1];
    $now += 1; check( $worker->deliver()['failed'] === 0, 'Retry respects backoff' );
    $now += 20; $mode = 'wrong_site'; check( $worker->deliver()['failed'] === 1, 'Wrong-tenant acknowledgement rejected' );
    $now += 40; $mode = 'redirect'; check( $worker->deliver()['failed'] === 1, 'Redirect is retained as failure' );
    $now += 80; $mode = 'success'; check( $worker->deliver()['accepted'] === 1, 'Validated durable controller receipt removes row' );
    $last_call = $http_calls[count( $http_calls ) - 1];
    check( $first_call[1]['body'] === $last_call[1]['body'], 'Retries preserve body and event ID' );
    check( $first_call[1]['headers']['X-CyberEdge-Timestamp'] !== $last_call[1]['headers']['X-CyberEdge-Timestamp'], 'Retries refresh authentication timestamp' );
    check( $outbox->status()['pending'] === 0, 'Accepted event is removed' );

    $outbox->enqueue( $config, 'tenant-boundary', $now ); $now += 5;
    $other = new CyberEdge_Config( 'another-site', 'https://controller.example.test', str_repeat( 'x', 32 ) );
    check( $outbox->claim( $other, $now ) === null, 'Reconfigured tenant cannot claim old site events' );
    $moved = new CyberEdge_Config( 'site-test', 'https://other.example.test', str_repeat( 'x', 32 ) );
    check( $outbox->claim( $moved, $now ) === null, 'Reconfigured controller cannot receive old events' );
    $wpdb->fail_insert = true;
    check( $worker->enqueue( 'db_failure' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Database failure raises persistent alarm' );
    $wpdb->fail_insert = false;
    foreach ( array( 'http://controller.example.test', 'https://user:pass@controller.example.test', 'https://controller.example.test/path', 'https://controller.example.test?token=secret' ) as $url ) {
        $rejected = false;
        try { new CyberEdge_Config( 'site-test', $url, str_repeat( 'x', 32 ) ); } catch ( InvalidArgumentException $e ) { $rejected = true; }
        check( $rejected, 'Reject insecure or ambiguous controller URL' );
    }
    $rejected = false;
    try { new CyberEdge_Config( str_repeat( 'x', 101 ), CYBEREDGE_CONTROLLER_URL, CYBEREDGE_PURGE_SECRET ); }
    catch ( InvalidArgumentException $e ) { $rejected = true; }
    check( $rejected, 'Site ID length matches the controller contract' );
    $_SERVER = array( 'REQUEST_METHOD' => 'GET' ); $_COOKIE = array();
    check( $worker->cache_policy() === 'public,max-age=300', 'Anonymous fallback uses bounded public TTL' );
    http_response_code( 403 ); check( strpos( $worker->cache_policy(), 'no-store' ) !== false, 'Non-success status bypass' ); http_response_code( 200 );
    foreach ( array( 'logged_in', 'admin', 'preview', 'search', '404', 'feed', 'password', 'ajax', 'cart', 'checkout', 'account' ) as $flag ) {
        $flags[$flag] = true;
        check( strpos( $worker->cache_policy(), 'no-store' ) !== false, 'Private context bypass: ' . $flag );
        $flags[$flag] = false;
    }
    foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'HTTP_COOKIE', 'QUERY_STRING' ) as $header ) {
        $_SERVER[$header] = 'test'; check( strpos( $worker->cache_policy(), 'no-store' ) !== false, 'Bypass request input: ' . $header ); unset( $_SERVER[$header] );
    }
    $_COOKIE['woocommerce_items_in_cart'] = '1'; check( strpos( $worker->cache_policy(), 'no-store' ) !== false, 'WooCommerce cart cookie bypass' ); $_COOKIE = array();
    $_SERVER['REQUEST_METHOD'] = 'POST'; check( strpos( $worker->cache_policy(), 'no-store' ) !== false, 'POST bypass' ); $_SERVER['REQUEST_METHOD'] = 'GET';
    define( 'LSCWP_V', 'test' );
    check( $worker->cache_policy() === 'public,max-age=300', 'Installed LSCWP without active page cache does not disable fallback' );
    define( 'LITESPEED_ON', true );
    check( $worker->cache_policy() === null, 'Active LSCWP page cache determines public cacheability' );
    define( 'LITESPEED_DISABLE_ALL', true );
    check( $worker->cache_policy() === 'public,max-age=300', 'Disabled LSCWP page cache does not disable fallback' );
    $flags['checkout'] = true;
    check( strpos( $worker->cache_policy(), 'no-store' ) !== false, 'Woo private veto persists with LSCWP present' );
    echo json_encode( array( 'passed' => $test_count, 'database' => 'SQLite; production SQL DML unchanged', 'network_requests' => 0 ) ) . "\n";
} finally {
    $wpdb = null; $db2 = null; $outbox = null; $store2 = null; $worker = null;
    foreach ( array( '/outbox.sqlite', '/wp-admin/includes/upgrade.php' ) as $path ) { @unlink( $test_dir . $path ); }
    @rmdir( $test_dir . '/wp-admin/includes' ); @rmdir( $test_dir . '/wp-admin' ); @rmdir( $test_dir );
}
