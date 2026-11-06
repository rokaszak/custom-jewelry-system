import { Fragment, useEffect, useRef, useState } from 'react'
import { Button, ProgressBar } from '@heroui/react'
import { daysLabel, daysTone, hours } from '../../lib/format'
import { Check, DateInput, NumberInput, SelectField, Tone, cx } from '../../components/ui'
import InventoryBadge from '../../components/InventoryBadge'
import { Plus } from '@gravity-ui/icons'
import { DATE_FIELDS, useOrderActions } from './orderActions'
import { useStatuses } from '../../components/StockModal'

export function TermBadge({ term }) {
  if (!term) return null
  return (
    <span className="inline-flex cursor-default items-center px-1.5 py-0.5 text-[11px] font-semibold tabular-nums" style={{ background: term.bg, color: term.fg }} title={`Terminas ${term.days} d. / standartas ${term.standard} d.`}>
      {term.label}
    </span>
  )
}

export function DaysChip({ days, title }) {
  if (days === null || days === undefined) return <span className="text-[12px] text-muted">No date</span>
  return (
    <span title={title} className="inline-flex">
      <Tone tone={daysTone(days)} className="whitespace-nowrap">{daysLabel(days)}</Tone>
    </span>
  )
}

function DateCell({ order, field, label }) {
  const { changeDate } = useOrderActions()
  const [value, setValue] = useState(order[field] || '')
  const [saving, setSaving] = useState(false)
  useEffect(() => setValue(order[field] || ''), [order[field]])
  const commit = async (v) => {
    if (!v || !/^\d{4}-\d{2}-\d{2}$/.test(v)) {
      setValue(order[field] || '')
      return
    }
    if (v === (order[field] || '')) return
    setSaving(true)
    try {
      await changeDate(order, field, v)
    } catch {
      setValue(order[field] || '')
    } finally {
      setSaving(false)
    }
  }
  return <DateInput size="sm" aria-label={label} className={cx('w-[118px]', saving && 'opacity-60')} value={value} onChange={setValue} onCommit={commit} />
}

export function DatesEditor({ order }) {
  const rows = [{ key: 'created', label: 'Užsakyta' }, ...Object.entries(DATE_FIELDS).map(([key, label]) => ({ key, label }))]
  const endIdx = rows.findIndex((r) => r.key === (order.term_end || 'deliver_by_date'))
  const hasTerm = !!order.term && endIdx > 0
  const railClass = (i) => {
    if (!hasTerm || i > endIdx) return 'is-none'
    return cx((i === 0 || i === endIdx) && 'has-notch', i === 0 && 'is-first', i === endIdx && 'is-last')
  }
  return (
    <div className="cjs-terms">
      {hasTerm && (
        <div className="cjs-terms-sum" style={{ gridRow: `1 / span ${endIdx + 1}` }}>
          <span className="whitespace-nowrap text-[13px] font-semibold tabular-nums text-foreground" title={`Terminas ${order.term.days} d.`}>{order.term.days} d.</span>
          <TermBadge term={order.term} />
        </div>
      )}
      {rows.map((r, i) => (
        <Fragment key={r.key}>
          <span className={cx('cjs-rail', railClass(i))} style={{ gridRow: i + 1 }} />
          <span className="cjs-terms-label" style={{ gridRow: i + 1 }}>{r.label}</span>
          <div className="cjs-terms-input" style={{ gridRow: i + 1 }}>
            {r.key === 'created' ? (
              <DateInput size="sm" aria-label="Užsakyta" className="w-[118px]" value={order.created || ''} isDisabled />
            ) : (
              <DateCell order={order} field={r.key} label={r.label} />
            )}
          </div>
        </Fragment>
      ))}
      <div className="cjs-terms-input pt-1" style={{ gridRow: rows.length + 1 }}>
        <DaysChip days={order.days_left} title={order.deliver_by_date ? `Dienos liko (iki ${order.deliver_by_date})` : undefined} />
      </div>
    </div>
  )
}

function HoursInput({ order, field, label, floor = null, onLow }) {
  const { saveField } = useOrderActions()
  const current = order[field] === null || order[field] === undefined ? '' : String(order[field])
  const [value, setValue] = useState(current)
  useEffect(() => setValue(current), [current])
  const low = floor !== null && Number(String(value).replace(',', '.') || 0) < floor - 0.001
  useEffect(() => {
    if (onLow) onLow(low)
  }, [low, onLow])
  const commit = async () => {
    if (value === current || low) return
    try {
      await saveField(order.id, field, value === '' ? 0 : value)
    } catch {
      setValue(current)
    }
  }
  return (
    <NumberInput
      size="sm"
      aria-label={label}
      className="w-[52px] text-right"
      step="0.25"
      min={floor !== null ? String(floor) : '0'}
      value={value}
      invalid={low}
      onChange={setValue}
      onBlur={commit}
      onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
    />
  )
}

export function HoursEditor({ order }) {
  const statuses = useStatuses()
  const assigned = Number(order.assigned_hours || 0)
  const done = Number(order.completed_hours || 0)
  const pct = assigned > 0 ? Math.min(100, Math.round((done / assigned) * 100)) : 0
  const keys = statuses.list.map((s) => s.key)
  const made = keys.indexOf(order.status_key) >= 0 && keys.indexOf(order.status_key) >= keys.indexOf('pagaminta') && keys.indexOf('pagaminta') >= 0
  const [low, setLow] = useState(false)
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-center gap-1">
        <HoursInput order={order} field="completed_hours" label="Completed hours" floor={made && assigned > 0 ? assigned : null} onLow={setLow} />
        <span className="text-muted">/</span>
        <HoursInput order={order} field="assigned_hours" label="Assigned hours" />
        <span className="text-[11.5px] text-muted">h</span>
      </div>
      <ProgressBar aria-label="Hours progress" value={pct} size="sm" color={pct >= 100 ? 'success' : 'accent'}>
        <ProgressBar.Track>
          <ProgressBar.Fill />
        </ProgressBar.Track>
      </ProgressBar>
      {low && <div className="max-w-[180px] text-[11.5px] leading-snug text-danger">At {statuses.label('pagaminta')} or later the completed hours cannot be lower than the assigned hours ({hours(assigned)} h).</div>}
    </div>
  )
}

export function FlagsEditor({ order, vertical }) {
  const { saveField } = useOrderActions()
  const [state, setState] = useState({ order_model: order.order_model, order_production: order.order_production, order_printing: order.order_printing })
  useEffect(() => setState({ order_model: order.order_model, order_production: order.order_production, order_printing: order.order_printing }), [order.order_model, order.order_production, order.order_printing])
  const toggle = async (field, v) => {
    setState((s) => ({ ...s, [field]: v }))
    try {
      await saveField(order.id, field, v ? 1 : 0)
    } catch {
      setState((s) => ({ ...s, [field]: !v }))
    }
  }
  return (
    <div className={cx('flex gap-x-4 gap-y-1.5', vertical ? 'flex-col' : 'flex-wrap')}>
      <Check checked={state.order_model} onChange={(v) => toggle('order_model', v)} ariaLabel="Užsakyti modelį"><span className="whitespace-nowrap text-[12.5px]">Užsakyti modelį</span></Check>
      <Check checked={state.order_production} onChange={(v) => toggle('order_production', v)} ariaLabel="Užsakyti gamybą"><span className="whitespace-nowrap text-[12.5px]">Užsakyti gamybą</span></Check>
      <Check checked={state.order_printing} onChange={(v) => toggle('order_printing', v)} ariaLabel="Spauda"><span className="whitespace-nowrap text-[12.5px]">Spauda</span></Check>
    </div>
  )
}

export function StatusSelect({ order, statuses, onDone, isDisabled }) {
  const { saveField, completeOrder } = useOrderActions()
  const current = order.status_key || 'nepradeta'
  const [value, setValue] = useState(current)
  useEffect(() => setValue(current), [current])
  const options = (statuses || []).map((s) => (typeof s === 'string' ? { value: s, label: s } : { value: s.key, label: s.label }))
  const change = async (v) => {
    if (!v || v === value) return
    const prev = value
    setValue(v)
    try {
      const updated = await saveField(order.id, 'manufacturing_status', v)
      if (updated && updated.status_key === 'done' && prev !== 'done' && !updated.locked) {
        await completeOrder(updated, { fromStatus: true })
      }
      if (onDone) onDone()
    } catch {
      setValue(prev)
    }
  }
  return <SelectField size="sm" value={value} onChange={change} options={options} isDisabled={isDisabled} placeholder="Statusas" ariaLabel="Statusas" />
}

export function TypeSelect({ order, types }) {
  const { saveField } = useOrderActions()
  const [value, setValue] = useState(order.order_type || '')
  useEffect(() => setValue(order.order_type || ''), [order.order_type])
  const change = async (v) => {
    const prev = value
    setValue(v)
    try {
      await saveField(order.id, 'order_type', v)
    } catch {
      setValue(prev)
    }
  }
  return <SelectField size="sm" value={value} onChange={change} options={types} placeholder="Užsakymo tipas" ariaLabel="Užsakymo tipas" />
}


export function OrderComment({ order, rows = 2, fill = false }) {
  const { saveField } = useOrderActions()
  const [value, setValue] = useState(order.casting_notes || '')
  const saveTimer = useRef(null)
  const ref = useRef(null)

  useEffect(() => {
    if (document.activeElement !== ref.current) setValue(order.casting_notes || '')
  }, [order.casting_notes])

  useEffect(() => () => clearTimeout(saveTimer.current), [])

  const persist = (v) => {
    clearTimeout(saveTimer.current)
    saveField(order.id, 'casting_notes', v).catch(() => {})
  }

  const field = (
    <div data-no-expand className={cx('cjs-liej', fill && 'is-fill')}>
      <div className="cjs-liej-text">
        <div className="cjs-liej-mirror" aria-hidden="true">{(value || 'Komentaras') + ' '}</div>
        <textarea
          ref={ref}
          rows={rows}
          aria-label="Komentaras"
          placeholder="Komentaras"
          value={value}
          onChange={(e) => {
            const v = e.target.value
            setValue(v)
            clearTimeout(saveTimer.current)
            saveTimer.current = setTimeout(() => saveField(order.id, 'casting_notes', v).catch(() => {}), 1800)
          }}
          onBlur={() => {
            if (value !== (order.casting_notes || '')) persist(value)
          }}
        />
      </div>
    </div>
  )
  if (!fill) return field
  return (
    <div className="cjs-liej-cell">
      <div className="cjs-liej-sizer" aria-hidden="true">{(value || 'Komentaras') + ' '}</div>
      {field}
    </div>
  )
}

export function ProductsCompact({ items }) {
  if (!items?.length) return null
  return (
    <div className="flex min-w-0 flex-col gap-2.5 text-[12px] leading-snug">
      {items.map((it) => (
        <div key={it.id} className="min-w-0">
          <div className="line-clamp-2 break-words font-semibold text-foreground" title={it.name}>{it.name}</div>
          {it.qty > 1 && <div className="text-muted">× {it.qty}</div>}
          {it.meta?.length > 0 && (
            <div className="mt-1 flex flex-col gap-0.5">
              {it.meta.map((m, i) => (
                <div key={i} className="break-words text-foreground">
                  <span className="inline-block min-w-[84px] whitespace-nowrap pr-2 text-muted">{m.key}</span>
                  {m.value}
                </div>
              ))}
            </div>
          )}
        </div>
      ))}
    </div>
  )
}

export function CustomerNote({ order, rows = 2, fill = false }) {
  const text = (order.customer_note || '').trim()
  const field = (
    <div data-no-expand className={cx('cjs-liej is-locked', fill && 'is-fill')} title="Kliento pastaba iš WooCommerce, keisti negalima">
      <div className="cjs-liej-text">
        <div className="cjs-liej-mirror" aria-hidden="true">{text + ' '}</div>
        <textarea rows={rows} value={text} readOnly aria-readonly="true" aria-label="Kliento pastaba" tabIndex={-1} />
      </div>
    </div>
  )
  if (!fill) return field
  return (
    <div className="cjs-liej-cell">
      <div className="cjs-liej-sizer" aria-hidden="true">{text + ' '}</div>
      {field}
    </div>
  )
}

export function InventoryList({ order, onOpen, onOpenStockOrder, onOpenItem, onOrderMissing }) {
  const lines = Array.isArray(order.inv_lines) ? order.inv_lines : []
  if (!lines.length) {
    if (!onOpen) return <span className="text-[12px] text-muted">-</span>
    return (
      <span className="inline-flex" onClick={(e) => e.stopPropagation()}>
        <Button size="sm" variant="tertiary" onPress={onOpen}>
          <Plus className="size-4" />
          Add
        </Button>
      </span>
    )
  }
  return (
    <ul className="flex min-w-0 flex-col items-start gap-1">
      {lines.map((l) => {
        return (
          <li key={l.id} className="flex min-w-0 max-w-full flex-wrap items-center gap-1">
            <InventoryBadge line={l} onOpenItem={onOpenItem} onOpenStockOrder={onOpenStockOrder} onOrderMissing={onOrderMissing ? (x) => onOrderMissing(x, order) : undefined} pieces={l.pieces} />
          </li>
        )
      })}
    </ul>
  )
}

export const InventoryChips = InventoryList

export function HoursSummary({ order }) {
  return <span className="tabular-nums">{hours(order.completed_hours)} / {hours(order.assigned_hours)} h</span>
}
