jQuery(document).ready(function($) {
    let mediaUploader;

    $('#upload_category_thumbnail').on('click', function(e) {
        e.preventDefault();
        
        mediaUploader = wp.media({
            title: categoryThumbnailL10n.chooseThumbnail,
            button: { text: categoryThumbnailL10n.chooseImage },
            multiple: false
        });
        
        mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#category_thumbnail').val(attachment.id);
            
            // Get image URL with fallback
            let imageUrl = attachment.url; // fallback to full size
            if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                imageUrl = attachment.sizes.thumbnail.url;
            } else if (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) {
                imageUrl = attachment.sizes.medium.url;
            }
            
            $('#category_thumbnail_preview').html('<img src="' + imageUrl + '" style="max-width: 150px; height: auto;" />');
            $('#remove_category_thumbnail').show();
        });
        
        mediaUploader.open();
    });

    $('#remove_category_thumbnail').on('click', function(e) {
        e.preventDefault();
        $('#category_thumbnail').val('');
        $('#category_thumbnail_preview').html('');
        $(this).hide();
    });
});
