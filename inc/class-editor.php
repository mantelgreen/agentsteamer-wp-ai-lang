<?php
/**
 * Block editor (Gutenberg) integration: language sidebar panel.
 *
 * Mirrors the AgentSteamer SEO/GEO plugin's editor sidebar so both plugins
 * feel consistent on the same site.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the block-editor sidebar and its runtime data.
 */
class AgentSteamer_Lang_Editor {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Languages with their taxonomy term id, for the editor.
	 *
	 * @return array
	 */
	public static function editor_languages() {
		$out = array();
		foreach ( AgentSteamer_Lang_Languages::all( true ) as $lang ) {
			$term = AgentSteamer_Lang_Taxonomy::term_for_code( $lang['code'] );
			$out[] = array(
				'code'   => $lang['code'],
				'name'   => $lang['native_name'] ? $lang['native_name'] : $lang['name'],
				'slug'   => $lang['slug'],
				'flag'   => $lang['flag'],
				'termId' => $term ? (int) $term->term_id : 0,
			);
		}
		return $out;
	}

	/**
	 * Enqueue the sidebar script/style.
	 */
	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->post_type, agentsteamer_lang_supported_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'agentsteamer-lang-editor',
			AGENTSTEAMER_LANG_URL . 'assets/js/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-data', 'wp-core-data', 'wp-element', 'wp-i18n', 'wp-api-fetch' ),
			AGENTSTEAMER_LANG_VERSION,
			true
		);

		wp_enqueue_style(
			'agentsteamer-lang-editor',
			AGENTSTEAMER_LANG_URL . 'assets/css/admin.css',
			array( 'wp-components' ),
			AGENTSTEAMER_LANG_VERSION
		);

		wp_localize_script(
			'agentsteamer-lang-editor',
			'AgentSteamerLangEditor',
			array(
				'restUrl'   => esc_url_raw( rest_url( AgentSteamer_Lang_Rest::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'taxonomy'  => AgentSteamer_Lang_Taxonomy::TAX,
				'default'   => AgentSteamer_Lang_Languages::default_code(),
				'languages' => self::editor_languages(),
				'hasProvider' => AgentSteamer_Lang_Provider_Manager::is_configured(),
				'aiEnabled'   => (bool) agentsteamer_lang_get_option( 'ai_enabled', 1 ),
				'reviewUrl'   => admin_url( 'admin.php?page=agentsteamer-lang-reviews' ),
			)
		);
	}
}
