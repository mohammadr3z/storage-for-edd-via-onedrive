/**
 * ODSE Modal JS
 */
var ODSEModal = (function ($) {
    var $modal, $overlay, $iframe, $closeBtn, $skeleton;

    function init() {
        if ($('#odse-modal-overlay').length) {
            return;
        }

        // Skeleton HTML structure
        var skeletonHtml =
            '<div class="odse-skeleton-loader">' +
            '<div class="odse-skeleton-header">' +
            '<div class="odse-skeleton-title"></div>' +
            '<div class="odse-skeleton-button"></div>' +
            '</div>' +
            '<div class="odse-skeleton-breadcrumb">' +
            '<div class="odse-skeleton-back-btn"></div>' +
            '<div class="odse-skeleton-path"></div>' +
            '<div class="odse-skeleton-search"></div>' +
            '</div>' +
            '<div class="odse-skeleton-table">' +
            '<div class="odse-skeleton-thead">' +
            '<div class="odse-skeleton-row">' +
            '<div class="odse-skeleton-cell name"></div>' +
            '<div class="odse-skeleton-cell size"></div>' +
            '<div class="odse-skeleton-cell date"></div>' +
            '<div class="odse-skeleton-cell action"></div>' +
            '</div>' +
            '</div>' +
            '<div class="odse-skeleton-row">' +
            '<div class="odse-skeleton-cell name"></div>' +
            '<div class="odse-skeleton-cell size"></div>' +
            '<div class="odse-skeleton-cell date"></div>' +
            '<div class="odse-skeleton-cell action"></div>' +
            '</div>' +
            '</div>' +
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
            '<iframe class="odse-modal-frame loading" src=""></iframe>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        $overlay = $('#odse-modal-overlay');
        $modal = $overlay.find('.odse-modal');
        $iframe = $overlay.find('.odse-modal-frame');
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

        // Handle iframe load event
        $iframe.on('load', function () {
            $skeleton.addClass('hidden');
            $iframe.removeClass('loading').addClass('loaded');
        });
    }

    function open(url, title) {
        init();
        $title.text(title || 'Select File');

        // Reset state: show skeleton, hide iframe
        $skeleton.removeClass('hidden');
        $iframe.removeClass('loaded').addClass('loading');

        $iframe.attr('src', url);
        $overlay.addClass('open');
        $('body').css('overflow', 'hidden');
    }

    function close() {
        if ($overlay) {
            $overlay.removeClass('open');
            $iframe.attr('src', '');
            $iframe.removeClass('loaded').addClass('loading');
            $skeleton.removeClass('hidden');
            $('body').css('overflow', '');
        }
    }

    return {
        open: open,
        close: close
    };

})(jQuery);
