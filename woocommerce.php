<?php
/**
 * WooCommerce page template.
 *
 * @package wp-theme
 */

get_header();
?>
<main id="primary" class="site-main">

    <?php woocommerce_content(); ?>

</main>
<?php
get_footer();
