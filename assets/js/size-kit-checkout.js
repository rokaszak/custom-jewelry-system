/**
 * Size Kit checkout modal – clicking the checkbox opens the modal; user must choose Sutinku or Nesutinku.
 */
(function($) {
    'use strict';

    var $modal, $backdrop, $checkbox, $btnAccept, $btnDecline;

    function init() {
        var $wrap = $('#size_kit_checkout_field');
        if (!$wrap.length) {
            return;
        }

        $checkbox = $wrap.find('input[name="size_kit_checkbox"]');
        if (!$checkbox.length) {
            return;
        }

        $modal = $('#cjs-size-kit-modal');
        if (!$modal.length) {
            return;
        }

        $backdrop = $modal.find('.cjs-size-kit-modal-backdrop');
        $btnAccept = $modal.find('.cjs-size-kit-btn-accept');
        $btnDecline = $modal.find('.cjs-size-kit-btn-decline');

        // Use mousedown so we intercept before the browser toggles the checkbox
        // (click fires after toggle; label clicks can bypass our handler)
        $wrap.on('mousedown', function(e) {
            var $target = $(e.target);
            var isCheckbox = $target.is('input[name="size_kit_checkbox"]');
            var isInLabel = $target.closest('label').find('input[name="size_kit_checkbox"]').length > 0;
            if (!isCheckbox && !isInLabel) return;
            if ($checkbox.is(':checked')) {
                return; // Allow native uncheck (no preventDefault)
            }
            e.preventDefault();
            e.stopPropagation();
            openModal();
        });
        $btnAccept.on('click', onAccept);
        $btnDecline.on('click', onDecline);
        $backdrop.on('click', onDecline);
    }

    function openModal() {
        $modal.attr('hidden', false);
    }

    function closeModal() {
        $modal.attr('hidden', true);
    }

    function onAccept() {
        $checkbox.prop('checked', true);
        closeModal();
    }

    function onDecline() {
        $checkbox.prop('checked', false);
        closeModal();
    }

    $(function() {
        init();
    });
})(jQuery);
