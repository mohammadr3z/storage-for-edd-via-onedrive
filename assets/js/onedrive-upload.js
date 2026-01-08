jQuery(function ($) {
    // Handler for "Use this file in your Download" button after upload
    $('#odse_save_link').click(function () {
        var filename = $(this).data('odse-fn');
        var path = $(this).data('odse-path');
        var fileurl = odse_url_prefix + path;

        // Check for ODSE Modal variables (New System)
        if (parent.window && parent.window.odse_current_name_input && parent.window.odse_current_url_input) {
            parent.window.odse_current_name_input.val(filename);
            parent.window.odse_current_url_input.val(fileurl);

            if (parent.ODSEModal) {
                parent.ODSEModal.close();
            }
        }
    });

    // File size validation before upload
    $('input[name="odse_file"]').on('change', function () {
        if (this.files && this.files[0]) {
            var fileSize = this.files[0].size;
            var maxSize = odse_max_upload_size;
            if (fileSize > maxSize) {
                alert(odse_i18n.file_size_too_large + ' ' + (maxSize / 1024 / 1024).toFixed(2) + 'MB');
                this.value = '';
            }
        }
    });
});
