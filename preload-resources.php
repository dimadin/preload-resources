<?php

/**
 * The Preload Resources Plugin
 *
 * Add preload link headers for scripts and styles.
 *
 * @package    Preload_Resources
 * @subpackage Main
 */

/**
 * Plugin Name: Preload Resources
 * Plugin URI:  http://blog.milandinic.com/wordpress/plugins/preload-resources/
 * Description: Add preload link headers for scripts and styles.
 * Author:      Milan Dinić
 * Author URI:  http://blog.milandinic.com/
 * Version:     1.0.0-beta-1
 * Text Domain: preload-resources
 * Domain Path: /languages/
 * License:     GPL
 */

// Exit if accessed directly
defined( 'ABSPATH' ) or exit;

/*
 * Initialize a plugin.
 *
 * Load class when all plugins are loaded
 * so that other plugins can overwrite it.
 */
add_action( 'plugins_loaded', array( 'Preload_Resources', 'get_instance' ), 10 );

if ( ! class_exists( 'Preload_Resources' ) ) :
/**
 * Preload Resources main class.
 *
 * Add preload link headers for scripts and styles.
 */
class Preload_Resources {
	/**
	 * Maximum allowed header size in bytes.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @var string
	 */
	public $max_header_size;

	/**
	 * Was output buffering started.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @var bool
	 */
	public $ob_started = false;

	/**
	 * Constructor.
	 * 
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {
		// Add preloading hints or start output buffering just before sending headers 
		add_action( 'template_redirect', array( $this, 'start_preloading' ), 777 );
	}

	/**
	 * Initialize Preload_Resources object.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return Preload_Resources $instance Instance of Preload_Resources class.
	 */
	public static function get_instance() {
		static $instance = false;

		if ( false === $instance ) {
			$instance = new self;
		}

		return $instance;
	}

	/**
	 * Start output buffering and add register preloading, or do preloading.
	 *
	 * Also set maximum header size.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function start_preloading() {
		/**
		 * Filter maximum allowed header size in bytes.
		 *
		 * @since 1.0.0
		 *
		 * @param int $size Maximum allowed header size in bytes. Default 3072 (3KB).
		 */
		$this->max_header_size = apply_filters( 'preload_resources_max_header_size', 3 * KB_IN_BYTES );
		
		/**
		 * Filter whether preload hints headers should be sent immediately or after output buffering.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $ob Whether preload hints headers should be sent immediately
		 *                 or after output buffering. Default true.
		 */
		$ob = apply_filters( 'preload_resources_use_ob', true );

		if ( $ob ) {
			/**
			 * Filter hook name where output buffering should end.
			 *
			 * @since 1.0.0
			 *
			 * @param string $hook Hook name where output buffering should end.
			 */
			$hook = (string) apply_filters( 'preload_resources_ob_end_hook', 'wp_head' );

			add_action( $hook, array( $this, 'preload' ), 777 );

			ob_start();

			$this->ob_started = true;
		} else {
			$this->preload();
		}
	}

	/**
	 * Add preload header hints.
	 *
	 * If output buffer was started, close it,
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function preload() {
		// Styles should be preloaded before scripts
		$this->preload_dependencies( wp_styles()  );
		$this->preload_dependencies( wp_scripts() );

		// If output buffer was started, close it
		if ( $this->ob_started ) {
			echo ob_get_clean();
		}
	}

	/**
	 * Add header preload resource hints for dependencies.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param WP_Dependencies $dependencies Object with dependencies.
	 */
	public function preload_dependencies( &$dependencies ) {
		// Get item type
		$type = $this->get_type( $dependencies );

		/**
		 * Filter handles of dependency to add in preload hints headers.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $handles      An array of handles to add in preload hints headers.
		 *                                      Default is array of handles already queued.
	 	 * @param WP_Dependencies $dependencies Object with dependencies.
	 	 */
		$handles = apply_filters( "preload_resources_{$type}_handles", $dependencies->done, $dependencies );

		// Loop through all dependencies that are printed to HTML
		foreach ( $handles as $handle ) {
			// Get dependency for handle
			$item = $dependencies->query( $handle );

			// A single item may not exist or be alias a set of items by having dependencies but no source
			if ( ! $item || ! $item->src ) {
				continue;
			}

			// Check if current item is conditional
			if ( isset( $item->extra['conditional'] ) ) {
				continue;
			}

			// If the current header size is larger than maximum, stop loop
			if ( ! $this->is_header_size_allowed() ) {
				break;
			}

			// Get item source URL
			$src = $this->generate_src( $item, $dependencies );

			// Check if there is a source URL
			if ( ! $src ) {
				continue;
			}

			// Add header
			header(
				sprintf(
					'Link: <%s>; rel=preload; as=%s',
					esc_url_raw( $src ),
					$type
				),
				false
			);
		}
	}

	/**
	 * Check if the current header size is larger than maximum allowed.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return bool $status Is current header size larger than maximum allowed.
	 */
	public function is_header_size_allowed() {
		// Get current header as a string
		$current_header_as_string = implode( '  ', headers_list() );

		// +2 comes from the last CRLF since it's two bytes
		$header_size = strlen( $current_header_as_string ) + 2;

		// If the current header size is larger than maximum, return false
		if ( $header_size > $this->max_header_size ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Generate source URL of dependency.
	 *
	 * There are two parts of this method:
	 * 1) generating source URL as in WordPress core
	 * (since it doesn't have it's own function, same behavior is replicated);
	 * 2) shortening source URL to be shortest possible.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @param _WP_Dependency  $item         An object whose URL should be generated.
	 * @param WP_Dependencies $dependencies Object with dependencies.
	 * @return string $src Source URL of $item.
	 */
	protected function generate_src( &$item, &$dependencies ) {
		if ( null === $item->ver ) {
			$ver = '';
		} else {
			$ver = $item->ver ? $item->ver : $dependencies->default_version;
		}

		if ( isset( $dependencies->args[ $handle ] ) ) {
			$ver = $ver ? $ver . '&amp;' . $dependencies->args[ $handle ] : $dependencies->args[ $handle ];
		}

		$src = $item->src;

		if ( ! preg_match( '|^(https?:)?//|', $src ) && ! ( $dependencies->content_url && 0 === strpos( $src, $dependencies->content_url ) ) ) {
			$src = $dependencies->base_url . $src;
		}

		if ( ! empty( $ver ) ) {
			$src = add_query_arg( 'ver', $ver, $src );
		}

		// Get item type based on object class
		$type = $this->get_type( $dependencies );

		// Apply filters as used and documented in WordPress core
		$src = apply_filters( "{$type}_loader_src", $src, $item->handle );

		// Make relative path if item is on same hostname as site
		if ( 0 === strpos( $src, $dependencies->base_url ) ) {
			$src = str_replace( $dependencies->base_url, '', $src );
		}

		// Remove protocol if source's one and of current page match
		if ( is_ssl() ) {
			$src = str_replace( 'https://', '//', $src );
		} else {
			$src = str_replace( 'http://', '//', $src );
		}

		return $src;
	}

	/**
	 * Get type of dependencies.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param WP_Dependencies $dependencies Object with dependencies.
	 * @return string $type Type of $dependencies. Default 'script'.
	 */
	protected function get_type( &$dependencies ) {
		if ( $dependencies instanceof WP_Styles ) {
			return 'style';
		} else {
			return 'script';
		}
	}
}
endif;
