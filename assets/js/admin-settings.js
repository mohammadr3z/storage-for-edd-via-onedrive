/**
 * OneDrive Admin Settings JavaScript
 * 
 * Handles settings page interactions
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Disable folder select when not connected
        if ($('.odse-disabled select').length) {
            $('.odse-disabled select').prop('disabled', true);
        }


    });

})(jQuery);
