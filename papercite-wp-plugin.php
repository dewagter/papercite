<?php

/**
 * Plugin Name: Papercite
 * Plugin URI: http://www.bpiwowar.net/papercite
 * Description: papercite enables to add BibTeX entries formatted as HTML in wordpress pages and posts. The input data is the bibtex text file and the output is HTML. This fork adds the feature of textual footnotes, besides the references stored in bibtex files.
 * Version: 0.6.0
 * Author: Benjamin Piwowarski
 * Author URI: http://www.bpiwowar.net
 * Author: digfish
 * Author URI: http://digfish.org
 * License: GPL3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: papercite
 * Domain Path: /languages
 *
 * @package papercite
 */

// isolate papercite class in their own class file, keeping only the wordpress integtation
// in this file
require_once( "wp-papercite.php" );

// Options


include( "papercite_options.php" );


// -------------------- Interface with WordPress


// --- Head of the HTML ----
function papercite_head() {

	if ( ! function_exists( 'wp_enqueue_script' ) ) {
		// In case there is no wp_enqueue_script function (WP < 2.6), we load the javascript ourselves
		echo "\n" . '<script src="' . get_bloginfo( 'wpurl' ) . '/wp-content/plugins/papercite/js/jquery.js"  type="text/javascript"></script>' . "\n";
		echo '<script src="' . get_bloginfo( 'wpurl' ) . '/wp-content/plugins/papercite/js/papercite.js"  type="text/javascript"></script>' . "\n";
	}
}

// --- Initialise papercite ---
function papercite_init() {
	global $papercite;

	if ( function_exists( 'wp_enqueue_script' ) ) {
		wp_register_script( 'papercite', plugins_url( 'papercite/js/papercite.js' ), array( 'jquery' ) );
		wp_enqueue_script( 'papercite' );

		// register de scripts for the post editor
		if ( is_admin() ) {
			wp_register_script( 'papercite-post-editor', plugins_url( 'papercite/js/ppc_post_editor.js' ), array( 'wp-blocks' ) );
			wp_enqueue_script( 'papercite-post-editor' );
		}
	}

	// Register and enqueue the stylesheet
	wp_register_style( 'papercite_css', plugins_url( 'papercite/papercite.css' ) );
	wp_enqueue_style( 'papercite_css' );

	wp_register_style( 'ppc-post-editor-css', plugins_url( 'papercite/ppc_post_editor.css' ) );
	wp_enqueue_style( 'ppc-post-editor-css' );

	// Initialise the object
	$papercite = new WpPapercite();
}

// --- Callback function ----
/**
 * @param $myContent
 *
 * @return mixed|string|string[]|null
 */
function &papercite_cb( $myContent ) {
	// Init
	$papercite = &$GLOBALS["papercite"];

	// Fixes issue #39 (maintenance mode support)
	if ( ! is_object( $papercite ) ) {
		return $myContent;
	}

	$papercite->init();

	// Database support if needed
	if ( $papercite->options["use_db"] ) {
		require_once( dirname( __FILE__ ) . "/papercite_db.php" );
	}

	// (0) Skip processing on this page?
	if ( $papercite->options['skip_for_post_lists'] && ! is_single() && ! is_page() ) {
//        return preg_replace("/\[\s*((?:\/)bibshow|bibshow|bibcite|bibtex|ppcnote)(?:\s+([^[]+))?]/", '', $myContent);
		$replaced_content = preg_replace( "/\[\s*((?:\/)bibshow|bibshow|bibcite|bibtex)(?:\s+([^[]+))?]/", '', $myContent );
		// remove the shortcodes for textual footnotes in the post list or main page if the user set to do that
		$replaced_content = preg_replace( "/\[ppcnote\](.+?)\[\/ppcnote\]/i", '', $replaced_content );

		return $replaced_content;
	}

	// (1) First phase - handles everything but bibcite keys
	$text = preg_replace_callback(
	//       "/\[\s*((?:\/)bibshow|bibshow|bibcite|bibtex|bibfilter|ppcnote)(?:\s+([^[]+))?]/",
		"/\[\s*((?:\/)bibshow|bibshow|bibcite|bibtex|bibfilter)(?:\s+([^[]+))?]/",
		array( $papercite, "process" ),
		$myContent
	);

	//digfish: textual footnotes
	/*    $note_matches = array();
		$note_matches_count = preg_match_all('/\[ppcnote\](.+?)\[\/ppcnote\]/i',$text,$note_matches);

		if ($note_matches_count !== FALSE) {
			$ft_matches = $note_matches[1];
			foreach ($ft_matches as $match) {
				$papercite->textual_footnotes[] = $match;
			}
		}*/

	$post_id = get_the_ID();

	$text = preg_replace_callback(
		'/\[ppcnote\](.+?)\[\/ppcnote\]/i',
		function ( $match ) use ( $post_id, $papercite ) {
			return $papercite->processTextualFootnotes( $match, $post_id );
		},
		$text
	);

	if ( count( $papercite->getTextualFootnotes() ) > 0 ) {
		$text .= $papercite->showTextualFootnotes( get_the_ID() );
	}

	// digfish: reset the footnotes after the end of post/page
	$papercite->textual_footnotes         = array();
	$papercite->textual_footnotes_counter = 0;


	// (2) Handles missing bibshow tags
	while ( sizeof( $papercite->bibshows ) > 0 ) {
		$text .= $papercite->end_bibshow();
	}

	// (3) Handles custom keys in bibshow and return
	$text = str_replace( $papercite->keys, $papercite->keyValues, $text );

	return $text;
}


// --- Add the documentation link in the plugin list
function papercite_row_cb( $data, $file ) {
	if ( $file == "papercite/papercite-wp-plugin.php" ) {
		$data[] = "<a href='" . WP_PLUGIN_URL . "/papercite/documentation/index.html'>Documentation</a>";
	}

	return $data;
}

add_filter( 'plugin_row_meta', 'papercite_row_cb', 1, 2 );

// --- Add
function papercite_mime_types( $mime_types ) {
	// Adjust the $mime_types, which is an associative array where the key is extension and value is mime type.
	$mime_types['bib'] = 'application/x-bibtex'; // Adding bibtex

	return $mime_types;
}

add_filter( 'upload_mimes', 'papercite_mime_types', 1, 1 );


// digfish --- add the sidebard (metabox) to the post edit with the bibliography items

function papercite_render_metabox() {
	global $papercite;
	require_once WP_PLUGIN_DIR . "/papercite/lib/BibTex_pear.php";
	if ( empty( $papercite->options ) ) {
		$papercite->init();
	}


	$entries = $papercite->getEntries( $papercite->options );

	array_shift( $entries );
	$entries = array_slice( $entries, 0, 10 );
	//d($entries[0]);
	echo "<UL id='papercite-metabox-content' style=''>";
	foreach ( $entries as $entry ) {
		//var_dump($entry['author']->creators);
		$key     = $entry['cite'];
		$authors = join( ";", array_map( function ( $creator ) {
			return $creator['surname'] . "," . $creator['firstname'];
		}, (array) $entry['author']->creators ) );
		$year    = $entry['year'];
		$title   = $entry['title'];
		echo "<LI class='papercite-metabox-bibentry'><button>[bibcite&nbsp;key=$key]</button>&nbsp;$authors ($year) - $title </LI>";
	}
	echo "</UL>";
}

function papercite_metabox( $post_type ) {
	add_meta_box( 'papercite-metabox', 'Papercite', 'papercite_render_metabox', 'post', 'side', 'default' );

}

add_action( 'add_meta_boxes', 'papercite_metabox' );

// --- Add the different handlers to WordPress ---
add_action( 'init', 'papercite_init' );
add_action( 'wp_head', 'papercite_head' );
add_filter( 'the_content', 'papercite_cb', - 1 );
