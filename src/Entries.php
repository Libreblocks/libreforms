<?php
/**
 * Saved form entries: {prefix}lbforms_entries.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Core\Support\Json;

defined( 'ABSPATH' ) || exit;

final class Entries {

	public const TABLE = 'lbforms_entries';

	public static function columns(): string {
		return "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
form_id bigint(20) unsigned NOT NULL,
data longtext NOT NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
post_id bigint(20) unsigned NOT NULL DEFAULT 0,
ip_hash varchar(64) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'new',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY form (form_id,created_at)";
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function add( int $form_id, array $data, int $post_id = 0, string $ip_hash = '' ): int {
		global $wpdb;

		$wpdb->insert( $this->table(), [
			'form_id'    => $form_id,
			'data'       => Json::encode( $data ),
			'user_id'    => get_current_user_id(),
			'post_id'    => $post_id,
			'ip_hash'    => $ip_hash,
			'status'     => 'new',
			'created_at' => current_time( 'mysql', true ),
		] );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function page( int $form_id, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table  = $this->table();
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $form_id, $per_page, $offset ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [ 'items' => array_map( [ $this, 'hydrate' ], $rows ), 'total' => $total ];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $form_id ): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d ORDER BY id DESC", $form_id ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	/**
	 * @return array<int, array{total: int, new: int}> form_id => numbers of entries.
	 */
	public function counts(): array {
		global $wpdb;

		$table  = $this->table();
		$counts = [];

		foreach ( $wpdb->get_results( "SELECT form_id, COUNT(*) AS total, SUM(status = 'new') AS fresh FROM {$table} GROUP BY form_id", ARRAY_A ) ?: [] as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ (int) $row['form_id'] ] = [ 'total' => (int) $row['total'], 'new' => (int) $row['fresh'] ];
		}

		return $counts;
	}

	public function mark_read( int $form_id ): void {
		global $wpdb;

		$wpdb->update( $this->table(), [ 'status' => 'read' ], [ 'form_id' => $form_id, 'status' => 'new' ] );
	}

	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'form_id'    => (int) $row['form_id'],
			'data'       => Json::decode( $row['data'] ),
			'user_id'    => (int) $row['user_id'],
			'post_id'    => (int) $row['post_id'],
			'status'     => (string) $row['status'],
			'created_at' => get_date_from_gmt( (string) $row['created_at'] ),
		];
	}

	private function table(): string {
		return libreblocks()->schema()->table( self::TABLE );
	}
}
