<?php
/**
 * HTML of a form. A regular POST form (works without JavaScript, through
 * admin-post.php); the front-end script submits it with AJAX instead.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Core\Fields\Field;
use LibreBlocks\Forge\Model\Definition;

defined( 'ABSPATH' ) || exit;

final class View {

	public const HONEYPOT = 'lbf_website';
	public const TIMER    = '_lbf_t';
	public const NONCE    = '_lbf_nonce';

	/** @var array<int, array<string, mixed>> Result of a non-JS submission, by form id. */
	private array $results = [];

	public function __construct( private Forms $forms ) {}

	/**
	 * Result stored by the non-JS submission, shown after the redirect.
	 *
	 * @return array<string, mixed>|null
	 */
	private function result( int $form_id ): ?array {
		if ( isset( $this->results[ $form_id ] ) ) {
			return $this->results[ $form_id ];
		}

		$key = isset( $_GET['lbforms_result'] ) ? sanitize_key( wp_unslash( $_GET['lbforms_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $key ) {
			return null;
		}

		$result = get_transient( 'lbforms_result_' . $key );

		if ( ! is_array( $result ) || (int) ( $result['form_id'] ?? 0 ) !== $form_id ) {
			return null;
		}

		delete_transient( 'lbforms_result_' . $key );

		return $this->results[ $form_id ] = $result;
	}

	/**
	 * @param array<string, mixed> $s form_id, class.
	 */
	public function form( array $s ): string {
		$form = $this->forms->find( (int) ( $s['form_id'] ?? 0 ) );

		if ( ! $form ) {
			return current_user_can( 'edit_posts' ) ? '<div class="lbf-notice">' . esc_html__( 'Choose a form (LibreForms → Forms).', 'libreforms' ) . '</div>' : '';
		}

		Assets::enqueue();

		if ( $form->get( 'logged_in_only' ) && ! is_user_logged_in() ) {
			return '<div class="lbf-notice lbf-notice--login">' . sprintf(
				/* translators: %s: login link */
				esc_html__( 'Please %s to fill in this form.', 'libreforms' ),
				'<a href="' . esc_url( wp_login_url( (string) get_permalink() ) ) . '">' . esc_html__( 'log in', 'libreforms' ) . '</a>'
			) . '</div>';
		}

		$result = $this->result( $form->id );

		if ( $result && ! empty( $result['success'] ) && $form->get( 'hide_on_success', true ) ) {
			return '<div class="lbf-form lbf-form--done"><div class="lbf-message lbf-message--success" role="status">' . esc_html( (string) $result['message'] ) . '</div></div>';
		}

		$values = (array) ( $result['values'] ?? [] );
		$errors = (array) ( $result['errors'] ?? [] );
		$fields = $this->forms->fields( $form );
		$html   = '';

		foreach ( $fields as $field ) {
			$html .= $this->field( $form, $field, $values[ $field->name ] ?? $field->default, $errors[ $field->name ] ?? '' );
		}

		$message = '';

		if ( $result ) {
			$message = sprintf(
				'<div class="lbf-message lbf-message--%1$s" role="%2$s">%3$s</div>',
				! empty( $result['success'] ) ? 'success' : 'error',
				! empty( $result['success'] ) ? 'status' : 'alert',
				esc_html( (string) $result['message'] )
			);
		}

		return sprintf(
			'<form id="lbf-%3$d" class="%1$s" method="post" action="%2$s" enctype="multipart/form-data" data-libreforms="%3$d"%13$s>'
				. '<input type="hidden" name="action" value="lbforms_submit" />'
				. '<input type="hidden" name="lbforms_id" value="%3$d" />'
				. '<input type="hidden" name="%4$s" value="%5$s" />'
				. '<input type="hidden" name="_lbf_ref" value="%6$s" />%12$s'
				. '<div class="lbf-fields">%7$s</div>'
				. '<div class="lbf-hp" aria-hidden="true"><label>%8$s <input type="text" name="%9$s" value="" tabindex="-1" autocomplete="off" /></label></div>'
				. '<div class="lbf-footer"><button type="submit" class="lbf-submit wp-element-button">%10$s</button></div>'
				. '<div class="lbf-message-area" aria-live="polite">%11$s</div>'
				. '</form>',
			esc_attr( trim( 'lbf-form ' . ( $s['class'] ?? '' ) ) ),
			esc_url( admin_url( 'admin-post.php' ) ),
			$form->id,
			esc_attr( self::TIMER ),
			esc_attr( Protection::timer() ),
			esc_url( $this->current_url() ),
			$html,
			esc_html__( 'Leave this field empty', 'libreforms' ),
			esc_attr( self::HONEYPOT ),
			esc_html( (string) $form->get( 'submit_label' ) ?: __( 'Send', 'libreforms' ) ),
			$message,
			// Logged-in submissions act as the user: protect them against forged requests.
			is_user_logged_in() ? '<input type="hidden" name="' . esc_attr( self::NONCE ) . '" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" />' : '',
			$form->get( 'hide_on_success', true ) ? ' data-hide-on-success' : ''
		);
	}

	/**
	 * @param mixed $value
	 */
	private function field( Definition $form, Field $field, $value, string $error ): string {
		$id       = 'lbf-' . $form->id . '-' . $field->name;
		$width    = (int) $field->width ?: 100;
		$required = $field->required ? ' required' : '';
		$label    = '';

		if ( 'html' === $field->type ) {
			return sprintf( '<div class="lbf-field lbf-field--html lbf-w-%1$d"%2$s>%3$s</div>', $width, $this->conditions( $field ), wp_kses_post( (string) $field->arg( 'content', '' ) ) );
		}

		$grouped = in_array( $field->type, [ 'radio', 'checkbox' ], true );

		if ( $field->label ) {
			$label = sprintf(
				$grouped ? '<legend class="lbf-label">%2$s%3$s</legend>' : '<label class="lbf-label" for="%1$s">%2$s%3$s</label>',
				esc_attr( $id ),
				esc_html( $field->label ),
				$field->required ? ' <span class="lbf-required" aria-hidden="true">*</span>' : ''
			);
		}

		$control = $this->control( $field, $id, $value, $required );
		$help    = $field->description ? '<p class="lbf-help" id="' . esc_attr( $id ) . '-help">' . wp_kses_post( $field->description ) . '</p>' : '';
		$problem = '<p class="lbf-error" id="' . esc_attr( $id ) . '-error"' . ( '' === $error ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p>';
		$tag     = $grouped ? 'fieldset' : 'div';

		return sprintf(
			'<%1$s class="lbf-field lbf-field--%2$s lbf-w-%3$d%4$s" data-name="%5$s"%6$s>%7$s%8$s%9$s%10$s</%1$s>',
			$tag,
			esc_attr( $field->type ),
			$width,
			'' === $error ? '' : ' has-error',
			esc_attr( $field->name ),
			$this->conditions( $field ),
			$label,
			$control,
			$help,
			$problem
		);
	}

	/**
	 * @param mixed $value
	 */
	private function control( Field $field, string $id, $value, string $required ): string {
		$name        = esc_attr( $field->name );
		$aria        = ' aria-describedby="' . esc_attr( $id ) . '-error' . ( $field->description ? ' ' . esc_attr( $id ) . '-help' : '' ) . '"';
		$placeholder = (string) $field->arg( 'placeholder', '' );
		$attrs       = $required . $aria . ( '' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '' );

		switch ( $field->type ) {
			case 'textarea':
			case 'wysiwyg':
				$max = (int) $field->arg( 'max_length', 0 );
				return sprintf( '<textarea id="%1$s" name="%2$s" rows="%3$d"%4$s%5$s>%6$s</textarea>', esc_attr( $id ), $name, (int) $field->arg( 'rows', 5 ), $attrs, $max ? ' maxlength="' . $max . '"' : '', esc_textarea( is_scalar( $value ) ? (string) $value : '' ) );

			case 'select':
				$multiple = (bool) $field->arg( 'multiple' );
				$selected = array_map( 'strval', (array) $value );
				$html     = sprintf( '<select id="%1$s" name="%2$s%3$s"%4$s%5$s>', esc_attr( $id ), $name, $multiple ? '[]' : '', $multiple ? ' multiple' : '', $required . $aria );
				$html    .= $multiple ? '' : '<option value="">' . esc_html( $placeholder ?: __( '— Select —', 'libreforms' ) ) . '</option>';

				foreach ( $field->options as $option ) {
					$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $option['value'] ), in_array( $option['value'], $selected, true ) ? ' selected' : '', esc_html( $option['label'] ) );
				}

				return $html . '</select>';

			case 'radio':
			case 'checkbox':
				$type     = 'radio' === $field->type ? 'radio' : 'checkbox';
				$selected = array_map( 'strval', (array) $value );
				$html     = '<div class="lbf-options">';

				foreach ( $field->options as $index => $option ) {
					$html .= sprintf(
						'<label class="lbf-option"><input type="%1$s" name="%2$s%3$s" value="%4$s"%5$s%6$s /> <span>%7$s</span></label>',
						$type,
						$name,
						'checkbox' === $type ? '[]' : '',
						esc_attr( $option['value'] ),
						in_array( $option['value'], $selected, true ) ? ' checked' : '',
						'radio' === $type && 0 === $index ? $required : '',
						esc_html( $option['label'] )
					);
				}

				return $html . '</div>';

			case 'switch':
				return sprintf(
					'<label class="lbf-switch"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s /> <span>%5$s</span></label>',
					esc_attr( $id ),
					$name,
					filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? ' checked' : '',
					$required . $aria,
					esc_html( (string) $field->arg( 'toggle_label', '' ) ?: $field->label )
				);

			case 'media':
				$accept = [ 'image' => 'image/*', 'video' => 'video/*', 'audio' => 'audio/*', 'application/pdf' => 'application/pdf' ][ (string) $field->arg( 'mime_type', '' ) ] ?? '';
				return sprintf( '<input type="file" id="%1$s" name="%2$s"%3$s%4$s />', esc_attr( $id ), $name, '' !== $accept ? ' accept="' . esc_attr( $accept ) . '"' : '', $required . $aria );

			default:
				$types = [ 'email' => 'email', 'url' => 'url', 'number' => 'number', 'date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local', 'color' => 'color' ];
				$type  = $types[ $field->type ] ?? 'text';
				$extra = '';

				if ( 'number' === $type ) {
					foreach ( [ 'min', 'max', 'step' ] as $limit ) {
						if ( is_numeric( $field->arg( $limit ) ) ) {
							$extra .= ' ' . $limit . '="' . esc_attr( (string) $field->arg( $limit ) ) . '"';
						}
					}
				}

				if ( 'text' === $type && (int) $field->arg( 'max_length', 0 ) ) {
					$extra .= ' maxlength="' . (int) $field->arg( 'max_length' ) . '"';
				}

				$autocomplete = [ 'email' => 'email', 'url' => 'url' ][ $type ] ?? '';

				return sprintf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s"%5$s%6$s%7$s />',
					$type,
					esc_attr( $id ),
					$name,
					esc_attr( is_scalar( $value ) ? (string) $value : '' ),
					$attrs,
					$extra,
					$autocomplete ? ' autocomplete="' . $autocomplete . '"' : ''
				);
		}
	}

	private function conditions( Field $field ): string {
		return $field->conditions ? ' data-conditions="' . esc_attr( (string) wp_json_encode( $field->conditions ) ) . '"' : '';
	}

	private function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- escaped below.

		if ( '' === $host ) {
			return (string) get_permalink();
		}

		return remove_query_arg( 'lbforms_result', esc_url_raw( set_url_scheme( 'http://' . $host . $uri ) ) );
	}
}
