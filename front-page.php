<?php
/**
 * Front page template.
 *
 * @package wp-theme
 */

get_header(); ?>

<div class="container" style="max-width: 1440px; padding: 50px 15px; margin: 0 auto;">
    <h2>This is a demo page of your new project, you can delete it (<code>/front-page.php</code>)</h2>
    <h3>But pay attention to the form implementation, it might be useful 😉</h3>
  
    <?php echo do_shortcode( '[form id="contact-form"]' ); ?>
</div>

<?php
get_footer();
