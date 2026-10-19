<?php
/**
 * The LibreForms menu: Forms (list + editor) and Entries, plus the CSV export.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms\Admin;

use LibreBlocks\Core\Admin\Icons;
use LibreBlocks\Core\Plugin as Core;
use LibreBlocks\Core\Rest\Controller;
use LibreBlocks\Forge\Model\Definition;
use LibreBlocks\Forms\Entries;
use LibreBlocks\Forms\EntriesController;
use LibreBlocks\Forms\FormKind;
use LibreBlocks\Forms\Forms;

defined( 'ABSPATH' ) || exit;

final class Screens {

	public const MENU    = 'libreforms';
	public const ENTRIES = 'libreforms-entries';
	public const HANDLE  = 'libreforms-admin';

	public function __construct( private Core $core, private Forms $forms, private Entries $entries ) {}

	public function hook(): void {
		add_action( 'init', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_lbforms_export', [ $this, 'export' ] );
		add_filter( 'libreforge/definition/summary', [ $this, 'summary' ], 10, 2 );
	}

	public static function entries_url( int $form_id = 0 ): string {
		return admin_url( 'admin.php?page=' . self::ENTRIES . ( $form_id ? '&form=' . $form_id : '' ) );
	}

	public function add_menu(): void {
		$fresh = array_sum( array_column( $this->entries->counts(), 'new' ) );
		$label = __( 'Entries', 'libreforms' );

		if ( $fresh > 0 ) {
			$label .= sprintf( ' <span class="awaiting-mod">%d</span>', $fresh );
		}

		$this->core->get( 'admin' )->add_menu( [
			'slug'  => self::MENU,
			'title' => 'LibreForms',
			'icon'  => Icons::data_uri( 'libreforms' ),
			'order' => 30,
			'pages' => [
				[
					'slug'   => self::MENU,
					'title'  => __( 'Forms', 'libreforms' ),
					'render' => fn() => print( '<div id="libreforms-app" data-section="forms"></div>' ),
				],
				[
					'slug'   => self::ENTRIES,
					'title'  => $label,
					'render' => fn() => print( '<div id="libreforms-app" data-section="entries"></div>' ),
				],
			],
		] );
	}

	public function enqueue( string $hook_suffix ): void {
		$admin      = $this->core->get( 'admin' );
		$asset_file = LIBREFORMS_DIR . 'build/admin.asset.php';

		if ( ! in_array( $hook_suffix, [ $admin->page_hook( self::MENU ), $admin->page_hook( self::ENTRIES ) ], true ) || ! is_file( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script( self::HANDLE, LIBREFORMS_URL . 'build/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'libreforms', LIBREFORMS_DIR . 'languages' );
		$admin->share_ui_translations( self::HANDLE );
		$this->share_forge_translations();

		$forms = array_map( fn( Definition $form ) => [ 'value' => $form->id, 'label' => $form->title() ], $this->forms->all() );

		wp_add_inline_script( self::HANDLE, 'window.libreformsAdmin = ' . wp_json_encode( [
			'icon'       => Icons::data_uri( 'libreforms' ),
			'tabs'       => [
				[ 'key' => 'forms', 'label' => __( 'Forms', 'libreforms' ), 'href' => admin_url( 'admin.php?page=' . self::MENU ), 'icon' => 'feedback' ],
				[ 'key' => 'entries', 'label' => __( 'Entries', 'libreforms' ), 'href' => self::entries_url(), 'icon' => 'email-alt' ],
			],
			'forms'      => $forms,
			'types'      => FormKind::front_types(),
			// Types only for forms (hidden from Meta Boxes by LibreForge).
			'formTypes'  => array_values( array_filter( $this->core->fields()->describe(), fn( array $type ) => 'form' === $type['category'] ) ),
			'adminEmail' => (string) get_option( 'admin_email' ),
			'entriesUrl' => self::entries_url(),
		] ) . ';', 'before' );

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'dashicons' );

		if ( is_file( LIBREFORMS_DIR . 'build/admin.css' ) ) {
			wp_enqueue_style( self::HANDLE, LIBREFORMS_URL . 'build/admin.css', [ 'wp-components' ], $asset['version'] );
		}
	}

	/**
	 * The form editor reuses LibreForge's field builder, whose strings use the
	 * "libreforge" text domain: load those translations here too.
	 */
	private function share_forge_translations(): void {
		if ( ! defined( 'LIBREFORGE_URL' ) ) {
			return;
		}

		if ( ! wp_script_is( 'libreforge-admin', 'registered' ) ) {
			wp_register_script( 'libreforge-admin', LIBREFORGE_URL . 'build/admin.js', [], LIBREFORGE_VERSION, true );
		}

		$json = load_script_textdomain( 'libreforge-admin', 'libreforge', LIBREFORGE_DIR . 'languages' );

		if ( $json ) {
			wp_add_inline_script(
				self::HANDLE,
				'( function( data ) { wp.i18n.setLocaleData( data.locale_data[ data.domain ] || data.locale_data.messages, "libreforge" ); } )( ' . $json . ' );',
				'before'
			);
		}
	}

	/**
	 * Numbers on each form card: fields and entries.
	 *
	 * @param array<string, mixed> $summary
	 * @return array<string, mixed>
	 */
	public function summary( array $summary, Definition $definition ): array {
		if ( FormKind::ID !== $definition->kind ) {
			return $summary;
		}

		$counts = $this->entries->counts()[ $definition->id ] ?? [ 'total' => 0, 'new' => 0 ];

		return [
			'fields'   => count( (array) $definition->get( 'fields', [] ) ),
			'entries'  => $counts['total'],
			'new'      => $counts['new'],
			'list_url' => self::entries_url( $definition->id ),
		];
	}

	/**
	 * Downloads every entry of a form as a CSV file (opens in Excel and Google Sheets).
	 */
	public function export(): void {
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		check_admin_referer( 'lbforms_export_' . $form_id );

		if ( ! current_user_can( Controller::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'libreforms' ), 403 );
		}

		$form = $this->forms->find( $form_id );

		if ( ! $form ) {
			wp_die( esc_html__( 'This form no longer exists.', 'libreforms' ), 404 );
		}

		$fields = array_values( array_filter( $this->forms->fields( $form ), fn( $field ) => 'html' !== $field->type ) );
		$name   = sanitize_file_name( $form->slug . '-' . wp_date( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel reads the accents correctly.

		fputcsv( $out, array_merge( [ __( 'Date', 'libreforms' ) ], array_map( fn( $field ) => $field->label ?: $field->name, $fields ), [ __( 'Page', 'libreforms' ) ] ), ';' );

		foreach ( $this->entries->all( $form->id ) as $entry ) {
			$row = [ $entry['created_at'] ];

			foreach ( $fields as $field ) {
				$row[] = self::cell( EntriesController::readable( $field, $entry['data'][ $field->name ] ?? null ) );
			}

			$row[] = self::cell( (string) ( $entry['data']['_page'] ?? '' ) );

			fputcsv( $out, $row, ';' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Spreadsheets run cells starting with = + - @ as formulas: neutralize them.
	 */
	private static function cell( string $value ): string {
		return '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ? "'" . $value : $value;
	}
}
