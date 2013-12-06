<?php

/**
 * Get the latitude and longitude from json (google api)
 * @param string $address
 * @return array $location
 */
function ibg_get_latlng( $address ){
	global $debug_log;
	$location = array();
	
	//key=AIzaSyBTKxKqT-JThyt5NuZVkzUTnU-na03jJ6o

	$ibg_base_url = 'http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address=';
	
	$cont = 1;
	do{
		$json = json_decode(file_get_contents($ibg_base_url.urlencode($address)),true);

		if($debug_log){
			writeDebug(PHP_EOL.'Request to google: '.$ibg_base_url.urlencode($address));
			writeDebug(PHP_EOL.'Google response for request '.$cont.': '.PHP_EOL.print_r($json,true));
		}
		
		if($json['status'] == "OVER_QUERY_LIMIT"){
			if($debug_log)
				writeDebug(PHP_EOL.'Waiting for 2 seconds');
			sleep(2);
			$cont++;
		}
	}while($json['status'] == "OVER_QUERY_LIMIT" && $cont <= 3);
	
	if(empty($json) || $json['status'] !== "OK" || empty($json['results'][0]['address_components'])){
		return FALSE;
	}

	$location['lat'] = $json['results'][0]['geometry']['location']['lat'];
	$location['lng'] = $json['results'][0]['geometry']['location']['lng'];
	
	foreach ($json['results'][0]['address_components'] as $component) {
		if( in_array('neighborhood',$component['types']) )
			$location['neighborhood'] = $component['long_name'];
		if( in_array('locality',$component['types']) )
			$location['city'] = $component['long_name'];
	}
	
	if($debug_log)
		writeDebug(PHP_EOL.'The latitude, longitude, city and neighborhood '.PHP_EOL.print_r($location,true));
	
	return $location;
}

/**
 * Generate the url for the iframe map
 * @param string $address
 * @param float $lat
 * @param float $lng
 * @return string $url
 */
function ibg_get_iframe_url($address, $lat, $lng){
	$url = 'https://maps.google.com/maps?'.
			'f=q&'.
			'source=s_q&'.
			'hl=es-419&'.
			'geocode=&'.
			'q='.urlencode($address).'&'.
			'aq=&'.
			'sll='.$lat.','.$lng.'&'.
			'ie=UTF8&'.
			'hq=&'.
			'hnear='.urlencode($address).'&'.
			't=m&'.
			'z=14&'.
			'll='.$lat.','.$lng.'&'.
			'output=embed';
	return $url;
}

//Sphinx PDO
try{
	$ln_sph = new PDO( "mysql:host=inhabitatsphinx;port=9306;charset=utf8;protocol=tcp" , '', '', array(
    PDO::ATTR_PERSISTENT => true
));

} catch (PDOException $e) {
    error_log("geo-tools: " . $e->getMessage());
}
?>