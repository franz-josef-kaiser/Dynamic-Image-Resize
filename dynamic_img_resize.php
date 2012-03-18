<?php
/**
 * Plugin Name: Dynamic Image resize
 * Plugin URI: http://unserkaiser.com/plugins/dynamic-image-resize/
 * Description: Dynamically resizes the image. Enables the [dynamic_image] shortcode, pseudo-TimThumb but creates resized and cropped image files from existing media library entries. Usage: <code>[dynamic_image src="http://example.org/wp-content/uploads/2012/03/image.png" width="100" height="100"]</code> 
 * Version: 0.3
 * Author: Franz Josef Kaiser <http://unserkaiser.com/contact/>
 * Author URI: http://unserkaiser.com
 * License: GNU GPL 2 <https://gist.github.com/1365159>
 */
// Prevent loading this file directly - Busted!
if ( ! class_exists('WP') ) 
{
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}



if ( ! class_exists( 'oxoDynamicImageResize' ) )
{

/**
 * @author Franz Josef Kaiser
 * @link http://unserkaiser.com
 * @license MIT
 * 
 * @since 0.2
 */
class oxoDynamicImageResize
{
	/**
	 * Holds the input attributes
	 * @var array
	 */
	public $atts;


	/**
	 * Constructor
	 * Adds the shortcode
	 * 
	 * @since 0.2
	 * 
	 * @param array $atts
	 * 
	 * @return void
	 */
	public function __construct( $atts )
	{
		$this->atts = $this->sanitize( $atts );
	}


	/**
	 * Returns the image
	 * 
	 * @since 0.2
	 * 
	 * @return string $output Image Html Mark-Up or Error (Guest/Subscriber get an empty string)
	 */
	public function __toString()
	{
		$output = $this->get_image();

		if ( is_wp_error( $output ) )
		{
			// No error message for Guests or Subscribers
			// Assuming that no one has activated caching plugins when debugging
			// and not set WP_DEBUG to TRUE on a live site
			if ( 
				! is_user_logged_in()
				AND ! current_user_can( 'edit_posts' ) 
				AND ( ! defined( 'WP_DEBUG' ) OR ! WP_DEBUG )
			)
				return '';

			return "{$output->get_error_message( 'no_attachment' )}: {$output->get_error_data()}";
		}

		return $output;
	}


	/**
	 * Sanitize attributes
	 * 
	 * @since 0.5
	 * 
	 * @param array $atts
	 * 
	 * @return array $atts
	 */
	public function sanitize( $atts )
	{
		// Get rid of eventual leading/trailing white spaces around atts 
		$atts = array_map( 'trim', $this->atts );

		# >>>> Sanitize
		$atts['src']     = is_string( $atts['src'] ) ? esc_url( $atts['src'] ) : absint( $atts['src'] );
		$atts['height']  = absint( $atts['height'] );
		$atts['width']   = absint( $atts['width'] );
		$atts['classes'] = esc_attr( $atts['classes'] );
		# <<<<

		return $atts;
	}


	/**
	 * Builds the image
	 * 
	 * @since 0.1
	 * 
 	 * @uses image_make_intermediate_size
	 * 
	 * @param array $atts
	 * 
	 * @return mixed string/WP Error $html
	 */
	public function get_image() 
	{
		// parse atts
		extract( shortcode_atts( 
			 array(
				 'src'     => ''
				,'width'   => ''
				,'height'  => ''
				,'classes' => ''
			 )
			,$this->atts 
		), EXTR_SKIP );

		$hw_string = image_hwstring( $width, $height );

		$needs_resize = true;

		// ID as src
		if ( ! is_string( $src ) )
		{
			$attachment_id = $src;
			$src = wp_get_attachment_url( $src );
		}
		// Path as src
		else
		{
			$upload_dir = wp_upload_dir();
			$base_url   = $upload_dir['baseurl'];

			// Let's see if the image belongs to our uploads directory.
			// And if not: just return the image html string
			$img_url = substr( $src, 0, strlen( $base_url ) );
			if ( $img_url !== $base_url )
				return $this->get_markup( $img_url, $hw_string, $classes );

			// Look the file up in the database.
			$file = str_replace( trailingslashit( $base_url ), '', $src );
			$attachment_id = $this->get_attachment( $file );

			// If an attachment record was not found:
			if ( ! $attachment_id )
				return new WP_Error( 'no_attachment', __( 'Attachment not found.' ), $file );
		}

		// Look through the attachment meta data for an image that fits our size.
		$meta = wp_get_attachment_metadata( $attachment_id );
		foreach( $meta['sizes'] as $key => $size ) 
		{
			if ( 
				$width === $size['width'] 
				AND $height === $size['height'] 
				) 
			{
				$src = str_replace( basename( $src ), $size['file'], $src );
				$needs_resize = false;
				break;
			}
		}

		// If an image of such size was not found, we can create one.
		if ( $needs_resize ) 
		{
			$attached_file = get_attached_file( $attachment_id );
			$resized       = image_make_intermediate_size( $attached_file, $width, $height, true );

			if ( ! is_wp_error( $resized ) ) 
			{	
				// Let metadata know about our new size.
				$key = sprintf( 'resized-%dx%d', $width, $height );
				$meta['sizes'][ $key ] = $resized;
				$src = str_replace( basename( $src ), $resized['file'], $src );

				wp_update_attachment_metadata( $attachment_id, $meta );

				// Record in backup sizes so everything's cleaned up when attachment is deleted.
				$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

				if ( ! is_array( $backup_sizes ) ) 
					$backup_sizes = array();

				$backup_sizes[ $key ] = $resized;

				update_post_meta( $attachment_id, '_wp_attachment_backup_sizes', $backup_sizes );
			}
		}

		// Generate the markup and return:
		$html = $this->get_markup( $src, $hw_string, $classes );

	 	return $html;
	}


	/**
	 * Query for the file by URl
	 * 
	 * @since 0.2
	 * 
	 * @param string $file
	 * 
	 * @return string $attachment
	 */
	public function get_attachment( $file )
	{
		global $wpdb;

		$file = like_escape( $file );
		return $wpdb->get_var( $wpdb->prepare( 
			 "
				SELECT post_id 
				FROM {$wpdb->postmeta} 
				WHERE meta_key = '_wp_attachment_metadata' 
					AND meta_value 
					LIKE %s 
				LIMIT 1;
			 "
			,"%{$file}%" 
		) );
	}


	/**
	 * Builds the markup
	 * 
	 * @since 0.2
	 * 
	 * @param string $src URl to the image
	 * @param string $hw_string
	 * @param string $classes
	 * 
	 * @return string $html
	 */
	public function get_markup( $src, $hw_string, $classes )
	{
		return sprintf( 
			 '<img src="%s" %s %s />'
			,$src
			,$hw_string
			,"class='{$classes}'"
		);
	}
} // END Class oxoDynamicImageResize


/***********************************
 *          PUBLIC API
 ***********************************/


/**
 * Retrieve a dynamically/on-the-fly resized image
 * 
 * @since 0.2
 * 
 * @param array $atts Attributes: src(URi/ID), width, height, classes
 * 
 * @return mixed string/html $html Image mark up
 */
function dynamic_image_resize( $atts )
{
	return new oxoDynamicImageResize( $atts );
}


// Add a short code named [dynamic image]
add_shortcode( 'dynamic_image', 'dynamic_image_resize' );


} // endif;