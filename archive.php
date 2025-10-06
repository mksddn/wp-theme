<?php
/**
 * Archive page template.
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
        <?php
        the_archive_title( '<h1 class="page-title">', '</h1>' );
        the_archive_description( '<div class="archive-description">', '</div>' );
        ?>

    <br>

        <?php
        while (have_posts()) :
            the_post();

            get_template_part( 'template-parts/content' );
        endwhile;

        the_posts_navigation();
    else :
        get_template_part( 'template-parts/content', 'none' );
    endif;
    ?>
</main>
<?php
get_footer();
