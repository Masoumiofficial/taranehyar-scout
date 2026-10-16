/**
 * Taranehyar Scout Admin JS
 */
jQuery(document).ready(function($) {
    // Theme toggle if needed
    console.log('Taranehyar Scout Admin Loaded v' + (typeof tscout_ajax !== 'undefined' ? '1.0.0' : 'unknown'));
    
    // Auto-dismiss notices after 5 seconds
    setTimeout(function() {
        $('.tscout-notice.auto-dismiss').fadeOut();
    }, 5000);
    
    // Confirm dangerous actions
    $('.tscout-confirm').on('click', function(e) {
        if (!confirm('آیا مطمئن هستید؟')) {
            e.preventDefault();
        }
    });
    
    // Handle file upload preview
    $('.tscout-file-upload input[type="file"]').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).siblings('.upload-text').text(fileName || 'انتخاب فایل یا بکشید اینجا');
    });
    
    // Drag and drop
    $('.tscout-file-upload').on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
    }).on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        var files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            $(this).find('input[type="file"]')[0].files = files;
            $(this).find('input[type="file"]').trigger('change');
        }
    });
});
