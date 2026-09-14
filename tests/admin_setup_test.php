<?php
/** Local setup UI states: no network, database mutation, or real credentials. */
error_reporting( E_ALL );
define( 'ABSPATH', sys_get_temp_dir() . '/' );
$options = array(); $allowed = true; $scheduled = 123; $checks = 0;
$history_write_allowed = true; $history_read_allowed = true;
function add_action( ...$args ) {} function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {} function register_deactivation_hook( ...$args ) {}
function is_multisite() { return false; } function get_current_blog_id() { return 1; }
function get_option( $name ) {
    if ( $name === 'cyberedge_connection_history_v1' && ! $GLOBALS['history_read_allowed'] ) { throw new RuntimeException( 'private history read error' ); }
    return $GLOBALS['options'][$name] ?? false;
}
function update_option( $name, $value, $autoload = null ) {
    if ( $name === 'cyberedge_connection_history_v1' && ! $GLOBALS['history_write_allowed'] ) { return false; }
    $GLOBALS['options'][$name] = $value; return true;
}
function wp_salt( $scheme ) { return 'isolated-setup-salt'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_schedule_single_event( ...$args ) { return true; }
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled']; }
function admin_url( $path = '' ) { return 'https://fixture.example/wp-admin/' . $path; }
function plugins_url( $path, $file ) { return 'https://fixture.example/wp-content/plugins/cyberedge-cache/' . $path; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return esc_html( $value ); }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="fixture">'; }
function submit_button( $label, $type = 'primary', $name = 'submit', $wrap = true, $attrs = null ) {
    echo '<input type="submit" value="' . esc_html( $label ) . '"' . ( ! empty( $attrs['disabled'] ) ? ' disabled' : '' ) . '>';
}
function wp_die( $message ) { throw new RuntimeException( $message ); }
function check_setup( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } $GLOBALS['checks']++; }
function contains_setup( $html, $text ) { return strpos( $html, $text ) !== false; }
function capture_setup( $object, $method ) { ob_start(); try { $object->$method(); return ob_get_contents(); } finally { ob_end_clean(); } }
class SetupOutbox {
    public $status = array( 'pending' => 0, 'oldest' => null );
    public $fail = false;
    public $fail_enqueue = false;
    public function status() { if ( $this->fail ) { throw new RuntimeException( 'private database detail' ); } return $this->status; }
    public function enqueue( $config, $reason, $now ) {
        if ( $this->fail_enqueue ) { throw new RuntimeException( 'private insert detail' ); }
        return 'fixture-event';
    }
}
$wpdb = (object) array( 'prefix' => 'wp_' );
require __DIR__ . '/../cyberedge-cache/cyberedge-cache.php';
$outbox = new SetupOutbox(); $new = new CyberEdge_Cache( $outbox );
check_setup( $new->enqueue( 'preconnection_site_change' ) === false, 'No purge is promised before connection' );
check_setup( ! get_option( 'cyberedge_purge_enqueue_failed' ), 'Normal preconnection mutations are not failed purge writes' );
check_setup( $new->purge_tags( array( 'public-tag' ), false ) === array( 'public-tag' ), 'Unconnected LiteSpeed purge tags remain untouched' );
check_setup( ! get_option( 'cyberedge_purge_enqueue_failed' ), 'Unconnected origin purge does not create a delivery alarm' );
$_GET = array( 'cyberedge_connected' => 'yes', 'cyberedge_purge' => 'queued' );
$html = capture_setup( $new, 'status_page' );
check_setup( contains_setup( $html, '<strong>Not connected</strong>' ), 'Fresh installation is not an error' );
check_setup( ! contains_setup( $html, 'Needs attention' ), 'Fresh installation does not report a broken connection' );
check_setup( contains_setup( $html, 'Waiting for connection' ) && ! contains_setup( $html, '<strong>Enabled</strong>' ), 'Unconnected policy is inactive' );
check_setup( contains_setup( $html, 'id="cyberedge-check-cache" disabled' ), 'Unconnected cache check is disabled' );
check_setup( contains_setup( $html, 'value="Purge CyberEdge cache worldwide" disabled' ), 'Unconnected purge control is disabled' );
check_setup( strpos( $html, '<h2>Connect to CyberEdge</h2>' ) < strpos( $html, 'class="cyberedge-grid"' ), 'Connect action precedes diagnostics' );
check_setup( ! contains_setup( $html, 'Connection complete.' ), 'Query string cannot claim an unconnected site is connected' );
check_setup( ! contains_setup( $html, 'Connection saved.' ) && ! contains_setup( $html, 'Local delivery checks passed.' ), 'Fresh setup cannot claim saved credentials or delivery success' );
check_setup( ! contains_setup( $html, 'A worldwide cache purge is queued.' ), 'A success query flag cannot claim a purge for an unconnected site' );
unset( $_GET['cyberedge_purge'] );
check_setup( substr_count( $html, '<h1>' ) === 1 && substr_count( $html, 'class="wp-header-end"' ) === 1, 'One page heading and one standard notice anchor' );
check_setup( strpos( $html, 'class="wp-header-end"' ) > strpos( $html, '</header>' ), 'WordPress notice anchor is outside the hero' );
check_setup( substr_count( $html, 'name="action" value="cyberedge_connect_start"' ) === 1, 'Exactly one normal connection form' );
$notice = capture_setup( $new, 'admin_notice' );
check_setup( contains_setup( $notice, 'notice-info' ) && ! contains_setup( $notice, 'notice-error' ), 'Fresh notice is informational' );
check_setup( ! contains_setup( $notice, 'stale' ), 'Fresh installation does not claim undelivered cached content' );
check_setup( $new->site_health()['status'] === 'recommended', 'Fresh site health requests setup, not critical recovery' );
$scheduled = false;
check_setup( $new->site_health()['status'] === 'recommended', 'No connected delivery service is presumed before setup' );
$scheduled = 123;
$options['cyberedge_purge_enqueue_failed'] = 1;
check_setup( $new->enqueue( 'still_unconnected' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Unconnected hooks never erase a retained alarm' );
check_setup( $new->site_health()['status'] === 'critical', 'Persistent enqueue failure is never hidden by new-setup copy' );
unset( $options['cyberedge_purge_enqueue_failed'] );
$outbox->status = array( 'pending' => 1, 'oldest' => time() );
check_setup( $new->site_health()['status'] === 'critical', 'Stranded delivery after lost configuration remains critical' );
check_setup( $new->enqueue( 'stranded_before_connect' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Stranded queue is never ignored as a first installation' );
unset( $options['cyberedge_purge_enqueue_failed'] );
$outbox->status = array( 'pending' => 0, 'oldest' => null );
foreach ( array(
    array( 'pending' => 1, 'oldest' => null ), array( 'pending' => 0, 'oldest' => time() ),
    array( 'pending' => 1, 'oldest' => time() + 3600 ), array( 'pending' => 1, 'oldest' => 0 ),
    array( 'pending' => -1, 'oldest' => null ), array( 'pending' => '0', 'oldest' => null ),
) as $invalid_status ) {
    $outbox->status = $invalid_status;
    check_setup( $new->site_health()['status'] === 'critical', 'Inconsistent queue count/oldest metadata is never healthy' );
    check_setup( contains_setup( capture_setup( $new, 'status_page' ), '<strong>Unavailable</strong>' ), 'Inconsistent queue is not displayed as an empty successful queue' );
    check_setup( $new->enqueue( 'inconsistent_queue' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Inconsistent queue cannot use first-install skip' );
    unset( $options['cyberedge_purge_enqueue_failed'] );
}
$outbox->status = array( 'pending' => 0, 'oldest' => null );
$outbox->fail = true;
$html = capture_setup( $new, 'status_page' );
check_setup( contains_setup( $html, '<strong>Unavailable</strong>' ) && ! contains_setup( $html, 'No events are waiting.' ), 'Unreadable outbox is not reported empty' );
check_setup( ! contains_setup( $html, 'private database detail' ), 'Queue exceptions do not leak details' );
check_setup( $new->site_health()['status'] === 'critical', 'Unreadable outbox remains an error' );
check_setup( $new->enqueue( 'unreadable_before_connect' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Unreadable queue cannot use the first-install skip' );
unset( $options['cyberedge_purge_enqueue_failed'] );
$outbox->fail = false;
$options[CyberEdge_Config::OPTION] = array( 'version' => 999 );
check_setup( $new->enqueue( 'invalid_saved_connection' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Invalid configured source still raises a durable alarm' );
unset( $options['cyberedge_purge_enqueue_failed'] );
check_setup( contains_setup( capture_setup( $new, 'status_page' ), '<strong>Needs attention</strong>' ), 'Corrupt saved connection remains an error' );
check_setup( contains_setup( capture_setup( $new, 'admin_notice' ), 'notice-error' ), 'Saved-connection error remains visible' );
unset( $options[CyberEdge_Config::OPTION] );
$config = new CyberEdge_Config( 'fixture-site', 'https://controller.example', str_repeat( 'secret-marker-', 4 ) );
$connected = new CyberEdge_Cache( $outbox, $config );
$html = capture_setup( $connected, 'status_page' );
check_setup( contains_setup( $html, '<strong>Connected</strong>' ), 'Validated private connection reported saved' );
check_setup( contains_setup( $html, '<strong>Enabled</strong>' ) && contains_setup( $html, 'Disable CyberEdge page caching' ), 'Connected local policy is enabled and controllable' );
check_setup( ! contains_setup( $html, 'id="cyberedge-check-cache" disabled' ), 'Connected status control remains usable' );
check_setup( ! contains_setup( $html, 'value="Purge CyberEdge cache worldwide" disabled' ), 'Connected purge remains usable' );
check_setup( contains_setup( $html, 'Connection saved.' ) && contains_setup( $html, 'Local delivery checks passed.' ), 'Success requires actual valid connection and local delivery readiness' );
check_setup( ! contains_setup( $html, 'Automatic purge delivery is ready.' ) && contains_setup( $html, 'does not confirm worldwide purge completion' ), 'Local readiness does not invent global completion' );
check_setup( ! contains_setup( $html, 'secret-marker-' ) && ! contains_setup( $html, 'controller.example' ), 'Private connection never rendered' );
check_setup( $connected->site_health()['status'] === 'good', 'Healthy local delivery checks retained' );
$outbox->fail = true;
$html = capture_setup( $connected, 'status_page' );
check_setup( contains_setup( $html, '<strong>Connected</strong>' ) && contains_setup( $html, '<strong>Unavailable</strong>' ), 'Database failure does not pretend connection was lost' );
check_setup( $connected->site_health()['status'] === 'critical', 'Connected outbox failure is critical' );
check_setup( ! contains_setup( $html, 'Local delivery checks passed.' ) && contains_setup( $html, 'Purge delivery needs attention.' ), 'Unreadable queue defeats a success query flag' );
$outbox->fail = false; $scheduled = false;
check_setup( $connected->site_health()['status'] === 'critical', 'Missing connected schedule remains critical' );
check_setup( ! contains_setup( capture_setup( $connected, 'status_page' ), 'Local delivery checks passed.' ), 'Missing worker cannot claim delivery success' );
$scheduled = 123; $outbox->status = array( 'pending' => 1, 'oldest' => time() - 301 );
check_setup( $connected->site_health()['status'] === 'critical', 'Stale purge remains critical' );
$outbox->status = array( 'pending' => 0, 'oldest' => null );
$outbox->fail_enqueue = true;
check_setup( $connected->enqueue( 'configured_insert_failure' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'A genuinely configured failed write still raises the persistent alarm' );
$outbox->fail_enqueue = false;
check_setup( ! contains_setup( capture_setup( $connected, 'status_page' ), 'Local delivery checks passed.' ), 'Retained enqueue alarm defeats a success query flag' );
$html = capture_setup( $connected, 'status_page' );
check_setup( contains_setup( $html, 'A previous purge write failed.' ) && contains_setup( $html, 'Some content changes may have missed cache invalidation.' ), 'Retained alarm explains the actual risk even with an empty queue' );
check_setup( contains_setup( $html, 'A successful retry does not automatically clear it.' ) && contains_setup( $html, 'Contact your server administrator or CyberPanel support' ), 'Retained alarm explains review and support instead of implying retry clears it' );
unset( $options['cyberedge_purge_enqueue_failed'] );
CyberEdge_Config::store( 'previously-paired', 'https://controller.example', str_repeat( 'fixture-secret-', 4 ) );
check_setup( $new->site_health()['status'] === 'good' && get_option( CyberEdge_Config::HISTORY_OPTION ) === 'v1', 'Successful saved connection retains non-secret history' );
unset( $options[CyberEdge_Config::OPTION] );
check_setup( $new->enqueue( 'mutation_after_lost_config' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Lost credentials with an empty queue do not suppress the alarm' );
unset( $options['cyberedge_purge_enqueue_failed'] );
check_setup( $new->site_health()['status'] === 'critical', 'History keeps lost configuration critical even without a prior alarm' );
check_setup( ! contains_setup( capture_setup( $new, 'status_page' ), 'Connection saved.' ), 'A query flag cannot restore lost credentials' );
CyberEdge_Config::store( 'legacy-paired', 'https://controller.example', str_repeat( 'fixture-secret-', 4 ) );
unset( $options[CyberEdge_Config::HISTORY_OPTION] );
check_setup( CyberEdge_Config::load()->site_id === 'legacy-paired' && get_option( CyberEdge_Config::HISTORY_OPTION ) === 'v1', 'Legacy valid saved credentials backfill history' );
unset( $options[CyberEdge_Config::HISTORY_OPTION] ); $history_write_allowed = false;
check_setup( $new->enqueue( 'failed_history_backfill' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Failed history persistence fails closed' );
check_setup( $new->site_health()['status'] === 'critical', 'Marker write failure cannot claim a connected healthy state' );
$history_write_allowed = true;
check_setup( $new->enqueue( 'retry_after_history_repair' ) !== false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Repairing history never silently clears a real alarm' );
unset( $options['cyberedge_purge_enqueue_failed'], $options[CyberEdge_Config::OPTION] );
$options[CyberEdge_Config::HISTORY_OPTION] = array( 'invalid' => true );
check_setup( $new->enqueue( 'malformed_history' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Malformed history is not fresh setup' );
check_setup( $new->site_health()['status'] === 'critical', 'Malformed history is visible as a local failure' );
unset( $options['cyberedge_purge_enqueue_failed'], $options[CyberEdge_Config::HISTORY_OPTION] );
$history_read_allowed = false;
check_setup( $new->enqueue( 'history_read_error' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Unreadable history cannot silently skip a purge' );
check_setup( $new->site_health()['status'] === 'critical', 'Unreadable history fails closed without exposing private errors' );
$history_read_allowed = true;
unset( $options['cyberedge_purge_enqueue_failed'] );
$_GET['cyberedge_connected'] = 'partial';
check_setup( ! contains_setup( capture_setup( $new, 'status_page' ), 'Connection saved.' ), 'Partial callback flag alone cannot invent a saved connection' );
$allowed = false;
check_setup( capture_setup( $new, 'admin_notice' ) === '', 'Non-admin receives no admin notice' );
$rejected = false; try { capture_setup( $new, 'status_page' ); } catch ( RuntimeException $error ) { $rejected = true; }
check_setup( $rejected, 'Non-admin cannot read plugin dashboard' );
$allowed = true; define( 'CYBEREDGE_SITE_ID', 'incomplete-server-config' );
$html = capture_setup( $new, 'status_page' );
check_setup( contains_setup( $html, '<strong>Needs attention</strong>' ), 'Partial server constants remain invalid' );
check_setup( ! contains_setup( $html, 'name="action" value="cyberedge_connect_start"' ), 'Browser cannot replace server-managed connection' );
check_setup( $new->enqueue( 'incomplete_managed_config' ) === false && get_option( 'cyberedge_purge_enqueue_failed' ) === 1, 'Partial constants cannot use the fresh-install skip' );
unset( $options['cyberedge_purge_enqueue_failed'] );
define( 'CYBEREDGE_CONTROLLER_URL', 'https://managed-controller.example' );
define( 'CYBEREDGE_PURGE_SECRET', str_repeat( 'managed-fixture-', 4 ) );
check_setup( CyberEdge_Config::load()->site_id === 'incomplete-server-config' && get_option( CyberEdge_Config::HISTORY_OPTION ) === 'v1', 'Legacy valid managed configuration backfills history too' );
echo json_encode( array( 'passed' => $checks, 'network_requests' => 0 ) ) . "\n";
