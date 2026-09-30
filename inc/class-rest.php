<?php
/**
 * REST API controller.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers plugin REST routes.
 */
class AgentSteamer_Lang_Rest {

	/**
	 * REST namespace.
	 */
	const NS = 'agentsteamer-lang/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Capability: edit posts.
	 *
	 * @return bool
	 */
	public function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Capability: manage options.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/languages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_languages' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/translations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_translations' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/translations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_translation' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate_post' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/translate-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate_all' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/translation-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'translation_status' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/translate-step',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate_step' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/providers/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_provider' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Create a copied language version (manual).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_translation( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$target  = sanitize_text_field( (string) $request->get_param( 'target' ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_lang_forbidden', __( '无权编辑该文章。', 'agentsteamer-lang' ), array( 'status' => 403 ) );
		}
		$result = AgentSteamer_Lang_Translations::create_translation( $post_id, $target );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['existing'] ) ) {
				$result->add_data( array( 'status' => 409, 'existing' => (int) $data['existing'] ) );
			} else {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}
		return new WP_REST_Response(
			array(
				'post_id'  => $result,
				'edit_url' => get_edit_post_link( $result, 'raw' ),
			),
			201
		);
	}

	/**
	 * AI-translate a post into a target language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function translate_post( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$target  = sanitize_text_field( (string) $request->get_param( 'target' ) );
		$polish  = (bool) $request->get_param( 'polish' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_lang_forbidden', __( '无权编辑该文章。', 'agentsteamer-lang' ), array( 'status' => 403 ) );
		}
		$translator = new AgentSteamer_Lang_Translator();
		$result     = $translator->translate_post( $post_id, $target, array( 'polish' => $polish ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * One-click: translate a post into every other active language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function translate_all( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$source  = sanitize_text_field( (string) $request->get_param( 'source' ) );
		$mode    = (string) $request->get_param( 'mode' );
		$targets = $request->get_param( 'targets' );
		$targets = is_array( $targets ) ? array_map( 'sanitize_text_field', $targets ) : array();
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_lang_forbidden', __( '无权编辑该文章。', 'agentsteamer-lang' ), array( 'status' => 403 ) );
		}

		$translator = new AgentSteamer_Lang_Translator();

		// Default: queue a background job (safe for long docs / multiple languages).
		if ( 'sync' !== $mode ) {
			$result = $translator->queue_job( $post_id, $source, $targets );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}
			return new WP_REST_Response( $result, 202 );
		}

		@set_time_limit( 0 );

		if ( $targets ) {
			$results = array();
			foreach ( $targets as $target ) {
				$one       = $translator->translate_post( $post_id, $target );
				$results[] = is_wp_error( $one )
					? array( 'lang' => $target, 'ok' => false, 'error' => $one->get_error_message() )
					: array( 'lang' => $target, 'ok' => true, 'post_id' => $one['post_id'], 'edit_url' => $one['edit_url'] );
			}
			return new WP_REST_Response(
				array(
					'source'  => AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id ),
					'results' => $results,
				),
				200
			);
		}

		$result = $translator->translate_all( $post_id, $source );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Background translation job status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function translation_status( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_lang_forbidden', __( '无权编辑该文章。', 'agentsteamer-lang' ), array( 'status' => 403 ) );
		}
		$translator = new AgentSteamer_Lang_Translator();
		return new WP_REST_Response( $translator->job_status( $post_id ), 200 );
	}

	/**
	 * Advance a queued translation job by one language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function translate_step( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_lang_forbidden', __( '无权编辑该文章。', 'agentsteamer-lang' ), array( 'status' => 403 ) );
		}
		$translator = new AgentSteamer_Lang_Translator();
		return new WP_REST_Response( $translator->step_job( $post_id ), 200 );
	}

	/**
	 * List languages.
	 *
	 * @return WP_REST_Response
	 */
	public function get_languages() {
		return new WP_REST_Response(
			array(
				'languages' => AgentSteamer_Lang_Languages::all(),
				'default'   => AgentSteamer_Lang_Languages::default_code(),
			),
			200
		);
	}

	/**
	 * Translations of a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_translations( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_lang_forbidden', __( '无权访问该文章。', 'agentsteamer-lang' ), array( 'status' => 403 ) );
		}
		return new WP_REST_Response(
			array(
				'lang'         => AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id ),
				'translations' => AgentSteamer_Lang_Translations::translations( $post_id ),
			),
			200
		);
	}

	/**
	 * Test the configured provider.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_provider() {
		$provider = AgentSteamer_Lang_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			$provider->add_data( array( 'status' => 400 ) );
			return $provider;
		}

		$start  = microtime( true );
		$result = $provider->chat(
			array(
				array(
					'role'    => 'user',
					'content' => '请只回复两个字：正常',
				),
			),
			array( 'max_tokens' => 32, 'temperature' => 0 )
		);
		$elapsed = round( microtime( true ) - $start, 2 );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response(
			array(
				'message' => trim( $result['content'] ),
				'elapsed' => $elapsed,
			),
			200
		);
	}
}
