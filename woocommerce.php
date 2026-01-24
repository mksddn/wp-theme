<?php
/**
 * WooCommerce page template.
 * Uses WordPress template hierarchy - child theme templates are automatically checked first.
 *
 * @package wp-theme
 */

// WordPress template hierarchy automatically checks child theme first:
// 1. Child theme: woocommerce.php
// 2. Child theme: woocommerce/archive-product.php (for shop)
// 3. Child theme: woocommerce/single-product.php (for products)
// 4. Parent theme: woocommerce.php (this file) - only if child doesn't have templates

// This file only serves as fallback when child theme doesn't override templates
get_header('shop');
?>
<main id="primary" class="site-main">
    <?php woocommerce_content(); ?>
</main>
<?php
get_footer('shop');