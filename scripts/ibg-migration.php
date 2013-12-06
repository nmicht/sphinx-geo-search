<?php

/**
 * Script for data migration
 * This have to run in a loop for each post
 * mtorres October 2013
 * 
 * To execute, call the ibg_save_geolocation($postID) method.
 * If you needs debug logs use second parameter as true
 */

 

require_once( '../../../wp-load.php' );
wp();
header('HTTP/1.1 200 OK');
switch_to_blog(2);


$debug_log = FALSE;

function writeDebug($msg){
	$file = 'geo_migration_log.txt';
	file_put_contents($file, $msg, FILE_APPEND | LOCK_EX);
}

/**
 * Save the post meta info and generate the geo_post info
 * @param int $postID
 * @return int $postID
 */
function ibg_save_geolocation( $postID , $logging = FALSE){
	//Getting ready to work
	global $wpdb, $debug_log;
	require_once( ABSPATH . 'ib-tools/geo-tools/common.php');
	
	$debug_log = $logging;
	$date = explode(" ",microtime());
	
	if($debug_log)
		writeDebug(PHP_EOL.PHP_EOL.
				'****************************************************'.
				PHP_EOL.date("Y-m-d H:i:s",$date[1]).': START IB GEO MIGRATION FOR POST '.$postID);

	if(!isset($postID) || !is_int($postID)) {
		if($debug_log)
			writeDebug(PHP_EOL.'IBG-MIG: No post ID');

		error_log('IBG-MIG: No post ID');
		return false;
	}
	
	$post = get_post($postID);
	if($post == null) {
		if($debug_log)
			writeDebug(PHP_EOL.'IBG-MIG: Unable to get post '.$postID.' from WP');
		error_log('IBG-MIG: Unable to get post '.$postID.' from WP');
		return false;
	}

	if($post->post_type != 'listing' && $post->post_type != 'event'){
		if($debug_log)
			writeDebug(PHP_EOL.'IBG-MIG: The post '.$postID.' is not a listing or event');
		error_log('IBG-MIG: The post '.$postID.' is not a listing or event');
		return false;
	}
	$post_type = $post->post_type;
	
	//Get the address
	$meta = get_post_meta($postID);

	if($debug_log)
		writeDebug(PHP_EOL.'The post meta: '.PHP_EOL.print_r($meta,true));
	
	//Verify there is an address
	if(empty($meta['_listing_street'][0]) || empty($meta['_listing_city'][0]) || empty($meta['_listing_state'][0]) || empty($meta['_listing_zip'][0])){
		if($debug_log)
			writeDebug(PHP_EOL.'IBG-MIG: The post '.$postID.' do not have address info');
		error_log('IBG-MIG: The post '.$postID.' do not have address info');
		return false;
	}

	$address = $meta['_listing_street'][0] . ',' . $meta['_listing_city'][0] . ',' . $meta['_listing_state'][0];
	
	//Get geo location
	$location = ibg_get_latlng($address);
	if ( $location === FALSE ){
		if($debug_log)
			writeDebug(PHP_EOL.'IBG-MIG: Unable to get lat,lng,city or nbhd for post '.$postID);
		error_log('IBG-MIG: Unable to get lat,lng,city or nbhd for post '.$postID);
		return false;
	}
	
	//Setting city
	if($debug_log)
		writeDebug(PHP_EOL.'Old city string: '.$meta['_listing_city'][0]);
	if( !isset($location['city']) ){
		//Removing commas from post metadata
		if( strpos($meta['_listing_city'][0],',') !== FALSE ){
			$meta['_listing_city'][0] = str_replace(',', '', trim($meta['_listing_city'][0]));
			if($debug_log)
				writeDebug(PHP_EOL.'Updated city to remove commas in post '.$postID);
		}
		$location['city'] =  $meta['_listing_city'][0];
		if($debug_log)
			writeDebug(PHP_EOL.'IBG-MIG: Unable to get city for post '.$postID.'. Using the city from metadata: '.$location['city']);
	}
	update_post_meta($postID, '_listing_city', $location['city']);
	if($debug_log)
		writeDebug(PHP_EOL.'New city string: '.$location['city']);

	//Generate the iframe url
	$iframe_url = ibg_get_iframe_url($address, $location['lat'], $location['lng']);
	update_post_meta($postID, '_listing_iframe_url', $iframe_url);
	if($debug_log)
		writeDebug(PHP_EOL.'Iframe url generated '.$iframe_url);


	if($debug_log)
		writeDebug(PHP_EOL.'Old neighborhood string: '.$meta['_listing_neighborhood'][0]);
	
	//Update neighborhood

	//Cleaning whitespaces
	$meta['_listing_neighborhood'][0] = trim($meta['_listing_neighborhood'][0]);
	
	//Removing "All"
	if( $meta['_listing_neighborhood'][0] == 'All' ){
		$meta['_listing_neighborhood'][0] = '';
		if($debug_log)
			writeDebug(PHP_EOL.'Cleaning neigborhood to remove "All" in post '.$postID);
	}
	
	//Adding new neighborhood from google
	if( isset($location['neighborhood']) && stripos($meta['_listing_neighborhood'][0] , $location['neighborhood']) === FALSE ){
		if( !empty($meta['_listing_neighborhood'][0]) )
			$meta['_listing_neighborhood'][0] .= ', ';
		$location['neighborhood'] = $meta['_listing_neighborhood'][0].$location['neighborhood'];
		
	}
	else{
		$location['neighborhood'] = $meta['_listing_neighborhood'][0];
	}
		
	update_post_meta($postID, '_listing_neighborhood', $location['neighborhood']);

	if($debug_log)
		writeDebug(PHP_EOL.'New neighborhood string: '.$location['neighborhood']);

	
	//Sphinx PDO
	if( !is_object($ln_sph) ){
		if($debug_log)
			writeDebug(PHP_EOL.print_r($ln_sph->errorInfo(),true).PHP_EOL.'IBG-MIG: There was an error with PDO connection');
		error_log('IBG-MIG: There was an error with PDO connection');
		return false;
	}
	
	//Verify if the post is already in geo_posts
	$result = $wpdb->get_results(
        " SELECT *
          FROM geo_posts
          WHERE post_id={$postID}
          AND post_type={$post_type}"
    ); 
	
	if($debug_log)
		writeDebug(PHP_EOL.'Query to get geo_posts previously: '." SELECT * FROM geo_posts WHERE post_id={$postID}".PHP_EOL.print_r($result,true));
	
	//Save the location data
	if(empty($result)){	
		$query_mysql = "INSERT INTO geo_posts
				(post_id, post_type, lat, lng, zip, nbhd, city)
				VALUES (
					$postID,
					\"{$post_type}\",
					{$location['lat']},
					{$location['lng']},
					{$meta['_listing_zip'][0]},
					\"".addslashes($location['neighborhood'])."\",
					\"".addslashes($location['city'])."\"
				)";
				
		$query_sphinx = "INSERT INTO geo_posts_rt 
						(id, type, zip, nbhd, city, lat, lng)
						VALUES (
						$postID,
						'$post_type',
						'{$meta['_listing_zip'][0]}',
						'".addslashes($location['neighborhood'])."',
						'".addslashes($location['city'])."',					
						".deg2rad($location['lat']).",
						".deg2rad($location['lng'])."
					)";
	}
	else {
		$query_mysql = "UPDATE geo_posts
				SET lat = {$location['lat']}, 
				type = \"$post_type\",
				lng = {$location['lng']},
				zip = {$meta['_listing_zip'][0]},
				nbhd = \"".addslashes($location['neighborhood'])."\",
				city = \"".addslashes($location['city'])."\"
				WHERE post_id= $postID";
				
		$query_sphinx = "REPLACE INTO geo_posts_rt
						(id, type, zip, nbhd, city, lat, lng)
						VALUES (
						$postID,
						'$post_type',
						'{$meta['_listing_zip'][0]}',
						'".addslashes($location['neighborhood'])."',
						'".addslashes($location['city'])."',					
						".deg2rad($location['lat']).",
						".deg2rad($location['lng'])."
					)";
	}
	
	if($debug_log)
		writeDebug(PHP_EOL.'Query for geo_posts: '.$query_mysql.PHP_EOL.'Query for sphinx: '.$query_sphinx);
	
	if ( $wpdb->query($query_mysql) === FALSE || $ln_sph->query($query_sphinx) === FALSE){
		if($debug_log)
			writeDebug(var_export( $wpdb->last_error, true ).
						PHP_EOL.print_r($ln_sph->errorInfo(),true).
						PHP_EOL.'IBG-MIG: Was not able to save the geo_post info for post '.$postID);
		error_log('IBG-MIG: Was not able to save the geo_post info for post '.$postID);
		return false;
	}
	
	if($debug_log)
		writeDebug(PHP_EOL.'END IB GEO MIGRATION FOR POST '.$postID);
	
	return true;
}


ibg_save_geolocation((int)$_GET['id'],true);