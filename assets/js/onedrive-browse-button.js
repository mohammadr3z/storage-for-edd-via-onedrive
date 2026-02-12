/**
 * OneDrive Browse Button Script
 * Handles OneDrive browse button click events in EDD download files section
 */
jQuery(function ($) {
    // Event delegation for all browse buttons
    $(document).on('click', '.odse_browse_button', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $row = $btn.closest('.edd_repeatable_row');

        // Store references to the input fields for this row
        window.odse_current_row = $row;
        window.odse_current_name_input = $row.find('input[name^="edd_download_files"][name$="[name]"]');
        window.odse_current_url_input = $row.find('input[name^="edd_download_files"][name$="[file]"]');

        // Context-Aware: Extract folder path from current URL
        var currentUrl = window.odse_current_url_input.val();
        var folderPath = '';
        var urlPrefix = odse_browse_button.url_prefix;

        if (currentUrl && currentUrl.indexOf(urlPrefix) === 0) {
            // Remove prefix
            var path = currentUrl.substring(urlPrefix.length);
            // Remove filename, keep folder path
            var lastSlash = path.lastIndexOf('/');
            if (lastSlash !== -1) {
                folderPath = path.substring(0, lastSlash);
            }
        }

        // Open Modal
        // Pass true for isPath because we are providing a folder path string (e.g. "Attachments"), not an ID
        ODSEModal.open(folderPath, odse_browse_button.modal_title, true);
    });
});
