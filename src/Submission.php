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

	private static ?SubmissionContext $current = null;

	/** @var array<string, ActionType> Actions added by other plugins. */
	private array $types = [];

	public function __construct( private Forms $forms, private Entries $entries, private Tokens $tokens ) {}

	public function register( ActionType $type ): void {
		$this->types[ $type->type() ] = $type;
	}

	/**
	 * @return array<string, ActionType>
	 */
	public function types(): array {
		return $this->types;
	}

	/**
	 * The submission being processed (null outside of it).
	 */
	public static function current(): ?SubmissionContext {
		return self::$current;
	}

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

		$object  = $this->object( (int) ( $input[ View::OBJECT ] ?? 0 ) );
		$context       = new SubmissionContext( $form, $fields, $values, $object ? $object->ID : 0 );
		$context->meta = $this->meta( $form, $input, $object );
		$actions = array_values( array_filter( (array) $form->get( 'actions', [] ), fn( $action ) => $this->known( (string) ( $action['type'] ?? '' ) ) ) );

		// Actions added by other plugins can still refuse the submission (e.g. dates already booked).
		foreach ( $actions as $action ) {
			if ( isset( $this->types[ $action['type'] ] ) ) {
				$errors += $this->types[ $action['type'] ]->check( (array) ( $action['settings'] ?? [] ), $context );
			}
		}

		if ( $errors ) {
			return $fail( __( 'Please check the highlighted fields.', 'libreforms' ), $errors );
		}

		self::$current = $context;
		$redirect      = '';

		// Answers can also use the item of the page ({{object.meta|...}} in the e-mail).
		if ( $object ) {
			libreblocks()->context()->push( $object );
		}

		try {
			// "Create a post" and early actions first, so later actions (e-mail, entry) can mention their result.
			foreach ( $actions as $action ) {
				if ( $this->is_early( $action['type'] ) ) {
					$this->run( $action, $context, $input, $redirect );
				}
			}

			foreach ( $actions as $action ) {
				if ( ! $this->is_early( $action['type'] ) ) {
					$this->run( $action, $context, $input, $redirect );
				}
			}

			/**
			 * Fires after a form was submitted successfully.
			 *
			 * @param Definition           $form
			 * @param array<string, mixed> $values
			 * @param int                  $post_id Post created by the form (0 = none).
			 * @param SubmissionContext    $context
			 */
			do_action( 'libreforms/submitted', $form, $values, $context->post_id, $context );

			$message = $this->success_message( $form );
		} finally {
			self::$current = null;

			if ( $object ) {
				libreblocks()->context()->pop();
			}
		}

		return [ 'success' => true, 'message' => $message, 'errors' => [], 'redirect' => $redirect, 'values' => [] ];
	}

	/**
	 * @param array<string, mixed> $action
	 * @param array<string, mixed> $input
	 */
	private function run( array $action, SubmissionContext $context, array $input, string &$redirect ): void {
		switch ( $action['type'] ) {
			case 'create_post':
				$context->post_id = $this->create_post( $action, $context->fields, $context->values );
				return;
			case 'save_entry':
				$this->entries->add( $context->form->id, $context->values + [ '_page' => esc_url_raw( (string) ( $input['_lbf_ref'] ?? '' ) ) ], $context->post_id, Protection::visitor() );
				return;
			case 'email':
				$this->email( $action, $context );
				return;
			case 'webhook':
				$this->webhook( $context->form, $action, $context->fields, $context->values );
				return;
			case 'redirect':
				$redirect = esc_url_raw( (string) $this->tokens->render( (string) $action['url'] ) );
				return;
		}

		if ( isset( $this->types[ $action['type'] ] ) ) {
			$this->types[ $action['type'] ]->run( (array) ( $action['settings'] ?? [] ), $context );
		}
	}

	private function known( string $type ): bool {
		return in_array( $type, FormKind::ACTIONS, true ) || isset( $this->types[ $type ] );
	}

	private function is_early( string $type ): bool {
		return 'create_post' === $type || ( isset( $this->types[ $type ] ) && $this->types[ $type ]->early() );
	}

	/**
	 * The item of the page where the form was placed, when visitors may see it.
	 */
	private function object( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof \WP_Post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) ) {
			return null;
		}

		return $post;
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
			'post_title'   => sanitize_text_field( (string) ( $values[ $title_field ] ?? '' ) ) ?: sprintf( '%s — %s', self::$current->form->title(), wp_date( 'd/m/Y H:i' ) ),
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
	private function email( array $action, SubmissionContext $context ): void {
		$fields = $context->fields;
		$values = $context->values;
		$to     = array_filter( array_map( 'trim', explode( ',', (string) $this->tokens->render( (string) $action['to'] ) ) ), 'is_email' );

		if ( ! $to ) {
			return;
		}

		$subject = (string) $this->tokens->render( (string) $action['subject'] ) ?: sprintf( /* translators: %s: form title */ __( 'New submission: %s', 'libreforms' ), $context->form->title() );
		$message = trim( (string) $action['message'] ) ? wpautop( (string) $this->tokens->render( (string) $action['message'] ) ) : $this->table( $fields, $values );
		$message .= $this->meta_block( (array) ( $action['meta'] ?? FormKind::META_DEFAULT ), $context );
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
				'post_id' => self::$current->post_id,
				'meta'    => self::$current->meta,
				'site'    => home_url(),
				'date'    => gmdate( 'c' ),
			] ),
		] );
	}

	/**
	 * What the visitor did not type but the site owner wants to know.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, string>
	 */
	private function meta( Definition $form, array $input, ?\WP_Post $object ): array {
		$user = wp_get_current_user();
		$page = (string) wp_validate_redirect( esc_url_raw( (string) ( $input['_lbf_ref'] ?? '' ) ), '' );

		return [
			'date'       => wp_date( (string) get_option( 'date_format' ) ?: 'd/m/Y' ),
			'time'       => wp_date( (string) get_option( 'time_format' ) ?: 'H:i' ),
			'page_url'   => $page,
			'item'       => $object ? get_the_title( $object ) : '',
			'user'       => $user && $user->ID ? (string) $user->display_name : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 ) : '',
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '',
			'form'       => $form->title(),
		];
	}

	/**
	 * @return array<string, string> Labels of the submission details.
	 */
	public static function meta_labels(): array {
		return [
			'date'       => __( 'Date', 'libreforms' ),
			'time'       => __( 'Time', 'libreforms' ),
			'page_url'   => __( 'Page URL', 'libreforms' ),
			'item'       => __( 'Item of the page', 'libreforms' ),
			'user'       => __( 'Logged-in user', 'libreforms' ),
			'user_agent' => __( 'Browser (user agent)', 'libreforms' ),
			'ip'         => __( 'IP address', 'libreforms' ),
			'form'       => __( 'Form name', 'libreforms' ),
		];
	}

	/**
	 * The chosen details, below the answers.
	 *
	 * @param string[] $keys
	 */
	private function meta_block( array $keys, SubmissionContext $context ): string {
		$rows = '';

		foreach ( self::meta_labels() as $key => $label ) {
			$value = $context->meta[ $key ] ?? '';

			if ( in_array( $key, $keys, true ) && '' !== $value ) {
				$shown = 'page_url' === $key ? '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value );
				$rows .= '<tr><th align="left" style="padding:3px 12px 3px 0;vertical-align:top;font-weight:normal">' . esc_html( $label ) . ':</th><td style="padding:3px 0">' . $shown . '</td></tr>';
			}
		}

		return '' === $rows ? '' : '<hr style="margin:20px 0;border:0;border-top:1px solid #ddd" /><table cellspacing="0" cellpadding="0" style="color:#666;font-size:13px">' . $rows . '</table>';
	}

	/* ------------------------------------------------------------------ tokens */

	public function register_tokens( Tokens $tokens ): void {
		$tokens->add( new Token( 'form.value', fn( $name ) => $this->display( (string) $name ), __( 'Value sent in the form', 'libreforms' ), __( 'Field name', 'libreforms' ) ) );
		$tokens->add( new Token( 'form.all', fn() => self::$current ? $this->table( self::$current->fields, self::$current->values ) : '', __( 'Every value sent, as a table', 'libreforms' ) ) );
		$tokens->add( new Token( 'form.meta', fn( $key ) => self::$current ? (string) ( self::$current->meta[ (string) $key ] ?? '' ) : '', __( 'Submission detail (date, time, page_url, ip, user_agent…)', 'libreforms' ), __( 'Detail', 'libreforms' ) ) );
		$tokens->add( new Token( 'form.title', fn() => self::$current ? self::$current->form->title() : '', __( 'Form title', 'libreforms' ) ) );
		$tokens->add( new Token( 'form.post_url', fn() => self::$current && self::$current->post_id ? (string) get_permalink( self::$current->post_id ) : '', __( 'Address of the post created by the form', 'libreforms' ) ) );
	}

	/**
	 * A submitted value as readable text ("sp" → "São Paulo", dates formatted...).
	 */
	private function display( string $name ): string {
		if ( ! self::$current ) {
			return '';
		}

		foreach ( self::$current->fields as $field ) {
			if ( $field->name === $name ) {
				$value = self::$current->values[ $name ] ?? '';

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
