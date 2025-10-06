<?php
/**
 * Template part for displaying a message that posts cannot be found.
 *
 * @package wp-theme
 */

?>
<section class="no-results not-found">
    <h1 class="page-title">Nothing Found</h1>
    <div class="page-content">
    <?php
    if (is_search()) :
        ?>
        <p>Sorry, but nothing matched your search terms. Please try again with some different keywords.</p>
        <?php
        get_search_form();
    else :
        ?>
        <p>It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps searching can help.</p>
        <?php
        get_search_form();
    endif;
    ?>
    </div>
</section>