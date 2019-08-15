<?php
/**
 * Plugin Name: Wordpress Multisite recipe sync
 * Plugin URI: https://github.com/maxvelikodnev/recipe_sync
 * Description: Automatic recipe synchronization between network sites.
 * Version: 1.0
 * Author: Max Velikodnev
 * Author URI: https://github.com/maxvelikodnev
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * ----------------------------------------------------------------------
 * Copyright (C) 2019  Max Velikodnev  (Email: maxvelikodnev@gmail.com)
 * ----------------------------------------------------------------------
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * ----------------------------------------------------------------------
 */

//Default prefix db
$prefix = "wp_";

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';


//add_action( 'trashed_post', 'action_function_name_4403' );
function action_function_name_4403( $post_id ) {
	// action...
	$post = get_post( $post_id );
	if ( $post->post_type == 'recipes' ) {
		//Delete from trash
		delete_sync( $post->ID );
		do_action( 'save_post', $post_id, $post, TRUE );
	}
}

add_filter( 'pre_delete_post', 'recipes_pre_delete', 100, 3 );
function recipes_pre_delete( $delete, $post, $force_delete ) {
	if ( $post->post_type == 'recipes' ) {
		if ( $force_delete ) {
			//Delete from trash
			delete_sync( $post->ID );
		}
	}
}

add_action( 'save_post', 'recipes_save_post', 100, 3 );
function recipes_save_post( $post_ID, $post, $update ) {
	global $prefix, $wpdb;

	if ( $post->post_type == 'recipes' ) {

		$current_blog_id = get_current_blog_id();

		if ( $update ) {
			//this is update
			$post_data = $post;
			$post_data = (array) $post_data;

			unset( $post_data['ID'] );
			unset( $post_data['guid'] );

			$sites = get_sites( [ 'network' => 1, 'limit' => 1000 ] );

			//Delete sync data
			delete_sync( $post_ID );

			if ( $hasImg = has_post_thumbnail( $post_ID ) ) {
				$img_id            = get_post_thumbnail_id( $post_ID );
				$img               = $wpdb->get_row( "SELECT * FROM " . $wpdb->postmeta . " WHERE post_id = '" . $img_id . "' AND meta_key='_wp_attached_file'" );
				$wp_upload_dir     = wp_get_upload_dir();
				$filename_original = $wp_upload_dir['basedir'] . "/" . $img->meta_value;
				$site_url          = get_site_url();
			}

			$metas = $wpdb->get_results( "SELECT * FROM " . $wpdb->postmeta . " WHERE post_id = '" . $post_ID . "'" );

			foreach ( $sites as $site ) {

				if ( intval( $site->blog_id ) === $current_blog_id ) {
					continue;
				}
				switch_to_blog( $site->blog_id );

				$post_id = wp_insert_post( $post_data );

				$wpdb->insert(
					$prefix . "syncs",
					[
						"source_id"      => $post_ID,
						"source_site_id" => $current_blog_id,
						"clone_id"       => $post_id,
						"clone_site_id"  => $site->blog_id,
					],
					[
						"%d",
						"%d",
						"%d",
						"%d",
					] );

				//Meta items
				foreach ( $metas as $meta ) {
					if ( preg_match( "|image|", $meta->meta_key ) && intval( $meta->meta_value ) ) {

						switch_to_blog( $current_blog_id );
						$file_url = wp_get_attachment_url( $meta->meta_value );
						restore_current_blog();

						$attach_id = upload_media_file( $file_url, $post_id );

						update_post_meta( $post_id, $meta->meta_key, $attach_id );
					} else {
						update_post_meta( $post_id, $meta->meta_key, $meta->meta_value );
					}
				}
				//Attach thumbnail
				if ( $hasImg ) {
					$file_url = $wp_upload_dir['baseurl'] . "/" . $img->meta_value;
					$img_id   = upload_media_file( $file_url, $post_id );
					set_post_thumbnail( $post_id, $img_id );
				}

				restore_current_blog();

			}
		}
	}

}


function upload_media_file( $url = '', $post_id = 0 ) {
	global $wpdb;
	$wp_uploaddir = wp_get_upload_dir();

	// Get the path to the uploads directory.
	$tmp      = download_url( $url );
	$tmp_size = filesize( $tmp );

	$file_array = [
		'name'     => basename( $url ), // ex: wp-header-logo.png
		'tmp_name' => $tmp,
		'error'    => 0,
		'size'     => $tmp_size,
	];

	$abs_path = $wp_uploaddir['path'] . "/" . basename( $url );
	if ( file_exists( $abs_path ) && filesize( $abs_path ) == filesize( $tmp ) ) {

		$value = $wp_uploaddir['subdir'] . "/" . basename( $url );
		$value = trim( $value, '/' );
		$img   = $wpdb->get_row( "SELECT post_id FROM " . $wpdb->postmeta . " WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%" . $value . "%'" );

		if ( $img ) {
			@unlink( $tmp );

			return $img->post_id;
		} else {
			@unlink( $abs_path );
		}
	}

	// upload file
	$id = media_handle_sideload( $file_array, $post_id );
	@unlink( $tmp );

	return $id;
}

function delete_sync( $post_ID, $force_delete = FALSE ) {
	global $prefix, $wpdb;


	$posts = $wpdb->get_results( "SELECT * FROM " . $prefix . "syncs WHERE source_id IN (" . $post_ID . ")" );

	foreach ( $posts as $post ) {
		switch_to_blog( $post->clone_site_id );
		wp_delete_post( $post->clone_id, $force_delete );
		restore_current_blog();
	}
	$wpdb->query( "DELETE FROM " . $prefix . "syncs WHERE source_id = '" . $post_ID . "'" );


	$sync = $wpdb->get_row( "SELECT source_id FROM " . $prefix . "syncs WHERE clone_id = '" . $post_ID . "' LIMIT 0,1" );


	$posts = $wpdb->get_results( "SELECT * FROM " . $prefix . "syncs WHERE source_id = '" . $sync->source_id . "'" );
	foreach ( $posts as $post ) {
		switch_to_blog( $post->source_site_id );
		wp_delete_post( $post->source_id, $force_delete );
		restore_current_blog();
	}
	$wpdb->query( "DELETE FROM " . $prefix . "syncs WHERE source_id = '" . $sync->source_id . "'" );
}

if ( ( isset( $_GET['post_status'] ) && isset( $_GET['post_type'] ) ) && ( $_GET['post_status'] == "trash" && $_GET['post_type'] == "recipes" ) ) {
	add_action( 'admin_footer', '_wp_footer_scripts23' );
	function _wp_footer_scripts23() {
		echo '<script>jQuery("#delete_all").hide();</script>';
	}
}