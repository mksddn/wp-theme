<?php
/**
 * Template part for displaying a message that posts cannot be found.
 *
 * @package wp-theme
 */

?>
<section class="no-results not-found">
    <h1 class="page-title"><?php esc_html_e('Nothing Found', 'wp-theme'); ?></h1>
    <div class="page-content">
    <?php
    if (is_search()) :
        ?>
        <p><?php esc_html_e('Sorry, but nothing matched your search terms. Please try again with some different keywords.', 'wp-theme'); ?></p>
        <?php
        get_search_form();
    else :
        ?>
        <p><?php esc_html_e('It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps searching can help.', 'wp-theme'); ?></p>
        <?php
        get_search_form();
    endif;
    ?>
    </div>
</section>