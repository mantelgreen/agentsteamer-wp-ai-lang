<?php
/**
 * Admin menus, pages and assets.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the admin experience.
 */
class AgentSteamer_Lang_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'menu_icon_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . AGENTSTEAMER_LANG_BASENAME, array( $this, 'action_links' ) );

		add_action( 'admin_post_agentsteamer_lang_save_language', array( $this, 'handle_save_language' ) );
		add_action( 'admin_post_agentsteamer_lang_delete_language', array( $this, 'handle_delete_language' ) );
		add_action( 'admin_post_agentsteamer_lang_set_default', array( $this, 'handle_set_default' ) );
		add_action( 'admin_post_agentsteamer_lang_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_agentsteamer_lang_save_glossary', array( $this, 'handle_save_glossary' ) );
		add_action( 'admin_post_agentsteamer_lang_delete_glossary', array( $this, 'handle_delete_glossary' ) );
	}

	/**
	 * Register admin menus.
	 */
	public function menu() {
		add_menu_page(
			__( 'AgentSteamer Lang', 'agentsteamer-lang' ),
			__( 'AgentSteamer Lang', 'agentsteamer-lang' ),
			'edit_posts',
			'agentsteamer-lang',
			array( $this, 'render_dashboard' ),
			AGENTSTEAMER_LANG_URL . 'assets/images/menu-icon.png',
			57
		);

		add_submenu_page( 'agentsteamer-lang', __( '翻译总览', 'agentsteamer-lang' ), __( '翻译总览', 'agentsteamer-lang' ), 'edit_posts', 'agentsteamer-lang', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'agentsteamer-lang', __( '语言', 'agentsteamer-lang' ), __( '语言', 'agentsteamer-lang' ), 'manage_options', 'agentsteamer-lang-languages', array( $this, 'render_languages' ) );
		add_submenu_page( 'agentsteamer-lang', __( '术语表', 'agentsteamer-lang' ), __( '术语表', 'agentsteamer-lang' ), 'manage_options', 'agentsteamer-lang-glossary', array( $this, 'render_glossary' ) );
		add_submenu_page( 'agentsteamer-lang', __( '审阅队列', 'agentsteamer-lang' ), __( '审阅队列', 'agentsteamer-lang' ), 'manage_options', 'agentsteamer-lang-reviews', array( $this, 'render_reviews' ) );
		add_submenu_page( 'agentsteamer-lang', __( '设置', 'agentsteamer-lang' ), __( '设置', 'agentsteamer-lang' ), 'manage_options', 'agentsteamer-lang-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * Enqueue the menu-icon stylesheet on every admin screen.
	 */
	public function menu_icon_assets() {
		wp_enqueue_style( 'agentsteamer-lang-menu', AGENTSTEAMER_LANG_URL . 'assets/css/menu.css', array(), AGENTSTEAMER_LANG_VERSION );
	}

	/**
	 * Enqueue admin assets on plugin screens.
	 *
	 * @param string $hook Current screen.
	 */
	public function assets( $hook ) {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_editor = $screen && 'post' === $screen->base;
		if ( false === strpos( $hook, 'agentsteamer-lang' ) && ! $is_editor ) {
			return;
		}
		wp_enqueue_style( 'agentsteamer-lang-admin', AGENTSTEAMER_LANG_URL . 'assets/css/admin.css', array(), AGENTSTEAMER_LANG_VERSION );
		wp_enqueue_script( 'agentsteamer-lang-admin', AGENTSTEAMER_LANG_URL . 'assets/js/admin.js', array( 'wp-api-fetch' ), AGENTSTEAMER_LANG_VERSION, true );
		wp_localize_script(
			'agentsteamer-lang-admin',
			'AgentSteamerLang',
			array(
				'restUrl' => esc_url_raw( rest_url( AgentSteamer_Lang_Rest::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'testing' => __( '测试中…', 'agentsteamer-lang' ),
					'error'   => __( '出错了：', 'agentsteamer-lang' ),
				),
			)
		);
	}

	/**
	 * Plugin action links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=agentsteamer-lang-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( '设置', 'agentsteamer-lang' ) . '</a>' );
		return $links;
	}

	/**
	 * Translation overview.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$languages = AgentSteamer_Lang_Languages::all();
		$stats     = AgentSteamer_Lang_Translations::stats();
		$coverage  = AgentSteamer_Lang_Translations::coverage();
		$review    = new AgentSteamer_Lang_Review();

		$published_total  = 0;
		$translated_total = 0;
		foreach ( $coverage as $row ) {
			$published_total  += (int) $row['published'];
			$translated_total += (int) $row['translated'];
		}
		?>
		<div class="wrap asl-wrap">
			<h1><?php esc_html_e( '翻译总览', 'agentsteamer-lang' ); ?></h1>
			<p class="asl-sub"><?php esc_html_e( '多语言状态一览。', 'agentsteamer-lang' ); ?></p>

			<div class="asl-banner">
				<span class="asl-banner-emblem"><img src="<?php echo esc_url( AGENTSTEAMER_LANG_URL . 'assets/images/logo.png' ); ?>" alt="" width="46" height="46" /></span>
				<div class="asl-banner-body">
					<p class="asl-banner-title"><?php esc_html_e( '模釜智能体平台', 'agentsteamer-lang' ); ?></p>
					<p class="asl-banner-sub"><?php esc_html_e( '适用于电商、广告、PPT 制作、办公、数字员工等场景的企业级 AI 智能体，支持私有化部署、数据不出域。', 'agentsteamer-lang' ); ?></p>
				</div>
				<div class="asl-banner-price">
					<span class="asl-price-lead"><?php esc_html_e( '低至', 'agentsteamer-lang' ); ?></span>
					<span class="asl-price-main">39<span class="asl-price-unit"><?php esc_html_e( '元/席/月', 'agentsteamer-lang' ); ?></span></span>
				</div>
				<a class="asl-banner-cta button" href="https://www.agentsteamer.com" target="_blank" rel="noopener"><?php esc_html_e( '访问模釜官网', 'agentsteamer-lang' ); ?></a>
			</div>

			<div class="asl-cards">
				<div class="asl-card asl-stat">
					<span class="asl-stat-num"><?php echo esc_html( count( $languages ) ); ?></span>
					<span class="asl-stat-label"><?php esc_html_e( '已配置语言', 'agentsteamer-lang' ); ?></span>
				</div>
				<div class="asl-card asl-stat">
					<span class="asl-stat-num"><?php echo esc_html( $translated_total ); ?></span>
					<span class="asl-stat-label"><?php esc_html_e( '已翻译内容', 'agentsteamer-lang' ); ?></span>
				</div>
				<div class="asl-card asl-stat">
					<span class="asl-stat-num"><?php echo esc_html( $published_total ); ?></span>
					<span class="asl-stat-label"><?php esc_html_e( '已发布内容', 'agentsteamer-lang' ); ?></span>
				</div>
				<div class="asl-card asl-stat">
					<span class="asl-stat-num"><?php echo esc_html( $review->count_pending() ); ?></span>
					<span class="asl-stat-label"><?php esc_html_e( '待审翻译', 'agentsteamer-lang' ); ?></span>
				</div>
			</div>

			<?php if ( empty( $languages ) ) : ?>
				<div class="asl-card">
					<p><?php esc_html_e( '还没有配置任何语言。', 'agentsteamer-lang' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-lang-languages' ) ); ?>"><?php esc_html_e( '现在添加', 'agentsteamer-lang' ); ?></a>
					</p>
				</div>
			<?php else : ?>
				<div class="asl-card">
					<h2><?php esc_html_e( '按内容类型（含自定义类型，如知识库）', 'agentsteamer-lang' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( '内容类型', 'agentsteamer-lang' ); ?></th>
								<th><?php esc_html_e( '已发布', 'agentsteamer-lang' ); ?></th>
								<th><?php esc_html_e( '已翻译', 'agentsteamer-lang' ); ?></th>
								<th><?php esc_html_e( '覆盖率', 'agentsteamer-lang' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $coverage as $row ) :
							$as_published  = (int) $row['published'];
							$as_translated = (int) $row['translated'];
							$as_pct        = $as_published > 0 ? (int) round( $as_translated / $as_published * 100 ) : 0;
							?>
							<tr>
								<td><?php echo esc_html( $row['label'] ); ?></td>
								<td><?php echo esc_html( $as_published ); ?></td>
								<td><?php echo esc_html( $as_translated ); ?></td>
								<td><?php echo esc_html( $as_pct . '%' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="asl-hint"><?php esc_html_e( '「已翻译」= 至少包含两种语言的翻译组数量；覆盖率 = 已翻译 / 已发布（仅供参考）。', 'agentsteamer-lang' ); ?></p>
				</div>

				<div class="asl-card">
					<h2><?php esc_html_e( '各语言语言稿数量', 'agentsteamer-lang' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( '语言', 'agentsteamer-lang' ); ?></th><th><?php esc_html_e( '语言稿数', 'agentsteamer-lang' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $languages as $lang ) : ?>
							<tr>
								<td><?php echo esc_html( ( $lang['native_name'] ? $lang['native_name'] : $lang['name'] ) . ' (' . $lang['code'] . ')' ); ?></td>
								<td><?php echo esc_html( isset( $stats['by_lang'][ $lang['code'] ] ) ? $stats['by_lang'][ $lang['code'] ] : 0 ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="asl-hint"><?php esc_html_e( '含源语言语言稿；用于观察各语言的内容规模。', 'agentsteamer-lang' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="asl-card">
				<h2><?php esc_html_e( '前台使用', 'agentsteamer-lang' ); ?></h2>
				<p><?php esc_html_e( '在主题中输出语言切换器：', 'agentsteamer-lang' ); ?></p>
				<pre class="asl-code">&lt;?php agentsteamer_lang_switcher(); ?&gt;</pre>
				<p><?php esc_html_e( '或使用短代码：', 'agentsteamer-lang' ); ?> <code>[agentsteamer_lang_switcher]</code></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Languages management page.
	 */
	public function render_languages() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$languages = AgentSteamer_Lang_Languages::all();
		$edit_code = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$edit      = $edit_code ? AgentSteamer_Lang_Languages::get( $edit_code ) : null;
		$default   = AgentSteamer_Lang_Languages::default_code();
		?>
		<div class="wrap asl-wrap">
			<h1><?php esc_html_e( '语言', 'agentsteamer-lang' ); ?></h1>
			<p class="asl-sub"><?php esc_html_e( '配置站点语言、URL 段与默认语言。', 'agentsteamer-lang' ); ?></p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已保存。', 'agentsteamer-lang' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '已删除。', 'agentsteamer-lang' ); ?></p></div>
			<?php endif; ?>

			<div class="asl-card">
				<h2><?php echo $edit ? esc_html__( '编辑语言', 'agentsteamer-lang' ) : esc_html__( '添加语言', 'agentsteamer-lang' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="agentsteamer_lang_save_language" />
					<?php wp_nonce_field( 'agentsteamer_lang_save_language' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="asl-code"><?php esc_html_e( '语言代码', 'agentsteamer-lang' ); ?></label></th>
							<td>
								<input type="text" id="asl-code" name="code" class="regular-text" value="<?php echo esc_attr( $edit ? $edit['code'] : '' ); ?>" placeholder="en / zh-CN / ja" required <?php echo $edit ? 'readonly' : ''; ?> />
								<p class="description"><?php esc_html_e( 'BCP-47 代码，如 en、zh-CN、ja。创建后不可修改。', 'agentsteamer-lang' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-name"><?php esc_html_e( '名称', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="text" id="asl-name" name="name" class="regular-text" value="<?php echo esc_attr( $edit ? $edit['name'] : '' ); ?>" placeholder="English / 简体中文" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-native"><?php esc_html_e( '本地名称', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="text" id="asl-native" name="native_name" class="regular-text" value="<?php echo esc_attr( $edit ? $edit['native_name'] : '' ); ?>" placeholder="English / 简体中文" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-locale"><?php esc_html_e( 'Locale', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="text" id="asl-locale" name="locale" class="regular-text" value="<?php echo esc_attr( $edit ? $edit['locale'] : '' ); ?>" placeholder="en_US / zh_CN" />
							<p class="description"><?php esc_html_e( 'WordPress locale，用于日期格式与翻译。留空使用语言代码。', 'agentsteamer-lang' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-slug"><?php esc_html_e( 'URL 段', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="text" id="asl-slug" name="slug" class="regular-text" value="<?php echo esc_attr( $edit ? $edit['slug'] : '' ); ?>" placeholder="en / zh" />
							<p class="description"><?php esc_html_e( 'URL 中的语言段，如 example.com/en/。留空则使用语言代码的小写形式。', 'agentsteamer-lang' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-flag"><?php esc_html_e( '国旗（可选）', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="text" id="asl-flag" name="flag" class="small-text" value="<?php echo esc_attr( $edit ? $edit['flag'] : '' ); ?>" placeholder="🇬🇧" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-dir"><?php esc_html_e( '文本方向', 'agentsteamer-lang' ); ?></label></th>
							<td>
								<select id="asl-dir" name="dir">
									<option value="ltr" <?php selected( $edit ? $edit['dir'] : 'ltr', 'ltr' ); ?>>LTR</option>
									<option value="rtl" <?php selected( $edit ? $edit['dir'] : 'ltr', 'rtl' ); ?>>RTL</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-date-format"><?php esc_html_e( '日期格式', 'agentsteamer-lang' ); ?></label></th>
							<td>
								<input type="text" id="asl-date-format" name="date_format" class="regular-text" value="<?php echo esc_attr( $edit ? $edit['date_format'] : '' ); ?>" placeholder="<?php echo esc_attr( $edit ? agentsteamer_lang_default_date_format( $edit['code'] ) : 'F j, Y / Y年n月j日' ); ?>" />
								<p class="description"><?php esc_html_e( '留空则按语言自动选择（中/日/韩用「年月日」，其余用英文月份）。', 'agentsteamer-lang' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-time-format"><?php esc_html_e( '时间格式', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="text" id="asl-time-format" name="time_format" class="regular-text" value="<?php echo esc_attr( $edit ? $edit['time_format'] : '' ); ?>" placeholder="<?php echo esc_attr( $edit ? agentsteamer_lang_default_time_format( $edit['code'] ) : 'H:i / g:i a' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '排序', 'agentsteamer-lang' ); ?></th>
							<td><input type="number" name="sort_order" class="small-text" value="<?php echo esc_attr( $edit ? $edit['sort_order'] : 0 ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '启用', 'agentsteamer-lang' ); ?></th>
							<td><label><input type="checkbox" name="active" value="1" <?php checked( $edit ? $edit['active'] : 1, 1 ); ?> /> <?php esc_html_e( '在前台可用', 'agentsteamer-lang' ); ?></label></td>
						</tr>
					</table>
					<p class="asl-actions">
						<button type="submit" class="button button-primary"><?php echo $edit ? esc_html__( '保存修改', 'agentsteamer-lang' ) : esc_html__( '添加语言', 'agentsteamer-lang' ); ?></button>
						<?php if ( $edit ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-lang-languages' ) ); ?>"><?php esc_html_e( '取消', 'agentsteamer-lang' ); ?></a>
						<?php endif; ?>
					</p>
				</form>
			</div>

			<div class="asl-card">
				<h2><?php esc_html_e( '已配置语言', 'agentsteamer-lang' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( '语言', 'agentsteamer-lang' ); ?></th>
							<th><?php esc_html_e( '代码', 'agentsteamer-lang' ); ?></th>
							<th><?php esc_html_e( 'URL 段', 'agentsteamer-lang' ); ?></th>
							<th><?php esc_html_e( 'Locale', 'agentsteamer-lang' ); ?></th>
							<th><?php esc_html_e( '状态', 'agentsteamer-lang' ); ?></th>
							<th><?php esc_html_e( '操作', 'agentsteamer-lang' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $languages ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( '暂无语言。', 'agentsteamer-lang' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $languages as $lang ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $lang['native_name'] ? $lang['native_name'] : $lang['name'] ); ?></strong><?php echo $lang['flag'] ? ' ' . esc_html( $lang['flag'] ) : ''; ?></td>
								<td><code><?php echo esc_html( $lang['code'] ); ?></code></td>
								<td><code><?php echo esc_html( $lang['slug'] ); ?></code></td>
								<td><code><?php echo esc_html( $lang['locale'] ); ?></code></td>
								<td>
									<?php if ( $lang['code'] === $default ) : ?>
										<span class="asl-badge asl-badge-ok"><?php esc_html_e( '默认', 'agentsteamer-lang' ); ?></span>
									<?php endif; ?>
									<?php if ( ! $lang['active'] ) : ?>
										<span class="asl-badge"><?php esc_html_e( '停用', 'agentsteamer-lang' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentsteamer-lang-languages&edit=' . urlencode( $lang['code'] ) ) ); ?>"><?php esc_html_e( '编辑', 'agentsteamer-lang' ); ?></a>
									<?php if ( $lang['code'] !== $default ) : ?>
										| <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_lang_set_default&code=' . urlencode( $lang['code'] ) ), 'agentsteamer_lang_set_default_' . $lang['code'] ) ); ?>"><?php esc_html_e( '设为默认', 'agentsteamer-lang' ); ?></a>
									<?php endif; ?>
									| <a class="asl-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_lang_delete_language&code=' . urlencode( $lang['code'] ) ), 'agentsteamer_lang_delete_language_' . $lang['code'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( '确定删除该语言？已关联的语言稿不会删除，但会失去语言标记。', 'agentsteamer-lang' ) ); ?>');"><?php esc_html_e( '删除', 'agentsteamer-lang' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Glossary page.
	 */
	public function render_glossary() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$entries = AgentSteamer_Lang_Glossary::all();
		?>
		<div class="wrap asl-wrap">
			<h1><?php esc_html_e( '术语表', 'agentsteamer-lang' ); ?></h1>
			<p class="asl-sub"><?php esc_html_e( '统一 AI 翻译的固定译法；目标留空表示该词不翻译。', 'agentsteamer-lang' ); ?></p>

			<div class="asl-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asl-inline-form">
					<input type="hidden" name="action" value="agentsteamer_lang_save_glossary" />
					<?php wp_nonce_field( 'agentsteamer_lang_save_glossary' ); ?>
					<input type="text" name="source" placeholder="<?php esc_attr_e( '源词', 'agentsteamer-lang' ); ?>" required />
					<input type="text" name="target" placeholder="<?php esc_attr_e( '译法（留空=不翻译）', 'agentsteamer-lang' ); ?>" />
					<select name="lang">
						<option value=""><?php esc_html_e( '所有语言', 'agentsteamer-lang' ); ?></option>
						<?php foreach ( AgentSteamer_Lang_Languages::all() as $lang ) : ?>
							<option value="<?php echo esc_attr( $lang['code'] ); ?>"><?php echo esc_html( $lang['native_name'] ? $lang['native_name'] : $lang['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary"><?php esc_html_e( '添加', 'agentsteamer-lang' ); ?></button>
				</form>
			</div>

			<div class="asl-card">
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( '源词', 'agentsteamer-lang' ); ?></th><th><?php esc_html_e( '译法', 'agentsteamer-lang' ); ?></th><th><?php esc_html_e( '语言', 'agentsteamer-lang' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( '暂无术语。', 'agentsteamer-lang' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['source'] ); ?></td>
								<td><?php echo '' === $entry['target'] ? '<em>' . esc_html__( '不翻译', 'agentsteamer-lang' ) . '</em>' : esc_html( $entry['target'] ); ?></td>
								<td><?php echo esc_html( $entry['lang'] ? $entry['lang'] : __( '所有', 'agentsteamer-lang' ) ); ?></td>
								<td><a class="asl-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentsteamer_lang_delete_glossary&id=' . (int) $entry['id'] ), 'agentsteamer_lang_delete_glossary_' . (int) $entry['id'] ) ); ?>"><?php esc_html_e( '删除', 'agentsteamer-lang' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Review queue page (delegated).
	 */
	public function render_reviews() {
		$review = new AgentSteamer_Lang_Review();
		$review->render();
	}

	/**
	 * Settings page.
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$s = agentsteamer_lang_get_settings();
		?>
		<div class="wrap asl-wrap">
			<h1><?php esc_html_e( 'AgentSteamer Lang · 设置', 'agentsteamer-lang' ); ?></h1>
			<p class="asl-sub"><?php esc_html_e( 'URL 与语言检测、大模型接口与提示词。', 'agentsteamer-lang' ); ?> · <code>v<?php echo esc_html( AGENTSTEAMER_LANG_VERSION ); ?></code></p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '设置已保存。', 'agentsteamer-lang' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper asl-tabs">
				<a href="#url" class="nav-tab nav-tab-active" data-tab="url"><?php esc_html_e( 'URL 与检测', 'agentsteamer-lang' ); ?></a>
				<a href="#ai" class="nav-tab" data-tab="ai"><?php esc_html_e( '大模型接口', 'agentsteamer-lang' ); ?></a>
				<a href="#translation" class="nav-tab" data-tab="translation"><?php esc_html_e( '翻译', 'agentsteamer-lang' ); ?></a>
				<a href="#prompts" class="nav-tab" data-tab="prompts"><?php esc_html_e( '提示词', 'agentsteamer-lang' ); ?></a>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="agentsteamer_lang_save_settings" />
				<?php wp_nonce_field( 'agentsteamer_lang_save_settings' ); ?>

				<div class="asl-panel" data-panel="url">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '启用', 'agentsteamer-lang' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_lang_settings[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> /> <?php esc_html_e( '启用前台多语言功能', 'agentsteamer-lang' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'URL 形态', 'agentsteamer-lang' ); ?></th>
							<td>
								<select name="agentsteamer_lang_settings[url_mode]">
									<option value="directory" <?php selected( $s['url_mode'], 'directory' ); ?>><?php esc_html_e( '目录（example.com/en/）', 'agentsteamer-lang' ); ?></option>
									<option value="query" <?php selected( $s['url_mode'], 'query' ); ?>><?php esc_html_e( '查询参数（example.com/?asl_lang=en）', 'agentsteamer-lang' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( '目录形态更利于 SEO；无法使用 rewrite 时可选查询参数。', 'agentsteamer-lang' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '默认语言', 'agentsteamer-lang' ); ?></th>
							<td>
								<label><input type="checkbox" name="agentsteamer_lang_settings[hide_default_prefix]" value="1" <?php checked( $s['hide_default_prefix'], 1 ); ?> /> <?php esc_html_e( '默认语言不显示 URL 前缀', 'agentsteamer-lang' ); ?></label>
								<p class="description"><?php esc_html_e( '默认语言在「语言」页面设置。', 'agentsteamer-lang' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '浏览器语言自动跳转', 'agentsteamer-lang' ); ?></th>
							<td>
								<label><input type="checkbox" name="agentsteamer_lang_settings[auto_redirect]" value="1" <?php checked( $s['auto_redirect'], 1 ); ?> /> <?php esc_html_e( '首次访问时按浏览器语言自动跳转', 'agentsteamer-lang' ); ?></label>
								<p class="description"><?php esc_html_e( '仅在 URL 未指定语言且无访问记录时跳转一次，并记住用户选择。访问者可用 ?no_redirect=1 阻止跳转。', 'agentsteamer-lang' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '检测回退', 'agentsteamer-lang' ); ?></th>
							<td>
								<select name="agentsteamer_lang_settings[detect_fallback]">
									<option value="home" <?php selected( $s['detect_fallback'], 'home' ); ?>><?php esc_html_e( '跳到对应语言首页', 'agentsteamer-lang' ); ?></option>
									<option value="none" <?php selected( $s['detect_fallback'], 'none' ); ?>><?php esc_html_e( '不跳转', 'agentsteamer-lang' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cookie 有效期（天）', 'agentsteamer-lang' ); ?></th>
							<td><input type="number" name="agentsteamer_lang_settings[cookie_ttl]" value="<?php echo esc_attr( $s['cookie_ttl'] ); ?>" min="1" max="3650" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '切换器外观', 'agentsteamer-lang' ); ?></th>
							<td>
								<select name="agentsteamer_lang_settings[switcher_style]">
									<option value="list" <?php selected( $s['switcher_style'], 'list' ); ?>><?php esc_html_e( '列表', 'agentsteamer-lang' ); ?></option>
									<option value="dropdown" <?php selected( $s['switcher_style'], 'dropdown' ); ?>><?php esc_html_e( '下拉', 'agentsteamer-lang' ); ?></option>
								</select>
								<label style="margin-left:12px;"><input type="checkbox" name="agentsteamer_lang_settings[switcher_show_flags]" value="1" <?php checked( $s['switcher_show_flags'], 1 ); ?> /> <?php esc_html_e( '显示国旗', 'agentsteamer-lang' ); ?></label>
							</td>
						</tr>
					</table>
				</div>

				<div class="asl-panel asl-hidden" data-panel="ai">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '启用 AI 翻译', 'agentsteamer-lang' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_lang_settings[ai_enabled]" value="1" <?php checked( $s['ai_enabled'], 1 ); ?> /> <?php esc_html_e( '允许调用大模型翻译', 'agentsteamer-lang' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-provider"><?php esc_html_e( '服务商', 'agentsteamer-lang' ); ?></label></th>
							<td>
								<select id="asl-provider" name="agentsteamer_lang_settings[provider]">
									<?php foreach ( AgentSteamer_Lang_Provider_Manager::presets() as $key => $preset ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['provider'], $key ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-base-url"><?php esc_html_e( '接口地址 Base URL', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="url" id="asl-base-url" name="agentsteamer_lang_settings[base_url]" value="<?php echo esc_attr( $s['base_url'] ); ?>" class="large-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-api-key"><?php esc_html_e( 'API Key', 'agentsteamer-lang' ); ?></label></th>
							<td>
								<input type="password" id="asl-api-key" name="agentsteamer_lang_settings[api_key]" value="" class="large-text" autocomplete="off" placeholder="<?php echo esc_attr( $s['api_key'] ? AgentSteamer_Lang_Settings::mask( $s['api_key'] ) : __( '填写你的 API Key', 'agentsteamer-lang' ) ); ?>" />
								<p class="description"><?php esc_html_e( '留空表示保持现有 Key 不变。密钥仅存储于本站数据库。', 'agentsteamer-lang' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-model"><?php esc_html_e( '模型名', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="text" id="asl-model" name="agentsteamer_lang_settings[model]" value="<?php echo esc_attr( $s['model'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-thinking"><?php esc_html_e( '思考模式', 'agentsteamer-lang' ); ?></label></th>
							<td>
								<select id="asl-thinking" name="agentsteamer_lang_settings[thinking_mode]">
									<option value="auto" <?php selected( $s['thinking_mode'], 'auto' ); ?>><?php esc_html_e( '自动', 'agentsteamer-lang' ); ?></option>
									<option value="on" <?php selected( $s['thinking_mode'], 'on' ); ?>><?php esc_html_e( '关闭思考（加速）', 'agentsteamer-lang' ); ?></option>
									<option value="off" <?php selected( $s['thinking_mode'], 'off' ); ?>><?php esc_html_e( '保留思考', 'agentsteamer-lang' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-temp"><?php esc_html_e( '温度', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="number" id="asl-temp" name="agentsteamer_lang_settings[temperature]" value="<?php echo esc_attr( $s['temperature'] ); ?>" min="0" max="2" step="0.1" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-max-tokens"><?php esc_html_e( 'max_tokens', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="number" id="asl-max-tokens" name="agentsteamer_lang_settings[max_tokens]" value="<?php echo esc_attr( $s['max_tokens'] ); ?>" min="256" max="200000" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-timeout"><?php esc_html_e( '超时（秒）', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="number" id="asl-timeout" name="agentsteamer_lang_settings[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" min="10" max="600" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '连接测试', 'agentsteamer-lang' ); ?></th>
							<td>
								<button type="button" class="button" id="asl-test-provider"><?php esc_html_e( '测试连接', 'agentsteamer-lang' ); ?></button>
								<span class="asl-inline-status" id="asl-test-status" aria-live="polite"></span>
								<p class="description"><?php esc_html_e( '请先保存设置，再测试连接。', 'agentsteamer-lang' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="asl-panel asl-hidden" data-panel="translation">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '自动翻译', 'agentsteamer-lang' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_lang_settings[auto_translate]" value="1" <?php checked( $s['auto_translate'], 1 ); ?> /> <?php esc_html_e( '发布文章时自动生成缺失的目标语言稿（异步）', 'agentsteamer-lang' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '翻译稿状态', 'agentsteamer-lang' ); ?></th>
							<td>
								<select name="agentsteamer_lang_settings[translate_status]">
									<option value="draft" <?php selected( $s['translate_status'], 'draft' ); ?>><?php esc_html_e( '草稿', 'agentsteamer-lang' ); ?></option>
									<option value="publish" <?php selected( $s['translate_status'], 'publish' ); ?>><?php esc_html_e( '直接发布', 'agentsteamer-lang' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '翻译 SEO 字段', 'agentsteamer-lang' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_lang_settings[translate_seo]" value="1" <?php checked( $s['translate_seo'], 1 ); ?> /> <?php esc_html_e( '同时翻译 SEO 标题 / 描述 / 焦点关键词（配合 SEO 插件）', 'agentsteamer-lang' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '翻译后润色', 'agentsteamer-lang' ); ?></th>
							<td><label><input type="checkbox" name="agentsteamer_lang_settings[polish]" value="1" <?php checked( $s['polish'], 1 ); ?> /> <?php esc_html_e( '额外调用一次润色（更自然，成本更高）', 'agentsteamer-lang' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="asl-chunk"><?php esc_html_e( '分块字符数', 'agentsteamer-lang' ); ?></label></th>
							<td><input type="number" id="asl-chunk" name="agentsteamer_lang_settings[chunk_chars]" value="<?php echo esc_attr( $s['chunk_chars'] ); ?>" min="500" max="50000" class="small-text" />
							<p class="description"><?php esc_html_e( '长文按此大小分块翻译后再合并。', 'agentsteamer-lang' ); ?></p></td>
						</tr>
					</table>
				</div>

				<div class="asl-panel asl-hidden" data-panel="prompts">
					<p class="description"><?php esc_html_e( '留空则使用内置默认提示词。', 'agentsteamer-lang' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						$prompts = array(
							'translate'      => __( '正文翻译', 'agentsteamer-lang' ),
							'translate_meta' => __( '元数据翻译（标题/摘要/SEO）', 'agentsteamer-lang' ),
							'polish'         => __( '翻译后润色', 'agentsteamer-lang' ),
						);
						$defaults = agentsteamer_lang_prompt_defaults();
						foreach ( $prompts as $key => $label ) :
							$setting = 'prompt_' . $key;
							?>
							<tr>
								<th scope="row"><label for="asl-<?php echo esc_attr( $setting ); ?>"><?php echo esc_html( $label ); ?></label></th>
								<td>
									<textarea id="asl-<?php echo esc_attr( $setting ); ?>" name="agentsteamer_lang_settings[<?php echo esc_attr( $setting ); ?>]" rows="4" class="large-text code" placeholder="<?php esc_attr_e( '留空使用内置默认', 'agentsteamer-lang' ); ?>"><?php echo esc_textarea( isset( $s[ $setting ] ) ? $s[ $setting ] : '' ); ?></textarea>
									<details><summary class="asl-hint"><?php esc_html_e( '查看内置默认', 'agentsteamer-lang' ); ?></summary><pre class="asl-code"><?php echo esc_html( isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' ); ?></pre></details>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle language save.
	 */
	public function handle_save_language() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		check_admin_referer( 'agentsteamer_lang_save_language' );

		$data = array(
			'code'        => isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '',
			'name'        => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'native_name' => isset( $_POST['native_name'] ) ? wp_unslash( $_POST['native_name'] ) : '',
			'locale'      => isset( $_POST['locale'] ) ? wp_unslash( $_POST['locale'] ) : '',
			'slug'        => isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '',
			'flag'        => isset( $_POST['flag'] ) ? wp_unslash( $_POST['flag'] ) : '',
			'dir'         => isset( $_POST['dir'] ) ? wp_unslash( $_POST['dir'] ) : 'ltr',
			'date_format' => isset( $_POST['date_format'] ) ? wp_unslash( $_POST['date_format'] ) : '',
			'time_format' => isset( $_POST['time_format'] ) ? wp_unslash( $_POST['time_format'] ) : '',
			'sort_order'  => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			'active'      => isset( $_POST['active'] ) ? 1 : 0,
		);

		$result = AgentSteamer_Lang_Languages::save( $data );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-languages&updated=1' ) );
		exit;
	}

	/**
	 * Handle language delete.
	 */
	public function handle_delete_language() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		check_admin_referer( 'agentsteamer_lang_delete_language_' . $code );
		AgentSteamer_Lang_Languages::delete( $code );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-languages&deleted=1' ) );
		exit;
	}

	/**
	 * Handle "set default".
	 */
	public function handle_set_default() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		check_admin_referer( 'agentsteamer_lang_set_default_' . $code );
		AgentSteamer_Lang_Languages::set_default( $code );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-languages&updated=1' ) );
		exit;
	}

	/**
	 * Handle settings save.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		check_admin_referer( 'agentsteamer_lang_save_settings' );

		$input    = isset( $_POST['agentsteamer_lang_settings'] ) ? wp_unslash( $_POST['agentsteamer_lang_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$settings = new AgentSteamer_Lang_Settings();
		update_option( 'agentsteamer_lang_settings', $settings->sanitize( $input ) );

		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-settings&updated=1' ) );
		exit;
	}

	/**
	 * Handle glossary add.
	 */
	public function handle_save_glossary() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		check_admin_referer( 'agentsteamer_lang_save_glossary' );
		$source = isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : '';
		$target = isset( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : '';
		$lang   = isset( $_POST['lang'] ) ? wp_unslash( $_POST['lang'] ) : '';
		if ( '' !== trim( $source ) ) {
			AgentSteamer_Lang_Glossary::add( $source, $target, $lang );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-glossary&updated=1' ) );
		exit;
	}

	/**
	 * Handle glossary delete.
	 */
	public function handle_delete_glossary() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'agentsteamer_lang_delete_glossary_' . $id );
		AgentSteamer_Lang_Glossary::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=agentsteamer-lang-glossary&deleted=1' ) );
		exit;
	}
}
