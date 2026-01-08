/**
 * ODSE Modal JS
 */
var ODSEModal = (function ($) {
    var $modal, $overlay, $iframe, $closeBtn;

    function init() {
        if ($('#odse-modal-overlay').length) {
            return;
        }

        // Create DOM structure
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
            '<iframe class="odse-modal-frame" src=""></iframe>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        $overlay = $('#odse-modal-overlay');
        $modal = $overlay.find('.odse-modal');
        $iframe = $overlay.find('.odse-modal-frame');
        $title = $overlay.find('.odse-modal-title');
        $closeBtn = $overlay.find('.odse-modal-close');

        // Event listeners
        $closeBtn.on('click', close);
        $overlay.on('click', function (e) {
            if ($(e.target).is($overlay)) {
                close();
            }
        });

        // Close on Escape key
        $(document).on('keydown', function (e) {
            if (e.keyCode === 27 && $overlay.hasClass('open')) { // ESC
                close();
            }
        });
    }

    function open(url, title) {
        init();
        $title.text(title || 'Select File');
        $iframe.attr('src', url);
        $overlay.addClass('open');
        $('body').css('overflow', 'hidden'); // Prevent body scroll
    }

    function close() {
        if ($overlay) {
            $overlay.removeClass('open');
            $iframe.attr('src', ''); // Stop loading
            $('body').css('overflow', '');
        }
    }

    return {
        open: open,
        close: close
    };

})(jQuery);
