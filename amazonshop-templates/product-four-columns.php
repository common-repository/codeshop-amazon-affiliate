<?php 
/**
 * This template display four columns view of products
 * This template can be overridden by copying it to your active theme
 * If this template isn't yet under your active theme then
 * Copy wp-content/plugins/amazon-product-shop/amazonshop-templates whole folder to your active theme folder
 * which path should be as wp-content/themes/{your-active-theme}/amazonshop-templates/product-four-columns.php
 * You may now edit this template file as you want to display products
 * REMEMBER You need to copy 'amazonshop-templates' whole folder into your active theme to work properly
 * NEVER just only copy this template file into your active theme folder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
global $amazon_products;
//echo '<pre>';
//print_r( $amazon_products);
//echo '</pre>';

$total_products = ( isset( $amazon_products ) && count( $amazon_products ) > 0 )? count( $amazon_products ) : 0;
$i = 0;
if ( $total_products > 0 ) { 
	// Get Display Option Settings
	$display_options        = get_option('caaps_amazon-product-shop-displayoptions');
	$showcachedtime_checked = isset( $display_options['caaps_displayoptions_field_lastcachetime'] )? 1 : 0;
	$image_size             = isset( $display_options['caaps_displayoptions_field_fourcolumns'] )? $display_options['caaps_displayoptions_field_fourcolumns'] : 'medimage';	
	$imgclass               = strtolower( $image_size ); 
			
	while ( $i < $total_products ) {
		?>
        <div class="caaps-amazon-products-row"> 
            <ul class="caaps-amazon-products">
                <?php 
                for ( $p = 0; $p < 4; $p++ ) {
                    if ( isset( $amazon_products[$i]['asin'] ) && ! empty( $amazon_products[$i]['asin'] )  ) {?>
                    <li>
                        <a href="<?php echo $amazon_products[$i]['affurl'];?>" target="_new">
                        <?php
                            	echo ( isset( $amazon_products[$i][ $image_size ] ) && ( ! empty( $amazon_products[$i][ $image_size ] )) )? '<img class="' . $imgclass . '" src="'.$amazon_products[$i][ $image_size ].'" alt="'. $amazon_products[$i]['title'].'" />' : '';
							?>
                            <div class="caaps-amazonbuy-btn">
                            	<button class="caapsbuy-btn">
									<?php echo Caaps_Template_Helper::set_product_buybutton();?>
                                </button>
                            </div>
                            
                            <h6 class="caaps-product-title">                            
							<?php
								echo ( isset( $amazon_products[$i]['title'] ) && ! empty( $amazon_products[$i]['title'] ) )?Caaps_Template_Helper::set_product_title( $amazon_products[$i]['title'] ) : '';
							?>
                            </h6>
                            
                            <div class="caaps-price">
                            <?php
							    $cached_time = ( ! $showcachedtime_checked )? '' : ( isset( $amazon_products[$i]['CachedTime'] )? '<br/><small style="font-size:8px">Last checked: '. date( "j M, y G:i e", strtotime($amazon_products[$i]['CachedTime']) ) . '</small>' : '' );
								echo ( isset( $amazon_products[$i]['sellpricelowest'] ) && ! empty( $amazon_products[$i]['sellpricelowest'] ) )? $amazon_products[$i]['sellpricelowest'] . $cached_time : '';																				
							?>
                            </div>                            	
                        </a>
                    </li>
                    <?php 
                    }	
                // Next product	  	  
                $i++; 	  
                } // End for
                ?>
            </ul>	  
        </div> <!-- /.caaps-amazon-products-row --> 		    	 
<?php	  
	} // End while
	?>

    <div class="caaps-pgination">
    <?php
		// Display pagination if available
		echo Caaps_Template_Helper::get_amazonshop_pagination();
	?>
    </div>
    
<?php    
}
else {
	_e( 'No Products Found', 'codeshop-amazon-affiliate' );
}
?>