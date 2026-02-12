/**
 * OneDrive Media Library JavaScript (AJAX Version)
 */
window.ODSEMediaLibrary = (function ($) {
    var $container;

    // Initialize events using delegation (so they persist after content updates)
    function initEvents() {
        $container = $('#odse-modal-container');

        // Folder Navigation
        $(document).on('click', '.odse-folder-row a, .odse-breadcrumb-nav a', function (e) {
            e.preventDefault();
            var path = $(this).data('path');
            if (path !== undefined) {
                loadLibrary(path);
            }
        });

        // File Selection
        $(document).on('click', '.save-odse-file', function (e) {
            e.preventDefault();
            var filename = $(this).data('odse-filename');
            var fileurl = odse_url_prefix + $(this).data('odse-link');
            selectFile(filename, fileurl);
        });

        // Search
        $(document).on('input search', '#odse-file-search', function () {
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
        $(document).on('keydown', function (e) {
            if ($('#odse-modal-overlay').is(':visible') && (e.ctrlKey || e.metaKey) && e.keyCode === 70) {
                e.preventDefault();
                $('#odse-file-search').focus();
            }
        });

        // Toggle upload form
        $(document).on('click', '#odse-toggle-upload', function () {
            $('#odse-upload-section').slideToggle(200);
        });
    }

    // Helper to show notice
    function showError(message) {
        $('.odse-notice').remove();
        var errorHtml = '<div class="odse-notice warning"><p>' + message + '</p></div>';
        if ($('.odse-files-table').length) {
            $('.odse-files-table').before(errorHtml);
        } else {
            $('#odse-modal-container').prepend(errorHtml);
        }
    }

    // Load library content via AJAX
    function loadLibrary(path) {
        $container = $('#odse-modal-container'); // Refresh ref

        if (path && typeof path === 'string' && path.indexOf('?') !== -1) {
            try {
                var urlObj = new URL(path, window.location.origin);
                var params = new URLSearchParams(urlObj.search);
                if (params.has('path')) {
                    path = decodeURIComponent(params.get('path'));
                } else {
                    path = ''; // Default to root
                }
            } catch (e) {
                if (path.indexOf('path=') !== -1) {
                    var match = path.match(/path=([^&]*)/);
                    if (match) {
                        path = decodeURIComponent(match[1]);
                    }
                } else {
                    path = '';
                }
            }
        }

        // Check if container is visible (navigation mode)
        if ($container.is(':visible')) {
            // Remove notices
            $container.find('.odse-notice, .odse-no-search-results').remove();

            // If table exists, just replace tbody content with skeleton
            var $table = $container.find('.odse-files-table');
            if ($table.length && window.ODSEModal) {
                $table.addClass('odse-skeleton-table');
                $table.find('tbody').html(ODSEModal.getSkeletonRows());
            }
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'odse_get_library',
                path: path,
                _wpnonce: odse_browse_button.nonce
            },
            success: function (response) {
                if (response.success) {
                    $container.html(response.data.html);
                    // Update upload path hidden input
                    $('input[name="odse_path"]').val(path);

                    // Notify modal to hide skeleton if it was initial load
                    $(document).trigger('odse_content_loaded');
                } else {
                    showError('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function () {
                showError('Ajax connection error');
            }
        });
    }

    function selectFile(filename, fileurl) {
        if (window.odse_current_name_input && window.odse_current_url_input) {
            $(window.odse_current_name_input).val(filename);
            $(window.odse_current_url_input).val(fileurl);

            // Close modal
            if (window.ODSEModal) {
                window.ODSEModal.close();
            }
        } else {
            alert(odse_i18n.file_selected_error);
        }
    }

    // Auto-init on script load
    $(document).ready(function () {
        initEvents();
    });

    return {
        load: loadLibrary,
        reload: function () {
            loadLibrary('');
        }
    };

})(jQuery);
