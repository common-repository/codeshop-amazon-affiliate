<?php
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\api\DefaultApi;
use Amazon\ProductAdvertisingAPI\v1\ApiException;
use Amazon\ProductAdvertisingAPI\v1\Configuration;

use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsRequest;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsResource;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsRequest;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsResource;

use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\PartnerType;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\ProductAdvertisingAPIClientException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use ApaiIO\Configuration\GenericConfiguration;
use ApaiIO\Operations\Search;
use ApaiIO\Operations\Lookup;
use ApaiIO\ApaiIO;						

class Caaps_Amazon_Ajax_Handler {
	
	private static $initiated             = false;
	public static $search_country         = null;
	public static $search_kword           = null;
	public static $sort_by                = null; //If search category 'All' then no sort paramaters work
	public static $associate_partner_tag  = null;
	public static $search_asin            = null;
	public static $invalid_asins          = array();
	public static $search_type            = null;
	public static $result_page            = 1;
	public static $search_category        = 'All';
	public static $response_group         = array( 'Small', 'OfferFull', 'ItemAttributes', 'Images');
	public static $transient_mode         = true;
	public static $transientexpire_hours  = 24;
	
	public static $searchitem_resources	  = null;
	public static $getitem_resources	  = null;
	
	
	public function __construct() {
		if ( ! self::$initiated ) {
			self::initiate_hooks();
		}				
		
		// Update cache duration as set
		$display_options = get_option('caaps_amazon-product-shop-displayoptions');
		$cachedays       = isset( $display_options['caaps_displayoptions_field_cachedays'] )? intval( $display_options['caaps_displayoptions_field_cachedays'] ) : 0 ;
		if ( isset( $cachedays ) && is_numeric( $cachedays ) ) {
			self::$transientexpire_hours = $cachedays * 24; // Convert days into hours
		}
		
	}
	
	private static function initiate_hooks() {		
		  add_action('admin_enqueue_scripts', array( __CLASS__, 'admin_required_scripts') );	
		  add_action('wp_ajax_caaps_searchby_kword_display', array( __CLASS__, 'searchby_kword_display') );	
		  add_action('wp_ajax_caaps_searchby_asin_display', array( __CLASS__, 'searchby_asin_display') );
		  add_action('wp_ajax_caaps_add_selected_products', array( __CLASS__, 'add_selected_products') );	
		  add_action('wp_ajax_caaps_remove_selected_products', array( __CLASS__, 'remove_selected_products') );	
		  add_action('wp_ajax_caaps_test_api_settings', array( __CLASS__, 'test_api_settings') );	
		  self::$initiated = true;
	}	
			
	public static function admin_required_scripts() {
		// get current admin screen
		global $pagenow;
		$screen = get_current_screen();
		if ( is_object($screen ) ) {
			if ( in_array( $screen->post_type, array( 'amazonproductshop') ) ) {				
			    $loading_img = '<img src="' . esc_url( plugins_url( 'public/images/loader.gif', dirname(__FILE__) ) ) . '" alt="Loading" /> ';
				wp_enqueue_style('caaps_style', plugins_url( '../admin/css/codeshop-styles.css', __FILE__ ) , array(), AMZONPRODUCTSHOP_VERSION, 'all' );
				wp_enqueue_script('caaps_metabox_script', plugins_url( '../admin/js/amazon-product-shop.js', __FILE__ ) , array('jquery' ), AMZONPRODUCTSHOP_VERSION, true);
				// localize script
				$nonce = wp_create_nonce( 'caaps_wpnonce' );
				wp_localize_script(
					'caaps_metabox_script',
					'caaps_metabox_script_obj',
					array(
						'adminajax_url'                  => admin_url('admin-ajax.php'),
						'nonce'                          => $nonce, 
						'current_screenid'               => $screen->id,
						'current_posttype'               => $screen->post_type,
						'current_pagenow'                => $pagenow,
						'added_products_msg'             => __( 'Added products successfully.', 'codeshop-amazon-affiliate'),
						'removed_products_msg'           => __( 'Removed selected products successfully.', 'codeshop-amazon-affiliate'),
						'adding_products_msg'            => __( 'Adding selected products...Please wait.', 'codeshop-amazon-affiliate'),
						'removing_products_msg'          => __( 'Removing selected products...Please wait.', 'codeshop-amazon-affiliate'),
						'no_products_selected_msg'       => __( 'No products selected to add.', 'codeshop-amazon-affiliate'),
						'no_products_removeselected_msg' => __( 'No products selected to remove.', 'codeshop-amazon-affiliate'),
						'product_searching_msg'          => __( 'Searching Products...Please Wait.', 'codeshop-amazon-affiliate'),
						'sort_by'                        => __( 'Sort Results', 'codeshop-amazon-affiliate'),
						'product_searching_loadimage'    =>    $loading_img
					)
				);
			}
		}		
	}
		
	public static function searchby_kword_display() {
		check_ajax_referer( 'caaps_wpnonce', 'security' );
		//self::$search_country = $_POST['search_country'];		
		self::$search_category = ( ! isset ( $_POST['search_category'] ) || empty( $_POST['search_category'] ) )? 'All' : $_POST['search_category'];
		// When search category 'All' then no sorting option available - If sort_by empty then assign 'null' value
		self::$sort_by = ( self::$search_category == 'All' )? null : ( empty( $_POST['sort_by'] )? null : trim( $_POST['sort_by'] ) );
		self::$search_kword = $_POST['search_kword'];
		self::$search_type = 'itemsearch';
		self::$result_page = intval($_POST['result_page']);
		if ( empty( self::$search_kword ) ) {
			printf( '<div class="notice notice-warning"><h4>' . __('Search Keyword Required. Please input your search text / keyword to get results.', 'codeshop-amazon-affiliate') . '</h4></div>' );
		}
		else {        	
			// Set transient mode search
			if ( isset( self::$transient_mode ) && self::$transient_mode ) {
				$processed_responses = self::amazonsearch_products_transientmode();	
			}
			// Set non transient mode search
			else {				
				$processed_responses = self::amazonsearch_products();
			}												
			// Display products
			if ( is_array( $processed_responses ) ) {	
			    // Display thickbox search results
			    if ( isset( $_POST['thickbox_search'] ) && $_POST['thickbox_search'] ) {
					include_once AMZONPRODUCTSHOP_PLUGIN_DIR . 'admin/views/caaps_display_response_thickbox_results.php';
				}
				else {
					include_once AMZONPRODUCTSHOP_PLUGIN_DIR . 'admin/views/caaps_display_response_results.php';						
				}
			}
			else {
				_e( $processed_responses, 'codeshop-amazon-affiliate' );
			}							
			
		}
		wp_die();		
	}

	public static function searchby_asin_display() {
		check_ajax_referer( 'caaps_wpnonce', 'security' );
		//self::$search_country = $_POST['search_country'];
		self::$search_type = 'itemlookup';
		$request_asins = $_POST['search_asin'];		
		if ( empty( $request_asins ) ) {
			printf( '<div class="notice notice-warning"><h4>' . __('ASIN(s) Required. Please input your each ASIN per line to get search results.', 'codeshop-amazon-affiliate') . '</h4></div>' );
		}
		else {
			self::$search_asin = preg_split("/[\r\n|\n|\r,\s]+/", $request_asins, -1, PREG_SPLIT_NO_EMPTY );			
			// Set transient mode search
			if ( isset( self::$transient_mode ) && self::$transient_mode ) {
				$processed_responses = self::amazonsearch_products_transientmode();	
			}
			// Set non transient mode search
			else {				
				$processed_responses = self::amazonsearch_products();
			}			
			// Display products
			if ( is_array( $processed_responses ) ) {						
				include_once AMZONPRODUCTSHOP_PLUGIN_DIR . 'admin/views/caaps_display_response_results.php';						
			}
			else {
				_e( $processed_responses, 'codeshop-amazon-affiliate' );
			}				
												
		}	
		wp_die();
	}
	
	public static function initapi_settings() {					       		
		$options 						= get_option('caaps_amazon-product-shop-settings');		
		//$cc = self::$search_country;
		$cc 							= $options['caaps_settings_field_country'];
		$accesskeyid 					= $options['caaps_settings_field_accesskeyid'];
		$secretaccesskey 				= $options['caaps_settings_field_secretaccesskey'];
		$associateid 					= $options['caaps_settings_field_associateid'];
		self::$associate_partner_tag	= $associateid;
		if ( ! isset( $accesskeyid ) || empty( $accesskeyid ) ) {
			wp_die( __('Access Key Required.'), 'codeshop-amazon-affiliate' );
		}
		if ( ! isset( $secretaccesskey ) || empty( $secretaccesskey ) ) {
			wp_die( __('Secret Access Key Required.'), 'codeshop-amazon-affiliate' );
		}
		if ( ! isset( $associateid ) || empty( $associateid ) ) {
			wp_die( __('Associate ID Required.'), 'codeshop-amazon-affiliate' );
		}	
		
/*
		$conf = new GenericConfiguration();
		$conf
			->setCountry($cc)
			->setAccessKey($accesskeyid)
			->setSecretKey($secretaccesskey)
			->setAssociateTag($associateid)
			->setRequest('\ApaiIO\Request\Soap\Request')
			->setResponseTransformer('\ApaiIO\ResponseTransformer\ObjectToArray');				
		$apa_api = new ApaiIO($conf);
*/
		
		// Basic Configurations & Initialization
		$countries			  = Caaps_Amazon_Shop::supported_countries();	
		self::$search_country = $countries[ $cc ];  // get country name from country code
						
		$config      = new Configuration();			
		// Credentials - Access Key			 			 
		$config->setAccessKey( $accesskeyid );	
		// Credentials - Secret key
		$config->setSecretKey( $secretaccesskey );
		/*
		 * PAAPI host and region to which you want to send request
		 * For more details refer: https://webservices.amazon.com/paapi5/documentation/common-request-parameters.html#host-and-region
		 */	

//		$config->setHost('webservices.amazon.es');
//		$config->setRegion('eu-west-1');

		$config->setHost( self::get_host_n_region( 'host' ) );
		$config->setRegion( self::get_host_n_region( 'region' ) );				
		
		// Create Instance    
		$apiInstance = new DefaultApi( new GuzzleHttp\Client(), $config );
		$apa_api 	 = $apiInstance;								
								
		return $apa_api;
	}
	
	/**
	 *
	 * Ref: https://webservices.amazon.com/paapi5/documentation/common-request-parameters.html#host-and-region
	 */
	public static function get_host_n_region( $type = null ) {
		$host			= null;
		$region			= null;
		switch( self::$search_country ) {

			case 'Australia':
				$host	=	'webservices.amazon.com.au';
				$region = 	'us-west-2';
				break;

			case 'Brazil':
				$host	=	'webservices.amazon.com.br';
				$region = 	'us-east-1';
				break;
			case 'Canada':
				$host	=	'webservices.amazon.ca';
				$region = 	'us-east-1';
				break;
			case 'Egypt':
				$host	=	'webservices.amazon.eg';
				$region = 	'eu-west-1';
				break;
			case 'France':
				$host	=	'webservices.amazon.fr';
				$region = 	'eu-west-1';
				break;
			case 'Germany':
				$host	=	'webservices.amazon.de';
				$region = 	'eu-west-1';
				break;
			case 'India':
				$host	=	'webservices.amazon.in';
				$region = 	'eu-west-1';
				break;
			case 'Italy':
				$host	=	'webservices.amazon.it';
				$region = 	'eu-west-1';
				break;
			case 'Japan':
				$host	=	'webservices.amazon.co.jp';
				$region = 	'us-west-2';
				break;
			case 'Mexico':
				$host	=	'webservices.amazon.com.mx';
				$region = 	'us-east-1';
				break;
			case 'Netherlands':
				$host	=	'webservices.amazon.nl';
				$region = 	'eu-west-1';
				break;
			case 'Poland':
				$host	=	'webservices.amazon.pl';
				$region = 	'eu-west-1';
				break;
			case 'Singapore':
				$host	=	'webservices.amazon.sg';
				$region = 	'us-west-2';
				break;
			case 'Saudi Arabia':
				$host	=	'webservices.amazon.sa';
				$region = 	'eu-west-1';
				break;
			case 'Spain':
				$host	=	'webservices.amazon.es';
				$region = 	'eu-west-1';
				break;
			case 'Sweden':
				$host	=	'webservices.amazon.se';
				$region = 	'eu-west-1';
				break;
			case 'Turkey':
				$host	=	'webservices.amazon.com.tr';
				$region = 	'eu-west-1';
				break;
			case 'United Arab Emirates':
				$host	=	'webservices.amazon.ae';
				$region = 	'eu-west-1';
				break;
			case 'United Kingdom':
				$host	=	'webservices.amazon.co.uk';
				$region = 	'eu-west-1';
				break;
			case 'United States':
				$host	=	'webservices.amazon.com';
				$region = 	'us-east-1';
				break;							
		}		
		
		if ( $type == 'host' )   { return $host; }		
		if ( $type == 'region' ) { return $region; }									
		
		
		return;
	}
	
	public static function amazonsearch_products() {		
		$apa_api = self::initapi_settings();	
		$processed_responses = array();					
		switch ( self::$search_type ) {
			case 'itemsearch':
							# Forming the request
							$searchItemsRequest = new SearchItemsRequest();
							$searchItemsRequest->setSearchIndex( self::$search_category );
							$searchItemsRequest->setKeywords( self::$search_kword );		
							$searchItemsRequest->setPartnerTag( self::$associate_partner_tag );
							$searchItemsRequest->setPartnerType( PartnerType::ASSOCIATES );
							$searchItemsRequest->setMerchant( 'All' );
							# If book condition is NOT set to 'New' then more ISBNs info returned during search on homepage
							# In old API we set bookcondition to 'New' but that did not work but working fine on PAAPI 5
							$searchItemsRequest->setCondition( 'New' );  

							$searchItemsRequest->setResources( self::search_item_resources() );

							# Validating request
							$invalidPropertyList = $searchItemsRequest->listInvalidProperties();
							$length              = count($invalidPropertyList);
							if ($length > 0) {
								echo "Error forming the request".  PHP_EOL;
								foreach ($invalidPropertyList as $invalidProperty) {
									echo $invalidProperty.  PHP_EOL;
								}
								//return;
							}

							# Sending the request
							try {
								# Call API Request
								$searchItemsResponse   = $apa_api->searchItems( $searchItemsRequest );
								# Convert Json encoded string into associative array
								$response              = json_decode( $searchItemsResponse, true ); 

								# Parsing the response
								if ( $searchItemsResponse->getSearchResult() != null) {
									$items             = $response['SearchResult']['Items'];
									//echo '<pre>';
									//print_r( $items );
									//echo '</pre>';

									# Parsed each API call found books information
									$processed_responses = self::parse_paapi5_response( $items );						

									// Test per API call processed info
									//echo '<pre>';
									//print_r( $processed_responses );
									//echo '</pre>';

								} // End if ($searchItemsResponse->getSearchResult() != null)
							} catch (ApiException $exception) {
								echo "Error calling PA-API 5.0!".  PHP_EOL;
								echo "HTTP Status Code: ", $exception->getCode().  PHP_EOL;
								echo "Error Message: ", $exception->getMessage().  PHP_EOL;
								if ($exception->getResponseObject() instanceof ProductAdvertisingAPIClientException) {
									$errors = $exception->getResponseObject()->getErrors();
									foreach ($errors as $error) {
										echo "Error Type: ", $error->getCode().  PHP_EOL;
										echo "Error Message: ", $error->getMessage().  PHP_EOL;
									}
								} else {
									echo "Error response body: ", $exception->getResponseBody().  PHP_EOL;
								}
							} catch (Exception $exception) {
								echo "Error Message: ", $exception->getMessage().  PHP_EOL;
							}


							/*
											$search = new Search();
											$search->setCategory( self::$search_category );			
											$search->setKeywords( self::$search_kword );
											$search->setPage( self::$result_page );
											$search->setResponsegroup( self::$response_group );
											$response = $apa_api->runOperation( $search );
											$processed_responses = Caaps_Amazon_Response_Process::process_response( $response );				
							*/
				
			break;
			
			case 'itemlookup':			    
				$asins = array_map( array( __CLASS__, 'validate_asins'), self::$search_asin );				
				// Amazon allows maximum 10 asins per request
				//if ( count( $asins ) > 10 ) {					
					$chunked_asins = array_chunk( $asins, 10 );
					foreach ( $chunked_asins as $chunk_asin ) {																												
							# Store Per API call processed data to cache or use 
							$each_apicall_info  = array();								

							# Forming the request
							$getItemsRequest = new GetItemsRequest();
							//$getItemsRequest->setItemIds($itemIds);
							$getItemsRequest->setItemIds( $chunk_asin );
							$getItemsRequest->setPartnerTag( self::$associate_partner_tag );
							$getItemsRequest->setPartnerType( PartnerType::ASSOCIATES );
							$getItemsRequest->setCondition( 'New' );
							$getItemsRequest->setMerchant( 'All' );	
							$getItemsRequest->setResources( self::get_item_resources() );

							# Validating request
							$invalidPropertyList = $getItemsRequest->listInvalidProperties();
							$length              = count($invalidPropertyList);
							if ($length > 0) {
								echo "Error forming the request", PHP_EOL;
								foreach ($invalidPropertyList as $invalidProperty) {
									echo $invalidProperty, PHP_EOL;
								}
								//return;
							}

							# Sending the request
							try {
								# Call API Request
								$getItemsResponse = $apa_api->getItems( $getItemsRequest );

								/*echo 'API called successfully'. "<br/>";
								//echo 'Complete Response: ', $getItemsResponse . "<br/>";

								echo '<pre>';
								print_r( json_decode( $getItemsResponse, true ) );
								echo '</pre>';
								//exit(0);*/

								# Convert Json encoded string into associative array
								$response         = json_decode( $getItemsResponse, true );

								# Parsing the response
								if ( $getItemsResponse->getItemsResult() != null ) {
									$items        = $response['ItemsResult']['Items'];
									//echo '<pre>';
									//print_r( $items );
									//echo '</pre>';

									# Parsed each API call found books information
									$each_apicall_info = self::parse_paapi5_response( $items );	

									# Merge each API call parsed data with all api found information data
									$processed_responses = array_merge( $processed_responses, $each_apicall_info );

									// Test per API call processed info
									//echo '<pre>';
									//print_r( $each_apicall_info );
									//echo '</pre>';							
								}
								/*if ($getItemsResponse->getErrors() != null) {
									echo PHP_EOL, 'Printing Errors:', PHP_EOL, 'Printing first error object from list of errors', PHP_EOL;
									echo 'Error code: ', $getItemsResponse->getErrors()[0]->getCode(), PHP_EOL;
									echo 'Error message: ', $getItemsResponse->getErrors()[0]->getMessage(), PHP_EOL;
								}*/
							} catch (ApiException $exception) {
								/*echo "Error calling PA-API 5.0!", PHP_EOL;
								echo "HTTP Status Code: ", $exception->getCode(), PHP_EOL;
								echo "Error Message: ", $exception->getMessage(), PHP_EOL;
								if ($exception->getResponseObject() instanceof ProductAdvertisingAPIClientException) {
									$errors = $exception->getResponseObject()->getErrors();
									foreach ($errors as $error) {
										echo "Error Type: ", $error->getCode(), PHP_EOL;
										echo "Error Message: ", $error->getMessage(), PHP_EOL;
									}
								} else {
									echo "Error response body: ", $exception->getResponseBody(), PHP_EOL;
								}*/
							} catch (Exception $exception) {
								//echo "Error Message: ", $exception->getMessage(), PHP_EOL;
							}
						
						
/*
						$chunk_asin = implode( ',', $chunk_asin );
						$lookup = new Lookup();						
						$lookup->setItemId( $chunk_asin );
						$lookup->setResponseGroup( self::$response_group );				
						$response = $apa_api->runOperation( $lookup );				
						$processed_response = Caaps_Amazon_Response_Process::process_response( $response );						
						$processed_responses = array_merge( $processed_responses, $processed_response );
*/
						
					} // foreach loop
				
				//} // if ( count( $asins ) > 10 )
				
/*
				else {
					$asins = implode(',', $asins);
					$lookup = new Lookup();
					$lookup->setItemId( $asins );
					$lookup->setResponseGroup( self::$response_group );				
					$response = $apa_api->runOperation( $lookup );				
					$processed_responses = Caaps_Amazon_Response_Process::process_response( $response );					
				}								
*/
			break;
			
		} // End switch
		return $processed_responses;
	}
	
	
	public static function amazonsearch_products_transientmode() {		
		$apa_api = self::initapi_settings();	
		$processed_responses = array();					
		switch ( self::$search_type ) {
			case 'itemsearch':				
							# Forming the request
							$searchItemsRequest = new SearchItemsRequest();
							$searchItemsRequest->setSearchIndex( self::$search_category );
							$searchItemsRequest->setKeywords( self::$search_kword );		
							$searchItemsRequest->setPartnerTag( self::$associate_partner_tag );
							$searchItemsRequest->setPartnerType( PartnerType::ASSOCIATES );
							$searchItemsRequest->setMerchant( 'All' );
							# If book condition is NOT set to 'New' then more ISBNs info returned during search on homepage
							# In old API we set bookcondition to 'New' but that did not work but working fine on PAAPI 5
							$searchItemsRequest->setCondition( 'New' );  

							$searchItemsRequest->setResources( self::search_item_resources() );

							# Validating request
							$invalidPropertyList = $searchItemsRequest->listInvalidProperties();
							$length              = count($invalidPropertyList);
							if ($length > 0) {
								echo "Error forming the request".  PHP_EOL;
								foreach ($invalidPropertyList as $invalidProperty) {
									echo $invalidProperty.  PHP_EOL;
								}
								//return;
							}

							# Sending the request
							try {
								# Call API Request
								$searchItemsResponse   = $apa_api->searchItems( $searchItemsRequest );
								# Convert Json encoded string into associative array
								$response              = json_decode( $searchItemsResponse, true ); 

								# Parsing the response
								if ( $searchItemsResponse->getSearchResult() != null) {
									$items             = $response['SearchResult']['Items'];
									//echo '<pre>';
									//print_r( $items );
									//echo '</pre>';

									# Parsed each API call found books information
									$processed_responses = self::parse_paapi5_response( $items );						

									// Test per API call processed info
									//echo '<pre>';
									//print_r( $processed_responses );
									//echo '</pre>';

								} // End if ($searchItemsResponse->getSearchResult() != null)
							} catch (ApiException $exception) {
								echo "Error calling PA-API 5.0!".  PHP_EOL;
								echo "HTTP Status Code: ", $exception->getCode().  PHP_EOL;
								echo "Error Message: ", $exception->getMessage().  PHP_EOL;
								if ($exception->getResponseObject() instanceof ProductAdvertisingAPIClientException) {
									$errors = $exception->getResponseObject()->getErrors();
									foreach ($errors as $error) {
										echo "Error Type: ", $error->getCode().  PHP_EOL;
										echo "Error Message: ", $error->getMessage().  PHP_EOL;
									}
								} else {
									echo "Error response body: ", $exception->getResponseBody().  PHP_EOL;
								}
							} catch (Exception $exception) {
								echo "Error Message: ", $exception->getMessage().  PHP_EOL;
							}				

				
				
							/*
											$search = new Search();
											$search->setCategory( self::$search_category );			
											$search->setKeywords( self::$search_kword );
											if ( self::$sort_by !== null ) {
												$search->setSort( self::$sort_by );
											}
											$search->setPage( self::$result_page );
											$search->setResponsegroup( self::$response_group );
											$response = $apa_api->runOperation( $search );
											$processed_responses = Caaps_Amazon_Response_Process::process_response( $response );			
											if ( is_array( $processed_responses ) ) {						
												self::transient_amazon_products( $processed_responses );					
											}
							*/
				
			break;
			
			case 'itemlookup':
				$asins = array_map( array( __CLASS__, 'validate_asins'), self::$search_asin );								
				$nontransient_asins = self::skip_transient_products_tocall( $asins );								
				// Amazon allows maximum 10 asins per request
				//if ( count( $nontransient_asins ) > 10 ) {					
					$chunked_asins = array_chunk( $nontransient_asins, 10 );
					foreach ( $chunked_asins as $chunk_asin ) {												
							# Store Per API call processed data to cache or use 
							$each_apicall_info  = array();								

							# Forming the request
							$getItemsRequest = new GetItemsRequest();
							//$getItemsRequest->setItemIds($itemIds);
							$getItemsRequest->setItemIds( $chunk_asin );
							$getItemsRequest->setPartnerTag( self::$associate_partner_tag );
							$getItemsRequest->setPartnerType( PartnerType::ASSOCIATES );
							$getItemsRequest->setCondition( 'New' );
							$getItemsRequest->setMerchant( 'All' );	
							$getItemsRequest->setResources( self::get_item_resources() );

							# Validating request
							$invalidPropertyList = $getItemsRequest->listInvalidProperties();
							$length              = count($invalidPropertyList);
							if ($length > 0) {
								echo "Error forming the request", PHP_EOL;
								foreach ($invalidPropertyList as $invalidProperty) {
									echo $invalidProperty, PHP_EOL;
								}
								//return;
							}

							# Sending the request
							try {
								# Call API Request
								$getItemsResponse = $apa_api->getItems( $getItemsRequest );

								/*echo 'API called successfully'. "<br/>";
								//echo 'Complete Response: ', $getItemsResponse . "<br/>";

								echo '<pre>';
								print_r( json_decode( $getItemsResponse, true ) );
								echo '</pre>';
								//exit(0);*/

								# Convert Json encoded string into associative array
								$response         = json_decode( $getItemsResponse, true );

								# Parsing the response
								if ( $getItemsResponse->getItemsResult() != null ) {
									$items        = $response['ItemsResult']['Items'];
									//echo '<pre>';
									//print_r( $items );
									//echo '</pre>';

									# Parsed each API call found books information
									$each_apicall_info = self::parse_paapi5_response( $items );	

									# Merge each API call parsed data with all api found information data
									$processed_responses = array_merge( $processed_responses, $each_apicall_info );

									// Test per API call processed info
									//echo '<pre>';
									//print_r( $each_apicall_info );
									//echo '</pre>';							
								}
								/*if ($getItemsResponse->getErrors() != null) {
									echo PHP_EOL, 'Printing Errors:', PHP_EOL, 'Printing first error object from list of errors', PHP_EOL;
									echo 'Error code: ', $getItemsResponse->getErrors()[0]->getCode(), PHP_EOL;
									echo 'Error message: ', $getItemsResponse->getErrors()[0]->getMessage(), PHP_EOL;
								}*/
							} catch (ApiException $exception) {
								/*echo "Error calling PA-API 5.0!", PHP_EOL;
								echo "HTTP Status Code: ", $exception->getCode(), PHP_EOL;
								echo "Error Message: ", $exception->getMessage(), PHP_EOL;
								if ($exception->getResponseObject() instanceof ProductAdvertisingAPIClientException) {
									$errors = $exception->getResponseObject()->getErrors();
									foreach ($errors as $error) {
										echo "Error Type: ", $error->getCode(), PHP_EOL;
										echo "Error Message: ", $error->getMessage(), PHP_EOL;
									}
								} else {
									echo "Error response body: ", $exception->getResponseBody(), PHP_EOL;
								}*/
							} catch (Exception $exception) {
								//echo "Error Message: ", $exception->getMessage(), PHP_EOL;
							}



	/*
							$chunk_asin = implode( ',', $chunk_asin );
							$lookup = new Lookup();						
							$lookup->setItemId( $chunk_asin );
							$lookup->setResponseGroup( self::$response_group );				
							$response = $apa_api->runOperation( $lookup );				
							$processed_response = Caaps_Amazon_Response_Process::process_response( $response );						
							$processed_responses = array_merge( $processed_responses, $processed_response );
	*/
						
					} // foreach loop					
				//} // if ( count( $nontransient_asins ) > 10
/*
				elseif ( count( $nontransient_asins ) > 0 ) {
					$nontransient_asin = implode(',', $nontransient_asins);
					$lookup = new Lookup();
					$lookup->setItemId( $nontransient_asin );
					$lookup->setResponseGroup( self::$response_group );				
					$response = $apa_api->runOperation( $lookup );				
					$processed_responses = Caaps_Amazon_Response_Process::process_response( $response );						
				}
*/
				
				
				if ( is_array( $processed_responses ) ) {						
				    self::transient_amazon_products( $processed_responses );
					// Get earlier transient products and merge them with current api call products																				
					$transient_products = self::getall_transient_products( array_diff( $asins, $nontransient_asins) );
					$processed_responses = array_merge( $processed_responses, $transient_products );					
				}
			break;						
		} // End switch
		return $processed_responses;
	}	
	
	public static function transient_amazon_products( $products = array() ) {
		$i = 0;
		while ( $i < count( $products ) ) {
			if ( isset( $products[$i]['asin'] ) && ! empty( $products[$i]['asin'] ) ) {
				if ( false === get_transient( 'caaps_transient_'.$products[$i]['asin'] ) ) {
					set_transient( 'caaps_transient_'.$products[$i]['asin'], $products[$i], self::$transientexpire_hours * HOUR_IN_SECONDS );
				}
			}
		$i++;
		}
	}
	
	public static function skip_transient_products_tocall( $asins = array() ) {
		$asins_tocall = array();
		if( isset( $asins ) && count( $asins ) > 0 ) {
			foreach ( $asins as $asin) {
				if ( false === get_transient( 'caaps_transient_'.$asin) ) {
					$asins_tocall[] = $asin;
				}
			}			
		}
		return $asins_tocall;
	}
	
	public static function getall_transient_products ( $asins = array() ) {
		$transient_products = array();
		if ( isset( $asins ) && count( $asins ) > 0 ) {
			foreach ( $asins as $asin ) {				
				if ( $product = get_transient( 'caaps_transient_'.$asin ) ) {
					$transient_products[] = $product;
				}
			}
		}
		return $transient_products;
	}
	
	public static function amazonsearch_products_byasins( $asins = array() ) {		
		$asins = implode( ',', array_map( array( __CLASS__, 'validate_asins'), $asins ) );
		$options = get_option('caaps_amazon-product-shop-settings');
		self::$search_country = $options['caaps_settings_field_country'];		
		$apa_api = self::initapi_settings();
		foreach ( $chunked_asins as $chunk_asin ) {																												
				# Store Per API call processed data to cache or use 
				$each_apicall_info  = array();								

				# Forming the request
				$getItemsRequest = new GetItemsRequest();
				//$getItemsRequest->setItemIds($itemIds);
				$getItemsRequest->setItemIds( $chunk_asin );
				$getItemsRequest->setPartnerTag( self::$associate_partner_tag );
				$getItemsRequest->setPartnerType( PartnerType::ASSOCIATES );
				$getItemsRequest->setCondition( 'New' );
				$getItemsRequest->setMerchant( 'All' );	
				$getItemsRequest->setResources( self::get_item_resources() );

				# Validating request
				$invalidPropertyList = $getItemsRequest->listInvalidProperties();
				$length              = count($invalidPropertyList);
				if ($length > 0) {
					echo "Error forming the request", PHP_EOL;
					foreach ($invalidPropertyList as $invalidProperty) {
						echo $invalidProperty, PHP_EOL;
					}
					//return;
				}

				# Sending the request
				try {
					# Call API Request
					$getItemsResponse = $apa_api->getItems( $getItemsRequest );

					/*echo 'API called successfully'. "<br/>";
					//echo 'Complete Response: ', $getItemsResponse . "<br/>";

					echo '<pre>';
					print_r( json_decode( $getItemsResponse, true ) );
					echo '</pre>';
					//exit(0);*/

					# Convert Json encoded string into associative array
					$response         = json_decode( $getItemsResponse, true );

					# Parsing the response
					if ( $getItemsResponse->getItemsResult() != null ) {
						$items        = $response['ItemsResult']['Items'];
						//echo '<pre>';
						//print_r( $items );
						//echo '</pre>';

						# Parsed each API call found books information
						$each_apicall_info = self::parse_paapi5_response( $items );	

						# Merge each API call parsed data with all api found information data
						$processed_responses = array_merge( $processed_responses, $each_apicall_info );

						// Test per API call processed info
						//echo '<pre>';
						//print_r( $each_apicall_info );
						//echo '</pre>';							
					}
					/*if ($getItemsResponse->getErrors() != null) {
						echo PHP_EOL, 'Printing Errors:', PHP_EOL, 'Printing first error object from list of errors', PHP_EOL;
						echo 'Error code: ', $getItemsResponse->getErrors()[0]->getCode(), PHP_EOL;
						echo 'Error message: ', $getItemsResponse->getErrors()[0]->getMessage(), PHP_EOL;
					}*/
				} catch (ApiException $exception) {
					/*echo "Error calling PA-API 5.0!", PHP_EOL;
					echo "HTTP Status Code: ", $exception->getCode(), PHP_EOL;
					echo "Error Message: ", $exception->getMessage(), PHP_EOL;
					if ($exception->getResponseObject() instanceof ProductAdvertisingAPIClientException) {
						$errors = $exception->getResponseObject()->getErrors();
						foreach ($errors as $error) {
							echo "Error Type: ", $error->getCode(), PHP_EOL;
							echo "Error Message: ", $error->getMessage(), PHP_EOL;
						}
					} else {
						echo "Error response body: ", $exception->getResponseBody(), PHP_EOL;
					}*/
				} catch (Exception $exception) {
					//echo "Error Message: ", $exception->getMessage(), PHP_EOL;
				}

		} // foreach loop

						
/*
		$lookup = new Lookup();
		$lookup->setItemId( $asins );
		$lookup->setResponseGroup( self::$response_group );				
		$response = $apa_api->runOperation( $lookup );				
		$processed_response = Caaps_Amazon_Response_Process::process_response( $response );
*/
		
		
		return $processed_responses;
	}
	
	public static function validate_asins( $asin = null ) {
		// Valid asins char ranges A-Z, a-z, 0-9
		if ( ! preg_match("/[^0-9A-Za-z]/i", trim($asin) ) ) {
			return $asin;
		}
		else {
			self::$invalid_asins[] = $asin;
		}
	}
	
	public static function add_selected_products() {
		check_ajax_referer( 'caaps_wpnonce', 'security' );					     		
		if ( isset( $_POST['selected_products'] ) ) {
			$productsto_add = $_POST['selected_products'];	// Product ASINs		
			$post_id = $_POST['post_id'];									
			if ( isset($post_id) && $post_id ) {
				$meta_key = '_caaps_added_products_'.$post_id;
				$exist_products = get_post_meta( $post_id, $meta_key );								
				// Merge with exist products If exist - also remove duplicates
				if ( $exist_products ) {
					$productsto_add = array_unique( array_merge( $exist_products[0], $productsto_add ) );
				}
				$add_products_status = update_post_meta( $post_id, $meta_key, $productsto_add );				
				
				echo $add_products_status;
			}			
		}		
	}
	
	public static function remove_selected_products() {
		check_ajax_referer( 'caaps_wpnonce', 'security' );					     		
		if ( isset( $_POST['selected_products'] ) ) {
			$productsto_remove = array_unique( $_POST['selected_products'] );			
			$post_id = $_POST['post_id'];			
			if ( isset( $post_id ) && $post_id ) {
				$meta_key = '_caaps_added_products_'.$post_id;
				$exist_products = get_post_meta( $post_id, $meta_key );
				// Remove selected asins from exist product asins
				if ( $exist_products ) {
					$productsto_remove = array_diff( $exist_products[0], $productsto_remove );
				}
				$removed_products_status = update_post_meta( $post_id, $meta_key, $productsto_remove );
				echo $removed_products_status;
			}			
		}
	}
	
	public static function test_api_settings() {
		check_ajax_referer( 'caaps_wpnonce', 'security' );		
		$apa_api 			= self::initapi_settings();			        	

		# Create API requests format of ISBNs - Separated with pipe | of isbns
		$keyword            = 'mySQL books' ; 

		# Forming the request
		$searchItemsRequest = new SearchItemsRequest();
		$searchItemsRequest->setSearchIndex( 'Books' );
		$searchItemsRequest->setKeywords( $keyword );		
		$searchItemsRequest->setPartnerTag( self::$associate_partner_tag );
		$searchItemsRequest->setPartnerType( PartnerType::ASSOCIATES );
		$searchItemsRequest->setMerchant( 'All' );
		# If book condition is NOT set to 'New' then more ISBNs info returned during search on homepage
		# In old API we set bookcondition to 'New' but that did not work but working fine on PAAPI 5
		$searchItemsRequest->setCondition( 'New' );  

		$searchItemsRequest->setResources( self::search_item_resources() );

		# Validating request
		$invalidPropertyList = $searchItemsRequest->listInvalidProperties();
		$length              = count($invalidPropertyList);
		if ($length > 0) {
			echo "Error forming the request".  PHP_EOL;
			foreach ($invalidPropertyList as $invalidProperty) {
				echo $invalidProperty.  PHP_EOL;
			}
			//return;
		}

		# Sending the request
		try {
			# Call API Request
			$searchItemsResponse   = $apa_api->searchItems( $searchItemsRequest );
			# Convert Json encoded string into associative array
			$response              = json_decode( $searchItemsResponse, true ); 

			# Parsing the response
			if ( $searchItemsResponse->getSearchResult() != null) {
				$items             = $response['SearchResult']['Items'];
				//echo '<pre>';
				//print_r( $items );
				//echo '</pre>';

				# Parsed each API call found books information
				$apicall_info = self::parse_paapi5_response( $items );						

				// Test per API call processed info
				echo '<pre>';
				print_r( $apicall_info );
				echo '</pre>';

			} // End if ($searchItemsResponse->getSearchResult() != null)
		} catch (ApiException $exception) {
			echo "Error calling PA-API 5.0!".  PHP_EOL;
			echo "HTTP Status Code: ", $exception->getCode().  PHP_EOL;
			echo "Error Message: ", $exception->getMessage().  PHP_EOL;
			if ($exception->getResponseObject() instanceof ProductAdvertisingAPIClientException) {
				$errors = $exception->getResponseObject()->getErrors();
				foreach ($errors as $error) {
					echo "Error Type: ", $error->getCode().  PHP_EOL;
					echo "Error Message: ", $error->getMessage().  PHP_EOL;
				}
			} else {
				echo "Error response body: ", $exception->getResponseBody().  PHP_EOL;
			}
		} catch (Exception $exception) {
			echo "Error Message: ", $exception->getMessage().  PHP_EOL;
		}
		
		
		
/*
		$search = new Search();
		$search->setCategory( self::$search_category );			
		$search->setKeywords( 'softwares' );
		$search->setPage( self::$result_page );
		$search->setResponsegroup( self::$response_group );
		$response = $apa_api->runOperation( $search );
		$processed_response = Caaps_Amazon_Response_Process::process_response( $response );
		print_r( $processed_response );
*/
		
		wp_die();
	}
							
	
	
	public static function search_item_resources() {
		/*
		 * Choose resources you want from SearchItemsResource enum
		 * For more details, refer: https://webservices.amazon.com/paapi5/documentation/search-items.html#resources-parameter
		 */	
		self::$searchitem_resources   = array(        		
					// Get EAN or ISBN values
					SearchItemsResource::ITEM_INFOEXTERNAL_IDS,

					// Items Info
					SearchItemsResource::ITEM_INFOTITLE,
					SearchItemsResource::ITEM_INFOCONTENT_INFO,
					SearchItemsResource::ITEM_INFOCONTENT_RATING,
					SearchItemsResource::ITEM_INFOCLASSIFICATIONS,

					SearchItemsResource::ITEM_INFOFEATURES,
					SearchItemsResource::ITEM_INFOBY_LINE_INFO,

					SearchItemsResource::ITEM_INFOMANUFACTURE_INFO,
					SearchItemsResource::ITEM_INFOPRODUCT_INFO,
					SearchItemsResource::ITEM_INFOTECHNICAL_INFO,
					SearchItemsResource::ITEM_INFOTRADE_IN_INFO,


					// Images with variations
					SearchItemsResource::IMAGESPRIMARYSMALL,
					SearchItemsResource::IMAGESPRIMARYMEDIUM,
					SearchItemsResource::IMAGESPRIMARYLARGE,
					SearchItemsResource::IMAGESVARIANTSSMALL,
					SearchItemsResource::IMAGESVARIANTSMEDIUM,
					SearchItemsResource::IMAGESVARIANTSLARGE,

					// Offers summaries Prices with savings
					SearchItemsResource::OFFERSLISTINGSPRICE,
			
			        // CONDITION - SUBCONDITION
					SearchItemsResource::OFFERSLISTINGSCONDITION,			
			        SearchItemsResource::OFFERSLISTINGSCONDITIONSUB_CONDITION, 
			
					SearchItemsResource::OFFERSLISTINGSPROMOTIONS,
					SearchItemsResource::OFFERSLISTINGSSAVING_BASIS,

					SearchItemsResource::OFFERSSUMMARIESHIGHEST_PRICE,
					SearchItemsResource::OFFERSSUMMARIESLOWEST_PRICE,
					SearchItemsResource::OFFERSSUMMARIESOFFER_COUNT,
					SearchItemsResource::OFFERSLISTINGSMERCHANT_INFO,
					SearchItemsResource::OFFERSSUMMARIESOFFER_COUNT,

					SearchItemsResource::OFFERSLISTINGSAVAILABILITYTYPE,
					SearchItemsResource::OFFERSLISTINGSAVAILABILITYMESSAGE,

					SearchItemsResource::OFFERSLISTINGSDELIVERY_INFOIS_AMAZON_FULFILLED,
					SearchItemsResource::OFFERSLISTINGSDELIVERY_INFOSHIPPING_CHARGES,		
					SearchItemsResource::OFFERSLISTINGSDELIVERY_INFOIS_FREE_SHIPPING_ELIGIBLE
				);			
		return self::$searchitem_resources;
	}
	
	public static function get_item_resources() {
		/*
		 * Choose resources you want from GetItemsResource enum
		 * For more details, refer: https://webservices.amazon.com/paapi5/documentation/get-items.html#resources-parameter
		 */
		self::$getitem_resources = array(        		
			// Items Info
			GetItemsResource::ITEM_INFOTITLE,
			GetItemsResource::ITEM_INFOCONTENT_INFO,
			GetItemsResource::ITEM_INFOCONTENT_RATING,
			GetItemsResource::ITEM_INFOCLASSIFICATIONS,
			GetItemsResource::ITEM_INFOEXTERNAL_IDS,

			GetItemsResource::ITEM_INFOFEATURES,
			GetItemsResource::ITEM_INFOBY_LINE_INFO,

			GetItemsResource::ITEM_INFOMANUFACTURE_INFO,
			GetItemsResource::ITEM_INFOPRODUCT_INFO,
			GetItemsResource::ITEM_INFOTECHNICAL_INFO,
			GetItemsResource::ITEM_INFOTRADE_IN_INFO,


			// Images with variations
			GetItemsResource::IMAGESPRIMARYSMALL,
			GetItemsResource::IMAGESPRIMARYMEDIUM,
			GetItemsResource::IMAGESPRIMARYLARGE,
			GetItemsResource::IMAGESVARIANTSSMALL,
			GetItemsResource::IMAGESVARIANTSMEDIUM,
			GetItemsResource::IMAGESVARIANTSLARGE,

			// Offers summaries Prices with savings
			GetItemsResource::OFFERSLISTINGSPRICE,
			GetItemsResource::OFFERSLISTINGSCONDITION,
			GetItemsResource::OFFERSLISTINGSPROMOTIONS,
			GetItemsResource::OFFERSLISTINGSSAVING_BASIS,

			GetItemsResource::OFFERSSUMMARIESHIGHEST_PRICE,
			GetItemsResource::OFFERSSUMMARIESLOWEST_PRICE,
			GetItemsResource::OFFERSSUMMARIESOFFER_COUNT,
			GetItemsResource::OFFERSLISTINGSMERCHANT_INFO,
			GetItemsResource::OFFERSSUMMARIESOFFER_COUNT,

			GetItemsResource::OFFERSLISTINGSAVAILABILITYTYPE,
			GetItemsResource::OFFERSLISTINGSAVAILABILITYMESSAGE,

			GetItemsResource::OFFERSLISTINGSDELIVERY_INFOIS_AMAZON_FULFILLED,
			GetItemsResource::OFFERSLISTINGSDELIVERY_INFOSHIPPING_CHARGES,		
			GetItemsResource::OFFERSLISTINGSDELIVERY_INFOIS_FREE_SHIPPING_ELIGIBLE
		);
		return self::$getitem_resources;
	}
	
	
	public static function parse_paapi5_response( $items =  null ) {
		$items_info                    =  array();	
		foreach( $items as $item ) {
			// TO HOLD EACH ASIN INFO ALWAYS INIT TEMP VARIABLE
			$temp                      =  array();  

			//AMAZON ASIN VALUE
			//$cacheData[$index]['asin'] = $response['Items']['Item']['ASIN'];	 					
			$temp['asin']              = $item['ASIN'];

			//AMAZON AFFILIATE URL
			//$cacheData[$index]['affurl'] = $response['Items']['Item']['DetailPageURL'];
			$temp['affurl']            = $item['DetailPageURL'];


			//AMAZON SELL PRICE
			//if ( isset( $response['Items']['Item']['Offers']['Offer']['OfferListing']['Price']['FormattedPrice']) ):
				//$cacheData[$index]['sellprice'] = $response['Items']['Item']['Offers']['Offer']['OfferListing']['Price']['FormattedPrice'];	
			//endif;	
			$temp['sellprice']         = isset( $item['Offers']['Listings'][0]['Price']['DisplayAmount'] )? $item['Offers']['Listings'][0]['Price']['DisplayAmount'] : '';

			//AMAZON SELL PRICE - LOWEST PRICE
			//if ( isset($response['Items']['Item']['OfferSummary']['LowestNewPrice']['FormattedPrice']) ):
				//$cacheData[$index]['sellpricelowest'] = $response['Items']['Item']['OfferSummary']['LowestNewPrice']['FormattedPrice'];
			//endif;	
			$temp['sellpricelowest']   = isset( $item['Offers']['Summaries'][0]['LowestPrice']['DisplayAmount'] )? $item['Offers']['Summaries'][0]['LowestPrice']['DisplayAmount'] : '';

			// USED BOOKS LOWEST PRTCE
			$temp['usedbooklowest']    = isset( $item['Offers']['Summaries'][1]['LowestPrice']['DisplayAmount'] )? $item['Offers']['Summaries'][1]['LowestPrice']['DisplayAmount'] : '';


			//Total Offers Number - Zero(0) means Not Available
			//if ( isset($response['Items']['Item']['Offers']['TotalOffers']) ):
				//$testTotalOffer[] = $response['Items']['Item']['Offers']['TotalOffers'];
				//$cacheData[$index]['totaloffer'] = $response['Items']['Item']['Offers']['TotalOffers'];
			//endif;//if ( isset($response['Items']['Item']['Offers']['TotalOffers']) ):
			$temp['totaloffer']        = isset( $item['Offers']['Summaries'][0]['OfferCount'] );

			// BOOK CONDITION - THOUGH CURRENTLY WE DON'T SAVE IT ON DB JUST TO CHECK ON API PARSED RETURN ARRAY
			$temp['condition']         = isset( $item['Offers']['Summaries'][0]['Condition']['Value'] )? $item['Offers']['Summaries'][0]['Condition']['Value'] : '';		

			//MERCHANT NAME
			//if (isset($response['Items']['Item']['Offers']['Offer']['Merchant']['Name']) ):
			  //$cacheData[$index]['merchant'] = $response['Items']['Item']['Offers']['Offer']['Merchant']['Name'];
			//elseif (isset($response['Items']['Item']['Offers']['Offer'][0]['Merchant']['Name']) ):
			  //$cacheData[$index]['merchant']= $response['Items']['Item']['Offers']['Offer'][0]['Merchant']['Name'];
			//endif;
			$temp['merchant']          = isset( $item['Offers']['Listings'][0]['MerchantInfo']['Name'] )? $item['Offers']['Listings'][0]['MerchantInfo']['Name'] : '';

			// OfferListingId
			$temp['offerlistingid']    = isset( $item['Offers']['Listings'][0]['Id'] )? $item['Offers']['Listings'][0]['Id'] : '';		


			//AMAZON ISBN - EAN  NUMBER
			//if (isset($response['Items']['Item']['ItemAttributes']['EAN'])):									
				//$cacheData[$index]['isbn'] = $response['Items']['Item']['ItemAttributes']['EAN'];
			//endif;//End if (isset($response['Items']['Item']['ItemAttributes']['EAN'])):	
			$temp['isbn']              = isset( $item['ItemInfo']['ExternalIds']['EANs']['DisplayValues'][0] )? $item['ItemInfo']['ExternalIds']['EANs']['DisplayValues'][0] : '';

			//AMAZON ISBN 10
			//if (isset($response['Items']['Item']['ItemAttributes']['ISBN'])):									
				//$cacheData[$index]['isbn10'] = $response['Items']['Item']['ItemAttributes']['ISBN'];
			//endif;//End if (isset($response['Items']['Item']['ItemAttributes']['ISBN'])):		
			$temp['isbn10']            = isset( $item['ItemInfo']['ExternalIds']['ISBNs']['DisplayValues'][0] )? $item['ItemInfo']['ExternalIds']['ISBNs']['DisplayValues'][0] : '';


			//AMAZON DEFAULT / PRIMARY IMAGE - LARGE
			//if (isset($response['Items']['Item']['LargeImage']['URL']) ): 				
				//$cacheData[$index]['lgimage'] = $response['Items']['Item']['LargeImage']['URL'];
			//endif;			
			$temp['lgimage']           = isset( $item['Images']['Primary']['Large']['URL'] )? $item['Images']['Primary']['Large']['URL'] : '';

			//AMAZON DEFAULT / PRIMARY IMAGE - MEDIUM
			//if (isset($response['Items']['Item']['MediumImage']['URL']) ): 									
			//		$cacheData[$index]['medimage'] = $response['Items']['Item']['MediumImage']['URL'];
			//endif;//End if (isset($response['Items']['Item']['MediumImage']['URL']) ): 
			$temp['medimage']          = isset( $item['Images']['Primary']['Medium']['URL'] )? $item['Images']['Primary']['Medium']['URL'] : '';

			//AMAZON DEFAULT / PRIMARY IMAGE - SMALL
			//if (isset($response['Items']['Item']['SmallImage']['URL']) ): 									
			//		$cacheData[$index]['smallimage'] = $response['Items']['Item']['SmallImage']['URL'];
			//endif;//End if (isset($response['Items']['Item']['SmallImage']['URL']) ):
			$temp['smallimage']        = isset( $item['Images']['Primary']['Small']['URL'] )? $item['Images']['Primary']['Small']['URL'] : '';


			//AMAZON VARIANT IMAGE 1 - LARGE
			//$cacheData[$index]['variant_largeimage1'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][0]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][0]['Category'] == 'variant' ): 				
			//	$cacheData[$index]['variant_largeimage1'] = $response['Items']['Item']['ImageSets']['ImageSet'][0]['LargeImage']['URL'];
			//endif;										
			$temp['variant_largeimage1'] = isset( $item['Images']['Variants'][0]['Large']['URL'] )? $item['Images']['Variants'][0]['Large']['URL'] : '';

			//AMAZON VARIANT IMAGE 1 - MEDIUM
			//$cacheData[$index]['variant_mediumimage1'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][0]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][0]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_mediumimage1'] = $response['Items']['Item']['ImageSets']['ImageSet'][0]['MediumImage']['URL'];
			//endif;	
			$temp['variant_mediumimage1']= isset( $item['Images']['Variants'][0]['Medium']['URL'] )? $item['Images']['Variants'][0]['Medium']['URL'] : '';

			//AMAZON VARIANT IMAGE 1 - SMALL
			//$cacheData[$index]['variant_smallimage1'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][0]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][0]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_smallimage1'] = $response['Items']['Item']['ImageSets']['ImageSet'][0]['SmallImage']['URL'];
			//endif;				
			$temp['variant_smallimage1']= isset( $item['Images']['Variants'][0]['Small']['URL'] )? $item['Images']['Variants'][0]['Small']['URL'] : '';


			//AMAZON VARIANT IMAGE 2 - LARGE
			//$cacheData[$index]['variant_largeimage2'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][1]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][1]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_largeimage2'] = $response['Items']['Item']['ImageSets']['ImageSet'][1]['LargeImage']['URL'];
			//endif;																													
			$temp['variant_largeimage2'] = isset( $item['Images']['Variants'][1]['Large']['URL'] )? $item['Images']['Variants'][1]['Large']['URL'] : '';

			//AMAZON VARIANT IMAGE 2 - MEDIUM
			//$cacheData[$index]['variant_mediumimage2'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][1]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][1]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_mediumimage2'] = $response['Items']['Item']['ImageSets']['ImageSet'][1]['MediumImage']['URL'];
			//endif;		
			$temp['variant_mediumimage2']= isset( $item['Images']['Variants'][1]['Medium']['URL'] )? $item['Images']['Variants'][1]['Medium']['URL'] : '';

			//AMAZON VARIANT IMAGE 2 - SMALL
			//$cacheData[$index]['variant_smallimage2'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][1]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][1]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_smallimage2'] = $response['Items']['Item']['ImageSets']['ImageSet'][1]['SmallImage']['URL'];
			//endif;		
			$temp['variant_smallimage2']= isset( $item['Images']['Variants'][1]['Small']['URL'] )? $item['Images']['Variants'][1]['Small']['URL'] : '';


			//AMAZON VARIANT IMAGE 3 - LARGE
			//$cacheData[$index]['variant_largeimage3'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][2]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][2]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_largeimage3'] = $response['Items']['Item']['ImageSets']['ImageSet'][2]['LargeImage']['URL'];
			//endif;		
			$temp['variant_largeimage3'] = isset( $item['Images']['Variants'][2]['Large']['URL'] )? $item['Images']['Variants'][2]['Large']['URL'] : '';

			//AMAZON VARIANT IMAGE 3 - MEDIUM
			//$cacheData[$index]['variant_mediumimage3'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][2]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][2]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_mediumimage3'] = $response['Items']['Item']['ImageSets']['ImageSet'][2]['MediumImage']['URL'];
			//endif;			
			$temp['variant_mediumimage3']= isset( $item['Images']['Variants'][2]['Medium']['URL'] )? $item['Images']['Variants'][2]['Medium']['URL'] : '';

			//AMAZON VARIANT IMAGE 3 - SMALL
			//$cacheData[$index]['variant_smallimage3'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][2]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][2]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_smallimage3'] = $response['Items']['Item']['ImageSets']['ImageSet'][2]['SmallImage']['URL'];
			//endif;	
			$temp['variant_smallimage3']= isset( $item['Images']['Variants'][2]['Small']['URL'] )? $item['Images']['Variants'][2]['Small']['URL'] : '';

			//AMAZON VARIANT IMAGE 4 - LARGE
			//$cacheData[$index]['variant_largeimage4'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][3]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][3]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_largeimage4'] = $response['Items']['Item']['ImageSets']['ImageSet'][3]['LargeImage']['URL'];
			//endif;							
			$temp['variant_largeimage4'] = isset( $item['Images']['Variants'][3]['Large']['URL'] )? $item['Images']['Variants'][3]['Large']['URL'] : '';

			//AMAZON VARIANT IMAGE 4 - MEDIUM
			//$cacheData[$index]['variant_mediumimage4'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][3]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][3]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_mediumimage4'] = $response['Items']['Item']['ImageSets']['ImageSet'][3]['MediumImage']['URL'];
			//endif;			
			$temp['variant_mediumimage4']= isset( $item['Images']['Variants'][3]['Medium']['URL'] )? $item['Images']['Variants'][3]['Medium']['URL'] : '';
			//AMAZON VARIANT IMAGE 4 - SMALL
			//$cacheData[$index]['variant_smallimage4'] = '';
			//if (isset($response['Items']['Item']['ImageSets']['ImageSet'][3]['LargeImage']['URL']) && 
			//$response['Items']['Item']['ImageSets']['ImageSet'][3]['Category'] == 'variant' ): 				
				//$cacheData[$index]['variant_smallimage4'] = $response['Items']['Item']['ImageSets']['ImageSet'][3]['SmallImage']['URL'];
			//endif;	
			$temp['variant_smallimage4']= isset( $item['Images']['Variants'][3]['Small']['URL'] )? $item['Images']['Variants'][3]['Small']['URL'] : '';


			//AMAZON BOOK TITLE
			//if ( isset($response['Items']['Item']['ItemAttributes']['Title']) ):									
			//		$cacheData[$index]['title'] = $response['Items']['Item']['ItemAttributes']['Title'];									
			//endif;//End if ( isset($response['Items']['Item']['ItemAttributes']['Title']) ):
			$temp['title']            = isset( $item['ItemInfo']['Title']['DisplayValue'] )? $item['ItemInfo']['Title']['DisplayValue'] : '';


			//AMAZON BOOK PUBLISHER
			//if ( isset($response['Items']['Item']['ItemAttributes']['Publisher']) ):
			//		$cacheData[$index]['publisher'] = $response['Items']['Item']['ItemAttributes']['Publisher'];
			//endif;//End if ( isset($response['Items']['Item']['ItemAttributes']['Publisher']) ):
			$temp['publisher']        = isset( $item['ItemInfo']['ByLineInfo']['Manufacturer']['DisplayValue'] )? $item['ItemInfo']['ByLineInfo']['Manufacturer']['DisplayValue'] : '';


			//AMAZON BOOK LIST PRICE -  RECOMENDADO PRICE - IN PAAPI 5 SELL PRICE AND LISTING PRICE SAME
			//if (isset($response['Items']['Item']['ItemAttributes']['ListPrice']['FormattedPrice']) ):
			//		$cacheData[$index]['listprice'] = $response['Items']['Item']['ItemAttributes']['ListPrice']['FormattedPrice'];									
			//endif;//End if (isset($response['Items']['Item']['ItemAttributes']['ListPrice']['FormattedPrice']) ):
			$temp['listprice']        = isset( $item['Offers']['Listings'][0]['Price']['DisplayAmount'] )? $item['Offers']['Listings'][0]['Price']['DisplayAmount'] : '';


			//AMAZON AMOUNT SAVED FORMATTED - PERCENTAGE AMOUNT
			/*if (isset( $response['Items']['Item']['Offers']['Offer']['OfferListing']['AmountSaved']['FormattedPrice']) ):
				@$cacheData[$index]['saveamount'] = $response['Items']['Item']['Offers']['Offer']['OfferListing']['AmountSaved']['FormattedPrice'];
				@$cacheData[$index]['savepercentage'] = $response['Items']['Item']['Offers']['Offer']['OfferListing']['PercentageSaved'];

			//In case of some ISBNs like 978-84-294-9670-3 - NOT readable normally while returns amount saved	
			elseif ( isset( $response['Items']['Item']['Offers']['Offer'][0]['OfferListing']['AmountSaved']['FormattedPrice']) ):

				$cacheData[$index]['saveamount'] = $response['Items']['Item']['Offers']['Offer'][0]['OfferListing']['AmountSaved']['FormattedPrice'];
				$cacheData[$index]['savepercentage'] = $response['Items']['Item']['Offers']['Offer'][0]['OfferListing']['PercentageSaved'];							
			endif;*/

			// IN PA API 5 THESE VALUES ARE NOT PRESENT YET
			$temp['saveamount']       = isset( $item['Offers']['Listings'][0]['Price']['Savings']['Amount'] )? $item['Offers']['Listings'][0]['Price']['Savings']['Amount'] : '';
			$temp['savepercentage']   = isset( $item['Offers']['Listings'][0]['Price']['Savings']['Percentage'] )? $item['Offers']['Listings'][0]['Price']['Savings']['Percentage'] : '';;

			// STORE EACH ASIN INFO IN MULTI-ARRAY VARIABLE TO HOLD ALL ASINS INFO ( MAXIMUM 10 ASINS INFO )
			$items_info[]             = $temp;

		} // End foreach

		return $items_info;	
	}
	
	
	
				
} // End Class
?>