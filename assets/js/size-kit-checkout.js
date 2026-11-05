/**
 * Size Kit checkout modal - clicking the checkbox opens the modal; user must choose Sutinku or Nesutinku.
 */
(function($) {
    'use strict';

    function setup($wrap) {
        var name = $wrap.data('cjs-field');
        var $checkbox = $wrap.find('input[name="' + name + '"]');
        if (!$checkbox.length) {
            return;
        }

        var $modal = $('#' + $wrap.data('cjs-modal'));
        if (!$modal.length) {
            return;
        }

        var $backdrop = $modal.find('.cjs-size-kit-modal-backdrop');
        var $btnAccept = $modal.find('.cjs-size-kit-btn-accept');
        var $btnDecline = $modal.find('.cjs-size-kit-btn-decline');

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

        $wrap.on('mousedown', function(e) {
            var $target = $(e.target);
            var isCheckbox = $target.is($checkbox);
            var isInLabel = $target.closest('label').find($checkbox).length > 0;
            if (!isCheckbox && !isInLabel) return;
            if ($checkbox.is(':checked')) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            openModal();
        });
        $btnAccept.on('click', onAccept);
        $btnDecline.on('click', onDecline);
        $backdrop.on('click', onDecline);
    }

    function init() {
        $('.cjs-offer-field').each(function() {
            setup($(this));
        });
    }

    $(function() {
        init();
    });
})(jQuery);
