<?php
/**
 * Processes a submitted form: protection, uploads, sanitizing, validation and
 * the form actions (create post, save entry, e-mail, webhook, redirect).
 *
 * While actions run, the tokens {{form.value|field}}, {{form.all}}, {{form.title}}
 * and {{form.post_url}} describe this submission.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Core\Fields\Conditions;
use LibreBlocks\Core\Fields\Field;
use LibreBlocks\Core\Tokens\Registry as Tokens;
use LibreBlocks\Core\Tokens\Token;
use LibreBlocks\Forge\Model\Definition;
use LibreBlocks\Forge\Render\Formatter;

defined( 'ABSPATH' ) || exit;

final class Submission {

	private const MAX_UPLOAD_MB = 8;

	/** @var array{form: Definition, fields: Field[], values: array<string, mixed>, post_id: int}|null */
	private static ?array $current = null;

	public function __construct( private Forms $forms, private Entries $entries, private Tokens $tokens ) {}

	public function hook(): void {
		add_action( 'libreblocks/tokens/register', [ $this, 'register_tokens' ] );
	}

	/**
	 * @param array<string, mixed> $input Submitted fields (unslashed).
	 * @param array<string, mixed> $files $_FILES-shaped array.
	 * @return array{success: bool, message: string, errors: array<string, string>, redirect: string, values: array<string, mixed>}
	 */
	public function process( Definition $form, array $input, array $files ): array {
		$fail = fn( string $message, array $errors = [] ) => [ 'success' => false, 'message' => $message, 'errors' => $errors, 'redirect' => '', 'values' => $this->echo_values( $form, $input ) ];

		if ( $form->get( 'logged_in_only' ) && ! is_user_logged_in() ) {
			return $fail( __( 'Please log in to fill in this form.', 'libreforms' ) );
		}

		// Bots fill every field: pretend it worked and do nothing.
		if ( '' !== trim( (string) ( $input[ View::HONEYPOT ] ?? '' ) ) ) {
			return [ 'success' => true, 'message' => $this->success_message( $form ), 'errors' => [], 'redirect' => '', 'values' => [] ];
		}

		$elapsed = Protection::elapsed( (string) ( $input[ View::TIMER ] ?? '' ) );

		if ( null === $elapsed || $elapsed < (int) $form->get( 'min_seconds', 3 ) ) {
			return $fail( __( 'The form was sent too fast. Please wait a moment and try again.', 'libreforms' ) );
		}

		if ( ! Protection::within_limit( $form->id, (int) $form->get( 'limit_per_hour', 20 ) ) ) {
			return $fail( __( 'Too many submissions. Please try again later.', 'libreforms' ) );
		}

		$fields   = $this->forms->fields( $form );
		$registry = $this->forms->registry();
		$errors   = [];

		// Files first: their value is the ID of the uploaded attachment.
		foreach ( $fields as $field ) {
			if ( 'media' === $field->type && ! empty( $files[ $field->name ]['name'] ) ) {
				$upload = $this->upload( $field, $files[ $field->name ] );

				if ( is_string( $upload ) ) {
					$errors[ $field->name ] = $upload;
				} else {
					$input[ $field->name ] = $upload;
				}
			}
		}

		$values  = $registry->sanitize_values( $fields, array_intersect_key( $input, array_flip( array_map( fn( Field $field ) => $field->name, $fields ) ) ) + $this->defaults_for_missing( $fields, $input ) );
		$errors += $registry->validate_values( $fields, $values );

		if ( $errors ) {
			return $fail( __( 'Please check the highlighted fields.', 'libreforms' ), $errors );
		}

		// Fields hidden by their conditions were not really answered.
		foreach ( $fields as $field ) {
			if ( $field->conditions && ! Conditions::matches( $field->conditions, $values ) ) {
				unset( $values[ $field->name ] );
			}
		}

		self::$current = [ 'form' => $form, 'fields' => $fields, 'values' => $values, 'post_id' => 0 ];
		$redirect      = '';

		try {
			$actions = (array) $form->get( 'actions', [] );

			// Create the post first, so later actions (e-mail, entry) can mention it.
			foreach ( $actions as $action ) {
				if ( 'create_post' === $action['type'] ) {
					self::$current['post_id'] = $this->create_post( $action, $fields, $values );
				}
			}

			foreach ( $actions as $action ) {
				switch ( $action['type'] ) {
					case 'save_entry':
						$this->entries->add( $form->id, $values + [ '_page' => esc_url_raw( (string) ( $input['_lbf_ref'] ?? '' ) ) ], self::$current['post_id'], Protection::visitor() );
						break;
					case 'email':
						$this->email( $action, $fields, $values );
						break;
					case 'webhook':
						$this->webhook( $form, $action, $fields, $values );
						break;
					case 'redirect':
						$redirect = esc_url_raw( (string) $this->tokens->render( (string) $action['url'] ) );
						break;
				}
			}

			/**
			 * Fires after a form was submitted successfully.
			 *
			 * @param Definition           $form
			 * @param array<string, mixed> $values
			 * @param int                  $post_id Post created by the form (0 = none).
			 */
			do_action( 'libreforms/submitted', $form, $values, self::$current['post_id'] );

			$message = $this->success_message( $form );
		} finally {
			self::$current = null;
		}

		return [ 'success' => true, 'message' => $message, 'errors' => [], 'redirect' => $redirect, 'values' => [] ];
	}

	/* ------------------------------------------------------------------ actions */

	/**
	 * @param array<string, mixed> $action
	 * @param Field[]              $fields
	 * @param array<string, mixed> $values
	 */
	private function create_post( array $action, array $fields, array $values ): int {
		$title_field   = (string) $action['title_field'];
		$content_field = (string) $action['content_field'];
		$image_field   = (string) $action['image_field'];

		$post_id = wp_insert_post( [
			'post_type'    => (string) $action['post_type'],
			'post_status'  => (string) $action['status'],
			'post_title'   => sanitize_text_field( (string) ( $values[ $title_field ] ?? '' ) ) ?: sprintf( '%s — %s', self::$current['form']->title(), wp_date( 'd/m/Y H:i' ) ),
			'post_content' => wp_kses_post( (string) ( $values[ $content_field ] ?? '' ) ),
			'post_author'  => get_current_user_id(),
		], true );

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		foreach ( $fields as $field ) {
			$name = $field->name;

			if ( 'html' === $field->type || in_array( $name, [ $title_field, $content_field ], true ) ) {
				continue;
			}

			if ( 'media' === $field->type && (int) ( $values[ $name ] ?? 0 ) ) {
				wp_update_post( [ 'ID' => (int) $values[ $name ], 'post_parent' => $post_id ] );

				if ( $name === $image_field ) {
					set_post_thumbnail( $post_id, (int) $values[ $name ] );
					continue;
				}
			}

			// Same name as a Meta Box field = it fills that field.
			if ( ! empty( $action['save_meta'] ) && ! $this->is_empty( $values[ $name ] ?? null ) ) {
				update_post_meta( $post_id, $name, $values[ $name ] );
			}
		}

		return (int) $post_id;
	}

	/**
	 * @param array<string, mixed> $action
	 * @param Field[]              $fields
	 * @param array<string, mixed> $values
	 */
	private function email( array $action, array $fields, array $values ): void {
		$to = array_filter( array_map( 'trim', explode( ',', (string) $this->tokens->render( (string) $action['to'] ) ) ), 'is_email' );

		if ( ! $to ) {
			return;
		}

		$subject = (string) $this->tokens->render( (string) $action['subject'] ) ?: sprintf( /* translators: %s: form title */ __( 'New submission: %s', 'libreforms' ), self::$current['form']->title() );
		$message = trim( (string) $action['message'] ) ? wpautop( (string) $this->tokens->render( (string) $action['message'] ) ) : $this->table( $fields, $values );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$reply   = (string) ( $values[ (string) $action['reply_to'] ] ?? '' );

		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		wp_mail( $to, wp_strip_all_tags( $subject ), $message, $headers );
	}

	/**
	 * @param array<string, mixed> $action
	 * @param Field[]              $fields
	 * @param array<string, mixed> $values
	 */
	private function webhook( Definition $form, array $action, array $fields, array $values ): void {
		$labels = [];

		foreach ( $fields as $field ) {
			if ( 'html' !== $field->type ) {
				$labels[ $field->name ] = $field->label ?: $field->name;
			}
		}

		wp_safe_remote_post( (string) $action['url'], [
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => [ 'Content-Type' => 'application/json' ],
			'body'     => (string) wp_json_encode( [
				'form'    => [ 'id' => $form->id, 'slug' => $form->slug, 'title' => $form->title() ],
				'values'  => $values,
				'labels'  => $labels,
				'post_id' => self::$current['post_id'],
				'site'    => home_url(),
				'date'    => gmdate( 'c' ),
			] ),
		] );
	}

	/* ------------------------------------------------------------------ tokens */

	public function register_tokens( Tokens $tokens ): void {
		$tokens->add( new Token( 'form.value', fn( $name ) => $this->display( (string) $name ), __( 'Value sent in the form', 'libreforms' ), __( 'Field name', 'libreforms' ) ) );
		$tokens->add( new Token( 'form.all', fn() => self::$current ? $this->table( self::$current['fields'], self::$current['values'] ) : '', __( 'Every value sent, as a table', 'libreforms' ) ) );
		$tokens->add( new Token( 'form.title', fn() => self::$current ? self::$current['form']->title() : '', __( 'Form title', 'libreforms' ) ) );
		$tokens->add( new Token( 'form.post_url', fn() => self::$current && self::$current['post_id'] ? (string) get_permalink( self::$current['post_id'] ) : '', __( 'Address of the post created by the form', 'libreforms' ) ) );
	}

	/**
	 * A submitted value as readable text ("sp" → "São Paulo", dates formatted...).
	 */
	private function display( string $name ): string {
		if ( ! self::$current ) {
			return '';
		}

		foreach ( self::$current['fields'] as $field ) {
			if ( $field->name === $name ) {
				$value = self::$current['values'][ $name ] ?? '';

				if ( 'media' === $field->type ) {
					return (int) $value ? (string) wp_get_attachment_url( (int) $value ) : '';
				}

				return wp_strip_all_tags( Formatter::format( $value, [], $field ) );
			}
		}

		return '';
	}

	/**
	 * @param Field[]              $fields
	 * @param array<string, mixed> $values
	 */
	private function table( array $fields, array $values ): string {
		$rows = '';

		foreach ( $fields as $field ) {
			if ( 'html' === $field->type || $this->is_empty( $values[ $field->name ] ?? null ) ) {
				continue;
			}

			$value = 'media' === $field->type
				? '<a href="' . esc_url( (string) wp_get_attachment_url( (int) $values[ $field->name ] ) ) . '">' . esc_html__( 'Open file', 'libreforms' ) . '</a>'
				: Formatter::format( $values[ $field->name ], [], $field );

			$rows .= '<tr><th align="left" style="padding:6px 12px 6px 0;vertical-align:top">' . esc_html( $field->label ?: $field->name ) . '</th><td style="padding:6px 0">' . $value . '</td></tr>';
		}

		return '<table cellspacing="0" cellpadding="0">' . $rows . '</table>';
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * @param array<string, mixed> $file
	 * @return int|string Attachment ID, or an error message.
	 */
	private function upload( Field $field, array $file ) {
		$max = (float) $field->arg( 'max_size', self::MAX_UPLOAD_MB );

		if ( (int) ( $file['error'] ?? 0 ) !== UPLOAD_ERR_OK ) {
			return __( 'The file could not be uploaded.', 'libreforms' );
		}

		if ( (int) $file['size'] > $max * MB_IN_BYTES ) {
			/* translators: %s: size in MB */
			return sprintf( __( 'The file is too big (maximum %s MB).', 'libreforms' ), $max );
		}

		$check   = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'] );
		$mime    = (string) ( $check['type'] ?: '' );
		$allowed = (string) $field->arg( 'mime_type', '' );

		if ( '' === $mime || ( '' !== $allowed && 0 !== strpos( $mime, rtrim( $allowed, '*' ) ) ) ) {
			return __( 'This type of file is not accepted.', 'libreforms' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$key              = 'lbforms_upload_' . $field->name;
		$_FILES[ $key ]   = $file; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- handled by media_handle_upload().
		$attachment_id    = media_handle_upload( $key, 0, [], [ 'test_form' => false ] );
		unset( $_FILES[ $key ] );

		return is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : (int) $attachment_id;
	}

	/**
	 * Unchecked checkboxes and switches send nothing; they must still be validated.
	 *
	 * @param Field[]              $fields
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function defaults_for_missing( array $fields, array $input ): array {
		$missing = [];

		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field->name, $input ) && in_array( $field->type, [ 'checkbox', 'switch', 'select', 'radio' ], true ) ) {
				$missing[ $field->name ] = 'switch' === $field->type ? false : '';
			}
		}

		return $missing;
	}

	/**
	 * What the visitor typed, to refill the form after an error (no files).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function echo_values( Definition $form, array $input ): array {
		$values = [];

		foreach ( $this->forms->fields( $form ) as $field ) {
			if ( 'media' !== $field->type && isset( $input[ $field->name ] ) ) {
				$values[ $field->name ] = is_array( $input[ $field->name ] )
					? array_map( 'sanitize_text_field', array_filter( $input[ $field->name ], 'is_scalar' ) )
					: sanitize_textarea_field( (string) $input[ $field->name ] );
			}
		}

		return $values;
	}

	private function success_message( Definition $form ): string {
		$message = (string) $form->get( 'success_message' );

		return '' !== trim( $message ) ? wp_strip_all_tags( (string) $this->tokens->render( $message ) ) : __( 'Thank you! Your message was sent.', 'libreforms' );
	}

	/**
	 * @param mixed $value
	 */
	private function is_empty( $value ): bool {
		return null === $value || '' === $value || [] === $value;
	}
}
