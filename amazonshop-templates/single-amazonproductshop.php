<?php 
/**
 * This Template for displaying single post page available / added products
 * This template can be overridden by copying it to your active theme
 * If this template isn't yet under your active theme then 
 * Copy wp-content/plugins/amazon-product-shop/amazonshop-templates whole folder to your active theme folder
 * which path should be as wp-content/themes/{your-active-theme}/amazonshop-templates/single-amazonproductshop.php 
 * You may now edit this template file as you want to display products
 * REMEMBER You need to copy 'amazonshop-templates' whole folder into your active theme to work properly
 * NEVER just only copy this template file into your active theme folder
 *
 * @author 		CodeApple
 * @package 	amazon-product-shop/amazonshop-templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
get_header();
/**
 * You can set how many products will be shown per page
 * @param $products_per_page to define how many products will be shown per page if available / added
 */ 
$amazon_products = Caaps_Template_Helper::get_amazon_products( $products_per_page = 6 );  
?>
<div class="wrap"> 
    <!-- Post Title -->
	<h1 class="amazonshop-header-title">
		<?php echo Caaps_Template_Helper::get_post_title();?>
    </h1>    
	
    <div id="primary" class="content-area">
		<main id="main" class="site-main" role="main">
        	
            <!-- Display Breadcrumbs -->
            <nav class="caaps-breadcrumb">
            	<?php echo implode( ' &raquo; ', Caaps_Template_Helper::$breadcrumbs ); ?>                
            </nav>
			
            <!-- Post Contents -->
            <div>
				<?php echo Caaps_Template_Helper::get_post_content(); ?>
            </div>    
            
            <!-- Display Showing N to N of N products -->
            <div class="caaps-showing-products-count">
            	<?php Caaps_Template_Helper::products_result_count(); ?>
            </div>
            
			<!-- Display products -->
            <div class="caaps-main-products-wrapper">	        
			<?php 
				/** 
				 * Dsiplay products as template chosen on CodeShop -> Display Options page
				 * Get Template name - if not set use default as 'product-two-columns'
				 * @param Template name which will be loaded
				 * 
				 */				
				$template_options = get_option('caaps_amazon-product-shop-displayoptions');
				$homepage_template = 'product-two-columns.php';
				if ( isset( $template_options['caaps_displayoptions_field_posttemplate'] ) &&
				     ! empty( $template_options['caaps_displayoptions_field_posttemplate'] ) ) {
					 $homepage_template = trim( $template_options['caaps_displayoptions_field_posttemplate'] ) . '.php';
				}
				 Caaps_Template_Helper::caaps_load_template($homepage_template); 
            ?>
            </div>
            
        </main><!-- #main -->
	</div><!-- #primary -->
    
    <div class="caaps-sidebar">
		<?php get_sidebar(); ?>
    </div>
</div><!-- .wrap -->
<?php
get_footer();
?>