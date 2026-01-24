<?php
/**
 * 404 Page template.
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

    <section class="error-404 not-found">
        <article>
            <h1>
                404
            </h1>
            <br>
            <p><?php esc_html_e('Oops! That page can\'t be found.', 'wp-theme'); ?></p>
            <p><?php esc_html_e('It looks like nothing was found at this location.', 'wp-theme'); ?></p>
        </article>
    </section>

</main>
<?php
get_footer();
