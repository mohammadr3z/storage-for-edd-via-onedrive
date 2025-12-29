/**
 * OneDrive Upload Tab JavaScript
 * Matching Dropbox plugin pattern
 */
jQuery(function ($) {
    // Handler for "Use this file in your Download" button after upload
    $('#odse_save_link').click(function () {
        // Try to handle parent window interaction safely
        if (parent.window && parent.window !== window) {
            if (parent.window.edd_filename && parent.window.edd_fileurl) {
                $(parent.window.edd_filename).val($(this).data('odse-fn'));
                $(parent.window.edd_fileurl).val(odse_url_prefix + $(this).data('odse-path'));
                try { parent.window.tb_remove(); } catch (e) { parent.window.tb_remove(); }
                return;
            }
        }

        // Fallback or same-window context (rare for TB but possible)
        if (window.edd_filename && window.edd_fileurl) {
            $(window.edd_filename).val($(this).data('odse-fn'));
            $(window.edd_fileurl).val(odse_url_prefix + $(this).data('odse-path'));
        }

        // Final fallback: try to find inputs by name if global vars fail
        var $filenameInput = $(parent.document).find('input[name*="edd_download_files"][name*="[name]"]').last();
        var $fileurlInput = $(parent.document).find('input[name*="edd_download_files"][name*="[file]"]').last();

        if ($filenameInput.length && $fileurlInput.length) {
            $filenameInput.val($(this).data('odse-fn'));
            $fileurlInput.val(odse_url_prefix + $(this).data('odse-path'));
            try { parent.window.tb_remove(); } catch (e) { parent.window.tb_remove(); }
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
