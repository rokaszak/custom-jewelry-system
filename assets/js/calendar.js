(function($) {
    'use strict';

    window.CJSCalendar = {

        calendar: null,
        settings: {},
        types: {},
        weekdays: {},
        dayOrder: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        viewStorageKey: 'cjs_calendar_view',
        allowedViews: ['timeGridDay', 'timeGridWeek', 'dayGridMonth', 'listWeek'],
        currentEvent: null,
        animateNextRender: false,
        enterIndex: 0,

        init: function() {
            var data = window.cjs_calendar_data || {};
            this.settings = data.settings || {};
            this.types = data.types || {};
            this.weekdays = data.weekdays || {};
            this.ordersUrl = data.orders_url || '';

            this.initCalendar();
            this.bindEvents();
        },

        pad2: function(n) {
            return n < 10 ? '0' + n : String(n);
        },

        fmtDT: function(date) {
            return date.getFullYear() + '-' + this.pad2(date.getMonth() + 1) + '-' + this.pad2(date.getDate()) +
                ' ' + this.pad2(date.getHours()) + ':' + this.pad2(date.getMinutes()) + ':00';
        },

        toDateInput: function(date) {
            return date.getFullYear() + '-' + this.pad2(date.getMonth() + 1) + '-' + this.pad2(date.getDate());
        },

        toTimeInput: function(date) {
            return this.pad2(date.getHours()) + ':' + this.pad2(date.getMinutes());
        },

        normTime: function(value) {
            var match = /^(\d{1,2}):(\d{2})$/.exec($.trim(value || ''));
            if (!match) {
                return null;
            }
            var h = parseInt(match[1], 10);
            var m = parseInt(match[2], 10);
            if (h > 23 || m > 59) {
                return null;
            }
            return this.pad2(h) + ':' + this.pad2(m);
        },

        combine: function(dateValue, timeValue) {
            var time = this.normTime(timeValue);
            if (!dateValue || !time) {
                return null;
            }
            return dateValue + ' ' + time + ':00';
        },

        timeToMinutes: function(time) {
            var parts = time.split(':');
            return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
        },

        defaultView: function() {
            var stored = null;
            try {
                stored = localStorage.getItem(this.viewStorageKey);
            } catch (e) {}
            if (stored && this.allowedViews.indexOf(stored) !== -1) {
                return stored;
            }
            return window.matchMedia('(max-width: 768px)').matches ? 'listWeek' : 'timeGridWeek';
        },

        scrollTimeFromSettings: function() {
            var self = this;
            var min = null;
            this.dayOrder.forEach(function(key) {
                var day = self.settings.week && self.settings.week[key];
                if (day && parseInt(day.enabled, 10) && self.normTime(day.start)) {
                    var norm = self.normTime(day.start);
                    if (min === null || norm < min) {
                        min = norm;
                    }
                }
            });
            if (!min) {
                return '07:00:00';
            }
            var hour = Math.max(0, parseInt(min.slice(0, 2), 10) - 1);
            return this.pad2(hour) + ':00:00';
        },

        initCalendar: function() {
            var self = this;
            var el = document.getElementById('cjs-calendar');
            if (!el || typeof FullCalendar === 'undefined') {
                return;
            }

            var initialView = this.defaultView();
            $('#cjs-calendar-holder').toggleClass('cjs-cal-auto', initialView === 'dayGridMonth');
            $('.cjs-calendar-wrap').toggleClass('cjs-cal-auto-wrap', initialView === 'dayGridMonth');

            this.calendar = new FullCalendar.Calendar(el, {
                locale: 'lt',
                firstDay: 1,
                initialView: initialView,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridDay,timeGridWeek,dayGridMonth,listWeek'
                },
                height: initialView === 'dayGridMonth' ? 'auto' : '100%',
                nowIndicator: true,
                allDaySlot: false,
                slotDuration: '00:30:00',
                scrollTime: this.scrollTimeFromSettings(),
                expandRows: true,
                views: {
                    dayGridMonth: {
                        dayMaxEvents: 4
                    }
                },
                selectable: true,
                selectMirror: true,
                editable: true,
                eventResizableFromStart: true,
                longPressDelay: 300,
                eventLongPressDelay: 300,
                selectLongPressDelay: 400,
                events: function(info, success, failure) {
                    $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_calendar_intervals',
                        nonce: cjs_ajax.nonce,
                        start: info.startStr,
                        end: info.endStr
                    }).done(function(response) {
                        if (response.success) {
                            success(response.data.events);
                        } else {
                            failure(new Error('feed'));
                        }
                    }).fail(function() {
                        failure(new Error('feed'));
                    });
                },
                eventDrop: function(info) {
                    self.saveEventTimes(info);
                },
                eventResize: function(info) {
                    self.saveEventTimes(info);
                },
                eventDidMount: function(info) {
                    if (self.animateNextRender) {
                        var delay = Math.min(self.enterIndex * 30, 900);
                        self.enterIndex++;
                        info.el.classList.add('cjs-ev-enter');
                        info.el.style.animationDelay = delay + 'ms';
                    }
                    if (info.event.extendedProps.type === 'work') {
                        var status = self.statusDotClass(info.event.extendedProps);
                        if (status) {
                            if (info.view.type === 'dayGridMonth') {
                                var $title = $(info.el).find('.fc-event-title').first();
                                var match = /^(#\S+)([\s\S]*)$/.exec($title.text());
                                if (match) {
                                    $title.empty()
                                        .append($('<span>').addClass('cjs-id-' + status).text(match[1]))
                                        .append(document.createTextNode(match[2]));
                                }
                            } else if (info.view.type.indexOf('list') === 0) {
                                var $listTitle = $(info.el).find('.fc-list-event-title a').first();
                                if (!$listTitle.length) {
                                    $listTitle = $(info.el).find('.fc-list-event-title').first();
                                }
                                if ($listTitle.length && !$listTitle.find('.cjs-list-status').length) {
                                    var late = parseInt(info.event.extendedProps.days_late, 10);
                                    var text = late > 0 ? 'Vėluoja ' + late + ' d.' : 'Spėjama laiku';
                                    $listTitle.append($('<span>').addClass('cjs-list-status cjs-id-' + status).text(text));
                                }
                            } else {
                                var $target = $(info.el).find('.fc-event-title').first();
                                if ($target.length && !$target.find('.cjs-status-dot').length) {
                                    $target.prepend($('<span>').addClass('cjs-status-dot cjs-dot-' + status));
                                }
                            }
                        }
                    }
                },
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    self.openIntervalModal(info.event, null);
                },
                select: function(info) {
                    self.openIntervalModal(null, info);
                    self.calendar.unselect();
                },
                datesSet: function(info) {
                    var isMonth = info.view.type === 'dayGridMonth';
                    $('#cjs-calendar-holder').toggleClass('cjs-cal-auto', isMonth);
                    $('.cjs-calendar-wrap').toggleClass('cjs-cal-auto-wrap', isMonth);
                    var wanted = isMonth ? 'auto' : '100%';
                    if (self.calendar && self.calendar.getOption('height') !== wanted) {
                        self.calendar.setOption('height', wanted);
                    }
                    try {
                        localStorage.setItem(self.viewStorageKey, info.view.type);
                    } catch (e) {}
                }
            });

            this.calendar.render();
        },

        saveEventTimes: function(info) {
            var self = this;
            if (!info.event.start || !info.event.end) {
                info.revert();
                return;
            }
            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_calendar_interval_save',
                nonce: cjs_ajax.nonce,
                id: info.event.id,
                start: this.fmtDT(info.event.start),
                end: this.fmtDT(info.event.end)
            }).done(function(response) {
                if (!response.success) {
                    info.revert();
                    self.renderNotice(response.data && response.data.message ? response.data.message : cjs_ajax.strings.error, 'error');
                }
            }).fail(function() {
                info.revert();
                self.renderNotice(cjs_ajax.strings.error, 'error');
            });
        },

        bindEvents: function() {
            var self = this;

            $('#cjs-cal-recalc').on('click', function() {
                self.recalculate($(this));
            });

            $('#cjs-cal-new').on('click', function() {
                self.openIntervalModal(null, null);
            });

            $('#cjs-cal-settings').on('click', function() {
                self.openSettingsModal();
            });

            $('#cjs-ci-save').on('click', function() {
                self.saveInterval();
            });

            $('#cjs-ci-delete').on('click', function() {
                self.deleteInterval();
            });

            $('#cjs-ci-split-btn').on('click', function() {
                self.splitInterval();
            });

            $('#cjs-ci-color-custom').on('change', function() {
                self.syncColorLock();
            });

            $('#cjs-cs-save').on('click', function() {
                self.saveSettings($(this));
            });

            $('#cjs-cs-overflow').on('change', function() {
                $('#cjs-cs-aggro-row').toggle($(this).val() === 'increase');
            });

            $('#cjs-cs-rest-add').on('click', function() {
                $('#cjs-cs-rest').append(self.blockRow('22:00', '06:00'));
                self.updateRestUsage();
            });

            $(document).on('click', '.cjs-cs-remove', function() {
                var inRest = $(this).closest('#cjs-cs-rest').length > 0;
                $(this).closest('.cjs-cs-block').remove();
                if (inRest) {
                    self.updateRestUsage();
                }
            });

            $(document).on('click', '.cjs-cs-break-add', function() {
                $(this).closest('.cjs-cs-day-body').find('.cjs-cs-breaks').append(self.blockRow('12:00', '13:00'));
            });

            $(document).on('click', '.cjs-cs-copy', function() {
                self.copyDayToAll($(this).closest('.cjs-cs-day'));
            });

            $(document).on('change', '.cjs-cs-enabled', function() {
                $(this).closest('.cjs-cs-day').find('.cjs-cs-day-body').toggle(this.checked);
            });

            $(document).on('blur', '.cjs-time-text', function() {
                var norm = self.normTime($(this).val());
                if (norm !== null) {
                    $(this).val(norm).removeClass('cjs-time-invalid');
                } else if ($.trim($(this).val()) !== '') {
                    $(this).addClass('cjs-time-invalid');
                }
            });

            $(document).on('input', '#cjs-cs-rest .cjs-time-text, #cjs-cs-rest-hours', function() {
                self.updateRestUsage();
            });

            $(document).on('click', '.cjs-cal-notice-dismiss', function() {
                $('#cjs-calendar-notice').empty();
                if (self.calendar) {
                    self.calendar.updateSize();
                }
            });
        },

        renderNotice: function(html, type) {
            var cssClass = 'cjs-cal-notice cjs-cal-notice-' + (type || 'success');
            $('#cjs-calendar-notice').html(
                '<div class="' + cssClass + '">' +
                '<div class="cjs-cal-notice-body">' + html + '</div>' +
                '<button type="button" class="cjs-cal-notice-dismiss">&times;</button>' +
                '</div>'
            );
            if (this.calendar) {
                this.calendar.updateSize();
            }
        },

        modalError: function(selector, message) {
            if (message) {
                $(selector).text(message).show();
            } else {
                $(selector).text('').hide();
            }
        },

        escapeHtml: function(text) {
            return $('<div>').text(text == null ? '' : String(text)).html();
        },

        recalculate: function($btn) {
            var self = this;
            var $label = $btn.find('.cjs-cal-recalc-label');
            var original = $label.text();
            var startedAt = Date.now();

            $btn.prop('disabled', true).addClass('cjs-cal-generating');
            $label.text('Skaičiuojama...');
            $('#cjs-calendar-holder').addClass('cjs-cal-working');

            var finalize = function(callback) {
                var wait = Math.max(0, 2500 - (Date.now() - startedAt));
                setTimeout(function() {
                    $btn.prop('disabled', false).removeClass('cjs-cal-generating');
                    $label.text(original);
                    $('#cjs-calendar-holder').removeClass('cjs-cal-working');
                    if (callback) {
                        callback();
                    }
                }, wait);
            };

            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_calendar_recalculate',
                nonce: cjs_ajax.nonce,
                treat_passed: $('#cjs-cal-treat-done').is(':checked') ? 1 : 0
            }).done(function(response) {
                if (response.success) {
                    finalize(function() {
                        var data = response.data;
                        var html = 'Grafikas perskaičiuotas. Suplanuota užsakymų: ' + data.scheduled_orders + ', sukurta intervalų: ' + data.created + '.';
                        if (data.late && data.late.length) {
                            html += '<br /><strong>Nespėjama laiku:</strong><ul>';
                            data.late.forEach(function(item) {
                                if (item.days_late === null) {
                                    html += '<li>' + self.escapeHtml(item.name) + ' - netelpa į grafiką (terminas ' + self.escapeHtml(item.deadline) + ')</li>';
                                } else {
                                    html += '<li>' + self.escapeHtml(item.name) + ' - vėluoja ' + item.days_late + ' d. (terminas ' + self.escapeHtml(item.deadline) + ', baigiama ' + self.escapeHtml(item.finish) + ')</li>';
                                }
                            });
                            html += '</ul>';
                            self.renderNotice(html, 'warning');
                        } else {
                            self.renderNotice(html, 'success');
                        }
                        self.animateNextRender = true;
                        self.enterIndex = 0;
                        self.calendar.refetchEvents();
                        setTimeout(function() {
                            self.animateNextRender = false;
                        }, 2500);
                        $btn.addClass('cjs-cal-complete');
                        setTimeout(function() {
                            $btn.removeClass('cjs-cal-complete');
                        }, 900);
                    });
                } else {
                    finalize(function() {
                        self.renderNotice(response.data && response.data.message ? response.data.message : cjs_ajax.strings.error, 'error');
                    });
                }
            }).fail(function() {
                finalize(function() {
                    self.renderNotice(cjs_ajax.strings.error, 'error');
                });
            });
        },

        syncColorLock: function() {
            var unlocked = $('#cjs-ci-color-custom').is(':checked');
            $('#cjs-ci-color').prop('disabled', !unlocked);
            $('#cjs-ci-color-wrap').toggleClass('cjs-color-locked', !unlocked);
        },

        statusDotClass: function(props) {
            if (!props || props.type !== 'work' || !props.deadline || !props.finish) {
                return null;
            }
            if (props.days_late === null || props.days_late === undefined || props.days_late === '') {
                return null;
            }
            if (parseInt(props.days_late, 10) > 0) {
                return 'red';
            }
            var deadlineTs = new Date(props.deadline + 'T23:59:59').getTime();
            var finishTs = new Date(props.finish.replace(' ', 'T')).getTime();
            if (isNaN(deadlineTs) || isNaN(finishTs)) {
                return null;
            }
            return (deadlineTs - finishTs) / 86400000 < 10 ? 'yellow' : 'green';
        },

        fillDeadlineInfo: function(event) {
            var props = event ? event.extendedProps : null;
            if (!event || !props || props.type !== 'work' || !props.deadline) {
                $('#cjs-ci-deadline-row').hide();
                return;
            }

            var html = '<span class="cjs-ci-meta">Terminas: <strong>' + this.escapeHtml(props.deadline) + '</strong></span>';
            if (props.finish) {
                html += '<span class="cjs-ci-meta">Baigiama: <strong>' + this.escapeHtml(props.finish) + '</strong></span>';
            }
            if (props.days_late !== null && props.days_late !== undefined && props.days_late !== '') {
                if (parseInt(props.days_late, 10) > 0) {
                    html += '<span class="cjs-ci-late">Vėluoja ' + parseInt(props.days_late, 10) + ' d.</span>';
                } else {
                    html += '<span class="cjs-ci-ontime">Spėjama laiku</span>';
                }
            }
            $('#cjs-ci-deadline-info').html(html);
            $('#cjs-ci-deadline-row').show();
        },

        openIntervalModal: function(event, selection) {
            this.currentEvent = event;
            this.modalError('#cjs-ci-error', '');
            $('.cjs-time-invalid').removeClass('cjs-time-invalid');

            var isWork = false;
            var start;
            var end;

            if (event) {
                isWork = event.extendedProps.type === 'work';
                start = event.start;
                end = event.end || new Date(event.start.getTime() + 3600000);

                var $heading = $('#cjs-ci-heading').empty();
                var dot = this.statusDotClass(event.extendedProps);
                if (isWork && dot) {
                    $heading.append($('<span>').addClass('cjs-status-dot cjs-dot-' + dot));
                }
                $heading.append(document.createTextNode(isWork ? event.title : 'Įrašas'));
                $('#cjs-ci-id').val(event.id);
                $('#cjs-ci-name').val(event.title);
                $('#cjs-ci-type').val(event.extendedProps.type);
                $('#cjs-ci-locked').prop('checked', event.extendedProps.is_locked === 1);
                $('#cjs-ci-done').prop('checked', event.extendedProps.is_done === 1);

                var rawColor = event.extendedProps.raw_color;
                var typeDefault = !isWork && this.types[event.extendedProps.type] ? this.types[event.extendedProps.type].color : (event.backgroundColor || '#4285f4');
                $('#cjs-ci-color-custom').prop('checked', !!rawColor && !isWork).prop('disabled', isWork);
                $('#cjs-ci-color').val(rawColor || typeDefault);

                $('#cjs-ci-type-row').toggle(!isWork);
                $('.cjs-ci-color-row').toggle(!isWork);
                $('#cjs-ci-done-wrap').toggle(isWork);
                $('#cjs-ci-split-row').show();
                $('#cjs-ci-delete').show();

                if (isWork && event.extendedProps.order_id) {
                    $('#cjs-ci-order-row').show();
                    $('#cjs-ci-order-link').attr('href', this.ordersUrl + '&highlight_order=' + event.extendedProps.order_id + '#order' + event.extendedProps.order_id);
                } else {
                    $('#cjs-ci-order-row').hide();
                }

                var mid = new Date(start.getTime() + Math.round((end.getTime() - start.getTime()) / 2));
                mid.setMinutes(mid.getMinutes() - (mid.getMinutes() % 15));
                $('#cjs-ci-split-time').val(this.toTimeInput(mid));
            } else {
                if (selection) {
                    start = selection.start;
                    end = selection.end;
                } else {
                    start = new Date();
                    start.setHours(9, 0, 0, 0);
                    end = new Date(start.getTime() + 3600000);
                }

                $('#cjs-ci-heading').text('Naujas įrašas');
                $('#cjs-ci-id').val(0);
                $('#cjs-ci-name').val('');
                $('#cjs-ci-locked').prop('checked', false);
                $('#cjs-ci-done').prop('checked', false);
                $('#cjs-ci-color-custom').prop('checked', false).prop('disabled', false);

                var $type = $('#cjs-ci-type');
                if ($type.find('option').length) {
                    $type.val($type.find('option').first().val());
                }
                var firstType = $type.val();
                $('#cjs-ci-color').val(this.types[firstType] ? this.types[firstType].color : '#616161');

                $('#cjs-ci-type-row').show();
                $('.cjs-ci-color-row').show();
                $('#cjs-ci-done-wrap').hide();
                $('#cjs-ci-split-row').hide();
                $('#cjs-ci-delete').hide();
                $('#cjs-ci-order-row').hide();
            }

            this.syncColorLock();
            this.fillDeadlineInfo(event);

            $('#cjs-ci-start-date').val(this.toDateInput(start));
            $('#cjs-ci-start-time').val(this.toTimeInput(start));
            $('#cjs-ci-end-date').val(this.toDateInput(end));
            $('#cjs-ci-end-time').val(this.toTimeInput(end));

            $('#cjs-interval-modal').show();
        },

        saveInterval: function() {
            var self = this;
            var id = parseInt($('#cjs-ci-id').val(), 10) || 0;
            var isWork = this.currentEvent && this.currentEvent.extendedProps.type === 'work';

            var start = this.combine($('#cjs-ci-start-date').val(), $('#cjs-ci-start-time').val());
            var end = this.combine($('#cjs-ci-end-date').val(), $('#cjs-ci-end-time').val());

            if (!start || !end) {
                this.modalError('#cjs-ci-error', 'Nurodykite teisingą pradžią ir pabaigą (laikas HH:MM).');
                return;
            }

            var data = {
                action: 'cjs_calendar_interval_save',
                nonce: cjs_ajax.nonce,
                id: id,
                name: $('#cjs-ci-name').val(),
                start: start,
                end: end,
                is_locked: $('#cjs-ci-locked').is(':checked') ? 1 : 0,
                color: $('#cjs-ci-color-custom').is(':checked') ? $('#cjs-ci-color').val() : ''
            };

            if (isWork) {
                data.is_done = $('#cjs-ci-done').is(':checked') ? 1 : 0;
                data.color = this.currentEvent.extendedProps.raw_color || '';
            } else {
                data.type = $('#cjs-ci-type').val();
            }

            $.post(cjs_ajax.ajax_url, data).done(function(response) {
                if (response.success) {
                    $('#cjs-interval-modal').hide();
                    self.calendar.refetchEvents();
                } else {
                    self.modalError('#cjs-ci-error', response.data && response.data.message ? response.data.message : cjs_ajax.strings.error);
                }
            }).fail(function() {
                self.modalError('#cjs-ci-error', cjs_ajax.strings.error);
            });
        },

        deleteInterval: function() {
            var self = this;
            var id = parseInt($('#cjs-ci-id').val(), 10) || 0;
            if (!id) {
                return;
            }
            if (!confirm(cjs_ajax.strings.confirm_delete)) {
                return;
            }
            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_calendar_interval_delete',
                nonce: cjs_ajax.nonce,
                id: id
            }).done(function(response) {
                if (response.success) {
                    $('#cjs-interval-modal').hide();
                    self.calendar.refetchEvents();
                } else {
                    self.modalError('#cjs-ci-error', response.data && response.data.message ? response.data.message : cjs_ajax.strings.error);
                }
            });
        },

        splitInterval: function() {
            var self = this;
            var id = parseInt($('#cjs-ci-id').val(), 10) || 0;
            var time = this.normTime($('#cjs-ci-split-time').val());
            var dateVal = $('#cjs-ci-start-date').val();

            if (!id || !dateVal) {
                return;
            }
            if (!time) {
                this.modalError('#cjs-ci-error', 'Neteisingas dalinimo laikas (HH:MM).');
                return;
            }

            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_calendar_interval_split',
                nonce: cjs_ajax.nonce,
                id: id,
                at: dateVal + ' ' + time + ':00'
            }).done(function(response) {
                if (response.success) {
                    $('#cjs-interval-modal').hide();
                    self.calendar.refetchEvents();
                } else {
                    self.modalError('#cjs-ci-error', response.data && response.data.message ? response.data.message : cjs_ajax.strings.error);
                }
            });
        },

        blockRow: function(from, to) {
            return '<div class="cjs-cs-block">' +
                '<input type="text" inputmode="numeric" class="cjs-time-text cjs-cs-from" placeholder="HH:MM" value="' + this.escapeHtml(from) + '" /> &ndash; ' +
                '<input type="text" inputmode="numeric" class="cjs-time-text cjs-cs-to" placeholder="HH:MM" value="' + this.escapeHtml(to) + '" /> ' +
                '<button type="button" class="cjs-cs-remove" title="Pašalinti">&times;</button>' +
                '</div>';
        },

        openSettingsModal: function() {
            var self = this;
            this.modalError('#cjs-cs-error', '');
            var $week = $('#cjs-cs-week').empty();

            this.dayOrder.forEach(function(key) {
                var day = self.settings.week && self.settings.week[key] ? self.settings.week[key] : {enabled: 0, start: '08:00', end: '17:00', breaks: []};
                var enabled = !!parseInt(day.enabled, 10);

                var html = '<div class="cjs-cs-day" data-day="' + key + '">' +
                    '<div class="cjs-cs-day-head">' +
                    '<label><input type="checkbox" class="cjs-cs-enabled"' + (enabled ? ' checked' : '') + ' /> <strong>' + self.escapeHtml(self.weekdays[key] || key) + '</strong></label>' +
                    '<button type="button" class="button button-small cjs-cs-copy" title="Kopijuoti šios dienos laiką visoms įjungtoms dienoms"><span class="dashicons dashicons-admin-page"></span></button>' +
                    '</div>' +
                    '<div class="cjs-cs-day-body"' + (enabled ? '' : ' style="display:none;"') + '>' +
                    '<div class="cjs-cs-section-label">Darbo valandos</div>' +
                    '<div class="cjs-cs-hours">' +
                    '<input type="text" inputmode="numeric" class="cjs-time-text cjs-cs-start" placeholder="HH:MM" value="' + self.escapeHtml(day.start) + '" /> &ndash; ' +
                    '<input type="text" inputmode="numeric" class="cjs-time-text cjs-cs-end" placeholder="HH:MM" value="' + self.escapeHtml(day.end) + '" />' +
                    '</div>' +
                    '<div class="cjs-cs-section-label">Pertraukos <button type="button" class="button button-small cjs-cs-break-add" title="Pridėti pertrauką">+</button></div>' +
                    '<div class="cjs-cs-breaks"></div>' +
                    '</div>' +
                    '</div>';

                var $day = $(html);
                var breaks = day.breaks || [];
                breaks.forEach(function(block) {
                    $day.find('.cjs-cs-breaks').append(self.blockRow(block.from, block.to));
                });
                $week.append($day);
            });

            var $rest = $('#cjs-cs-rest').empty();
            (this.settings.rest || []).forEach(function(block) {
                $rest.append(self.blockRow(block.from, block.to));
            });

            $('#cjs-cs-rest-hours').val(this.settings.rest_hours !== undefined ? this.settings.rest_hours : 8);
            $('#cjs-cs-algorithm').val(this.settings.algorithm || 'fcfs');
            $('#cjs-cs-overflow').val(this.settings.overflow || 'delay');
            $('#cjs-cs-aggro').val(String(this.settings.aggro || 1));
            $('#cjs-cs-autosplit').val(String(this.settings.auto_split || 0));
            $('#cjs-cs-aggro-row').toggle(($('#cjs-cs-overflow').val()) === 'increase');

            this.updateRestUsage();

            $('#cjs-calendar-settings-modal').show();
        },

        restBlockMinutes: function(blocks) {
            var self = this;
            var total = 0;
            blocks.forEach(function(block) {
                var from = self.normTime(block.from);
                var to = self.normTime(block.to);
                if (!from || !to || from === to) {
                    return;
                }
                var fromMin = self.timeToMinutes(from);
                var toMin = self.timeToMinutes(to);
                total += fromMin < toMin ? (toMin - fromMin) : (1440 - fromMin + toMin);
            });
            return total;
        },

        updateRestUsage: function() {
            var used = this.restBlockMinutes(this.collectBlocks($('#cjs-cs-rest')));
            var required = Math.round((parseFloat($('#cjs-cs-rest-hours').val()) || 0) * 60);
            var usedHours = Math.round(used / 6) / 10;
            var requiredHours = Math.round(required / 6) / 10;
            var $usage = $('#cjs-cs-rest-usage');
            $usage.text('Panaudota: ' + usedHours + ' val. / ' + requiredHours + ' val.');
            $usage.toggleClass('cjs-rest-ok', used === required).toggleClass('cjs-rest-bad', used !== required);
        },

        copyDayToAll: function($sourceDay) {
            var self = this;
            var start = $sourceDay.find('.cjs-cs-start').val();
            var end = $sourceDay.find('.cjs-cs-end').val();
            var blocks = this.collectBlocks($sourceDay.find('.cjs-cs-breaks'));

            $('#cjs-cs-week .cjs-cs-day').each(function() {
                var $day = $(this);
                if ($day.is($sourceDay) || !$day.find('.cjs-cs-enabled').is(':checked')) {
                    return;
                }
                $day.find('.cjs-cs-start').val(start);
                $day.find('.cjs-cs-end').val(end);
                var $breaks = $day.find('.cjs-cs-breaks').empty();
                blocks.forEach(function(block) {
                    $breaks.append(self.blockRow(block.from, block.to));
                });
            });
        },

        collectBlocks: function($container) {
            var blocks = [];
            $container.find('.cjs-cs-block').each(function() {
                var from = $(this).find('.cjs-cs-from').val();
                var to = $(this).find('.cjs-cs-to').val();
                if ($.trim(from) && $.trim(to)) {
                    blocks.push({from: from, to: to});
                }
            });
            return blocks;
        },

        validateSettingsTimes: function() {
            var self = this;
            var valid = true;
            $('#cjs-calendar-settings-modal .cjs-time-text').each(function() {
                var $input = $(this);
                var value = $.trim($input.val());
                if (value === '') {
                    return;
                }
                var norm = self.normTime(value);
                if (norm === null) {
                    $input.addClass('cjs-time-invalid');
                    valid = false;
                } else {
                    $input.val(norm).removeClass('cjs-time-invalid');
                }
            });
            return valid;
        },

        saveSettings: function($btn) {
            var self = this;
            this.modalError('#cjs-cs-error', '');

            if (!this.validateSettingsTimes()) {
                this.modalError('#cjs-cs-error', 'Neteisingas laiko formatas. Naudokite 24 val. formatą HH:MM.');
                return;
            }

            var restBlocks = this.collectBlocks($('#cjs-cs-rest'));
            var restHours = parseFloat($('#cjs-cs-rest-hours').val()) || 0;
            var usedMinutes = this.restBlockMinutes(restBlocks);
            var requiredMinutes = Math.round(restHours * 60);

            if (usedMinutes !== requiredMinutes) {
                this.updateRestUsage();
                this.modalError('#cjs-cs-error', 'Poilsio blokai sudaro ' + (Math.round(usedMinutes / 6) / 10) + ' val., o privaloma lygiai ' + restHours + ' val. Pakoreguokite blokus arba privalomų valandų skaičių.');
                return;
            }

            var settings = {
                week: {},
                rest: restBlocks,
                rest_hours: restHours,
                algorithm: $('#cjs-cs-algorithm').val(),
                overflow: $('#cjs-cs-overflow').val(),
                aggro: parseInt($('#cjs-cs-aggro').val(), 10) || 1,
                auto_split: parseInt($('#cjs-cs-autosplit').val(), 10) || 0
            };

            $('#cjs-cs-week .cjs-cs-day').each(function() {
                var $day = $(this);
                settings.week[$day.data('day')] = {
                    enabled: $day.find('.cjs-cs-enabled').is(':checked') ? 1 : 0,
                    start: $day.find('.cjs-cs-start').val(),
                    end: $day.find('.cjs-cs-end').val(),
                    breaks: self.collectBlocks($day.find('.cjs-cs-breaks'))
                };
            });

            $btn.prop('disabled', true);

            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_calendar_save_settings',
                nonce: cjs_ajax.nonce,
                settings: JSON.stringify(settings)
            }).done(function(response) {
                if (response.success) {
                    self.settings = response.data.settings;
                    $('#cjs-calendar-settings-modal').hide();
                    self.renderNotice('Nustatymai išsaugoti. Paspauskite „Perskaičiuoti grafiką", kad pritaikytumėte pakeitimus.', 'success');
                } else {
                    self.modalError('#cjs-cs-error', response.data && response.data.message ? response.data.message : cjs_ajax.strings.error);
                }
            }).fail(function() {
                self.modalError('#cjs-cs-error', cjs_ajax.strings.error);
            }).always(function() {
                $btn.prop('disabled', false);
            });
        }
    };

    $(document).ready(function() {
        if ($('#cjs-calendar').length) {
            CJSCalendar.init();
        }
    });

})(jQuery);
