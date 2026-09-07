(function($) {
    'use strict';

    var config = window.cjs_checkout_deadline || {};

    function parseYmd(value) {
        if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return null;
        }
        var parts = value.split('-');
        return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    }

    function init() {
        var $wrap = $('#cjs_custom_deadline_field');
        if (!$wrap.length || $wrap.data('cjsDeadlineReady')) {
            return;
        }
        $wrap.data('cjsDeadlineReady', true);

        var $radios = $wrap.find('input[name="cjs_custom_deadline"]');
        var $row = $wrap.find('.cjs-custom-deadline-date-row');
        var $input = $wrap.find('#cjs_custom_deadline_date');
        var minDate = parseYmd($input.data('min-date') || config.min_date);
        var maxDate = parseYmd($input.data('max-date') || config.max_date);

        function toggle() {
            var yes = $radios.filter(':checked').val() === 'yes';
            $radios.each(function() {
                $(this).closest('.cjs-custom-deadline-option').toggleClass('is-checked', this.checked);
            });
            $row.toggleClass('cjs-hidden', !yes);
            if (!yes) {
                $input.val('');
            }
        }

        $radios.on('change', toggle);
        toggle();

        if ($.fn.datepicker) {
            $input.datepicker({
                dateFormat: 'yy-mm-dd',
                minDate: minDate,
                maxDate: maxDate,
                firstDay: 1,
                monthNames: config.month_names,
                monthNamesShort: config.month_names,
                dayNamesMin: config.day_names_min,
                prevText: config.prev_text || '',
                nextText: config.next_text || '',
                showAnim: '',
                beforeShow: function(input, inst) {
                    inst.dpDiv.addClass('cjs-deadline-datepicker');
                },
                onSelect: function() {
                    $(this).trigger('change');
                }
            });
            $input.on('focus click', function() {
                $(this).datepicker('show');
            });
        } else {
            $input.prop('readonly', false).attr('type', 'date');
            if (config.min_date) {
                $input.attr('min', config.min_date);
            }
            if (config.max_date) {
                $input.attr('max', config.max_date);
            }
        }
    }

    $(init);
    $(document.body).on('updated_checkout', init);
})(jQuery);
