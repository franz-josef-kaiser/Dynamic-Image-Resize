<?php
defined( 'ABSPATH' ) OR exit;
/**
 * Plugin Name: Dynamic Image resize
 * Plugin URI:  http://unserkaiser.com/plugins/dynamic-image-resize/
 * Description: Dynamically resizes images. Enables the <code>[dynamic_image]</code> shortcode, pseudo-TimThumb but creates resized and cropped image files from existing media library entries. Usage: <code>[dynamic_image src="http://example.org/wp-content/uploads/2012/03/image.png" width="100" height="100"]</code>. Also offers a template tag.
 * Version:     0.8
 * Author:      Franz Josef Kaiser <http://unserkaiser.com/contact/>
 * Author URI:  http://unserkaiser.com
 * License:     MIT
 */


if ( ! class_exists( 'oxoDynamicImageResize' ) )
{

/**
 * @author Franz Josef Kaiser
 * @link http://unserkaiser.com
 * @license MIT
 * @since 0.2
 */
class oxoDynamicImageResize
{
	/**
	 * Holds the input attributes
	 * @var array
	 * @access protected
	 */
	protected $atts = array();

	/**
	 * Constructor
	 * Adds the shortcode
	 *
	 * @since 0.2
	 * @param array $atts
	 * @return \oxoDynamicImageResize|\WP_Error
	 */
	public function __construct( $atts )
	{
		if ( ! is_array( $atts ) )
		{
			return new WP_Error(
				'wrong_arg_type',
				__( 'Arguments need to be an array.', 'dyn_textdomain' ),
				__FILE__
			);
		}

		return $this->setAttributes( $atts );
	}

	/**
	 * Set the Attributes
	 * @param $attributes
	 */
	public function setAttributes( $attributes )
	{
		$this->atts = $attributes;
	}

	/**
	 * Get the Attributes
	 * @return array
	 */
	public function getAttributes()
	{
		return $this->atts;
	}

	/**
	 * Returns the image
	 * @since 0.2
	 * @return string $output Image Html Mark-Up or Error (Guest/Subscriber get an empty string)
	 */
	public function __toString()
	{
		$output = $this->getImage();

		if ( ! is_wp_error( $output ) )
			return $output;

		// No error message for Guests or Subscribers
		// Assuming that no one has activated caching plugins when debugging
		// and not set WP_DEBUG to TRUE on a live site
		if (
			! is_user_logged_in()
			AND ! current_user_can( 'edit_posts' )
			AND ( ! defined( 'WP_DEBUG' ) OR ! WP_DEBUG )
		)
			return '';

		// Error output for development
		return "{$output->get_error_message( 'no_attachment' )}: {$output->get_error_data()}";
	}

	/**
	 * Sanitize attributes
	 * @since 0.5
	 * @param array $atts
	 * @return array $atts
	 */
	public function sanitizeAttributes( $atts )
	{
		// Get rid of eventual leading/trailing white spaces around atts
		$atts = array_map(
			'trim',
			$atts
		);

		# >>>> Sanitize
		$atts['src']     = is_string( $atts['src'] )
			? esc_url( $atts['src'] )
			: absint( $atts['src'] )
		;
		$atts['height']  = absint( $atts['height'] );
		$atts['width']   = absint( $atts['width'] );
		$atts['classes'] = esc_attr( $atts['classes'] );
		# <<<<

		return $atts;
	}

	public function parseAttributes( $atts )
	{
		return shortcode_atts(
			array(
				'src'     => '',
				'width'   => '',
				'height'  => '',
				'classes' => '',
			),
			$atts,
			'dynamic_image'
		);
	}

	/**
	 * Builds the image
	 * @since    0.1
	 * @uses     image_make_intermediate_size
	 * @internal param array $atts
	 * @return   mixed string/WP Error $html
	 */
	public function getImage()
	{
		$atts = $this->getAttributes();
		$atts = $this->parseAttributes( $atts );
		$atts = $this->sanitizeAttributes( $atts );

		$hw_string = image_hwstring(
			$atts['width'],
			$atts['height']
		);
		$needs_resize = true;
		$file = 'No image';
		$error = false;
		// ID as src
		if ( ! is_string( $atts['src'] ) )
		{
			$att_id = $atts['src'];
			// returns false on failure
			$atts['src'] = wp_get_attachment_url( $atts['src'] );

			// If nothing was found:
			! $atts['src'] AND $error = true;
		}
		// Path as src
		else
		{
			$upload_dir = wp_upload_dir();
			$base_url   = $upload_dir['baseurl'];

			// Let's see if the image belongs to our uploads directory...
			$img_url = substr(
				$atts['src'],
				0,
				strlen( $base_url )
			);
			// ...And if not: just return the image HTML string
			if ( $img_url !== $base_url )
			{
				return $this->getMarkup(
					$img_url,
					$hw_string,
					$atts['classes']
				);
			}

			// Look up the file in the database.
			$file = str_replace(
				trailingslashit( $base_url ),
				'',
				$atts['src']
			);
			$att_id = $this->getAttachment( $file );

			// If no attachment record was found:
			! $att_id AND $error = true;
		}

		// Abort if the attachment wasn't found
		if ( $error )
		{
			# @TODO Error handling with proper message
			# @TODO Needs a test case
			# remove $file in favor of $error_msg
			/*
			$data = get_plugin_data( __FILE__ );
			$error_msg = "Plugin: {$data['Name']}: Version {$data['Version']}";
			*/

			# @TODO In case, we got an ID, but found no image:
			# if ( ! $atts['src'] ) $file = $att_id;

			return new WP_Error(
				'no_attachment',
				__( 'Attachment not found.', 'dyn_textdomain' ),
				$file
			);
		}

		// Look through the attachment meta data for an image that fits our size.
		$meta = wp_get_attachment_metadata( $att_id );
		foreach( $meta['sizes'] as $key => $size )
		{
			if (
				$atts['width'] === $size['width']
				AND $atts['height'] === $size['height']
				)
			{
				$atts['src'] = str_replace(
					basename( $atts['src'] ),
					$size['file'],
					$atts['src']
				);
				$needs_resize = false;
				break;
			}
		}

		// If an image of such size was not found, ...
		if ( $needs_resize )
		{
			$attached_file = get_attached_file( $att_id );
			// ...we can create one.
			$resized = image_make_intermediate_size(
				$attached_file,
				$atts['width'],
				$atts['height'],
				true
			);

			if ( ! is_wp_error( $resized ) )
			{
				// Let metadata know about our new size.
				$key = sprintf(
					'resized-%dx%d',
					$atts['width'],
					$atts['height']
				);
				$meta['sizes'][ $key ] = $resized;
				$atts['src'] = str_replace(
					basename( $atts['src'] ),
					$resized['file'],
					$atts['src']
				);

				wp_update_attachment_metadata( $att_id, $meta );

				// Record in backup sizes, so everything's
				// cleaned up when attachment is deleted.
				$backup_sizes = get_post_meta(
					$att_id,
					'_wp_attachment_backup_sizes',
					true
				);

				! is_array( $backup_sizes ) AND $backup_sizes = array();

				$backup_sizes[ $key ] = $resized;

				update_post_meta(
					$att_id,
					'_wp_attachment_backup_sizes',
					$backup_sizes
				);
			}
		}

		// Generate the markup and return:
		$html = $this->getMarkup(
			$atts['src'],
			$hw_string,
			$atts['classes']
		);

	 	return $html;
	}

	/**
	 * Query for the file by URl
	 * @since 0.2
	 * @param string $file
	 * @return mixed string/bool $result Attachment or FALSE if nothing was found (needed for error)
	 */
	public function getAttachment( $file )
	{
		global $wpdb;

		$file   = like_escape( $file );
		$result = $wpdb->get_var( $wpdb->prepare(
			"
				SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attachment_metadata'
				  AND meta_value
				  LIKE %s
				LIMIT 1;
			",
			"%{$file}%"
		) );

		// FALSE if no result
		if ( empty( $result ) )
			return false;

		return $result;
	}

	/**
	 * Builds the markup
	 * @since 0.2
	 * @param string $src URl to the image
	 * @param string $hw_string
	 * @param string $classes
	 * @return string $html
	 */
	public function getMarkup( $src, $hw_string, $classes )
	{
		return sprintf(
			'<img src="%s" %s %s />',
			$src,
			$hw_string,
			! empty( $classes ) ? "class='{$classes}'" : ''
		);
	}
} // END Class oxoDynamicImageResize


/***********************************
 *          PUBLIC API
 ***********************************/


/**
 * Retrieve a dynamically/on-the-fly resized image
 * @since 0.2
 * @param array $atts Attributes: src(URi/ID), width, height, classes
 * @return mixed string/html $html Image mark up
 */
function dynamic_image_resize( $atts )
{
	return new oxoDynamicImageResize( $atts );
}


/**
 * Add a short code named [dynamic_image]
 * Use the same attributes as for the class
 */
add_shortcode( 'dynamic_image', 'dynamic_image_resize' );

} // endif;