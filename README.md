GEO Searching service with Sphinx and PHP  
===========

This is a JSON service to return data after a geo searching inside sphinx  

The data is stored in sphinx indexes and we are using geodist function from sphinxQL  
The service will respond with a JSON listing the data including the distance.  

Requirements  
1. A real time index in sphinx with ID, latitude and longitude in radians format  
2. Config your common.php file to set the conecction to sphinx service  

Notes  
1. This service do not have security validation  
