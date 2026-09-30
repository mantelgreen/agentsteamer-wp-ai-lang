<?php
/**
 * Editor metabox: assign the post language and manage its language versions.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the language / translations panel to the post editor.
 */
class AgentSteamer_Lang_Metabox {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'admin_post_agentsteamer_lang_create_translation', array( $this, 'handle_create' ) );
	}

	/**
	 * Register the metabox on supported post types.
	 */
	public function register() {
		foreach ( agentsteamer_lang_supported_post_types() as $type ) {
			// Block editor post types use the AgentSteamer sidebar instead.
			if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $type ) ) {
				continue;
			}
			add_meta_box(
				'agentsteamer_lang_box',
				__( '语言与语言稿', 'agentsteamer-lang' ),
				array( $this, 'render' ),
				$type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( $post ) {
		$languages = AgentSteamer_Lang_Languages::all( true );
		$current   = AgentSteamer_Lang_Taxonomy::get_post_lang( $post->ID );
		$default   = AgentSteamer_Lang_Languages::default_code();

		wp_nonce_field( 'asl_save_language_' . $post->ID, 'asl_language_field' );
		?>
		<div class="asl-metabox">
			<?php if ( empty( $languages ) ) : ?>
				<p><?php esc_html_e( '请先在「AgentSteamer Lang → 语言」中添加语言。', 'agentsteamer-lang' ); ?></p>
				<?php
				return;
			endif;
			?>
			<p>
				<label class="asl-label" for="asl_language"><?php esc_html_e( '本文语言', 'agentsteamer-lang' ); ?></label>
				<select id="asl_language" name="asl_language" class="widefat">
					<option value=""><?php esc_html_e( '（未指定）', 'agentsteamer-lang' ); ?></option>
					<?php foreach ( $languages as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $current, $lang['code'] ); ?>>
							<?php echo esc_html( ( $lang['native_name'] ? $lang['native_name'] : $lang['name'] ) . ( $lang['code'] === $default ? ' ★' : '' ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<hr />

			<p class="asl-label"><?php esc_html_e( '语言稿', 'agentsteamer-lang' ); ?></p>
			<?php
			$translations = AgentSteamer_Lang_Translations::translations( $post->ID );
			?>
			<?php if ( empty( $translations ) ) : ?>
				<p class="asl-hint"><?php esc_html_e( '尚未关联任何语言稿。保存本文并指定语言后即可创建翻译。', 'agentsteamer-lang' ); ?></p>
			<?php else : ?>
				<ul class="asl-translations">
					<?php foreach ( $translations as $lang => $t ) : ?>
						<li>
							<span class="asl-flag-code"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
							<?php if ( (int) $t['post_id'] === (int) $post->ID ) : ?>
								<em><?php esc_html_e( '（本文）', 'agentsteamer-lang' ); ?></em>
							<?php else : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $t['post_id'] ) ); ?>"><?php echo esc_html( wp_trim_words( get_the_title( $t['post_id'] ), 6, '…' ) ); ?></a>
								<span class="asl-hint"><?php echo esc_html( $t['status'] ); ?><?php echo $t['ai'] ? ' · AI' : ''; ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<hr />

			<p class="asl-label"><?php esc_html_e( '创建语言稿', 'agentsteamer-lang' ); ?></p>
			<?php
			$existing = array_keys( $translations );
			$missing  = array();
			foreach ( $languages as $lang ) {
				if ( $lang['code'] !== $current && ! in_array( $lang['code'], $existing, true ) ) {
					$missing[] = $lang;
				}
			}
			?>
			<?php if ( empty( $missing ) ) : ?>
				<p class="asl-hint"><?php esc_html_e( '所有语言均已存在。', 'agentsteamer-lang' ); ?></p>
			<?php else : ?>
				<?php foreach ( $missing as $lang ) : ?>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( $this->create_url( $post->ID, $lang['code'] ) ); ?>">
							<?php
							/* translators: %s: language name. */
							echo esc_html( sprintf( __( '复制并创建 %s 语言稿', 'agentsteamer-lang' ), $lang['native_name'] ? $lang['native_name'] : $lang['name'] ) );
							?>
						</a>
					</p>
				<?php endforeach; ?>
				<p class="asl-hint"><?php esc_html_e( '将复制标题与正文为新语言的草稿；AI 翻译将在后续版本提供。', 'agentsteamer-lang' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build the secure "create translation" URL.
	 *
	 * @param int    $post_id Source post.
	 * @param string $target  Target language.
	 * @return string
	 */
	protected function create_url( $post_id, $target ) {
		$url = admin_url( 'admin-post.php?action=agentsteamer_lang_create_translation&source=' . (int) $post_id . '&target=' . urlencode( $target ) );
		return wp_nonce_url( $url, 'agentsteamer_lang_create_translation_' . (int) $post_id . '_' . $target );
	}

	/**
	 * Handle the create-translation action.
	 */
	public function handle_create() {
		$source = isset( $_GET['source'] ) ? (int) $_GET['source'] : 0;
		$target = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';

		if ( ! $source || ! current_user_can( 'edit_post', $source ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		check_admin_referer( 'agentsteamer_lang_create_translation_' . $source . '_' . $target );

		$result = AgentSteamer_Lang_Translations::create_translation( $source, $target );
		if ( is_wp_error( $result ) ) {
			$existing = $result->get_error_data();
			if ( is_array( $existing ) && ! empty( $existing['existing'] ) ) {
				wp_safe_redirect( get_edit_post_link( (int) $existing['existing'], 'raw' ) );
				exit;
			}
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( get_edit_post_link( (int) $result, 'raw' ) );
		exit;
	}
}
