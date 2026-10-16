<?php
/**
 * REST routes of LibreForms.
 *
 * POST libreblocks/v1/forms/{id}/submit — public: visitors send forms (multipart).
 * GET  libreblocks/v1/forms              — saved forms, for the block and widget selectors.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Core\Rest\Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class SubmitController extends Controller {

	public function register_routes(): void {
		$this->route( '/forms', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'index' ],
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
		] );

		$this->route( '/forms/(?P<id>\d+)/submit', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'submit' ],
			// Public on purpose: anyone can fill in a form. Protection runs inside Submission.
			'permission_callback' => '__return_true',
		] );
	}

	public function index(): WP_REST_Response {
		return new WP_REST_Response( array_map(
			fn( $form ) => [ 'value' => $form->id, 'label' => $form->title() ],
			Plugin::instance()->forms()->all()
		) );
	}

	/**
	 * @return WP_REST_Response|\WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$form = Plugin::instance()->forms()->find( (int) $request['id'] );

		if ( ! $form ) {
			return $this->error( 'form_not_found', __( 'This form no longer exists.', 'libreforms' ), 404 );
		}

		$result = Plugin::instance()->submission()->process( $form, (array) $request->get_body_params(), (array) $request->get_file_params() );

		unset( $result['values'] );

		return new WP_REST_Response( $result, $result['success'] ? 200 : 422 );
	}
}
