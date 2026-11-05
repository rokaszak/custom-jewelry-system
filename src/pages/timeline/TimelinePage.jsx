import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Button, Tooltip } from '@heroui/react'
import { Minus, Plus, ArrowRotateLeft } from '@gravity-ui/icons'
import { get } from '../../lib/api'
import { hours } from '../../lib/format'
import { useMediaQuery } from '../../lib/hooks'
import { adminViewUrl } from '../../lib/nav'
import { MultiFilter, Page, Loading, Stat, Empty, cx } from '../../components/ui'

const ZOOM = [8, 12, 16, 20, 26, 34, 44]
const DAY = 86400000
const MONTHS = ['Sausis', 'Vasaris', 'Kovas', 'Balandis', 'Gegužė', 'Birželis', 'Liepa', 'Rugpjūtis', 'Rugsėjis', 'Spalis', 'Lapkritis', 'Gruodis']
const WD = ['Sk', 'Pr', 'An', 'Tr', 'Kt', 'Pn', 'Št']
const NO_STATUS = '__none'

const parse = (ymd) => Date.UTC(+ymd.slice(0, 4), +ymd.slice(5, 7) - 1, +ymd.slice(8, 10))
const diff = (a, b) => Math.round((parse(b) - parse(a)) / DAY)
const ymdOf = (ms) => new Date(ms).toISOString().slice(0, 10)
const orderEnd = (o) => o.deliver_by || o.finish_by || o.manufacture_by || o.start
const WINDOW_MARGIN_DAYS = 21

function color(id) {
  const hue = (id * 137.508) % 360
  const sat = 60 + ((id * 53) % 23)
  const light = 40 + ((id * 29) % 16)
  return `hsl(${hue.toFixed(1)}, ${sat}%, ${light}%)`
}

function ordersWord(n) {
  const m10 = n % 10
  const m100 = n % 100
  if (m10 === 1 && m100 !== 11) return 'užsakymas'
  if (m10 >= 2 && m10 <= 9 && (m100 < 11 || m100 > 19)) return 'užsakymai'
  return 'užsakymų'
}

function segments(o) {
  const bounds = []
  if (o.manufacture_by) bounds.push({ date: o.manufacture_by, next: 'stripes' })
  if (o.finish_by) bounds.push({ date: o.finish_by, next: 'dots' })
  if (o.deliver_by) bounds.push({ date: o.deliver_by, next: null })
  if (!bounds.length) return []
  const segs = []
  let cur = o.start
  let kind = 'solid'
  for (const b of bounds) {
    const end = b.date < cur ? cur : b.date
    if (end > cur) {
      segs.push({ from: cur, to: end, kind })
      cur = end
    }
    if (b.next) kind = b.next
  }
  if (!segs.length) segs.push({ from: cur, to: cur, kind: 'solid' })
  return segs
}

export default function TimelinePage() {
  const q = useQuery({ queryKey: ['timeline'], queryFn: () => get('timeline') })
  const [zoom, setZoom] = useState(4)
  const [selectedDay, setSelectedDay] = useState(null)
  const [hidden, setHidden] = useState(null)
  const [viewW, setViewW] = useState(1200)
  const [win, setWin] = useState(null)
  const [grabbing, setGrabbing] = useState(false)
  const scroller = useRef(null)
  const drag = useRef(null)
  const momentum = useRef(null)
  const suppressClick = useRef(false)
  const anchor = useRef(null)
  const didInitialScroll = useRef(false)
  const wheelAcc = useRef(0)
  const dayW = ZOOM[zoom]
  const wide = useMediaQuery('(min-width: 640px)')
  const nameW = wide ? 210 : 150
  const nameRef = useRef(nameW)
  nameRef.current = nameW
  const zoomRef = useRef(zoom)
  zoomRef.current = zoom
  const rangeRef = useRef(null)

  const zoomBy = (step, clientX) => {
    const el = scroller.current
    const next = Math.max(0, Math.min(ZOOM.length - 1, zoomRef.current + step))
    if (next === zoomRef.current) return
    if (el) {
      const rect = el.getBoundingClientRect()
      const inner = Math.max(1, el.clientWidth - nameRef.current)
      const offset = clientX !== undefined ? Math.max(0, Math.min(inner, clientX - rect.left - nameRef.current)) : inner / 2
      anchor.current = { day: (el.scrollLeft + offset) / ZOOM[zoomRef.current], offset }
    }
    setZoom(next)
  }
  const data = q.data
  const statuses = data?.statuses || []

  const statusOptions = useMemo(() => {
    if (!data) return []
    const counts = {}
    data.orders.forEach((o) => {
      const k = o.status || NO_STATUS
      counts[k] = (counts[k] || 0) + 1
    })
    const seen = new Set()
    const list = []
    statuses.forEach((s) => {
      if (!s || seen.has(s)) return
      seen.add(s)
      list.push({ value: s, label: `${s} (${counts[s] || 0})` })
    })
    Object.keys(counts).sort().forEach((s) => {
      if (s === NO_STATUS || seen.has(s)) return
      seen.add(s)
      list.push({ value: s, label: `${s} (${counts[s]})` })
    })
    if (counts[NO_STATUS]) list.push({ value: NO_STATUS, label: `(Be statuso) (${counts[NO_STATUS]})` })
    return list
  }, [data, statuses])

  useEffect(() => {
    if (data && hidden === null) {
      const doneLabel = statuses[(data.status_keys || []).indexOf('done')] ?? 'DONE'
      setHidden(statusOptions.map((o) => o.value).filter((v) => v === doneLabel))
    }
  }, [data])
  const filter = useMemo(() => statusOptions.map((o) => o.value).filter((v) => !(hidden || []).includes(v)), [statusOptions, hidden])
  const setFilter = (values) => setHidden(statusOptions.map((o) => o.value).filter((v) => !values.includes(v)))

  const statusIndex = useMemo(() => Object.fromEntries((data?.status_keys || []).map((k, i) => [k, i])), [data])
  const atlieta = statusIndex['atlieta'] ?? -1
  const progress = (o) => {
    const hp = o.assigned > 0 ? Math.max(0, Math.min(1, (o.completed || 0) / o.assigned)) : 0
    if (atlieta === -1) return o.status_key === 'done' ? 1 : hp
    const idx = statusIndex[o.status_key]
    if (idx === undefined || idx < atlieta) return 0
    if (idx === atlieta) return hp
    return 1
  }
  const doneHours = (o) => (o.assigned || 0) * progress(o)
  const tip = (o) => {
    const lines = [`#${o.number}${o.name ? ` ${o.name}` : ''}`]
    if (o.status) lines.push(`Statusas: ${o.status}`)
    lines.push(`Užsakyta: ${o.start}`)
    if (o.manufacture_by) lines.push(`Pagaminti iki: ${o.manufacture_by}`)
    if (o.finish_by) lines.push(`Užprabuoti iki: ${o.finish_by}`)
    if (o.deliver_by) lines.push(`Pristatyti iki: ${o.deliver_by}`)
    if (o.assigned !== null && o.assigned !== undefined) lines.push(`Valandos: ${hours(doneHours(o))} / ${hours(o.assigned)} h`)
    return lines
  }

  const orders = useMemo(() => {
    if (!data || hidden === null) return []
    return data.orders
      .filter((o) => filter.includes(o.status || NO_STATUS))
      .sort((a, b) => orderEnd(a).localeCompare(orderEnd(b)) || a.start.localeCompare(b.start) || a.id - b.id)
  }, [data, filter, hidden])

  const range = useMemo(() => {
    if (!data) return null
    let min = data.today
    let max = data.today
    data.orders.forEach((o) => {
      if (o.start < min) min = o.start
      const end = orderEnd(o)
      if (end > max) max = end
    })
    const start = ymdOf(parse(min) - 3 * DAY)
    let days = diff(start, max) + 4
    days = Math.max(days, Math.ceil(Math.max(0, viewW - nameW) / dayW) + 2)
    return { start, days }
  }, [data, dayW, viewW, nameW])
  rangeRef.current = range

  const scrollToToday = (smooth = true) => {
    if (!range || !scroller.current) return
    scroller.current.scrollTo({ left: Math.max(0, (diff(range.start, data.today) - 2) * dayW), behavior: smooth ? 'smooth' : 'auto' })
  }

  const measure = () => {
    const el = scroller.current
    if (!el) return
    const a = Math.floor(el.scrollLeft / dayW) - WINDOW_MARGIN_DAYS
    const b = Math.ceil((el.scrollLeft + Math.max(0, el.clientWidth - nameRef.current)) / dayW) + WINDOW_MARGIN_DAYS
    setWin((w) => (w && w[0] === a && w[1] === b ? w : [a, b]))
  }

  const rows = useMemo(() => {
    if (!win || !range) return orders
    return orders.filter((o) => diff(range.start, orderEnd(o)) >= win[0] && diff(range.start, o.start) <= win[1])
  }, [orders, win, range])

  const hasRows = orders.length > 0
  useLayoutEffect(() => {
    if (!range || !scroller.current) return
    if (!didInitialScroll.current) {
      didInitialScroll.current = true
      scrollToToday(false)
      return
    }
    if (anchor.current) {
      scroller.current.scrollLeft = Math.max(0, anchor.current.day * dayW - anchor.current.offset)
      anchor.current = null
    }
    measure()
  }, [range?.start, range?.days, dayW, hasRows])

  const cancelMomentum = () => {
    if (momentum.current !== null) {
      cancelAnimationFrame(momentum.current)
      momentum.current = null
    }
  }

  useEffect(() => {
    const el = scroller.current
    if (!el) return
    const ro = new ResizeObserver(() => {
      setViewW(el.clientWidth)
      measure()
    })
    ro.observe(el)
    const onWheel = (e) => {
      cancelMomentum()
      if (!e.ctrlKey) return
      e.preventDefault()
      wheelAcc.current += e.deltaY
      if (Math.abs(wheelAcc.current) >= 40) {
        zoomBy(wheelAcc.current < 0 ? 1 : -1, e.clientX)
        wheelAcc.current = 0
      }
    }
    el.addEventListener('wheel', onWheel, { passive: false })
    return () => {
      ro.disconnect()
      el.removeEventListener('wheel', onWheel)
    }
  }, [q.isLoading, hidden === null, hasRows])

  useEffect(() => () => cancelMomentum(), [])

  useEffect(() => {
    const onKey = (e) => {
      const t = e.target
      if (t && ((t.closest && t.closest('input,textarea,select')) || t.isContentEditable)) return
      if (e.ctrlKey || e.metaKey || e.altKey) return
      if (e.key === '+' || e.key === '=') {
        e.preventDefault()
        zoomBy(1)
      } else if (e.key === '-' || e.key === '_') {
        e.preventDefault()
        zoomBy(-1)
      } else if (e.key === 'Escape') setSelectedDay(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  if (q.isLoading || !range) return <Loading />

  const days = Array.from({ length: range.days }, (_, i) => ymdOf(parse(range.start) + i * DAY))
  const width = range.days * dayW
  const todayIdx = diff(range.start, data.today)
  const compact = dayW <= 12
  const gridBg = {
    backgroundImage: 'linear-gradient(to right, color-mix(in oklab, var(--separator) 70%, transparent) 1px, transparent 1px)',
    backgroundSize: `${compact ? dayW * 7 : dayW}px 100%`,
    backgroundPosition: compact ? `${((8 - new Date(parse(range.start)).getUTCDay()) % 7) * dayW}px 0` : '0 0',
  }
  const stats = orders.reduce((acc, o) => ({ assigned: acc.assigned + (o.assigned || 0), done: acc.done + doneHours(o) }), { assigned: 0, done: 0 })
  const months = []
  days.forEach((d, i) => {
    const key = d.slice(0, 7)
    if (!months.length || months[months.length - 1].key !== key) months.push({ key, start: i, count: 1, label: `${MONTHS[+d.slice(5, 7) - 1]} ${d.slice(0, 4)}` })
    else months[months.length - 1].count++
  })

  const onPointerDown = (e) => {
    if (e.pointerType !== 'mouse' || e.button !== 0) return
    const el = scroller.current
    const rect = el.getBoundingClientRect()
    if (e.clientX - rect.left > el.clientWidth || e.clientY - rect.top > el.clientHeight) return
    cancelMomentum()
    suppressClick.current = false
    const now = performance.now()
    drag.current = { id: e.pointerId, x: e.clientX, y: e.clientY, left: el.scrollLeft, top: el.scrollTop, lastX: e.clientX, lastY: e.clientY, lastT: now, vx: 0, vy: 0, moved: false, downX: e.clientX, downY: e.clientY }
  }
  const onPointerMove = (e) => {
    const d = drag.current
    if (!d || e.pointerId !== d.id) return
    const el = scroller.current
    const dx = e.clientX - d.x
    const dy = e.clientY - d.y
    if (!d.moved) {
      if (Math.abs(dx) < 3 && Math.abs(dy) < 3) return
      d.moved = true
      setGrabbing(true)
      try {
        el.setPointerCapture(d.id)
      } catch {}
    }
    e.preventDefault()
    el.scrollLeft = d.left - dx
    el.scrollTop = d.top - dy
    const t = performance.now()
    const dt = t - d.lastT
    if (dt > 0) {
      d.vx = 0.8 * d.vx + 0.2 * ((e.clientX - d.lastX) / dt)
      d.vy = 0.8 * d.vy + 0.2 * ((e.clientY - d.lastY) / dt)
    }
    d.lastX = e.clientX
    d.lastY = e.clientY
    d.lastT = t
  }
  const onPointerUp = (e) => {
    const d = drag.current
    if (!d || e.pointerId !== d.id) return
    drag.current = null
    setGrabbing(false)
    suppressClick.current = d.moved
    if (d.moved && performance.now() - d.lastT < 80) {
      let vx = d.vx
      let vy = d.vy
      const step = () => {
        const el = scroller.current
        if (!el) {
          momentum.current = null
          return
        }
        vx *= 0.94
        vy *= 0.94
        el.scrollLeft -= vx * 16
        el.scrollTop -= vy * 16
        momentum.current = Math.abs(vx) > 0.02 || Math.abs(vy) > 0.02 ? requestAnimationFrame(step) : null
      }
      momentum.current = requestAnimationFrame(step)
    }
  }
  const onClickCapture = (e) => {
    if (suppressClick.current) {
      suppressClick.current = false
      e.preventDefault()
      e.stopPropagation()
    }
  }
  const onBodyClick = (e) => {
    if (e.target.closest('[data-tl-label]')) return
    const el = scroller.current
    const r = rangeRef.current
    if (!el || !r) return
    const x = e.clientX - el.getBoundingClientRect().left
    if (x < nameRef.current || x > el.clientWidth) return
    const idx = Math.floor((el.scrollLeft + x - nameRef.current) / dayW)
    if (idx < 0 || idx >= r.days) return
    const d = ymdOf(parse(r.start) + idx * DAY)
    setSelectedDay((cur) => (cur === d ? null : d))
  }

  return (
    <Page
      title="Rodomi užsakymai matomame laikotarpyje"
      actions={
        <>
          <Button variant="secondary" onPress={() => scrollToToday()}><ArrowRotateLeft className="size-4" />Grįžti į šiandien</Button>
          <div className="flex items-center gap-1 bg-surface-secondary p-1">
            <Button isIconOnly size="sm" variant="ghost" aria-label="Mažiau detalu (-)" isDisabled={zoom <= 0} onPress={() => zoomBy(-1)}><Minus className="size-4" /></Button>
            <span className="w-12 text-center text-[12px] tabular-nums">{Math.round((dayW / 26) * 100)}%</span>
            <Button isIconOnly size="sm" variant="ghost" aria-label="Daugiau detalu (+)" isDisabled={zoom >= ZOOM.length - 1} onPress={() => zoomBy(1)}><Plus className="size-4" /></Button>
          </div>
          <MultiFilter label="Filtrai" options={statusOptions} value={filter || []} onChange={setFilter} size="md" />
        </>
      }
    >
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap">
          <Stat label={ordersWord(orders.length)} value={orders.length} />
          <Stat label="Paskirta" value={`${hours(stats.assigned)} h`} />
          <Stat label="Pabaigta" value={`${hours(stats.done)} h`} tone="success" />
          <Stat label="Likę" value={`${hours(stats.assigned - stats.done)} h`} />
        </div>
        <div className="ml-auto flex flex-wrap items-center gap-3 text-[12px] text-muted">
          <span className="flex items-center gap-1.5"><span className="h-2.5 w-6 border-2 border-accent" />Pagaminti iki</span>
          <span className="flex items-center gap-1.5"><span className="h-2.5 w-6 bg-accent opacity-80" style={{ backgroundImage: 'repeating-linear-gradient(45deg, transparent 0 3px, rgba(255,255,255,.55) 3px 6px)' }} />Užprabuoti iki</span>
          <span className="flex items-center gap-1.5"><span className="h-2.5 w-6 bg-accent opacity-70" style={{ backgroundImage: 'radial-gradient(rgba(255,255,255,.7) 1px, transparent 1.2px)', backgroundSize: '5px 5px' }} />Pristatyti iki</span>
        </div>
      </div>
      <div className="cjs-card overflow-hidden">
        {orders.length === 0 ? (
          <Empty title="Nėra užsakymų pagal pasirinktus filtrus" />
        ) : (
          <div
            ref={scroller}
            className={cx('relative max-h-[calc(100vh-280px)] min-h-[320px] select-none overflow-auto', grabbing ? 'cursor-grabbing' : 'cursor-grab')}
            style={{ scrollbarWidth: 'thin' }}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerCancel={onPointerUp}
            onClickCapture={onClickCapture}
            onScroll={measure}
          >
            <div className="relative" style={{ width: nameW + width }}>
              <div className="sticky top-0 z-[4] flex bg-surface" style={{ width: nameW + width }}>
                <div className="sticky left-0 z-[5] h-[54px] shrink-0 border-b border-r border-separator bg-surface" style={{ width: nameW }} />
                <div className="shrink-0" style={{ width }}>
                  <div className="flex h-[22px] border-b border-separator">
                    {months.map((m) => (
                      <div key={m.key} className="truncate border-r border-separator px-2 text-[11px] font-semibold leading-[22px] text-muted" style={{ width: m.count * dayW }}>{m.label}</div>
                    ))}
                  </div>
                  <div className="flex h-8 border-b border-separator">
                    {days.map((d, i) => {
                      const dow = new Date(parse(d)).getUTCDay()
                      const show = !compact || dow === 1 || i === todayIdx
                      return (
                        <button
                          type="button"
                          key={d}
                          onClick={() => setSelectedDay(selectedDay === d ? null : d)}
                          className={cx('flex shrink-0 flex-col items-center justify-center text-[10px] leading-tight', selectedDay === d ? 'cjs-tl-sel-cell' : i === todayIdx ? 'cjs-tl-today-cell' : dow === 0 || dow === 6 ? 'text-muted/70' : 'text-muted')}
                          style={{ width: dayW }}
                        >
                          {dayW >= 18 && <span>{WD[dow]}</span>}
                          {show && <span className="font-semibold">{+d.slice(8, 10)}</span>}
                        </button>
                      )
                    })}
                  </div>
                </div>
              </div>
              <div className="relative" onClick={onBodyClick}>
                <div className="cjs-tl-today-col pointer-events-none absolute inset-y-0 z-0" style={{ left: nameW + todayIdx * dayW, width: dayW }} />
                {selectedDay && <div className="cjs-tl-sel-col pointer-events-none absolute inset-y-0 z-0" style={{ left: nameW + diff(range.start, selectedDay) * dayW, width: dayW }} />}
                {rows.length === 0 && <div className="sticky px-4 py-6 text-[13px] text-muted" style={{ left: nameW, marginLeft: nameW }}>Šiuo laikotarpiu užsakymų nėra. Paslinkite arba spauskite „Grįžti į šiandien“.</div>}
                {rows.map((o) => {
                  const c = color(o.id)
                  const segs = segments(o)
                  const pct = progress(o)
                  const lines = tip(o)
                  const title = lines.join('\n')
                  return (
                    <div key={o.id} className="relative flex h-9">
                      <div data-tl-label className="sticky left-0 z-[2] h-9 shrink-0 border-b border-r border-separator bg-surface" style={{ width: nameW }}>
                        <Tooltip delay={400}>
                          <Tooltip.Trigger className="h-full w-full">
                            <a href={adminViewUrl('orders', { order: o.id })} target="_blank" rel="noopener" draggable={false} className="flex h-full w-full items-center gap-2 truncate px-3 text-left text-[12.5px] hover:bg-surface-secondary">
                              <span className="cjs-dot" style={{ background: c }} />
                              <span className="font-semibold">#{o.number}</span>
                              <span className="truncate text-muted">{o.name}</span>
                            </a>
                          </Tooltip.Trigger>
                          <Tooltip.Content>
                            <div className="text-[12px]">
                              {lines.map((l, i) => <div key={i} className={i === 0 ? 'font-semibold' : undefined}>{l}</div>)}
                            </div>
                          </Tooltip.Content>
                        </Tooltip>
                      </div>
                      <div className="relative h-9 shrink-0 border-b border-separator/60" style={{ width, ...gridBg }}>
                        {segs.map((s, idx) => {
                          const from = diff(range.start, s.from)
                          const to = diff(range.start, s.to)
                          const last = idx === segs.length - 1
                          const w = Math.max(1, to - from + (last ? 1 : 0)) * dayW
                          const style = { left: from * dayW, width: w }
                          if (s.kind === 'solid') {
                            return (
                              <div key={idx} title={title} className="absolute top-2 h-5 overflow-hidden border-2" style={{ ...style, borderColor: c }}>
                                <div className="h-full" style={{ width: `${(pct * 100).toFixed(1)}%`, background: c }} />
                              </div>
                            )
                          }
                          return (
                            <div
                              key={idx}
                              title={title}
                              className="absolute top-2 h-5"
                              style={{
                                ...style,
                                background: c,
                                opacity: s.kind === 'stripes' ? 0.85 : 0.7,
                                backgroundImage: s.kind === 'stripes' ? 'repeating-linear-gradient(45deg, transparent 0 4px, rgba(255,255,255,.45) 4px 8px)' : 'radial-gradient(rgba(255,255,255,.65) 1.2px, transparent 1.4px)',
                                backgroundSize: s.kind === 'dots' ? '6px 6px' : undefined,
                              }}
                            />
                          )
                        })}
                      </div>
                    </div>
                  )
                })}
              </div>
            </div>
          </div>
        )}
      </div>
      <p className="mt-2 text-[12px] text-muted">Tempkite pele, kad slinktumėte · Ctrl + ratukas arba +/- keičia mastelį · spustelėkite dieną, kad ją pažymėtumėte (Esc nuima)</p>
    </Page>
  )
}
