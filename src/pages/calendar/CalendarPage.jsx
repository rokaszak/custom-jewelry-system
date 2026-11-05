import { useCallback, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import FullCalendar from '@fullcalendar/react'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import listPlugin from '@fullcalendar/list'
import interactionPlugin from '@fullcalendar/interaction'
import ltLocale from '@fullcalendar/core/locales/lt'
import { Button, Spinner } from '@heroui/react'
import { Sparkles, Plus, Gear, Lock, Xmark } from '@gravity-ui/icons'
import { ajax, get } from '../../lib/api'
import { notifyError, useMediaQuery } from '../../lib/hooks'
import { Check, IconButton, Notice, Page, Loading, cx } from '../../components/ui'
import IntervalDialog from './IntervalDialog'
import SettingsDialog from './SettingsDialog'
import { BADGE, DOT, fmtDT, pad, statusOf, statusText } from './calendarUtils'
import { useStockFlow } from '../../components/StockModal'
import './calendar.css'

const VIEW_KEY = 'cjs_calendar_view'
const PLUGINS = [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]
const LOCALES = [ltLocale]
const DAY_MS = 86400000

const eventCache = new Map()
const inflight = new Map()

const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

function shiftDays(d, n) {
  const x = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  x.setDate(x.getDate() + n)
  return x
}

function fetchRange(start, end, force = false) {
  const key = `${start}|${end}`
  if (!force && inflight.has(key)) return inflight.get(key)
  const p = ajax('cjs_calendar_intervals', { start, end })
    .then((d) => {
      const events = Array.isArray(d?.events) ? d.events : []
      const entry = { events, json: JSON.stringify(events) }
      eventCache.set(key, entry)
      return entry
    })
    .finally(() => {
      if (inflight.get(key) === p) inflight.delete(key)
    })
  inflight.set(key, p)
  return p
}

function monthRange(year, month) {
  const first = new Date(year, month, 1)
  const start = shiftDays(first, -((first.getDay() + 6) % 7))
  return [start, shiftDays(start, 42)]
}

function neighbours(view, start, end) {
  if (!view) return []
  if (view.type === 'dayGridMonth') {
    const c = view.currentStart
    if (!c) return []
    return [monthRange(c.getFullYear(), c.getMonth() - 1), monthRange(c.getFullYear(), c.getMonth() + 1)]
  }
  const days = Math.max(1, Math.round((end.getTime() - start.getTime()) / DAY_MS))
  return [[shiftDays(start, -days), start], [end, shiftDays(end, days)]]
}

function prefetchAround(view, start, end) {
  for (const [a, b] of neighbours(view, start, end)) {
    const key = `${ymd(a)}|${ymd(b)}`
    if (!eventCache.has(key)) fetchRange(ymd(a), ymd(b)).catch(() => {})
  }
}

function scrollTime(settings) {
  const week = settings?.week || {}
  let min = null
  for (const day of Object.values(week)) {
    if (day && Number(day.enabled) && day.start && (!min || day.start < min)) min = day.start
  }
  if (!min) return '07:00:00'
  return `${pad(Math.max(0, parseInt(min.slice(0, 2), 10) - 1))}:00:00`
}

function EventBody({ arg }) {
  const props = arg.event.extendedProps
  const status = statusOf(props)
  if (arg.view.type.startsWith('list')) {
    return (
      <span className="flex flex-wrap items-center gap-2">
        {props.is_locked || props.order_locked ? <Lock className="size-3" /> : null}
        <span className={props.is_done ? 'line-through' : undefined}>{arg.event.title}</span>
        {status && <span className={cx('px-1.5 text-[11px] font-medium text-white', BADGE[status])}>{statusText(props)}</span>}
      </span>
    )
  }
  const multiDay = !!arg.event.end && !!arg.event.start && arg.event.end.getTime() - arg.event.start.getTime() >= DAY_MS - 60000
  const row = arg.view.type === 'dayGridMonth' || multiDay
  const start = arg.event.start ? `${pad(arg.event.start.getHours())}:${pad(arg.event.start.getMinutes())}` : ''
  return (
    <div className={cx('cjs-ev-body', row && 'cjs-ev-row')}>
      <span className="cjs-ev-title">
        {status && <span className={cx('cjs-dot cjs-ev-status', DOT[status])} />}
        {props.is_locked || props.order_locked ? <Lock className="size-3 shrink-0" /> : null}
        {start && !row && <span className="cjs-ev-start">{start}</span>}
        <span className="cjs-ev-title-text">{arg.event.title}</span>
      </span>
      {arg.timeText && !multiDay && <span className="cjs-ev-time">{arg.timeText}</span>}
    </div>
  )
}

const renderEvent = (arg) => <EventBody arg={arg} />
const TOOLBAR_WIDE = { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth,listWeek' }
const TOOLBAR_NARROW = { left: 'prev,next', center: 'title', right: 'today' }
const FOOTER_NARROW = { center: 'timeGridDay,timeGridWeek,dayGridMonth,listWeek' }
const VIEWS = { dayGridMonth: { dayMaxEvents: 4 } }
const TIME_FORMAT = { hour: '2-digit', minute: '2-digit', hour12: false }

export default function CalendarPage() {
  const { resolveConflicts } = useStockFlow()
  const config = useQuery({ queryKey: ['calendar-config'], queryFn: () => get('calendar/config') })
  const narrow = useMediaQuery('(max-width: 768px)')
  const calRef = useRef(null)
  const [treatPassed, setTreatPassed] = useState(false)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)
  const [dialog, setDialog] = useState(null)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [view, setView] = useState(() => {
    try {
      return localStorage.getItem(VIEW_KEY) || (window.matchMedia('(max-width: 768px)').matches ? 'listWeek' : 'timeGridWeek')
    } catch {
      return 'timeGridWeek'
    }
  })
  const types = config.data?.types || {}
  const otherTypes = useMemo(() => Object.entries(types).filter(([k]) => k !== 'work'), [types])
  const forceRef = useRef(false)
  const quietRef = useRef(null)
  const spinnerRef = useRef(null)
  const fetchingRef = useRef(0)

  const track = useCallback((p) => {
    fetchingRef.current += 1
    spinnerRef.current?.classList.add('is-on')
    return p.finally(() => {
      fetchingRef.current = Math.max(0, fetchingRef.current - 1)
      if (fetchingRef.current === 0) spinnerRef.current?.classList.remove('is-on')
    })
  }, [])

  const refetch = useCallback(() => {
    eventCache.clear()
    forceRef.current = true
    calRef.current?.getApi().refetchEvents()
  }, [])

  const loadEvents = useCallback((info, success, failure) => {
    const start = ymd(info.start)
    const end = ymd(info.end)
    const key = `${start}|${end}`
    const forced = forceRef.current
    forceRef.current = false
    const cached = forced ? null : eventCache.get(key)
    if (cached) {
      success(cached.events)
      if (quietRef.current === key) {
        quietRef.current = null
        return
      }
      track(fetchRange(start, end))
        .then((fresh) => {
          if (fresh.json !== cached.json) {
            quietRef.current = key
            calRef.current?.getApi().refetchEvents()
          }
        })
        .catch(() => {})
      return
    }
    track(fetchRange(start, end, forced))
      .then((entry) => {
        success(entry.events)
      })
      .catch((e) => failure(e))
  }, [track])

  const animateRef = useRef(false)
  const enterRef = useRef(0)
  const [complete, setComplete] = useState(false)

  const recalc = async () => {
    setBusy(true)
    const started = Date.now()
    const wait = () => new Promise((r) => setTimeout(r, Math.max(0, 2500 - (Date.now() - started))))
    try {
      const data = await ajax('cjs_calendar_recalculate', { treat_passed: treatPassed ? 1 : 0 })
      await wait()
      if (data && data.stock_conflicts && data.stock_conflicts.length) await resolveConflicts(data.stock_conflicts)
      setNotice({ tone: data.late?.length ? 'warning' : 'success', data })
      animateRef.current = true
      enterRef.current = 0
      refetch()
      setTimeout(() => { animateRef.current = false }, 2500)
      setComplete(true)
      setTimeout(() => setComplete(false), 900)
    } catch (e) {
      await wait()
      setNotice({ tone: 'danger', message: e.message })
    } finally {
      setBusy(false)
    }
  }

  const conflictsRef = useRef(resolveConflicts)
  conflictsRef.current = resolveConflicts
  const saveTimes = useCallback(async (info) => {
    if (!info.event.start || !info.event.end) {
      info.revert()
      return
    }
    try {
      const saved = await ajax('cjs_calendar_interval_save', { id: info.event.id, start: fmtDT(info.event.start), end: fmtDT(info.event.end) })
      if (saved && saved.stock_conflicts && saved.stock_conflicts.length) await conflictsRef.current(saved.stock_conflicts)
      refetch()
    } catch (e) {
      info.revert()
      notifyError(e)
    }
  }, [refetch])

  const onEventDidMount = useCallback((info) => {
    if (animateRef.current) {
      info.el.classList.add('cjs-ev-enter')
      info.el.style.animationDelay = `${Math.min(enterRef.current * 30, 900)}ms`
      enterRef.current += 1
    }
    if (!info.view.type.startsWith('list') || !info.event.start || !info.event.end) return
    if (info.event.end.getTime() - info.event.start.getTime() < DAY_MS - 60000) return
    const cell = info.el.querySelector('.fc-list-event-time')
    if (cell && /^(00:00|23:5\d|[–-]\s*23:5\d|00:00\s*[–-]\s*23:5\d)$/.test(cell.textContent.trim())) cell.textContent = 'visą dieną'
  }, [])

  const onEventClick = useCallback((info) => {
    info.jsEvent.preventDefault()
    setDialog({ event: info.event, selection: null })
  }, [])

  const onSelect = useCallback((info) => {
    setDialog({ event: null, selection: info })
  }, [])

  const onDatesSet = useCallback((info) => {
    const { view: v, start, end } = info
    setTimeout(() => prefetchAround(v, start, end), 500)
    setView(info.view.type)
    try {
      localStorage.setItem(VIEW_KEY, info.view.type)
    } catch {}
  }, [])

  if (config.isLoading) return <Loading />

  return (
    <Page
      title="Kalendorius"
      actions={
        <>
          <div className="flex flex-col items-start gap-1.5">
            <Button variant="primary" className={cx('cjs-recalc', complete && 'is-complete')} isDisabled={busy} onPress={recalc}>
              <Sparkles className={cx('cjs-sparkle size-4', busy && 'is-spinning')} />
              Perskaičiuoti grafiką
            </Button>
            <Check checked={treatPassed} onChange={setTreatPassed}><span className="text-[13px]">Praėjusį darbą laikyti atliktu</span></Check>
          </div>
          <Button variant="secondary" onPress={() => setDialog({ event: null, selection: null })}><Plus className="size-4" />Naujas įrašas</Button>
          <Button variant="tertiary" onPress={() => setSettingsOpen(true)}><Gear className="size-4" />Nustatymai</Button>
        </>
      }
    >
      {notice && (
        <div className="mb-3">
          <Notice
            tone={notice.tone}
            title={notice.message || `Grafikas perskaičiuotas. Suplanuota užsakymų: ${Number(notice.data?.scheduled_orders) || 0}, sukurta intervalų: ${Number(notice.data?.created) || 0}.`}
            action={<IconButton icon={Xmark} label="Uždaryti" tooltip={false} onPress={() => setNotice(null)} />}
          >
            {!notice.message && notice.data?.late?.length > 0 && (
              <>
                <div className="font-medium">Nespėjama laiku:</div>
                <ul className="ml-4 list-disc">
                  {notice.data.late.map((l, i) => (
                    <li key={i}>
                      {l.name} - {l.days_late === null ? `netelpa į grafiką (terminas ${l.deadline})` : `vėluoja ${l.days_late} d. (terminas ${l.deadline}, baigiama ${l.finish})`}
                    </li>
                  ))}
                </ul>
              </>
            )}
          </Notice>
        </div>
      )}
      <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-[12px] text-muted">
        <span className="flex items-center gap-2">
          Trumpas
          <span className="h-2 w-24" style={{ background: 'linear-gradient(90deg,#8ab4f8,#4285f4,#174ea6)' }} />
          Ilgas darbas
        </span>
        {otherTypes.map(([k, t]) => (
          <span key={k} className="flex items-center gap-1.5"><span className="cjs-dot" style={{ background: t.color }} />{t.label}</span>
        ))}
        <span className="flex items-center gap-1.5"><span className="cjs-dot bg-success" />Spėjama laiku</span>
        <span className="flex items-center gap-1.5"><span className="cjs-dot bg-warning" />Mažiau nei 10 d. atsargos</span>
        <span className="flex items-center gap-1.5"><span className="cjs-dot bg-danger" />Vėluoja</span>
        <span ref={spinnerRef} className="cjs-fc-loading" aria-hidden="true"><Spinner size="sm" /></span>
      </div>
      <div className={cx('cjs-card cjs-fc p-2 sm:p-3', busy && 'cjs-fc-working')}>
        <FullCalendar
          ref={calRef}
          plugins={PLUGINS}
          locales={LOCALES}
          locale="lt"
          firstDay={1}
          initialView={view}
          headerToolbar={narrow ? TOOLBAR_NARROW : TOOLBAR_WIDE}
          footerToolbar={narrow ? FOOTER_NARROW : undefined}
          height={view === 'dayGridMonth' || view.startsWith('list') ? 'auto' : narrow ? 640 : 'calc(100vh - 290px)'}
          noEventsContent="Šiuo laikotarpiu nieko nesuplanuota"
          nowIndicator
          allDaySlot={false}
          slotDuration="00:30:00"
          slotEventOverlap={false}
          eventMinHeight={20}
          eventShortHeight={34}
          scrollTime={scrollTime(config.data?.settings)}
          expandRows
          views={VIEWS}
          selectable
          selectMirror
          unselectAuto={false}
          editable
          eventResizableFromStart
          longPressDelay={300}
          eventLongPressDelay={300}
          selectLongPressDelay={400}
          eventTimeFormat={TIME_FORMAT}
          slotLabelFormat={TIME_FORMAT}
          events={loadEvents}
          eventDidMount={onEventDidMount}
          eventDrop={saveTimes}
          eventResize={saveTimes}
          eventClick={onEventClick}
          select={onSelect}
          datesSet={onDatesSet}
          eventContent={renderEvent}
        />
      </div>
      {dialog && (
        <IntervalDialog
          event={dialog.event}
          selection={dialog.selection}
          types={types}
          onClose={() => {
            calRef.current?.getApi().unselect()
            setDialog(null)
          }}
          onSaved={() => {
            calRef.current?.getApi().unselect()
            setDialog(null)
            refetch()
          }}
        />
      )}
      {settingsOpen && <SettingsDialog settings={config.data?.settings} onClose={() => setSettingsOpen(false)} onSaved={() => { setSettingsOpen(false); config.refetch(); setNotice({ tone: 'success', message: 'Nustatymai išsaugoti. Paspauskite „Perskaičiuoti grafiką“, kad pritaikytumėte pakeitimus.' }) }} />}
    </Page>
  )
}
