(function ($) {
    'use strict';

    var data = window.cjs_orders_calendar_data || {};
    var orders = data.orders || [];
    var labels = data.labels || {};
    var today = data.today;

    var ZOOM_LEVELS = [8, 12, 16, 20, 26, 34, 44];
    var zoomIdx = 4;
    var DAY_W = ZOOM_LEVELS[zoomIdx];
    var LABEL_W = 200;
    var VIRTUAL_MARGIN_DAYS = 21;
    var NO_STATUS_KEY = '__none__';

    var checked = {};
    var selectedDate = null;
    var rangeStart = null;
    var totalDays = 0;
    var layoutOrders = [];
    var rowSignature = null;
    var scrollEl = null;
    var $body = null;

    function toTs(ymd) {
        var p = ymd.split('-');
        return Date.UTC(+p[0], +p[1] - 1, +p[2]);
    }

    function dayDiff(a, b) {
        return Math.round((toTs(b) - toTs(a)) / 86400000);
    }

    function addDays(ymd, n) {
        return new Date(toTs(ymd) + n * 86400000).toISOString().slice(0, 10);
    }

    function dayOfWeek(ymd) {
        return new Date(toTs(ymd)).getUTCDay();
    }

    function statusKey(order) {
        return order.status === '' ? NO_STATUS_KEY : order.status;
    }

    function orderColor(id) {
        var hue = (id * 137.508) % 360;
        var sat = 60 + (id * 53) % 23;
        var light = 40 + (id * 29) % 16;
        return 'hsl(' + hue.toFixed(1) + ', ' + sat + '%, ' + light + '%)';
    }

    function buildSegments(order) {
        var boundaries = [];
        if (order.manufacture_by) {
            boundaries.push({ date: order.manufacture_by, next: 0.85 });
        }
        if (order.finish_by) {
            boundaries.push({ date: order.finish_by, next: 0.7 });
        }
        if (order.deliver_by) {
            boundaries.push({ date: order.deliver_by, next: null });
        }
        if (!boundaries.length) {
            return [];
        }

        var segs = [];
        var cur = order.start;
        var opacity = 1;
        for (var i = 0; i < boundaries.length; i++) {
            var end = boundaries[i].date < cur ? cur : boundaries[i].date;
            if (end > cur) {
                segs.push({ from: cur, to: end, opacity: opacity });
                cur = end;
            }
            if (boundaries[i].next !== null) {
                opacity = boundaries[i].next;
            }
        }
        if (!segs.length) {
            segs.push({ from: cur, to: cur, opacity: 1 });
        }
        return segs;
    }

    function orderEnd(order) {
        return order.deliver_by || order.finish_by || order.manufacture_by || order.start;
    }

    var statusIndex = {};
    var atliktaIdx = -1;
    (data.statuses || []).forEach(function (s, i) {
        statusIndex[s.toLowerCase()] = i;
        if (s.toLowerCase() === 'atlieta') {
            atliktaIdx = i;
        }
    });

    function hoursPct(order) {
        if (!order.assigned || order.assigned <= 0) {
            return 0;
        }
        return Math.max(0, Math.min(1, (order.completed || 0) / order.assigned));
    }

    function progressPct(order) {
        if (atliktaIdx === -1) {
            return order.status.toLowerCase() === 'done' ? 1 : hoursPct(order);
        }
        var idx = statusIndex[order.status.toLowerCase()];
        if (idx === undefined || idx < atliktaIdx) {
            return 0;
        }
        if (idx === atliktaIdx) {
            return hoursPct(order);
        }
        return 1;
    }

    function doneHours(order) {
        return (order.assigned || 0) * progressPct(order);
    }

    function fmtHours(x) {
        return String(Math.round(x * 10) / 10).replace('.', ',');
    }

    function ordersWord(n) {
        var m10 = n % 10;
        var m100 = n % 100;
        if (m10 === 1 && m100 !== 11) {
            return labels.orders_1;
        }
        if (m10 >= 2 && m10 <= 9 && (m100 < 11 || m100 > 19)) {
            return labels.orders_few;
        }
        return labels.orders_many;
    }

    function compareOrders(a, b) {
        var ka = a.deliver_by || orderEnd(a);
        var kb = b.deliver_by || orderEnd(b);
        if (ka !== kb) {
            return ka < kb ? -1 : 1;
        }
        if (a.start !== b.start) {
            return a.start < b.start ? -1 : 1;
        }
        return a.id - b.id;
    }

    function buildFilterEntries() {
        var counts = {};
        orders.forEach(function (o) {
            var key = statusKey(o);
            counts[key] = (counts[key] || 0) + 1;
        });

        var entries = [];
        var seen = {};
        (data.statuses || []).forEach(function (s) {
            if (s === '' || seen[s]) {
                return;
            }
            seen[s] = true;
            entries.push({ key: s, label: s, count: counts[s] || 0 });
        });
        Object.keys(counts).sort().forEach(function (key) {
            if (key === NO_STATUS_KEY || seen[key]) {
                return;
            }
            seen[key] = true;
            entries.push({ key: key, label: key, count: counts[key] });
        });
        if (counts[NO_STATUS_KEY]) {
            entries.push({ key: NO_STATUS_KEY, label: labels.no_status, count: counts[NO_STATUS_KEY] });
        }
        return entries;
    }

    function renderFilter() {
        var $filter = $('#cjs-oc-filter');
        $filter.empty();
        buildFilterEntries().forEach(function (entry) {
            var $label = $('<label class="cjs-oc-filter-item"></label>');
            var $cb = $('<input type="checkbox" />')
                .val(entry.key)
                .prop('checked', checked[entry.key] !== false)
                .on('change', function () {
                    checked[entry.key] = $(this).is(':checked');
                    rerenderPreservingScroll();
                });
            $label.append($cb);
            $label.append(document.createTextNode(' ' + entry.label + ' (' + entry.count + ')'));
            $filter.append($label);
        });
    }

    function visibleOrders() {
        return orders.filter(function (o) {
            return checked[statusKey(o)] !== false;
        });
    }

    function tooltipText(order) {
        var lines = ['#' + order.number + (order.name ? ' ' + order.name : '')];
        if (order.status) {
            lines.push(labels.status + ': ' + order.status);
        }
        lines.push(labels.ordered + ': ' + order.start);
        if (order.manufacture_by) {
            lines.push(labels.manufacture_by + ': ' + order.manufacture_by);
        }
        if (order.finish_by) {
            lines.push(labels.finish_by + ': ' + order.finish_by);
        }
        if (order.deliver_by) {
            lines.push(labels.deliver_by + ': ' + order.deliver_by);
        }
        if (order.assigned !== null && order.assigned !== undefined) {
            lines.push(labels.hours + ': ' + fmtHours(doneHours(order)) + ' / ' + fmtHours(order.assigned) + ' h');
        }
        return lines.join('\n');
    }

    function buildStatsCorner(visible) {
        var assigned = 0;
        var done = 0;
        visible.forEach(function (o) {
            assigned += o.assigned || 0;
            done += doneHours(o);
        });

        var $corner = $('<div class="cjs-oc-corner cjs-oc-head-corner"></div>');
        $corner.append($('<div class="cjs-oc-stat cjs-oc-stat-main"></div>').text(visible.length + ' ' + ordersWord(visible.length)));
        $corner.append($('<div class="cjs-oc-stat"></div>').text(fmtHours(assigned) + 'h ' + labels.assigned_short));
        $corner.append($('<div class="cjs-oc-stat"></div>').text(fmtHours(done) + 'h ' + labels.done_short));
        $corner.append($('<div class="cjs-oc-stat"></div>').text(fmtHours(assigned - done) + 'h ' + labels.left_short));
        return $corner;
    }

    function render() {
        var $scroll = $('#cjs-oc-scroll');
        $scroll.empty();
        $body = null;
        rowSignature = null;

        var visible = visibleOrders();
        if (!visible.length) {
            rangeStart = null;
            totalDays = 0;
            layoutOrders = [];
            $scroll.append($('<div class="cjs-oc-empty"></div>').text(labels.no_orders));
            return;
        }

        visible.sort(compareOrders);
        layoutOrders = visible;

        var minStart = visible[0].start;
        var maxEnd = today;
        visible.forEach(function (o) {
            if (o.start < minStart) {
                minStart = o.start;
            }
            var end = orderEnd(o);
            if (end > maxEnd) {
                maxEnd = end;
            }
        });
        if (today < minStart) {
            minStart = today;
        }

        rangeStart = addDays(minStart, -3);
        var rangeEnd = addDays(maxEnd, 3);
        totalDays = dayDiff(rangeStart, rangeEnd) + 1;

        var fillDays = Math.ceil((scrollEl.clientWidth - LABEL_W) / DAY_W) + 1;
        if (totalDays < fillDays) {
            totalDays = fillDays;
        }

        var trackW = totalDays * DAY_W;
        var innerW = LABEL_W + trackW;

        var $inner = $('<div class="cjs-oc-inner"></div>').css('width', innerW + 'px');

        var $head = $('<div class="cjs-oc-head"></div>');
        $head.append(buildStatsCorner(visible));
        var $cols = $('<div class="cjs-oc-head-cols"></div>');
        $cols.append(buildMonthRow());
        $cols.append(buildDayRow());
        $head.append($cols);
        $inner.append($head);

        var $bodyEl = $('<div class="cjs-oc-body"></div>');
        $bodyEl.append(buildUnderlay(trackW));
        $inner.append($bodyEl);
        $body = $bodyEl;

        $scroll.append($inner);
        updateRows();
        applySelection();
    }

    function applySelection() {
        var $scroll = $('#cjs-oc-scroll');
        $scroll.find('.cjs-oc-sel-col').remove();
        $scroll.find('.cjs-oc-day-cell.cjs-oc-selected').removeClass('cjs-oc-selected');

        if (selectedDate === null || rangeStart === null) {
            return;
        }
        var idx = dayDiff(rangeStart, selectedDate);
        if (idx < 0 || idx >= totalDays) {
            return;
        }
        $scroll.find('.cjs-oc-underlay').append($('<div class="cjs-oc-sel-col"></div>').css({
            left: idx * DAY_W + 'px',
            width: DAY_W + 'px'
        }));
        $scroll.find('.cjs-oc-days').children().eq(idx).addClass('cjs-oc-selected');
    }

    function updateRows() {
        if (!scrollEl || rangeStart === null || !$body) {
            return;
        }
        var viewStart = Math.floor(scrollEl.scrollLeft / DAY_W) - VIRTUAL_MARGIN_DAYS;
        var viewEnd = Math.ceil((scrollEl.scrollLeft + scrollEl.clientWidth - LABEL_W) / DAY_W) + VIRTUAL_MARGIN_DAYS;

        var rows = layoutOrders.filter(function (o) {
            var from = dayDiff(rangeStart, o.start);
            var to = dayDiff(rangeStart, orderEnd(o));
            return to >= viewStart && from <= viewEnd;
        });

        var sig = rows.map(function (o) { return o.id; }).join(',');
        if (sig === rowSignature) {
            return;
        }
        rowSignature = sig;

        var trackW = totalDays * DAY_W;
        $body.children('.cjs-oc-row').remove();
        rows.forEach(function (order) {
            $body.append(buildOrderRow(order, trackW));
        });
    }

    function buildMonthRow() {
        var $row = $('<div class="cjs-oc-hrow cjs-oc-months"></div>');
        var i = 0;
        while (i < totalDays) {
            var date = addDays(rangeStart, i);
            var parts = date.split('-');
            var year = +parts[0];
            var month = +parts[1];
            var span = 0;
            while (i + span < totalDays) {
                var d = addDays(rangeStart, i + span).split('-');
                if (+d[1] !== month || +d[0] !== year) {
                    break;
                }
                span++;
            }
            var $cell = $('<div class="cjs-oc-month-cell"></div>')
                .css('width', span * DAY_W + 'px')
                .text(labels.months[month - 1] + ' ' + year);
            $row.append($cell);
            i += span;
        }
        return $row;
    }

    function buildDayRow() {
        var $row = $('<div class="cjs-oc-hrow cjs-oc-days"></div>');
        if (DAY_W <= 12) {
            $row.addClass('cjs-oc-days-compact');
        }
        for (var i = 0; i < totalDays; i++) {
            var date = addDays(rangeStart, i);
            var dow = dayOfWeek(date);
            var $cell = $('<div class="cjs-oc-day-cell"></div>').css('width', DAY_W + 'px');
            if (date === today) {
                $cell.addClass('cjs-oc-today');
            }
            if (DAY_W > 12 || dow === 1 || date === today) {
                $cell.append($('<span class="cjs-oc-day-num"></span>').text(+date.split('-')[2]));
            }
            if (DAY_W >= 18) {
                $cell.append($('<span class="cjs-oc-day-dow"></span>').text(labels.weekdays[dow]));
            }
            $row.append($cell);
        }
        return $row;
    }

    function buildUnderlay(trackW) {
        var $underlay = $('<div class="cjs-oc-underlay"></div>').css({
            left: LABEL_W + 'px',
            width: trackW + 'px'
        });

        if (DAY_W > 12) {
            $underlay.css('background-image', 'repeating-linear-gradient(to right, rgba(0,0,0,0.05) 0 1px, transparent 1px ' + DAY_W + 'px)');
        } else {
            var monOffset = ((1 - dayOfWeek(rangeStart)) % 7 + 7) % 7;
            $underlay.css({
                'background-image': 'repeating-linear-gradient(to right, rgba(0,0,0,0.06) 0 1px, transparent 1px ' + (7 * DAY_W) + 'px)',
                'background-position': monOffset * DAY_W + 'px 0'
            });
        }

        var todayIdx = dayDiff(rangeStart, today);
        if (todayIdx >= 0 && todayIdx < totalDays) {
            $underlay.append($('<div class="cjs-oc-today-col"></div>').css({
                left: todayIdx * DAY_W + 'px',
                width: DAY_W + 'px'
            }));
        }
        return $underlay;
    }

    function buildOrderRow(order, trackW) {
        var $row = $('<div class="cjs-oc-row"></div>');
        var $label = $('<a class="cjs-oc-row-label"></a>')
            .attr('href', data.orders_url + '&highlight_order=' + order.id + '#order' + order.id)
            .attr('target', '_blank')
            .attr('title', tooltipText(order));
        $label.append($('<span class="cjs-oc-row-dot"></span>').css('background', orderColor(order.id)));
        $label.append($('<span class="cjs-oc-row-num"></span>').text('#' + order.number));
        if (order.name) {
            $label.append($('<span class="cjs-oc-row-name"></span>').text(order.name));
        }
        $row.append($label);

        var $track = $('<div class="cjs-oc-row-track"></div>').css('width', trackW + 'px');
        var segs = buildSegments(order);
        var color = orderColor(order.id);
        var title = tooltipText(order);

        segs.forEach(function (seg, idx) {
            var fromIdx = dayDiff(rangeStart, seg.from);
            var toIdx = dayDiff(rangeStart, seg.to);
            var isLast = idx === segs.length - 1;
            var widthDays = toIdx - fromIdx + (isLast ? 1 : 0);
            if (widthDays <= 0) {
                widthDays = 1;
            }
            var $seg = $('<div class="cjs-oc-seg"></div>').css({
                left: fromIdx * DAY_W + 'px',
                width: widthDays * DAY_W + 'px',
                'background-color': color,
                opacity: seg.opacity
            }).attr('title', title);
            if (seg.opacity === 1) {
                $seg.addClass('cjs-oc-seg-progress').css({
                    'background-color': 'transparent',
                    'border-color': color
                });
                $seg.append($('<div class="cjs-oc-seg-fill"></div>').css({
                    width: (progressPct(order) * 100).toFixed(1) + '%',
                    'background-color': color
                }));
            } else if (seg.opacity === 0.85) {
                $seg.addClass('cjs-oc-seg-stripes');
            } else if (seg.opacity === 0.7) {
                $seg.addClass('cjs-oc-seg-dots');
            }
            if (idx === 0) {
                $seg.addClass('cjs-oc-seg-first');
            }
            if (isLast) {
                $seg.addClass('cjs-oc-seg-last');
            }
            $track.append($seg);
        });

        $row.append($track);
        return $row;
    }

    function scrollToDate(date, smooth) {
        if (rangeStart === null) {
            return;
        }
        var target = Math.max(0, dayDiff(rangeStart, date) * DAY_W);
        if (smooth && scrollEl.scrollTo) {
            scrollEl.scrollTo({ left: target, behavior: 'smooth' });
        } else {
            scrollEl.scrollLeft = target;
        }
    }

    function renderPreservingScroll() {
        var leftDate = null;
        if (rangeStart !== null) {
            leftDate = addDays(rangeStart, Math.round(scrollEl.scrollLeft / DAY_W));
        }
        render();
        if (leftDate !== null && rangeStart !== null) {
            scrollToDate(leftDate, false);
        }
    }

    function rerenderPreservingScroll() {
        renderFilter();
        renderPreservingScroll();
    }

    function updateZoomUi() {
        $('#cjs-oc-zoom-level').text(Math.round(DAY_W / ZOOM_LEVELS[4] * 100) + '%');
        $('#cjs-oc-zoom-out').prop('disabled', zoomIdx === 0);
        $('#cjs-oc-zoom-in').prop('disabled', zoomIdx === ZOOM_LEVELS.length - 1);
    }

    function setZoom(idx, anchorClientX) {
        idx = Math.max(0, Math.min(ZOOM_LEVELS.length - 1, idx));
        if (idx === zoomIdx) {
            return;
        }
        if (rangeStart === null) {
            zoomIdx = idx;
            DAY_W = ZOOM_LEVELS[idx];
            updateZoomUi();
            return;
        }

        var rect = scrollEl.getBoundingClientRect();
        var anchorX;
        if (anchorClientX == null) {
            anchorX = LABEL_W + (scrollEl.clientWidth - LABEL_W) / 2;
        } else {
            anchorX = anchorClientX - rect.left;
        }
        var anchorDay = (scrollEl.scrollLeft + anchorX - LABEL_W) / DAY_W;

        zoomIdx = idx;
        DAY_W = ZOOM_LEVELS[idx];
        render();
        scrollEl.scrollLeft = anchorDay * DAY_W + LABEL_W - anchorX;
        updateRows();
        updateZoomUi();
    }

    function setupScrollSync() {
        var pending = false;
        scrollEl.addEventListener('scroll', function () {
            if (pending) {
                return;
            }
            pending = true;
            requestAnimationFrame(function () {
                pending = false;
                updateRows();
            });
        }, { passive: true });
    }

    function setupDragPan() {
        var activeId = null;
        var moved = false;
        var suppressClick = false;
        var startX = 0;
        var startY = 0;
        var startSL = 0;
        var startST = 0;
        var lastX = 0;
        var lastY = 0;
        var lastT = 0;
        var vx = 0;
        var vy = 0;
        var momentum = null;

        function cancelMomentum() {
            if (momentum !== null) {
                cancelAnimationFrame(momentum);
                momentum = null;
            }
        }

        function startMomentum() {
            function step() {
                vx *= 0.94;
                vy *= 0.94;
                scrollEl.scrollLeft -= vx * 16;
                scrollEl.scrollTop -= vy * 16;
                if (Math.abs(vx) > 0.02 || Math.abs(vy) > 0.02) {
                    momentum = requestAnimationFrame(step);
                } else {
                    momentum = null;
                }
            }
            momentum = requestAnimationFrame(step);
        }

        scrollEl.addEventListener('pointerdown', function (e) {
            if (e.pointerType !== 'mouse' || e.button !== 0) {
                return;
            }
            var rect = scrollEl.getBoundingClientRect();
            if (e.clientX - rect.left > scrollEl.clientWidth || e.clientY - rect.top > scrollEl.clientHeight) {
                return;
            }
            cancelMomentum();
            activeId = e.pointerId;
            moved = false;
            suppressClick = false;
            startX = lastX = e.clientX;
            startY = lastY = e.clientY;
            startSL = scrollEl.scrollLeft;
            startST = scrollEl.scrollTop;
            lastT = performance.now();
            vx = vy = 0;
        });

        scrollEl.addEventListener('pointermove', function (e) {
            if (activeId === null || e.pointerId !== activeId) {
                return;
            }
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            if (!moved) {
                if (Math.abs(dx) < 3 && Math.abs(dy) < 3) {
                    return;
                }
                moved = true;
                scrollEl.classList.add('cjs-oc-grabbing');
                if (scrollEl.setPointerCapture) {
                    scrollEl.setPointerCapture(activeId);
                }
            }
            e.preventDefault();
            scrollEl.scrollLeft = startSL - dx;
            scrollEl.scrollTop = startST - dy;

            var t = performance.now();
            var dt = t - lastT;
            if (dt > 0) {
                vx = 0.8 * vx + 0.2 * ((e.clientX - lastX) / dt);
                vy = 0.8 * vy + 0.2 * ((e.clientY - lastY) / dt);
            }
            lastX = e.clientX;
            lastY = e.clientY;
            lastT = t;
        });

        function endDrag(e) {
            if (activeId === null || e.pointerId !== activeId) {
                return;
            }
            activeId = null;
            scrollEl.classList.remove('cjs-oc-grabbing');
            suppressClick = moved;
            if (moved && performance.now() - lastT < 80) {
                startMomentum();
            }
        }

        scrollEl.addEventListener('pointerup', endDrag);
        scrollEl.addEventListener('pointercancel', endDrag);
        scrollEl.addEventListener('wheel', cancelMomentum, { passive: true });
        scrollEl.addEventListener('click', function (e) {
            if (suppressClick) {
                suppressClick = false;
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
    }

    function dayIndexFromClientX(clientX) {
        if (rangeStart === null) {
            return -1;
        }
        var x = clientX - scrollEl.getBoundingClientRect().left;
        if (x < LABEL_W || x > scrollEl.clientWidth) {
            return -1;
        }
        var idx = Math.floor((scrollEl.scrollLeft + x - LABEL_W) / DAY_W);
        return idx >= 0 && idx < totalDays ? idx : -1;
    }

    function setupDaySelection() {
        var downX = 0;
        var downY = 0;

        scrollEl.addEventListener('pointerdown', function (e) {
            downX = e.clientX;
            downY = e.clientY;
        });

        scrollEl.addEventListener('click', function (e) {
            if (Math.abs(e.clientX - downX) > 3 || Math.abs(e.clientY - downY) > 3) {
                return;
            }
            if ($(e.target).closest('.cjs-oc-row-label, .cjs-oc-corner').length) {
                return;
            }
            var idx = dayIndexFromClientX(e.clientX);
            if (idx < 0) {
                return;
            }
            var date = addDays(rangeStart, idx);
            selectedDate = selectedDate === date ? null : date;
            applySelection();
        });
    }

    function setupZoomControls() {
        scrollEl.addEventListener('wheel', function (e) {
            if (!e.ctrlKey) {
                return;
            }
            e.preventDefault();
            setZoom(zoomIdx + (e.deltaY < 0 ? 1 : -1), e.clientX);
        }, { passive: false });

        $('#cjs-oc-zoom-in').on('click', function () {
            setZoom(zoomIdx + 1);
        });
        $('#cjs-oc-zoom-out').on('click', function () {
            setZoom(zoomIdx - 1);
        });

        $(document).on('keydown', function (e) {
            var tag = (e.target.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable) {
                return;
            }
            if (e.ctrlKey || e.metaKey || e.altKey) {
                return;
            }
            if (e.key === '+' || e.key === '=') {
                e.preventDefault();
                setZoom(zoomIdx + 1);
            } else if (e.key === '-' || e.key === '_') {
                e.preventDefault();
                setZoom(zoomIdx - 1);
            } else if (e.key === 'Escape' && selectedDate !== null) {
                selectedDate = null;
                applySelection();
            }
        });
    }

    function init() {
        scrollEl = document.getElementById('cjs-oc-scroll');
        if (!scrollEl) {
            return;
        }

        buildFilterEntries().forEach(function (entry) {
            checked[entry.key] = entry.label.toLowerCase() !== 'done';
        });

        renderFilter();
        render();
        scrollToDate(addDays(today, -2), false);
        updateRows();
        updateZoomUi();

        setupScrollSync();
        setupDragPan();
        setupDaySelection();
        setupZoomControls();

        var resizePending = false;
        window.addEventListener('resize', function () {
            if (resizePending) {
                return;
            }
            resizePending = true;
            requestAnimationFrame(function () {
                resizePending = false;
                renderPreservingScroll();
            });
        });

        $('#cjs-oc-today').on('click', function () {
            scrollToDate(addDays(today, -2), true);
        });
    }

    $(document).ready(init);
})(jQuery);
