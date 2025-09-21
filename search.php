<?php
/**
 * Search results page template.
 *
 * @package wp-theme
 */

get_header();
?>
<main id="primary" class="site-main">
    <?php
    if (function_exists( 'yoast_breadcrumb' )) {
        yoast_breadcrumb( '<p id="breadcrumbs">', '</p>' );
    }
    ?>
    <?php if (have_posts()) : ?>
    <h1 class="page-title">
        <?php
        printf( 'Search Results for: %s', '<span>' . get_search_query() . '</span>' );
        ?>
    </h1>
    <br>
        <?php
        while (have_posts()) :
            the_post();
            get_template_part( 'template-parts/content', 'search' );
        endwhile;
        the_posts_navigation();
    else :
        get_template_part( 'template-parts/content', 'none' );
    endif;
    ?>
</main>
<?php
get_footer();
