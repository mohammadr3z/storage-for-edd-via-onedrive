/**
 * OneDrive Media Library JavaScript
 */
jQuery(function ($) {
    // File selection handler
    $('.save-odse-file').click(function () {
        var filename = $(this).data('odse-filename');
        var fileurl = odse_url_prefix + $(this).data('odse-link');

        // Check for ODSE Modal variables (New System)
        if (parent.window && parent.window.odse_current_name_input && parent.window.odse_current_url_input) {
            parent.window.odse_current_name_input.val(filename);
            parent.window.odse_current_url_input.val(fileurl);

            if (parent.ODSEModal) {
                parent.ODSEModal.close();
            }
            // Alert is not needed as the modal closes immediately, providing visual feedback
        }

        return false;
    });

    // Search functionality for OneDrive files
    $('#odse-file-search').on('input search', function () {
        var searchTerm = $(this).val().toLowerCase();
        var $fileRows = $('.odse-files-table tbody tr');
        var visibleCount = 0;

        $fileRows.each(function () {
            var $row = $(this);
            var fileName = $row.find('.file-name').text().toLowerCase();

            if (fileName.indexOf(searchTerm) !== -1) {
                $row.show();
                visibleCount++;
            } else {
                $row.hide();
            }
        });

        // Show/hide "no results" message
        var $noResults = $('.odse-no-search-results');
        if (visibleCount === 0 && searchTerm.length > 0) {
            if ($noResults.length === 0) {
                $('.odse-files-table').after('<div class="odse-no-search-results" style="padding: 20px; text-align: center; color: #666; font-style: italic;">No files found matching your search.</div>');
            } else {
                $noResults.show();
            }
        } else {
            $noResults.hide();
        }
    });



    // Keyboard shortcut for search
    $(document).keydown(function (e) {
        if ((e.ctrlKey || e.metaKey) && e.keyCode === 70) {
            e.preventDefault();
            $('#odse-file-search').focus();
        }
    });

    // Toggle upload form
    $('#odse-toggle-upload').click(function () {
        $('#odse-upload-section').slideToggle(200);
    });
});
