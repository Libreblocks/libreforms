<?php
/**
 * LibreForms entry point.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Core\Plugin as Core;
use LibreBlocks\Forge\Plugin as Forge;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const SCHEMA_VERSION = '1';

	private static ?Plugin $instance = null;

	private ?Core $core = null;

	private Forms $forms;

	private Entries $entries;

	private View $view;

	private Submission $submission;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot( Core $core, Forge $forge ): void {
		if ( $this->core ) {
			return;
		}

		$this->core = $core;

		$forge->register_kind( new FormKind( $forge->field_list(), fn() => $this->submission->types() ) );
		$core->schema()->register_table( Entries::TABLE, Entries::columns(), self::SCHEMA_VERSION, 'libreforms' );

		if ( $core->schema()->pending() ) {
			$core->schema()->install();
		}

		$this->forms      = new Forms( $forge->repository(), $core->fields() );
		$this->entries    = new Entries();
		$this->view       = new View( $this->forms );
		$this->submission = new Submission( $this->forms, $this->entries, $core->tokens() );

		$this->submission->hook();
		$core->rest()->add( SubmitController::class );
		$core->rest()->add( EntriesController::class );

		add_action( 'admin_post_lbforms_submit', [ $this, 'submit_without_js' ] );
		add_action( 'admin_post_nopriv_lbforms_submit', [ $this, 'submit_without_js' ] );

		( new Blocks( $this->view ) )->hook();
		add_shortcode( 'libreforms', fn( $atts ) => $this->view->form( [
			'form_id' => absint( ( (array) $atts )['id'] ?? 0 ),
			'class'   => sanitize_html_class( ( (array) $atts )['class'] ?? '' ),
		] ) );
		add_action( 'elementor/widgets/register', fn( $manager ) => $manager->register( new Elementor\FormWidget() ) );

		if ( is_admin() ) {
			( new Admin\Screens( $core, $this->forms, $this->entries ) )->hook();
		}

		add_action( 'init', fn() => load_plugin_textdomain( 'libreforms', false, dirname( plugin_basename( LIBREFORMS_FILE ) ) . '/languages' ), 0 );

		/**
		 * Other plugins add their actions here: $forms->register_action( new MyAction() ).
		 *
		 * @param Plugin $forms
		 */
		do_action( 'libreforms/register', $this );

		/**
		 * Fires when LibreForms is ready.
		 *
		 * @param Plugin $forms
		 */
		do_action( 'libreforms/loaded', $this );
	}

	public function activate(): void {
		$this->core?->schema()->install();
	}

	public function register_action( ActionType $type ): void {
		$this->submission->register( $type );
	}

	public function forms(): Forms {
		return $this->forms;
	}

	public function entries(): Entries {
		return $this->entries;
	}

	public function view(): View {
		return $this->view;
	}

	public function submission(): Submission {
		return $this->submission;
	}

	/**
	 * Fallback when JavaScript is off: process, keep the result for a minute and
	 * send the visitor back to the page, which shows the message.
	 */
	public function submit_without_js(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; logged-in users are checked below.
		$input   = wp_unslash( $_POST );
		$form    = $this->forms->find( absint( $input['lbforms_id'] ?? 0 ) );
		$back    = wp_validate_redirect( esc_url_raw( (string) ( $input['_lbf_ref'] ?? '' ) ), home_url( '/' ) );
		// phpcs:enable

		if ( ! $form ) {
			wp_safe_redirect( $back );
			exit;
		}

		if ( is_user_logged_in() && ! wp_verify_nonce( (string) ( $input[ View::NONCE ] ?? '' ), View::NONCE ) ) {
			$result = [ 'success' => false, 'message' => __( 'Your session expired. Reload the page and try again.', 'libreforms' ), 'errors' => [], 'redirect' => '', 'values' => [] ];
		} else {
			$result = $this->submission->process( $form, (array) $input, $_FILES ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- uploads are checked in Submission.
		}

		if ( $result['success'] && '' !== $result['redirect'] ) {
			wp_redirect( $result['redirect'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the admin chose this address.
			exit;
		}

		$key = strtolower( wp_generate_password( 12, false ) );
		set_transient( 'lbforms_result_' . $key, [ 'form_id' => $form->id ] + $result, MINUTE_IN_SECONDS * 5 );

		wp_safe_redirect( add_query_arg( 'lbforms_result', $key, $back ) . '#lbf-' . $form->id );
		exit;
	}

	private function __construct() {}
}
