<?php
/**
 * WP-CLI commands.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `wp agentsteamer-lang` commands.
 */
class AgentSteamer_Lang_CLI {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'agentsteamer-lang', $this );
		}
	}

	/**
	 * List configured languages.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agentsteamer-lang lang-list
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function lang_list( $args, $assoc_args ) {
		$languages = AgentSteamer_Lang_Languages::all();
		if ( empty( $languages ) ) {
			WP_CLI::warning( '尚未配置语言。' );
			return;
		}
		$default = AgentSteamer_Lang_Languages::default_code();
		$rows    = array();
		foreach ( $languages as $lang ) {
			$rows[] = array(
				'code'    => $lang['code'],
				'name'    => $lang['native_name'] ? $lang['native_name'] : $lang['name'],
				'slug'    => $lang['slug'],
				'locale'  => $lang['locale'],
				'active'  => $lang['active'] ? 'yes' : 'no',
				'default' => ( $lang['code'] === $default ) ? 'yes' : '',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'code', 'name', 'slug', 'locale', 'active', 'default' ) );
	}

	/**
	 * Add or update a language.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code, e.g. en or zh-CN.
	 *
	 * [--name=<name>]
	 * : Display name.
	 *
	 * [--slug=<slug>]
	 * : URL segment.
	 *
	 * [--locale=<locale>]
	 * : WordPress locale.
	 *
	 * [--dir=<dir>]
	 * : ltr or rtl.
	 *
	 * [--default]
	 * : Mark as the default language.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agentsteamer-lang lang-add en --name=English --slug=en --locale=en_US
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function lang_add( $args, $assoc_args ) {
		$code = isset( $args[0] ) ? $args[0] : '';
		if ( '' === $code ) {
			WP_CLI::error( '请提供语言代码。' );
		}
		$data = array(
			'code'        => $code,
			'name'        => isset( $assoc_args['name'] ) ? $assoc_args['name'] : $code,
			'native_name' => isset( $assoc_args['name'] ) ? $assoc_args['name'] : $code,
			'slug'        => isset( $assoc_args['slug'] ) ? $assoc_args['slug'] : '',
			'locale'      => isset( $assoc_args['locale'] ) ? $assoc_args['locale'] : '',
			'dir'         => isset( $assoc_args['dir'] ) ? $assoc_args['dir'] : 'ltr',
			'active'      => 1,
		);
		$result = AgentSteamer_Lang_Languages::save( $data );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( isset( $assoc_args['default'] ) ) {
			AgentSteamer_Lang_Languages::set_default( $code );
		}
		WP_CLI::success( '已保存语言 ' . $code );
	}

	/**
	 * Delete a language.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agentsteamer-lang lang-delete fr
	 *
	 * @param array $args Args.
	 */
	public function lang_delete( $args ) {
		$code = isset( $args[0] ) ? $args[0] : '';
		if ( ! AgentSteamer_Lang_Languages::delete( $code ) ) {
			WP_CLI::error( '语言不存在：' . $code );
		}
		WP_CLI::success( '已删除语言 ' . $code );
	}
}
