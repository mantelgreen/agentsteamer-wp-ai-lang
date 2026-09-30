<?php
/**
 * Language taxonomy (asl_language) — assigns a language to each content object.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the language taxonomy and per-post language helpers.
 */
class AgentSteamer_Lang_Taxonomy {

	/**
	 * Taxonomy key.
	 */
	const TAX = 'asl_language';

	/**
	 * Per-request cache of post => language code.
	 *
	 * @var array
	 */
	protected static $lang_cache = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register after custom post types (themes/plugins register them on init:10).
		add_action( 'init', array( $this, 'register' ), 20 );
		add_action( 'init', array( $this, 'post_type_hooks' ), 21 );

		add_action( 'admin_menu', array( $this, 'filter_admin_menu' ), 99 );
		add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_action( 'save_post', array( $this, 'on_save' ), 20, 3 );
	}

	/**
	 * Register per-post-type admin columns after all post types are known.
	 */
	public function post_type_hooks() {
		foreach ( agentsteamer_lang_supported_post_types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'column' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		}
	}

	/**
	 * Register the taxonomy.
	 */
	public function register() {
		register_taxonomy(
			self::TAX,
			agentsteamer_lang_supported_post_types(),
			array(
				'labels'            => array(
					'name'          => __( '语言', 'agentsteamer-lang' ),
					'singular_name' => __( '语言', 'agentsteamer-lang' ),
				),
				'public'            => false,
				'show_ui'           => false,
				'show_in_menu'      => false,
				'show_in_nav_menus' => false,
				'show_in_rest'      => true,
				'rest_base'         => 'asl_language',
				'show_admin_column' => false,
				'hierarchical'      => false,
				'query_var'         => false,
				'rewrite'           => false,
				'update_count_callback' => '__return_empty_string',
			)
		);
	}

	/**
	 * Hide the auto-generated taxonomy submenu (managed from our own page).
	 */
	public function filter_admin_menu() {
		remove_submenu_page( 'edit.php', 'edit-tags.php?taxonomy=' . self::TAX );
	}

	/**
	 * Keep rewrite rules fresh when languages change.
	 */
	public function rewrites() {
		// Rewrite registration happens in the router; nothing taxonomy-specific.
	}

	/**
	 * Create/update the term that mirrors a language.
	 *
	 * @param string $code Language code.
	 * @param array  $data Language row.
	 */
	public static function sync_term( $code, $data ) {
		$slug = isset( $data['slug'] ) && '' !== $data['slug'] ? $data['slug'] : strtolower( $code );
		$name = isset( $data['native_name'] ) && '' !== $data['native_name'] ? $data['native_name'] : ( isset( $data['name'] ) ? $data['name'] : $code );

		$term = get_term_by( 'slug', $slug, self::TAX );
		if ( $term ) {
			wp_update_term( $term->term_id, self::TAX, array( 'name' => $name, 'slug' => $slug ) );
			update_term_meta( $term->term_id, '_asl_code', $code );
			return;
		}

		$result = wp_insert_term( $name, self::TAX, array( 'slug' => $slug ) );
		if ( ! is_wp_error( $result ) && isset( $result['term_id'] ) ) {
			update_term_meta( $result['term_id'], '_asl_code', $code );
		}
	}

	/**
	 * Delete a language term.
	 *
	 * @param string $code Language code.
	 */
	public static function delete_term( $code ) {
		$term = self::term_for_code( $code );
		if ( $term ) {
			wp_delete_term( $term->term_id, self::TAX );
		}
	}

	/**
	 * Find the taxonomy term for a language code.
	 *
	 * @param string $code Language code.
	 * @return WP_Term|null
	 */
	public static function term_for_code( $code ) {
		$lang = AgentSteamer_Lang_Languages::get( $code );
		if ( ! $lang ) {
			return null;
		}
		$term = get_term_by( 'slug', $lang['slug'], self::TAX );
		return $term ? $term : null;
	}

	/**
	 * The language code assigned to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty when unassigned.
	 */
	public static function get_post_lang( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( self::$lang_cache[ $post_id ] ) ) {
			return self::$lang_cache[ $post_id ];
		}
		$terms = wp_get_object_terms( $post_id, self::TAX, array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			self::$lang_cache[ $post_id ] = '';
			return '';
		}
		$term = $terms[0];
		$code = get_term_meta( $term->term_id, '_asl_code', true );
		if ( ! $code ) {
			$lang = AgentSteamer_Lang_Languages::get_by_slug( $term->slug );
			$code = $lang ? $lang['code'] : '';
		}
		self::$lang_cache[ $post_id ] = $code;
		return $code;
	}

	/**
	 * Assign a language to a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $code    Language code.
	 */
	public static function set_post_lang( $post_id, $code ) {
		$term = self::term_for_code( $code );
		if ( ! $term ) {
			return;
		}
		wp_set_object_terms( (int) $post_id, array( (int) $term->term_id ), self::TAX, false );
		unset( self::$lang_cache[ (int) $post_id ] );
	}

	/**
	 * Persist the language chosen in the editor.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update flag.
	 */
	public function on_save( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, agentsteamer_lang_supported_post_types(), true ) ) {
			return;
		}
		if ( ! empty( $_POST['asl_language_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asl_language_field'] ) ), 'asl_save_language_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$code = isset( $_POST['asl_language'] ) ? sanitize_text_field( wp_unslash( $_POST['asl_language'] ) ) : '';
			if ( '' !== $code && AgentSteamer_Lang_Languages::get( $code ) ) {
				self::set_post_lang( $post_id, $code );
			}
		}

		// Register/link the translation when a language is present.
		$lang = self::get_post_lang( $post_id );
		if ( '' !== $lang ) {
			AgentSteamer_Lang_Translations::ensure_link( $post_id, $lang );
		}
	}

	/**
	 * Add the language column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function column( $columns ) {
		$columns['asl_language'] = __( '语言', 'agentsteamer-lang' );
		return $columns;
	}

	/**
	 * Render the language column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public function column_content( $column, $post_id ) {
		if ( 'asl_language' !== $column ) {
			return;
		}
		$code = self::get_post_lang( $post_id );
		if ( '' === $code ) {
			echo '<span class="asl-badge">' . esc_html__( '未指定', 'agentsteamer-lang' ) . '</span>';
			return;
		}
		$lang = AgentSteamer_Lang_Languages::get( $code );
		$slug = strtolower( $code );
		if ( $lang ) {
			echo '<span class="asl-badge asl-badge-' . esc_attr( $slug ) . '">' . esc_html( $lang['native_name'] ? $lang['native_name'] : $lang['name'] ) . '</span>';
		} else {
			echo esc_html( $code );
		}
	}

	/**
	 * Language filter dropdown on the post list.
	 *
	 * @param string $post_type Current post type.
	 */
	public function filter_dropdown( $post_type ) {
		if ( ! in_array( $post_type, agentsteamer_lang_supported_post_types(), true ) ) {
			return;
		}
		$current = isset( $_GET['asl_filter_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['asl_filter_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="asl_filter_lang"><option value="">' . esc_html__( '全部语言', 'agentsteamer-lang' ) . '</option>';
		foreach ( AgentSteamer_Lang_Languages::all() as $lang ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $lang['code'] ),
				selected( $current, $lang['code'], false ),
				esc_html( $lang['native_name'] ? $lang['native_name'] : $lang['name'] )
			);
		}
		echo '</select>';
	}

	/**
	 * Apply the language filter to the admin list query.
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_query( $query ) {
		if ( ! $query->is_main_query() ) {
			return;
		}

		// Admin list: apply the requested language filter.
		if ( is_admin() ) {
			$code = isset( $_GET['asl_filter_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['asl_filter_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' === $code ) {
				return;
			}
			$term = self::term_for_code( $code );
			if ( $term ) {
				$query->set(
					'tax_query',
					array(
						array(
							'taxonomy' => self::TAX,
							'field'    => 'term_id',
							'terms'    => array( (int) $term->term_id ),
						),
					)
				);
			}
			return;
		}

		// Front-end archives/search: restrict to the current language.
		if ( ! agentsteamer_lang_is_enabled() ) {
			return;
		}
		if ( $query->is_singular() || $query->is_feed() ) {
			return;
		}
		if ( ! ( $query->is_home() || $query->is_archive() || $query->is_search() ) ) {
			return;
		}

		$current = AgentSteamer_Lang_Router::current();
		if ( '' === $current ) {
			return;
		}
		$term = self::term_for_code( $current );
		if ( ! $term ) {
			return;
		}

		$default = AgentSteamer_Lang_Languages::default_code();
		if ( $current === $default ) {
			// Show default-language posts plus any unassigned posts.
			$all = array();
			foreach ( AgentSteamer_Lang_Languages::all() as $lang ) {
				$t = self::term_for_code( $lang['code'] );
				if ( $t ) {
					$all[] = (int) $t->term_id;
				}
			}
			$query->set(
				'tax_query',
				array(
					array(
						'relation' => 'OR',
						array(
							'taxonomy' => self::TAX,
							'field'    => 'term_id',
							'terms'    => array( (int) $term->term_id ),
						),
						array(
							'taxonomy' => self::TAX,
							'field'    => 'term_id',
							'terms'    => $all,
							'operator' => 'NOT IN',
						),
					),
				)
			);
			return;
		}

		$query->set(
			'tax_query',
			array(
				array(
					'taxonomy' => self::TAX,
					'field'    => 'term_id',
					'terms'    => array( (int) $term->term_id ),
				),
			)
		);
	}
}
