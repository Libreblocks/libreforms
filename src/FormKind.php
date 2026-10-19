<?php
/**
 * Saved forms (kind "form" of the LibreForge definitions).
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Forge\Model\Definition;
use LibreBlocks\Forge\Model\DefinitionRepository;
use LibreBlocks\Forge\Model\FieldList;
use LibreBlocks\Forge\Model\Kind;

defined( 'ABSPATH' ) || exit;

final class FormKind extends Kind {

	public const ID = 'form';

	/** Field types a visitor can fill in on the site. */
	public const FRONT_TYPES = [ 'text', 'textarea', 'wysiwyg', 'number', 'email', 'url', 'date', 'time', 'datetime', 'select', 'radio', 'checkbox', 'switch', 'color', 'media', 'html' ];

	/** Names used by the form itself (hidden inputs). */
	public const RESERVED_NAMES = [ 'action', 'lbforms_id', '_lbf_t', '_lbf_ref', '_lbf_nonce', 'lbf_website', '_page', '_wpnonce', '_lbf_object' ];

	/** Submission details an e-mail can list at the end (the visitor never types them). */
	public const META = [ 'date', 'time', 'page_url', 'item', 'user', 'user_agent', 'ip', 'form' ];

	public const META_DEFAULT = [ 'date', 'time', 'page_url' ];

	public const ACTIONS = [ 'save_entry', 'email', 'create_post', 'webhook', 'redirect' ];

	/** @var callable(): array<string, ActionType> */
	private $types;

	/**
	 * @param callable(): array<string, ActionType>|null $types Actions added by other plugins.
	 */
	public function __construct( private FieldList $fields, ?callable $types = null ) {
		$this->types = $types ?? static fn() => [];
	}

	/**
	 * Field types a visitor can fill in, including the ones added by other plugins
	 * (filter "libreforms/front_types").
	 *
	 * @return string[]
	 */
	public static function front_types(): array {
		return array_values( array_unique( (array) apply_filters( 'libreforms/front_types', self::FRONT_TYPES ) ) );
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Forms', 'libreforms' );
	}

	public function sanitize( array $input ): array {
		return [
			'title'           => self::text( $input, 'title' ),
			'fields'          => $this->fields->sanitize( $input['fields'] ?? [] ),
			'submit_label'    => self::text( $input, 'submit_label' ),
			'success_message' => sanitize_textarea_field( (string) ( $input['success_message'] ?? '' ) ),
			'hide_on_success' => self::flag( $input, 'hide_on_success', true ),
			'actions'         => $this->actions( $input['actions'] ?? [] ),
			'logged_in_only'  => self::flag( $input, 'logged_in_only', false ),
			'min_seconds'     => max( 0, min( 60, (int) ( $input['min_seconds'] ?? 3 ) ) ),
			'limit_per_hour'  => max( 0, min( 1000, (int) ( $input['limit_per_hour'] ?? 20 ) ) ),
		];
	}

	public function validate( Definition $definition, DefinitionRepository $repository ): array {
		$errors = $this->validate_slug( $definition, $repository, 64 );

		if ( '' === $definition->get( 'title', '' ) ) {
			$errors['title'] = __( 'The title is required.', 'libreforms' );
		}

		$errors += $this->fields->validate( (array) $definition->get( 'fields', [] ) );

		foreach ( (array) $definition->get( 'fields', [] ) as $index => $field ) {
			if ( in_array( $field['name'], self::RESERVED_NAMES, true ) && ! isset( $errors[ 'fields.' . $index ] ) ) {
				/* translators: %s: field name */
				$errors[ 'fields.' . $index ] = sprintf( __( 'The name "%s" is used by the form itself. Choose another one.', 'libreforms' ), $field['name'] );
			}

			if ( ! in_array( $field['type'], self::front_types(), true ) && ! isset( $errors[ 'fields.' . $index ] ) ) {
				/* translators: %s: field label */
				$errors[ 'fields.' . $index ] = sprintf( __( 'The field "%s" uses a type that forms do not support yet.', 'libreforms' ), $field['label'] ?: $field['name'] );
			}
		}

		foreach ( (array) $definition->get( 'actions', [] ) as $index => $action ) {
			if ( 'email' === $action['type'] && '' === trim( (string) $action['to'] ) ) {
				$errors[ 'actions.' . $index ] = __( 'The e-mail action needs a recipient.', 'libreforms' );
			}

			if ( 'create_post' === $action['type'] && ! post_type_exists( (string) $action['post_type'] ) ) {
				$errors[ 'actions.' . $index ] = __( 'Choose the post type to create.', 'libreforms' );
			}

			if ( 'webhook' === $action['type'] && ! wp_http_validate_url( (string) $action['url'] ) ) {
				$errors[ 'actions.' . $index ] = __( 'The webhook needs a valid address (https://…).', 'libreforms' );
			}

			$extra = ( $this->types )()[ $action['type'] ] ?? null;
			$error = $extra ? $extra->validate( (array) ( $action['settings'] ?? [] ), $definition ) : null;

			if ( null !== $error ) {
				$errors[ 'actions.' . $index ] = $error;
			}
		}

		return $errors;
	}

	/**
	 * @param mixed $input
	 * @return array<int, array<string, mixed>>
	 */
	private function actions( $input ): array {
		$clean = [];

		$extra = ( $this->types )();

		foreach ( is_array( $input ) ? $input : [] as $action ) {
			if ( ! is_array( $action ) || ! ( in_array( $action['type'] ?? '', self::ACTIONS, true ) || isset( $extra[ $action['type'] ?? '' ] ) ) ) {
				continue;
			}

			$status = (string) ( $action['status'] ?? 'pending' );

			$clean[] = [
				'type'          => $action['type'],
				// E-mail.
				'to'            => sanitize_text_field( (string) ( $action['to'] ?? '' ) ),
				'subject'       => sanitize_text_field( (string) ( $action['subject'] ?? '' ) ),
				'message'       => wp_kses_post( (string) ( $action['message'] ?? '' ) ),
				'reply_to'      => sanitize_key( (string) ( $action['reply_to'] ?? '' ) ),
				'meta'          => array_key_exists( 'meta', $action ) ? array_values( array_intersect( self::META, (array) $action['meta'] ) ) : self::META_DEFAULT,
				// Create post.
				'post_type'     => sanitize_key( (string) ( $action['post_type'] ?? '' ) ),
				'status'        => in_array( $status, [ 'publish', 'pending', 'draft', 'private' ], true ) ? $status : 'pending',
				'title_field'   => sanitize_key( (string) ( $action['title_field'] ?? '' ) ),
				'content_field' => sanitize_key( (string) ( $action['content_field'] ?? '' ) ),
				'image_field'   => sanitize_key( (string) ( $action['image_field'] ?? '' ) ),
				'save_meta'     => ! empty( $action['save_meta'] ) || ! array_key_exists( 'save_meta', $action ),
				// Webhook / redirect.
				'url'           => esc_url_raw( (string) ( $action['url'] ?? '' ) ),
				// Actions added by other plugins keep their own settings here.
				'settings'      => isset( $extra[ $action['type'] ] ) ? $extra[ $action['type'] ]->sanitize( is_array( $action['settings'] ?? null ) ? $action['settings'] : [] ) : [],
			];
		}

		return $clean;
	}
}
