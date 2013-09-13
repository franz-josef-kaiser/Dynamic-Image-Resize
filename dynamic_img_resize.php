<?php
defined( 'ABSPATH' ) OR exit;
/**
 * Plugin Name: Dynamic Image resize
 * Plugin URI:  http://unserkaiser.com/plugins/dynamic-image-resize/
 * Description: Dynamically resizes images. Enables the <code>[dynamic_image]</code> shortcode, pseudo-TimThumb but creates resized and cropped image files from existing media library entries. Usage: <code>[dynamic_image src="http://example.org/wp-content/uploads/2012/03/image.png" width="100" height="100"]</code>. Also offers a template tag.
 * Version:     1.6
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
 */
class oxoDynamicImageResize
{
	/**
	 * Holds the input attributes
	 * @var array
	 * @access private
	 */
	private $atts = array();

	/**
	 * Holds the HTML MarkUp for height/width Attributes
	 * @var string
	 */
	private $hw_string = '';

	/**
	 * Base URL to prefix for all image files.
	 * @var string
	 */
	private $baseUrl = '';

	/**
	 * The currently processed Attachments ID.
	 * @var int|null
	 */
	private $att_id = null;

	/**
	 * The currently processed Attachment Meta Data Array.
	 * @var array
	 */
	private $att_meta = array();

	/**
	 * Constructor
	 * Adds the shortcode
	 * @param array $atts
	 * @return \oxoDynamicImageResize|\WP_Error
	 */
	public function __construct( $atts )
	{
		if ( ! is_array( $atts ) )
		{
			return new WP_Error(
				'wrong_arg_type',
				__( 'Args need to be an array for the dynamic_image_resize template tag.', 'dyn_textdomain' ),
				__FILE__
			);
		}

		return $this->setAttributes( $atts );
	}

	/**
	 * Set the Attributes
	 * @param $atts
	 * @return void
	 */
	public function setAttributes( $atts )
	{
		$this->atts = $atts;
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
	 * Returns the image HTML or the Error message.
	 * @return string
	 */
	public function __toString()
	{
		return $this->getHTMLOutput();
	}

	/**
	 * Wrapper function that combines the HTML and Error output.
	 * Makes it easier for extending classes to just override the __toString() method.
	 * @return mixed|string
	 */
	public function getHTMLOutput()
	{
		$output = $this->getImage();

		if ( ! is_wp_error( $output ) )
			return $output;

		return $this->getErrorMessage( $output );
	}

	/**
	 * Displays an Error Message if an error was found instead of an Image.
	 * Only displays for logged in users who are allowed to edit posts.
	 * Only is available when WP_DEBUG is set to TRUE.
	 * @param $output \WP_Error
	 * @return string
	 */
	public function getErrorMessage( $output )
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

		// Error output for development
		return "{$output->get_error_message( 'no_attachment' )}: {$output->get_error_data()}";

	}

	/**
	 * Merges the Shortcode Attributes with input Arguments.
	 * @param  array $atts
	 * @return array $atts
	 */
	public function parseAttributes( $atts )
	{
		return shortcode_atts(
			array(
				'src'      => '',
				'width'    => '',
				'height'   => '',
				'classes'  => '',
				'hwmarkup' => 'true',
			),
			$atts,
			// If the shortcode name changes, this arg must align.
			'dynamic_image'
		);
	}

	/**
	 * Sanitize attributes
	 * @param array $atts
	 * @return array
	 */
	public function sanitizeAttributes( $atts )
	{
		// Get rid of eventual leading/trailing white spaces around attributes.
		$atts = array_map( 'trim', $atts );

		return array(
			'src'      => ! filter_var( $atts['src'], FILTER_VALIDATE_INT )
				? esc_url( $atts['src'] )
				: absint( $atts['src'] ),
			'height'   => absint( $atts['height'] ),
			'width'    => absint( $atts['width'] ),
			'classes'  => esc_attr( $atts['classes'] ),
			'hwmarkup' => filter_var( $atts['hwmarkup'], FILTER_VALIDATE_BOOLEAN )
		);
	}

	/**
	 * Sets the $hw_string class var that holds the height/width HTML attribute string.
	 * @param  int $width
	 * @param  int $height
	 * @return void
	 */
	public function setHeightWidthString( $width, $height )
	{
		$this->hw_string = image_hwstring( $width, $height );
	}

	/**
	 * Gets the height/width HTML string.
	 * @return string
	 */
	public function getHeightWidthString()
	{
		return $this->hw_string;
	}

	/**
	 * Builds the image
	 * @uses     image_make_intermediate_size
	 * @internal param array $atts
	 * @return   mixed string/WP Error $html
	 */
	public function getImage()
	{
		$atts = $this->getAttributes();
		$atts = $this->parseAttributes( $atts );
		$atts = $this->sanitizeAttributes( $atts );

		$this->setHeightWidthString(
			$atts['width'],
			$atts['height']
		);
		$hw_string = $this->getHeightWidthString();
		! $atts['hwmarkup'] AND $hw_string = '';

		$needs_resize = true;
		$file = 'No image';
		$error = false;

		// ID as src
		if ( is_int( $atts['src'] ) )
		{
			$att_id = $atts['src'];
			// returns false on failure
			$atts['src'] = wp_get_attachment_url( $att_id );

			// If nothing was found:
			! $atts['src'] AND $error = true;
		}
		// Path as src
		else
		{
			// Let's see if the image belongs to our uploads directory…
			$img_url = substr(
				$atts['src'],
				0,
				strlen( $this->getBaseUrl() )
			);

			// …And if not: just return the image HTML string
			if ( $img_url !== $this->getBaseUrl() )
			{
				return $this->getMarkUp(
					$img_url,
					$hw_string,
					$atts['classes']
				);
			}

			// Prepare file name for DB search.
			$file = str_replace(
				trailingslashit( $this->getBaseUrl() ),
				'',
				$atts['src']
			);
			// Look up the file in the database.
			$att_id = $this->getAttachment( $file );
			// If no attachment record was found: Prepare for an WP_Error.
			! $att_id AND $error = true;
		}

		// Abort if the attachment wasn't found
		if ( $error )
		{
			return new WP_Error(
				'no_attachment',
				__( 'Attachment not found by the dynamic-image shortcode.', 'dyn_textdomain' ),
				$file
			);
		}

		// Look through the attachment meta data for an image that fits our size.
		$meta = wp_get_attachment_metadata( $att_id );
		$this->setAttachmentMeta( $meta );
		foreach( $meta['sizes'] as $size )
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

				// We found an image. Now abort the loop and process it.
				break;
			}
		}

		// If we need resizing
		if ( $needs_resize )
		{
			// and if an image of such size was not found,…
			$attached_file = get_attached_file( $att_id );
			// …we can create one.
			$resized = image_make_intermediate_size(
				$attached_file,
				$atts['width'],
				$atts['height'],
				true
			);

			if (
				// \WP_Error returned from WP_Image_Editor_Imagick, WP_Image_Editor_GD
				// or any other editor that was added using the 'wp_image_editors'-filter.
				! is_wp_error( $resized )
				// FALSE returned from image_make_intermediate_size()
				// when no width/height were provided.
				AND false !== $resized
				)
			{
				// Generate key for new size
				$key = sprintf(
					'resized-%dx%d',
					$atts['width'],
					$atts['height']
				);
				// Push to Meta Data Array
				$meta['sizes'][ $key ] = $resized;

				// Update src for final MarkUp
				$atts['src'] = str_replace(
					basename( $atts['src'] ),
					$resized['file'],
					$atts['src']
				);

				// Let metadata know about our new size.
				wp_update_attachment_metadata( $att_id, $meta );

				// Record in backup sizes, so everything's
				// cleaned up when attachment is deleted.
				$backup_sizes = get_post_meta(
					$att_id,
					'_wp_attachment_backup_sizes',
					true
				);
#
				// If an error occurred, we'll get back FALSE
				// By default it's not a single meta entry, so we
				// should get an array anyway. Unless WP_Cache went off.
				! is_array( $backup_sizes ) AND $backup_sizes = array();

				// Add the new image to the size meta data array.
				$backup_sizes[ $key ] = $resized;

				// Update the meta entry.
				update_post_meta(
					$att_id,
					'_wp_attachment_backup_sizes',
					$backup_sizes
				);
			}
		}

		// Generate the markup and return:
		$html = $this->getMarkUp(
			$atts['src'],
			$hw_string,
			$atts['classes']
		);

	 	return $html;
	}

	/**
	 * Setter for the base URL for the Attachment/Image directory.
	 */
	public function setBaseUrl()
	{
		$uploaddir = wp_upload_dir();
		$this->baseUrl = $uploaddir['baseurl'];
	}

	/**
	 * Get the Base URL
	 * @return string
	 */
	public function getBaseUrl()
	{
		if ( !$this->baseUrl ) $this->setBaseUrl();
		return $this->baseUrl;
	}

	/**
	 * Sets the currently processed Attachment ID.
	 * @param int $id
	 */
	public function setAttachmentID( $id )
	{
		$this->att_id = $id;
	}

	/**
	 * Gets the currently processed Attachment ID.
	 * @return int|null
	 */
	public function getAttachmentID()
	{
		return $this->att_id;
	}

	/**
	 * Sets the currently processed Attachment Meta Data.
	 * Allows extending classes to retrieve the meta data
	 * to display captions, credits, generate MarkUp for
	 * responsive stuff, etc. Sky is the limit.
	 * @param array $data
	 */
	public function setAttachmentMeta( $data )
	{
		$this->att_meta = $data;
	}

	/**
	 * Gets the currently processed Attachment Meta Data Array.
	 * @return array
	 */
	public function getAttachmentMeta()
	{
		return $this->att_meta;
	}

	/**
	 * Query for the file by URl
	 * @param  string $url
	 * @return mixed string/bool $result Attachment or FALSE if nothing was found (needed for error)
	 */
	public function getAttachment( $url )
	{
		global $wpdb;

		$result = $wpdb->get_var( $this->getAttachmentSQL( $url ) );

		// FALSE if no result
		if ( empty( $result ) )
			return false;

		return $result;
	}

	/**
	 * Retrieves the SQL statement that is used to retrieve a single Attachment
	 * @param  string $url
	 * @return string $sql
	 */
	public function getAttachmentSQL( $url )
	{
		global $wpdb;

		$sql = <<<SQL
SELECT post_id
	FROM {$wpdb->postmeta}
	WHERE meta_key = '_wp_attachment_metadata'
	  AND meta_value
	  LIKE %s
	LIMIT 1
SQL;

		return $wpdb->prepare(
			$sql,
			"%".like_escape( $url )."%"
		);
	}

	/**
	 * Builds the markup
	 * @param string $src URl to the image
	 * @param string $hw_string
	 * @param string $classes
	 * @return string $html
	 */
	public function getMarkUp( $src, $hw_string, $classes )
	{
		return sprintf(
			'<img %s %s %s />',
			"{src='$src'}",
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
 * If the shortcode name changes, the third argument
 * for `shortcode_atts()` must change as well.
 */
add_shortcode( 'dynamic_image', 'dynamic_image_resize' );

} // endif;