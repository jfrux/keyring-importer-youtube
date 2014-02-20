<?php

/*
Plugin Name: Keyring YouTube Importer
Plugin URI: http://github.com/joshuairl/keyring-importer-youtube
Description: Imports your data from YouTube.
Version: 0.0.1
Author: Joshua F. Rountree
Author URI: http://www.joshuairl.com/
License: GPL2
Depends: Keyring, Keyring Social Importers
*/

function keyring_youtube_enable_importer( $importers ) {
	$importers[] = plugin_dir_path( __FILE__ ) . 'keyring-importer-youtube/keyring-importer-youtube.php';
	
	return $importers;
}

add_filter( 'keyring_importers', 'keyring_youtube_enable_importer' );