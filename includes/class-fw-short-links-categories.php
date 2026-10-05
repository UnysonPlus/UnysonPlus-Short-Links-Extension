<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Link categories: a real WordPress taxonomy whose objects are link ids.
 *
 * WordPress's term tables do not care what an object id refers to, and a
 * relationship is scoped by term_taxonomy_id, so link ids never mix with post
 * ids. The one thing that does assume posts is the default term counter —
 * hence `_update_generic_term_count`.
 *
 * Terms are managed on the core Categories screen (edit-tags.php), linked
 * from the Short Links screen; assigning them is part of the link form.
 */
class FW_Short_Links_Categories {

	const TAXONOMY    = 'fw_short_link_cat';
	const OBJECT_TYPE = 'fw_short_link';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'parent_file', array( $this, '_filter_parent_file' ) );
		add_filter( 'submenu_file', array( $this, '_filter_submenu_file' ) );
	}

	public static function register() {
		register_taxonomy( self::TAXONOMY, self::OBJECT_TYPE, array(
			'labels'                => array(
				'name'          => __( 'Link Categories', 'fw' ),
				'singular_name' => __( 'Link Category', 'fw' ),
				'menu_name'     => __( 'Link Categories', 'fw' ),
				'all_items'     => __( 'All Link Categories', 'fw' ),
				'add_new_item'  => __( 'Add New Link Category', 'fw' ),
				'edit_item'     => __( 'Edit Link Category', 'fw' ),
				'search_items'  => __( 'Search Link Categories', 'fw' ),
				'not_found'     => __( 'No link categories yet.', 'fw' ),
				'back_to_items' => __( '← Back to Short Links', 'fw' ),
			),
			'public'                => false,
			'publicly_queryable'    => false,
			'show_ui'               => true,
			'show_in_menu'          => false,
			'show_in_nav_menus'     => false,
			'show_tagcloud'         => false,
			'show_in_rest'          => false,
			'show_admin_column'     => false,
			'hierarchical'          => true,
			'rewrite'               => false,
			'query_var'             => false,
			'update_count_callback' => '_update_generic_term_count',
			'capabilities'          => array(
				'manage_terms' => 'manage_short_links',
				'edit_terms'   => 'manage_short_links',
				'delete_terms' => 'manage_short_links',
				'assign_terms' => 'edit_short_links',
			),
		) );
	}

	/**
	 * Keep Unyson+ → Short Links highlighted while editing categories.
	 *
	 * @internal
	 */
	public function _filter_parent_file( $parent ) {
		global $taxonomy;

		return self::TAXONOMY === $taxonomy ? 'fw-extensions' : $parent;
	}

	/**
	 * @internal
	 */
	public function _filter_submenu_file( $submenu ) {
		global $taxonomy;

		return self::TAXONOMY === $taxonomy ? 'fw-short-links' : $submenu;
	}

	/**
	 * @return string
	 */
	public static function manage_url() {
		return admin_url( 'edit-tags.php?taxonomy=' . self::TAXONOMY );
	}

	/**
	 * @return WP_Term[]
	 */
	public static function all() {
		$terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'orderby' => 'name' ) );

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * @param int $link_id
	 *
	 * @return WP_Term[]
	 */
	public static function of( $link_id ) {
		$terms = wp_get_object_terms( (int) $link_id, self::TAXONOMY );

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Category names for many links in one query (for list tables / exports).
	 *
	 * @param int[] $link_ids
	 *
	 * @return array link_id => string[] names
	 */
	public static function names_for( array $link_ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $link_ids ) ) );
		$out = array_fill_keys( $ids, array() );

		if ( ! $ids ) {
			return $out;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT tr.object_id, t.name FROM {$wpdb->term_relationships} tr
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE tr.object_id IN (" . implode( ',', $ids ) . ') ORDER BY t.name',
			self::TAXONOMY
		) );

		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->object_id ][] = $r->name;
		}

		return $out;
	}

	/**
	 * Set a link's categories from term ids and/or names. Unknown names are
	 * created when $create allows it (default: when the user may manage terms),
	 * otherwise ignored.
	 *
	 * @param int       $link_id
	 * @param array     $terms  int ids or string names
	 * @param bool|null $create
	 */
	public static function set( $link_id, array $terms, $create = null ) {
		$create = null === $create ? current_user_can( 'manage_short_links' ) : (bool) $create;
		$ids = array();

		foreach ( $terms as $t ) {
			if ( is_int( $t ) || ctype_digit( (string) $t ) ) {
				$ids[] = (int) $t;
				continue;
			}

			$name = trim( sanitize_text_field( (string) $t ) );

			if ( '' === $name ) {
				continue;
			}

			$existing = get_term_by( 'name', $name, self::TAXONOMY );

			if ( $existing ) {
				$ids[] = (int) $existing->term_id;
			} elseif ( $create ) {
				$new = wp_insert_term( $name, self::TAXONOMY );

				if ( ! is_wp_error( $new ) ) {
					$ids[] = (int) $new['term_id'];
				}
			}
		}

		wp_set_object_terms( (int) $link_id, array_values( array_unique( $ids ) ), self::TAXONOMY );
	}

	/**
	 * @param int[] $link_ids
	 */
	public static function forget( array $link_ids ) {
		foreach ( $link_ids as $id ) {
			wp_delete_object_term_relationships( (int) $id, self::TAXONOMY );
		}
	}

	/**
	 * Link ids in a category (for filtering).
	 *
	 * @param int $term_id
	 *
	 * @return string SQL fragment "id IN (…)" safe to embed, or "1=0"
	 */
	public static function sql_filter( $term_id ) {
		$ids = get_objects_in_term( (int) $term_id, self::TAXONOMY );

		if ( is_wp_error( $ids ) || ! $ids ) {
			return '1=0';
		}

		return 'id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
	}
}
