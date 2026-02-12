/**
 * ODSE Modal JS
 */
var ODSEModal = (function ($) {
    var $modal, $overlay, $container, $closeBtn, $skeleton;

    // Skeleton rows - shared with ODSEMediaLibrary
    var skeletonRowsHtml =
        '<tr><td><div class="odse-skeleton-cell" style="width: 70%;"></div></td><td><div class="odse-skeleton-cell" style="width: 60%;"></div></td><td><div class="odse-skeleton-cell" style="width: 80%;"></div></td><td><div class="odse-skeleton-cell" style="width: 70%;"></div></td></tr>' +
        '<tr><td><div class="odse-skeleton-cell" style="width: 55%;"></div></td><td><div class="odse-skeleton-cell" style="width: 50%;"></div></td><td><div class="odse-skeleton-cell" style="width: 75%;"></div></td><td><div class="odse-skeleton-cell" style="width: 70%;"></div></td></tr>' +
        '<tr><td><div class="odse-skeleton-cell" style="width: 80%;"></div></td><td><div class="odse-skeleton-cell" style="width: 45%;"></div></td><td><div class="odse-skeleton-cell" style="width: 70%;"></div></td><td><div class="odse-skeleton-cell" style="width: 70%;"></div></td></tr>' +
        '<tr><td><div class="odse-skeleton-cell" style="width: 65%;"></div></td><td><div class="odse-skeleton-cell" style="width: 55%;"></div></td><td><div class="odse-skeleton-cell" style="width: 85%;"></div></td><td><div class="odse-skeleton-cell" style="width: 70%;"></div></td></tr>';

    function init() {
        if ($('#odse-modal-overlay').length) {
            return;
        }

        // Skeleton HTML structure - uses real table with skeleton rows
        var skeletonHtml =
            '<div class="odse-skeleton-loader">' +
            '<div class="odse-header-row">' +
            '<h3 class="media-title">' + (typeof odse_browse_button !== 'undefined' && odse_browse_button.i18n_select_file || 'Select a file from OneDrive') + '</h3>' +
            '<div class="odse-header-buttons">' +
            '<button type="button" class="button button-primary" id="odse-toggle-upload">' + (typeof odse_browse_button !== 'undefined' && odse_browse_button.i18n_upload || 'Upload File') + '</button>' +
            '</div>' +
            '</div>' +
            '<div class="odse-breadcrumb-nav odse-skeleton-breadcrumb">' +
            '<div class="odse-nav-group">' +
            '<span class="odse-nav-back disabled"><span class="dashicons dashicons-arrow-left-alt2"></span></span>' +
            '<div class="odse-breadcrumbs"><div class="odse-skeleton-cell" style="width: 120px; height: 18px;"></div></div>' +
            '</div>' +
            '<div class="odse-search-inline"><input type="search" class="odse-search-input" placeholder="' + (typeof odse_browse_button !== 'undefined' && odse_browse_button.i18n_search || 'Search files...') + '" disabled></div>' +
            '</div>' +
            '<table class="wp-list-table widefat fixed odse-files-table">' +
            '<thead><tr>' +
            '<th class="column-primary" style="width: 40%;">' + (typeof odse_browse_button !== 'undefined' && odse_browse_button.i18n_file_name || 'File Name') + '</th>' +
            '<th class="column-size" style="width: 20%;">' + (typeof odse_browse_button !== 'undefined' && odse_browse_button.i18n_file_size || 'File Size') + '</th>' +
            '<th class="column-date" style="width: 25%;">' + (typeof odse_browse_button !== 'undefined' && odse_browse_button.i18n_last_modified || 'Last Modified') + '</th>' +
            '<th class="column-actions" style="width: 15%;">' + (typeof odse_browse_button !== 'undefined' && odse_browse_button.i18n_actions || 'Actions') + '</th>' +
            '</tr></thead>' +
            '<tbody>' + skeletonRowsHtml + '</tbody></table>' +
            '</div>';

        // Create DOM structure with skeleton
        var html =
            '<div id="odse-modal-overlay" class="odse-modal-overlay">' +
            '<div class="odse-modal">' +
            '<div class="odse-modal-header">' +
            '<h1 class="odse-modal-title"></h1>' +
            '<button type="button" class="odse-modal-close">' +
            '<span class="dashicons dashicons-no-alt"></span>' +
            '</button>' +
            '</div>' +
            '<div class="odse-modal-content">' +
            skeletonHtml +
            '<div id="odse-modal-container" class="odse-modal-container hidden"></div>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        $overlay = $('#odse-modal-overlay');
        $modal = $overlay.find('.odse-modal');
        $container = $overlay.find('#odse-modal-container');
        $title = $overlay.find('.odse-modal-title');
        $closeBtn = $overlay.find('.odse-modal-close');
        $skeleton = $overlay.find('.odse-skeleton-loader');

        // Event listeners
        $closeBtn.on('click', close);
        $overlay.on('click', function (e) {
            if ($(e.target).is($overlay)) {
                close();
            }
        });

        // Close on Escape key
        $(document).on('keydown', function (e) {
            if (e.keyCode === 27 && $overlay.hasClass('open')) {
                close();
            }
        });

        // Global event for content loaded
        $(document).on('odse_content_loaded', function () {
            $skeleton.addClass('hidden');
            $container.removeClass('hidden');
        });
    }

    function open(url, title, isPath) {
        init();
        $title.text(title || 'Select File');

        // Reset state: show skeleton, hide container
        $skeleton.removeClass('hidden');
        $container.addClass('hidden');

        $overlay.addClass('open');
        $('body').css('overflow', 'hidden');

        // Trigger library load
        if (window.ODSEMediaLibrary) {
            window.ODSEMediaLibrary.load(url || '', isPath);
        }
    }

    function close() {
        if ($overlay) {
            $overlay.removeClass('open');
            $container.html('');
            $skeleton.removeClass('hidden');
            $container.addClass('hidden');
            $('body').css('overflow', '');
        }
    }

    return {
        open: open,
        close: close,
        getSkeletonRows: function () {
            return skeletonRowsHtml;
        }
    };

})(jQuery);
