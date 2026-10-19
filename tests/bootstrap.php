<?php
/**
 * LibreForms unit tests reuse the LibreForge bootstrap (core stubs + in-memory $wpdb)
 * and add the few WordPress functions forms need.
 */

require dirname( __DIR__, 2 ) . '/libreforge/tests/bootstrap.php';

LibreBlocks\Core\Autoloader::register( 'LibreBlocks\\Forms\\', dirname( __DIR__ ) . '/src/' );

define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'MB_IN_BYTES', 1048576 );

/** What the form stubs recorded. */
final class FormsStub {
	public static array $transients = [];
	public static array $mails      = [];
	public static array $posts      = [];
	public static array $post_meta  = [];
	public static array $webhooks   = [];
	public static array $entries    = [];
	public static bool $logged_in   = false;

	public static function reset(): void {
		self::$transients = [];
		self::$mails      = [];
		self::$posts      = [];
		self::$post_meta  = [];
		self::$webhooks   = [];
		self::$entries    = [];
		self::$logged_in  = false;
	}
}

/** $wpdb for forms: entries go to FormsStub, everything else to the LibreForge fake. */
final class FormsWpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;

	public function __construct( private FakeWpdb $inner ) {}

	public function insert( $table, $data ) {
		if ( str_ends_with( $table, 'lbforms_entries' ) ) {
			FormsStub::$entries[] = $data;
			$this->insert_id      = count( FormsStub::$entries );
			return 1;
		}

		$result          = $this->inner->insert( $table, $data );
		$this->insert_id = $this->inner->insert_id;

		return $result;
	}

	public function __call( $name, $args ) {
		return $this->inner->$name( ...$args );
	}
}

function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function get_transient( $key ) { return FormsStub::$transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { FormsStub::$transients[ $key ] = $value; return true; }
function delete_transient( $key ) { unset( FormsStub::$transients[ $key ] ); return true; }
function is_user_logged_in() { return FormsStub::$logged_in; }
function wp_mail( $to, $subject, $message, $headers = [] ) { FormsStub::$mails[] = compact( 'to', 'subject', 'message', 'headers' ); return true; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_insert_post( $post, $wp_error = false ) { FormsStub::$posts[] = $post; return count( FormsStub::$posts ) + 100; }
function update_post_meta( $id, $key, $value ) { FormsStub::$post_meta[ $id ][ $key ] = $value; return true; }
function wp_update_post( $post ) { return $post['ID']; }
function set_post_thumbnail( $post, $id ) { FormsStub::$post_meta[ $post ]['_thumbnail_id'] = $id; return true; }
function get_permalink( $post = 0 ) { return 'https://example.test/?p=' . ( is_object( $post ) ? $post->ID : (int) $post ); }
function wp_safe_remote_post( $url, $args ) { FormsStub::$webhooks[] = [ 'url' => $url ] + $args; return []; }
function wp_http_validate_url( $url ) { return preg_match( '#^https?://[^/]+#', (string) $url ) ? $url : false; }
function wp_get_attachment_url( $id ) { return 'https://example.test/file-' . $id; }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '', public $data = '' ) {}
		public function get_error_message() { return $this->message; }
	}
}
function get_post( $post = null ) { return is_object( $post ) ? $post : ( WPStub::$registered['posts'][ (int) $post ] ?? null ); }
function wp_validate_redirect( $location, $fallback = '' ) { return 0 === strpos( (string) $location, 'https://example.test' ) ? $location : $fallback; }
function get_posts( $args = [] ) {
	return array_values( array_filter( WPStub::$registered['posts'] ?? [], fn( $p ) => in_array( $p->post_type, (array) ( $args['post_type'] ?? [ 'post' ] ), true ) && 'publish' === $p->post_status ) );
}
function selected( $a, $b, $echo = true ) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
