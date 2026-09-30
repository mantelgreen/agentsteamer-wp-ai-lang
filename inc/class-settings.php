<?php
/**
 * Settings registration and sanitization.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin settings option.
 */
class AgentSteamer_Lang_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'agentsteamer_lang_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Register the setting.
	 */
	public function register() {
		register_setting(
			'agentsteamer_lang_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => agentsteamer_lang_default_settings(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$existing = agentsteamer_lang_get_settings();
		$input    = is_array( $input ) ? $input : array();
		$out      = $existing;

		$checkboxes = array( 'enabled', 'hide_default_prefix', 'auto_redirect', 'ai_enabled', 'auto_translate', 'translate_seo', 'polish', 'switcher_show_flags' );
		foreach ( $checkboxes as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$enums = array(
			'url_mode'         => array( 'directory', 'query' ),
			'detect_fallback'  => array( 'home', 'none' ),
			'x_default'        => array( 'default', 'none' ),
			'translate_status' => array( 'draft', 'publish' ),
			'thinking_mode'    => array( 'auto', 'on', 'off' ),
			'switcher_style'   => array( 'list', 'dropdown' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}

		$text_fields = array( 'model', 'api_version' );
		foreach ( $text_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}

		if ( isset( $input['base_url'] ) ) {
			$out['base_url'] = esc_url_raw( $input['base_url'] );
		}

		// Keep the API key when the field is left blank (masked form).
		if ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$out['api_key'] = trim( (string) $input['api_key'] );
		}

		$numbers = array(
			'cookie_ttl'  => array( 1, 3650 ),
			'chunk_chars' => array( 500, 50000 ),
			'max_tokens'  => array( 256, 200000 ),
			'timeout'     => array( 10, 600 ),
		);
		foreach ( $numbers as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['temperature'] ) ) {
			$out['temperature'] = max( 0, min( 2, (float) $input['temperature'] ) );
		}

		$providers = array_keys( AgentSteamer_Lang_Provider_Manager::presets() );
		if ( isset( $input['provider'] ) && in_array( $input['provider'], $providers, true ) ) {
			$out['provider'] = $input['provider'];
		}

		$textareas = array( 'prompt_translate', 'prompt_translate_meta', 'prompt_polish' );
		foreach ( $textareas as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_textarea_field( $input[ $key ] );
			}
		}

		// Flush rewrites when the URL scheme changes.
		if ( isset( $input['url_mode'] ) && $input['url_mode'] !== agentsteamer_lang_get_option( 'url_mode' ) ) {
			AgentSteamer_Lang_Plugin::flush_rewrites();
		}

		return $out;
	}

	/**
	 * Mask a secret for display.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		$len    = strlen( $secret );
		if ( $len <= 8 ) {
			return $secret ? str_repeat( '•', max( 4, $len ) ) : '';
		}
		return substr( $secret, 0, 4 ) . str_repeat( '•', 8 ) . substr( $secret, -4 );
	}
}
