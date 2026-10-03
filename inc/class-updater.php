<?php
/**
 * GitHub release based auto-updates.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers updates from GitHub releases and installs them in place.
 */
class AgentSteamer_Lang_Updater {

	/**
	 * GitHub "owner/repo".
	 */
	const REPO = 'mantelgreen/agentsteamer-wp-ai-lang';

	/**
	 * Plugin basename.
	 */
	const BASENAME = 'agentsteamer-wp-ai-lang/agentsteamer-wp-ai-lang.php';

	/**
	 * Plugin folder slug.
	 */
	const SLUG = 'agentsteamer-wp-ai-lang';

	/**
	 * Release cache key.
	 */
	const CACHE = 'agentsteamer_lang_gh_release';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'upgrader_package_options', array( $this, 'allow_overwrite' ), 10, 1 );
	}

	/**
	 * Force an overwrite when (re)installing this plugin from an uploaded ZIP.
	 *
	 * WordPress only overwrites an existing plugin folder when the request opts
	 * in (`overwrite_package`), which a plain upload does not. Enable it here so
	 * uploading a newer ZIP replaces the installed copy instead of erroring out.
	 *
	 * @param array $options Upgrader options.
	 * @return array
	 */
	public function allow_overwrite( $options ) {
		if ( empty( $options['hook_extra']['type'] ) || 'plugin' !== $options['hook_extra']['type'] ) {
			return $options;
		}
		if ( empty( $options['destination'] ) || untrailingslashit( $options['destination'] ) !== untrailingslashit( WP_PLUGIN_DIR ) ) {
			return $options;
		}

		if ( ! empty( $options['hook_extra']['plugin'] ) ) {
			// Regular update: only touch our own plugin.
			if ( self::BASENAME === $options['hook_extra']['plugin'] ) {
				$options['clear_destination'] = true;
			}
			return $options;
		}

		// Fresh upload: inspect the package to confirm it is our plugin.
		if ( ! empty( $options['package'] ) && $this->package_is_ours( $options['package'] ) ) {
			$options['clear_destination'] = true;
		}

		return $options;
	}

	/**
	 * Whether a ZIP package contains this plugin's main file one level deep.
	 *
	 * @param string $package Local package path.
	 * @return bool
	 */
	protected function package_is_ours( $package ) {
		if ( ! is_string( $package ) || ! is_file( $package ) || ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $package ) ) {
			return false;
		}

		$main  = basename( self::BASENAME );
		$match = false;
		$count = $zip->numFiles;
		for ( $i = 0; $i < $count; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( preg_match( '#^[^/]+/' . preg_quote( $main, '#' ) . '$#', $name ) ) {
				$match = true;
				break;
			}
		}
		$zip->close();

		return $match;
	}

	/**
	 * Fetch the latest release (cached 6 hours).
	 *
	 * @param bool $force Force refresh.
	 * @return array|false
	 */
	protected function release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			if ( 'none' === $cached ) {
				return false;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AgentSteamer-Lang/' . AGENTSTEAMER_LANG_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE, 'none', 3 * HOUR_IN_SECONDS );
			return false;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['zipball_url'] ) ) {
			set_transient( self::CACHE, 'none', 3 * HOUR_IN_SECONDS );
			return false;
		}

		$release = array(
			'version'      => ltrim( (string) $data['tag_name'], 'vV' ),
			'url'          => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			'notes'        => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'package'      => (string) $data['zipball_url'],
		);

		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				$name = isset( $asset['name'] ) ? strtolower( (string) $asset['name'] ) : '';
				if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
					$release['package'] = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		set_transient( self::CACHE, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Inject an available update.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = $this->release();
		if ( ! $release || empty( $release['package'] ) ) {
			return $transient;
		}
		if ( version_compare( $release['version'], AGENTSTEAMER_LANG_VERSION, '>' ) ) {
			$obj              = new stdClass();
			$obj->slug        = self::SLUG;
			$obj->plugin      = self::BASENAME;
			$obj->new_version = $release['version'];
			$obj->url         = $release['url'];
			$obj->package     = $release['package'];
			$obj->icons       = array(
				'1x' => AGENTSTEAMER_LANG_URL . '.wordpress-org/icon-128x128.png',
				'2x' => AGENTSTEAMER_LANG_URL . '.wordpress-org/icon-256x256.png',
			);
			$obj->banners     = array();
			$transient->response[ self::BASENAME ] = $obj;
		}
		return $transient;
	}

	/**
	 * "View version details" popup.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = $this->release();
		if ( ! $release ) {
			return $result;
		}
		$obj                = new stdClass();
		$obj->name          = 'AgentSteamer WP AI Lang';
		$obj->slug          = self::SLUG;
		$obj->version       = $release['version'];
		$obj->last_updated  = $release['published_at'];
		$obj->download_link = $release['package'];
		$obj->sections      = array(
			'description' => __( '来自 GitHub Releases 的更新。', 'agentsteamer-lang' ),
			'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ) . '</pre>',
		);
		return $obj;
	}

	/**
	 * Rename the GitHub source folder to the plugin slug.
	 *
	 * @param string      $source        Source path.
	 * @param string      $remote_source Remote source.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $hook_extra    Extra args.
	 * @return string
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return $source;
		}

		// Only act on plugin operations, and never on another plugin's update.
		if ( ! empty( $hook_extra['type'] ) && 'plugin' !== $hook_extra['type'] ) {
			return $source;
		}
		if ( ! empty( $hook_extra['plugin'] ) && self::BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$main_name = basename( self::BASENAME );

		// Locate our main file: directly in the source, or in a single nested folder.
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . $main_name ) ) {
			$files = $wp_filesystem->dirlist( $source );
			if ( is_array( $files ) && 1 === count( $files ) ) {
				$nested = trailingslashit( $source ) . key( $files );
				if ( $wp_filesystem->exists( trailingslashit( $nested ) . $main_name ) ) {
					$source = $nested;
				}
			}
		}
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . $main_name ) ) {
			return $source; // not our plugin.
		}

		// Rename the folder in place so its basename matches the plugin slug.
		// The upgrader derives the destination folder from this basename, so the
		// package installs into (and overwrites) the correct plugin directory.
		$parent  = dirname( untrailingslashit( $source ) );
		$desired = trailingslashit( $parent ) . self::SLUG;
		if ( $source === trailingslashit( $desired ) ) {
			return $source; // already the correct folder.
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}
		if ( $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}
}
