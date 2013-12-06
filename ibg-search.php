<?php
// Allow from any origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");         

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
}
	
if(isset($_POST) && !empty($_POST)){
	require_once 'common.php';
	
	if( !is_object($ln_sph) ){
		header("HTTP/1.0 404 Not Found");
		die(json_encode(array(404 => 'There was an error with Sphinx connection')));
	}
	
	//Common variables
	$available_distances = array(5,10,20,50);
	$from = 'geo_posts_rt';
	$geodist = '';
	$where = array();
	$docs = array();
	$posts = array();
	$order = '';
	$offset = 0;
	$size_results = 20;	
	

	//Receiving data from AJAX request
	$post_type		= isset($_POST['post_type']) ? trim($_POST['post_type']) : 'listing';
	$zipcode        = isset($_POST['zipcode']) ? trim($_POST['zipcode']) : NULL;
	$neighborhood   = isset($_POST['neighborhood']) ? trim($_POST['neighborhood']) : NULL;
	$city   		= isset($_POST['city']) ? trim($_POST['city']) : NULL;
	$maxdistance    = isset($_POST['distance']) ? trim($_POST['distance']) : NULL;
	$offset			= isset($_POST['page']) ? trim($_POST['page']) : NULL;

	//Validating the request
	if ( $zipcode != '' && !preg_match("/^\d{1,5}$/",$zipcode) ){
		header("HTTP/1.0 404 Not Found");
		die(json_encode(array(404 => 'Zipcode invalid')));
	}
	
	if ( !in_array($maxdistance,$available_distances) ){
		header("HTTP/1.0 404 Not Found");
		die(json_encode(array(404 => 'Distance invalid')));
	}
	
	//Converting to meters
	$maxdistance *= 1609.34;
	
	//Removing @ and * to avoid weird queries
	$neighborhood = strtr($neighborhood,'@*','  ');
	$city = strtr($city,'@*','  ');
	
	//Setting the offset
	if ( $offset == NULL )
		$offset = 0;
	
	
	if($zipcode != NULL){
		$query = 
				"SELECT lat, lng
				FROM geo_zipcodes
				WHERE
				MATCH(".$ln_sph->quote("@zipcode ".$zipcode).")
				LIMIT 1";
	}
	else if($neighborhood != NULL && $city != NULL){
		$query = 
				"SELECT lat, lng
				FROM geo_neighborhood
				WHERE
				MATCH(".$ln_sph->quote("@neighborhood ".$neighborhood." @city ".$city).")
				LIMIT 1";
	}
	else if($city != NULL && $neighborhood == NULL){
		$query = 
				"SELECT lat, lng
				FROM geo_cities
				WHERE
				MATCH(".$ln_sph->quote("@city ".$city).")
				LIMIT 1";
	}

	if( !isset($query) ){
		header("HTTP/1.0 404 Not Found");
		die(json_encode(array(404 => 'Search not possible with the data')));
	}

	//Getting latitude and longitude
	$results = $ln_sph->query($query,PDO::FETCH_ASSOC);
	if($results === FALSE || $results->rowCount()<=0){
		header("HTTP/1.0 404 Not Found");
		die(json_encode(array(404 => 'There was an error to get the latitude and longitude for the search')));
	}

	foreach($results as $r){
		$center = $r;
	}


	$latitude  = $center['lat'];
	$longitude  = $center['lng'];

	//Match listing or event
	$match[] = "@type ".$post_type;
	
	if($zipcode!='') {
		$match[] = "@zip ".$zipcode;
	}

	if($neighborhood!='') {
		$match[] = "@nbhd ".$neighborhood;
	}

	if($city!='') {
		$match[] = "@city ".$city;
	}

	if ( !empty($match) )
		$where[] = ' MATCH('.$ln_sph->quote(implode(' ',$match)).')';
				
	if($latitude!=0 && $longitude!=0){
		//$geodist = ',  SQRT(69.1*69.1*(lat - '.$latitude.')*(lat - '.$latitude.') + 53*53*(lng - '.$longitude.')*(lng - '.$longitude.')) as distance ';
		$geodist = ', GEODIST('.$latitude.','.$longitude.',lat,lng) as distance ';
		$where[] = ' distance <= '.number_format($maxdistance,1,'.','');
		$order = 'ORDER BY distance ASC';
	}

	$sql = "SELECT *".$geodist.
		   " FROM ".$from.
		   " WHERE ".implode(' AND ',$where).
		   $order .
		   " LIMIT ".$offset.",".$size_results;

	$results = $ln_sph->query($sql);
	if($results === FALSE){
		header("HTTP/1.0 404 Not Found");
		echo $sql;
		die(json_encode(array(404 => 'There was an error in the search')));
	}

	if( $results->rowCount() > 0 ){
		foreach($results as $r){
			$posts[] = $r['id'];
		}

		$docs['posts'] = $posts;
		
		$meta = $ln_sph->query("SHOW META");
		foreach($meta as $m){
			if($m['Variable_name'] == 'total_found'){
				$docs['total'] = $m['Value'];
				break;
			}
		}	
		
		echo json_encode($docs);
	} else{
		echo json_encode(array());
	}

} else {
?>
<form method="POST">
	<label>Zipcode</label>
	<input type="text" name="zipcode" /><br />
	<label>Neighborhood</label>
	<input type="text" name="neighborhood" /><br />
	<label>City</label>
	<input type="text" name="city" /><br />
	<label>Distance</label>
	<input type="text" name="distance" /><br />
	<label>Page</label>
	<input type="text" name="page" /><br />
	<br />
	<br />
	<br />
	<button type="submit">Test</button>
</form>
<?php } ?>