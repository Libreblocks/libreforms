<?php
/**
 * REST routes of the Entries screen (admins only).
 *
 * GET    libreblocks/v1/forms/{id}/entries?page=1 — a page of entries, newest first.
 * DELETE libreblocks/v1/forms/entries/{id}        — deletes one entry.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Core\Fields\Field;
use LibreBlocks\Core\Rest\Controller;
use LibreBlocks\Forge\Render\Formatter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class EntriesController extends Controller {

	private const PER_PAGE = 25;

	public function register_routes(): void {
		$this->route( '/forms/(?P<id>\d+)/entries', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'index' ],
			'permission_callback' => [ $this, 'can_manage' ],
			'args'                => [ 'page' => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ] ],
		] );

		$this->route( '/forms/entries/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'destroy' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );
	}

	/**
	 * @return WP_REST_Response|\WP_Error
	 */
	public function index( WP_REST_Request $request ) {
		$plugin = Plugin::instance();
		$form   = $plugin->forms()->find( (int) $request['id'] );

		if ( ! $form ) {
			return $this->error( 'form_not_found', __( 'This form no longer exists.', 'libreforms' ), 404 );
		}

		$fields  = array_values( array_filter( $plugin->forms()->fields( $form ), fn( Field $field ) => 'html' !== $field->type ) );
		$page    = $plugin->entries()->page( $form->id, (int) $request['page'], self::PER_PAGE );
		$columns = array_map( fn( Field $field ) => [ 'name' => $field->name, 'label' => $field->label ?: $field->name ], $fields );

		$items = array_map( function ( array $entry ) use ( $fields ) {
			$values = [];

			foreach ( $fields as $field ) {
				$values[ $field->name ] = self::readable( $field, $entry['data'][ $field->name ] ?? null );
			}

			return [
				'id'       => $entry['id'],
				'date'     => $entry['created_at'],
				'is_new'   => 'new' === $entry['status'],
				'values'   => $values,
				'page'     => (string) ( $entry['data']['_page'] ?? '' ),
				'user'     => $entry['user_id'] ? (string) get_the_author_meta( 'display_name', $entry['user_id'] ) : '',
				'post'     => $entry['post_id'] ? [ 'title' => get_the_title( $entry['post_id'] ), 'url' => (string) get_edit_post_link( $entry['post_id'], 'raw' ) ] : null,
			];
		}, $page['items'] );

		// Opening the screen counts as reading them.
		$plugin->entries()->mark_read( $form->id );

		return new WP_REST_Response( [
			'form'     => [ 'id' => $form->id, 'title' => $form->title() ],
			'columns'  => $columns,
			'items'    => $items,
			'total'    => $page['total'],
			'pages'    => (int) ceil( $page['total'] / self::PER_PAGE ),
			'export'   => wp_nonce_url( admin_url( 'admin-post.php?action=lbforms_export&form_id=' . $form->id ), 'lbforms_export_' . $form->id ),
		] );
	}

	public function destroy( WP_REST_Request $request ): WP_REST_Response {
		Plugin::instance()->entries()->delete( (int) $request['id'] );

		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	/**
	 * A saved value as plain text: labels instead of option values, formatted dates, file links.
	 *
	 * @param mixed $value
	 */
	public static function readable( Field $field, $value ): string {
		if ( null === $value || '' === $value || [] === $value ) {
			return '';
		}

		if ( 'media' === $field->type ) {
			return (int) $value ? (string) wp_get_attachment_url( (int) $value ) : '';
		}

		if ( in_array( $field->type, [ 'select', 'radio', 'checkbox' ], true ) ) {
			return implode( ', ', Formatter::labels( (array) $value, $field ) );
		}

		return html_entity_decode( wp_strip_all_tags( Formatter::format( $value, [], $field ) ), ENT_QUOTES, 'UTF-8' );
	}
}
