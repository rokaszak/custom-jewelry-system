import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Button, Dropdown, Label, Spinner, ToggleButton } from '@heroui/react'
import { toast } from '../../lib/toast'
import { ArrowDownToLine, Check as CheckIcon, Comment, EllipsisVertical, Plus, ShoppingCart, TrashBin } from '@gravity-ui/icons'
import { get, post, patch, del } from '../../lib/api'
import { notifyError, useMediaQuery } from '../../lib/hooks'
import { PRABA_REF, fineQty, money, qty } from '../../lib/format'
import { BackLink, BulkBar, Btn, Card, Check, CopyButton, DateInput, Dialog, Empty, Expand, Field, HexChip, IconButton, MoneyInput, Notice, NumberInput, Page, SelectAll, TextArea, TextInput, useConfirm, useSelection } from '../../components/ui'
import ItemPicker from '../../components/ItemPicker'
import { OrderLink } from '../../components/OrderLink'
import { PieceChips, StockLineBadge } from '../../components/InventoryBadge'
import { saveDemandNote } from '../../components/LineComment'

const INVALIDATE = ['stock-orders', 'required', 'orders', 'order', 'items', 'item', 'bootstrap', 'order-inventory']

const toNum = (v) => (v === '' || v === null || v === undefined ? null : Number(v))

function unitWord(unit) {
  return !unit || unit === 'pcs' ? 'pieces' : unit
}

function Spin() {
  return (
    <div className="flex items-center justify-center py-16">
      <Spinner size="md" />
    </div>
  )
}

function ReceiveDialog({ line, onClose, onReceive }) {
  const remaining = Math.max(0, Number(line.qty) - Number(line.qty_received))
  const [amount, setAmount] = useState(String(remaining))
  const [cost, setCost] = useState(line.unit_cost === null || line.unit_cost === undefined ? '' : String(line.unit_cost))
  const [auto, setAuto] = useState([])
  const [manual, setManual] = useState({})
  const [saving, setSaving] = useState(false)
  const planned = Array.isArray(line.planned_identifiers) ? line.planned_identifiers : []
  const n = Number(amount)
  const valid = amount !== '' && Number.isFinite(n) && n > 0 && (!line.per_piece || Number.isInteger(n))
  const count = line.per_piece && valid ? Math.min(n, 500) : 0
  useEffect(() => {
    if (!count) return
    let alive = true
    get(`items/${line.item_id}/next-identifiers`, { count }).then((r) => alive && setAuto(r.identifiers || [])).catch(() => {})
    return () => { alive = false }
  }, [count, line.item_id])
  const waiting = (line.demands || []).filter((d) => d.missing > 0 && d.order?.id)
  const numbers = waiting.map((d) => `#${d.order.number || d.order.id}`)
  const word = unitWord(line.unit)
  const submit = async () => {
    if (!valid) return
    setSaving(true)
    try {
      const identifiers = line.per_piece ? Array.from({ length: count }, (_, i) => String(manual[i] ?? planned[i] ?? auto[i] ?? '').trim()).filter(Boolean) : []
      await onReceive({ qty: amount, unit_cost: cost, identifiers })
      toast.success(`Received ${qty(amount, line.unit)} ${line.name}`)
      onClose()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Dialog
      isOpen
      onClose={onClose}
      size="md"
      title={`Receive ${line.name}`}
      footer={
        <>
          <Button variant="tertiary" onPress={onClose}>Cancel</Button>
          <Btn variant="primary" icon={ArrowDownToLine} isPending={saving} isDisabled={!valid} onPress={submit}>Receive</Btn>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="text-[13px] text-muted tabular-nums">
          On this stock order: {qty(line.qty, line.unit)} · received so far: {qty(line.qty_received, line.unit)}
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={word === 'pieces' ? 'How many pieces arrived?' : `How much arrived? (${line.unit})`} required error={amount !== '' && !valid ? (line.per_piece ? 'Enter a whole number above 0' : 'Enter a number above 0') : null}>
            <NumberInput value={amount} onChange={setAmount} min="0" step={line.per_piece ? '1' : 'any'} invalid={!valid} autoFocus />
          </Field>
          <Field label={word === 'pieces' ? 'Cost per piece' : `Cost per ${line.unit}`} hint="Optional">
            <MoneyInput value={cost} onChange={setCost} min="0" />
          </Field>
        </div>
        <Expand open={valid && n > remaining && remaining > 0}>
          <div className="text-[12.5px] text-warning">That is more than ordered. The line goes up to {qty(Number(line.qty_received) + (valid ? n : 0), line.unit)}.</div>
        </Expand>
        <p className="text-[13.5px] text-foreground">
          {numbers.length > 0 ? (valid && n > Number(line.waiting_qty || 0) + 0.0001 ? `They go to the orders waiting for them first (${numbers.join(', ')}), the rest goes into stock.` : `They go to the orders waiting for them (${numbers.join(', ')}).`) : 'They go into stock.'}
        </p>
        {line.per_piece && count > 0 && (
          <Field label={count === 1 ? 'Identifier' : `Identifiers (${count})`}>
            <div className="grid gap-2 sm:grid-cols-2">
              {Array.from({ length: count }, (_, i) => (
                <div key={i} className="flex items-center gap-2">
                  <span className="w-6 shrink-0 text-right text-[12px] tabular-nums text-muted">{i + 1}.</span>
                  <TextInput size="sm" value={manual[i] ?? planned[i] ?? auto[i] ?? ''} onChange={(v) => setManual((m) => ({ ...m, [i]: v }))} aria-label={`Piece ${i + 1}`} />
                </div>
              ))}
            </div>
          </Field>
        )}
      </div>
    </Dialog>
  )
}

function PlannedDialog({ line, onClose, onSave }) {
  const left = Math.max(1, Math.round(Number(line.qty) - Number(line.qty_received)))
  const planned = Array.isArray(line.planned_identifiers) ? line.planned_identifiers : []
  const count = Math.max(left, planned.length)
  const [values, setValues] = useState(() => Array.from({ length: count }, (_, i) => planned[i] || ''))
  const [saving, setSaving] = useState(false)
  const save = async () => {
    setSaving(true)
    try {
      await onSave(values.map((v) => v.trim()).filter(Boolean))
      onClose()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Dialog
      isOpen
      size="md"
      onClose={onClose}
      title={`Identifiers for ${line.name}`}
      footer={
        <>
          <Button variant="tertiary" onPress={onClose}>Cancel</Button>
          <Btn variant="primary" isPending={saving} onPress={save}>Save</Btn>
        </>
      }
    >
      <Field label={count === 1 ? 'Identifier' : `Identifiers (${count})`} hint="Known before the pieces arrive, for example certificate numbers. Receiving fills them in.">
        <div className="grid gap-2 sm:grid-cols-2">
          {values.map((v, i) => (
            <div key={i} className="flex items-center gap-2">
              <span className="w-6 shrink-0 text-right text-[12px] tabular-nums text-muted">{i + 1}.</span>
              <TextInput size="sm" value={v} autoFocus={i === 0} onChange={(x) => setValues((cur) => cur.map((c, k) => (k === i ? x : c)))} aria-label={`Piece ${i + 1}`} />
            </div>
          ))}
        </div>
      </Field>
    </Dialog>
  )
}

function PlannedPieces({ line, onChanged }) {
  const [open, setOpen] = useState(false)
  if (!line.per_piece || line.state === 'received') return null
  const planned = Array.isArray(line.planned_identifiers) ? line.planned_identifiers : []
  return (
    <>
      <PieceChips pieces={planned.map((p) => ({ identifier: p, state: 'planned' }))} />
      <button type="button" className="inline-flex cursor-pointer items-center px-1 text-[12px] text-accent hover:underline" onClick={() => setOpen(true)}>{planned.length ? 'Edit identifiers' : 'Add identifiers'}</button>
      {open && <PlannedDialog line={line} onClose={() => setOpen(false)} onSave={async (list) => { await patch(`stock-order-lines/${line.id}`, { planned_identifiers: list }); onChanged() }} />}
    </>
  )
}

function OrderedToggle({ line, onChange }) {
  const locked = Number(line.qty_received) > 0
  return (
    <ToggleButton size="sm" isSelected={line.state !== 'not_ordered'} isDisabled={locked} onChange={onChange} className="w-full justify-center" aria-label={line.state === 'not_ordered' ? 'Mark as ordered' : 'Mark as not ordered'}>
      {line.state !== 'not_ordered' ? <CheckIcon className="size-4" /> : <ShoppingCart className="size-4" />}
      Ordered
    </ToggleButton>
  )
}

function LineActions({ line, onOrdered, onReceive, onDelete }) {
  const remaining = Math.max(0, Number(line.qty) - Number(line.qty_received))
  return (
    <div className="grid grid-cols-[112px_124px_32px] items-center justify-end gap-2">
      <OrderedToggle line={line} onChange={onOrdered} />
      {line.state === 'received' ? (
        <span className="inline-flex h-9 w-full select-none items-center justify-center gap-2 bg-success-soft px-3 text-sm font-medium whitespace-nowrap text-success-soft-foreground md:h-8">
          <CheckIcon className="size-4" />
          Received
        </span>
      ) : (
        <Btn size="sm" variant="secondary" icon={ArrowDownToLine} className="w-full" onPress={onReceive}>
          {Number(line.qty_received) > 0 ? `Receive ${qty(remaining)}` : 'Receive'}
        </Btn>
      )}
      {Number(line.qty_received) > 0 ? <span aria-hidden="true" /> : <IconButton icon={TrashBin} label="Remove line" onPress={onDelete} />}
    </div>
  )
}

function Demands({ line }) {
  const fineness = line.fineness !== null && line.fineness !== undefined ? PRABA_REF : null
  if (!line.demands?.length) return null
  return (
    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]">
      <span className="text-muted">For</span>
      {line.demands.map((d) => (
        <span key={d.id} className="inline-flex items-center gap-1.5 whitespace-nowrap">
          <OrderLink id={d.order.id} number={d.order.number} customer={d.order.customer} inList={d.order.in_list !== false} />
          <span className="tabular-nums text-muted">{fineQty(d.qty, line.unit, fineness)}</span>
        </span>
      ))}
    </div>
  )
}

function NoteInput({ label, value, ariaLabel, autoFocus, onSave, onEmpty }) {
  const [text, setText] = useState(value || '')
  useEffect(() => {
    setText(value || '')
  }, [value])
  const commit = async () => {
    const t = text.trim()
    if (t === (value || '').trim()) {
      if (!t && onEmpty) onEmpty()
      return
    }
    try {
      await onSave(t)
    } catch (e) {
      notifyError(e)
    }
  }
  return (
    <div className="flex min-w-0 items-center gap-2">
      {label && <span className="shrink-0 whitespace-nowrap text-[12px] tabular-nums text-muted">{label}</span>}
      <TextInput size="sm" className="min-w-0 flex-1" value={text} onChange={setText} onBlur={commit} autoFocus={autoFocus} aria-label={ariaLabel} />
    </div>
  )
}

function LineComments({ line, onChanged }) {
  const [adding, setAdding] = useState([])
  const orders = (line.demands || []).filter((d) => Number(d.order?.id) > 0)
  const saveOwn = async (t) => {
    await patch(`stock-order-lines/${line.id}`, { note: t })
    await onChanged()
  }
  if (!orders.length) return <NoteInput value={line.note} ariaLabel={`Comment for ${line.name}`} onSave={saveOwn} />
  const stockPart = Number(line.qty) > Number(line.for_orders || 0) + 0.0001
  const drop = (key) => setAdding((cur) => cur.filter((k) => k !== key))
  const entries = []
  for (const d of orders) {
    const key = `d${d.id}`
    if (d.note || adding.includes(key)) {
      entries.push({
        key,
        label: `#${d.order.number}`,
        value: d.note || '',
        save: async (t) => {
          await saveDemandNote(d.id, t)
          await onChanged()
          drop(key)
        },
      })
    }
  }
  if (line.note || adding.includes('own')) {
    entries.push({
      key: 'own',
      label: 'Stock',
      value: line.note || '',
      save: async (t) => {
        await saveOwn(t)
        drop('own')
      },
    })
  }
  const options = orders.filter((d) => !d.note && !adding.includes(`d${d.id}`)).map((d) => ({ key: `d${d.id}`, label: `#${d.order.number}${d.order.customer ? ` ${d.order.customer}` : ''}` }))
  if (stockPart && !line.note && !adding.includes('own')) options.push({ key: 'own', label: 'For stock' })
  return (
    <div className="flex min-w-0 flex-col gap-1.5">
      {entries.map((e) => (
        <NoteInput key={e.key} label={e.label} value={e.value} autoFocus={adding.includes(e.key)} ariaLabel={`Comment ${e.label}`} onSave={e.save} onEmpty={() => drop(e.key)} />
      ))}
      {options.length > 0 && (
        <Dropdown>
          <Button size="sm" variant="tertiary" className="self-start">
            <Comment className="size-4" />
            Add comment
          </Button>
          <Dropdown.Popover className="min-w-52">
            <Dropdown.Menu aria-label="Add comment for" onAction={(k) => setAdding((cur) => [...cur, String(k)])}>
              {options.map((o) => (
                <Dropdown.Item key={o.key} id={o.key} textValue={o.label}>
                  <Label>{o.label}</Label>
                </Dropdown.Item>
              ))}
            </Dropdown.Menu>
          </Dropdown.Popover>
        </Dropdown>
      )}
    </div>
  )
}

function useStockOrder(id, { onChanged, onDeleted }) {
  const qc = useQueryClient()
  const confirm = useConfirm()
  const key = useMemo(() => ['stock-order', id], [id])
  const q = useQuery({ queryKey: key, queryFn: () => get(`stock-orders/${id}`), enabled: !!id })
  const [lineDrafts, setLineDrafts] = useState({})
  const [headerDraft, setHeaderDraft] = useState({})
  const [pending, setPending] = useState(0)
  const [savedAt, setSavedAt] = useState(0)
  const [busy, setBusy] = useState('')
  const timers = useRef({})
  const draftsRef = useRef({ lines: {}, header: {} })
  draftsRef.current = { lines: lineDrafts, header: headerDraft }
  const changedRef = useRef(onChanged)
  changedRef.current = onChanged

  useEffect(() => () => Object.values(timers.current).forEach(clearTimeout), [])
  useEffect(() => {
    setLineDrafts({})
    setHeaderDraft({})
  }, [id])

  const touched = useCallback(() => {
    for (const k of INVALIDATE) qc.invalidateQueries({ queryKey: [k] })
    if (changedRef.current) changedRef.current()
  }, [qc])

  const apply = useCallback((so) => {
    if (so && so.id) qc.setQueryData(key, so)
  }, [qc, key])

  const track = useCallback(async (fn) => {
    setPending((n) => n + 1)
    try {
      const so = await fn()
      apply(so)
      setSavedAt(Date.now())
      touched()
      return so
    } finally {
      setPending((n) => n - 1)
    }
  }, [apply, touched])

  const lineValid = (line, draft) => {
    if (!draft) return true
    if ('qty' in draft) {
      const n = toNum(draft.qty)
      if (n === null || !Number.isFinite(n) || n <= 0 || n < Number(line.qty_received)) return false
    }
    if ('unit_cost' in draft && draft.unit_cost !== '') {
      const c = Number(draft.unit_cost)
      if (!Number.isFinite(c) || c < 0) return false
    }
    return true
  }

  const saveLine = useCallback(async (lineId) => {
    clearTimeout(timers.current[`l${lineId}`])
    const draft = draftsRef.current.lines[lineId]
    const so = qc.getQueryData(key)
    const line = so?.lines?.find((l) => l.id === lineId)
    if (!draft || !line || !lineValid(line, draft)) return
    const body = {}
    if ('qty' in draft) body.qty = draft.qty
    if ('unit_cost' in draft) body.unit_cost = draft.unit_cost
    try {
      await track(() => patch(`stock-order-lines/${lineId}`, body))
      setLineDrafts((all) => {
        const cur = all[lineId]
        if (!cur) return all
        const next = { ...cur }
        for (const [k, v] of Object.entries(body)) if (next[k] === v) delete next[k]
        const out = { ...all }
        if (Object.keys(next).length) out[lineId] = next
        else delete out[lineId]
        return out
      })
    } catch (e) {
      notifyError(e)
    }
  }, [qc, key, track])

  const editLine = (lineId, field, value) => {
    setLineDrafts((all) => ({ ...all, [lineId]: { ...(all[lineId] || {}), [field]: value } }))
    clearTimeout(timers.current[`l${lineId}`])
    timers.current[`l${lineId}`] = setTimeout(() => saveLine(lineId), 700)
  }

  const saveHeader = useCallback(async () => {
    clearTimeout(timers.current.header)
    const draft = draftsRef.current.header
    if (!Object.keys(draft).length) return
    if ('number' in draft && !String(draft.number).trim()) return
    try {
      await track(() => patch(`stock-orders/${id}`, draft))
      setHeaderDraft((cur) => {
        const next = { ...cur }
        for (const [k, v] of Object.entries(draft)) if (next[k] === v) delete next[k]
        return next
      })
    } catch (e) {
      notifyError(e)
    }
  }, [id, track])

  const editHeader = (field, value, immediate) => {
    setHeaderDraft((cur) => ({ ...cur, [field]: value }))
    clearTimeout(timers.current.header)
    timers.current.header = setTimeout(() => saveHeader(), immediate ? 0 : 700)
  }

  const flush = useCallback(async (lineIds) => {
    const ids = lineIds || Object.keys(draftsRef.current.lines).map(Number)
    await Promise.all([...ids.map((lid) => saveLine(lid)), saveHeader()])
  }, [saveLine, saveHeader])

  const run = async (label, fn, success) => {
    setBusy(label)
    try {
      const so = await track(fn)
      if (success) toast.success(typeof success === 'function' ? success(so) : success)
      return so
    } catch (e) {
      notifyError(e)
      return null
    } finally {
      setBusy('')
    }
  }

  const setOrdered = (line, value) => {
    qc.setQueryData(key, (so) => so && { ...so, lines: so.lines.map((l) => (l.id === line.id ? { ...l, state: value ? 'ordered' : 'not_ordered', ordered: value, in_cart: value } : l)) })
    return run(`ordered-${line.id}`, () => post(`stock-order-lines/${line.id}/ordered`, { ordered: value })).then((so) => {
      if (!so) q.refetch()
    })
  }

  const addLine = (item) => {
    if (!item) return null
    return run('add', () => post(`stock-orders/${id}/lines`, { item_id: item.id, qty: 1 }))
  }

  const removeLine = async (line) => {
    const ok = await confirm({ title: 'Remove this line?', message: `${line.name} is removed from this stock order. Orders waiting for it go back to the Required list.`, confirmLabel: 'Remove', tone: 'danger' })
    if (!ok) return
    clearTimeout(timers.current[`l${line.id}`])
    setLineDrafts((all) => {
      const out = { ...all }
      delete out[line.id]
      return out
    })
    await run(`delete-${line.id}`, () => del(`stock-order-lines/${line.id}`))
  }

  const receive = async (line, body) => {
    const so = await track(() => post(`stock-order-lines/${line.id}/receive`, body))
    return so
  }

  const receiveAll = async () => {
    const ok = await confirm({ title: 'Receive everything?', message: 'Use this when everything still missing on this stock order has arrived. It goes to the orders waiting for it first, the rest goes into stock.', confirmLabel: 'Receive all' })
    if (!ok) return
    await flush()
    await run('receive-all', () => post(`stock-orders/${id}/receive-all`), 'Everything received')
  }

  const bulk = async (action, lineIds) => {
    if (!lineIds.length) return null
    if (action === 'delete') {
      const ok = await confirm({ title: `Remove ${lineIds.length} ${lineIds.length === 1 ? 'line' : 'lines'}?`, message: 'Lines that already received something stay. Orders waiting for the removed lines go back to the Required list.', confirmLabel: 'Remove', tone: 'danger' })
      if (!ok) return null
    }
    if (action === 'receive') {
      const ok = await confirm({ title: `Receive ${lineIds.length} ${lineIds.length === 1 ? 'line' : 'lines'}?`, message: 'Use this when everything still missing on the selected lines has arrived. It goes to the orders waiting for it first, the rest goes into stock.', confirmLabel: 'Receive' })
      if (!ok) return null
    }
    await flush(lineIds)
    return run(`bulk-${action}`, () => post(`stock-orders/${id}/bulk`, { action, line_ids: lineIds }), (so) => {
      const r = so?.bulk || {}
      return r.skipped ? `${r.done} done, ${r.skipped} skipped` : 'Done'
    })
  }

  const removeOrder = async () => {
    const ok = await confirm({ title: 'Delete stock order?', message: 'Orders waiting for it go back to the Required list.', confirmLabel: 'Delete', tone: 'danger' })
    if (!ok) return
    try {
      await del(`stock-orders/${id}`)
      for (const k of INVALIDATE) qc.invalidateQueries({ queryKey: [k] })
      if (changedRef.current) changedRef.current()
      if (onDeleted) onDeleted()
    } catch (e) {
      notifyError(e)
    }
  }

  const reload = () => {
    touched()
    return q.refetch()
  }

  return { q, so: q.data, reload, lineDrafts, headerDraft, editLine, saveLine, editHeader, saveHeader, flush, setOrdered, addLine, removeLine, receive, receiveAll, bulk, removeOrder, busy, saving: pending > 0, savedAt, lineValid }
}

function SaveState({ saving, savedAt }) {
  if (saving) return <span className="inline-flex h-8 items-center px-1"><Spinner size="sm" /></span>
  if (!savedAt) return null
  return (
    <span className="inline-flex h-8 items-center gap-1 px-1 text-[12px] text-muted">
      <CheckIcon className="size-3.5" />
      Saved
    </span>
  )
}

function Actions({ ctx, onWhatsapp }) {
  const { so } = ctx
  const hasOpen = so.lines.some((l) => l.state !== 'received')
  const locked = so.lines.some((l) => Number(l.qty_received) > 0)
  return (
    <>
      <SaveState saving={ctx.saving} savedAt={ctx.savedAt} />
      <Button variant="secondary" onPress={onWhatsapp}>WhatsApp message</Button>
      {hasOpen && <Btn variant="primary" icon={ArrowDownToLine} isPending={ctx.busy === 'receive-all'} onPress={ctx.receiveAll}>Receive all</Btn>}
      <Dropdown>
        <Button isIconOnly variant="tertiary" aria-label="More"><EllipsisVertical className="size-4" /></Button>
        <Dropdown.Popover>
          <Dropdown.Menu aria-label="More" disabledKeys={locked ? ['delete'] : []} onAction={() => ctx.removeOrder()}>
            <Dropdown.Item id="delete" textValue="Delete stock order" variant="danger"><TrashBin className="size-4" /><Label>{locked ? 'Cannot delete: stock already received' : 'Delete stock order'}</Label></Dropdown.Item>
          </Dropdown.Menu>
        </Dropdown.Popover>
      </Dropdown>
    </>
  )
}

function Lines({ ctx }) {
  const { so, lineDrafts } = ctx
  const wide = useMediaQuery('(min-width: 900px)')
  const selection = useSelection(so.lines.map((l) => l.id))
  const [receiving, setReceiving] = useState(null)
  const [pickerKey, setPickerKey] = useState(0)
  const [pick, setPick] = useState(null)
  const selected = so.lines.filter((l) => selection.isSelected(l.id))
  const view = (l) => ({ ...l, ...(lineDrafts[l.id] || {}) })
  const total = so.lines.reduce((sum, l) => {
    const v = view(l)
    return sum + (v.unit_cost !== '' && v.unit_cost !== null && v.unit_cost !== undefined ? Number(v.unit_cost) * Number(v.qty || 0) : 0)
  }, 0)

  const openReceive = async (line) => {
    await ctx.flush([line.id])
    setReceiving(line.id)
  }
  const add = async (item) => {
    if (!item) return
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur()
    setPick(null)
    setPickerKey((k) => k + 1)
    await ctx.addLine(item)
  }
  const bulk = async (action) => {
    const so2 = await ctx.bulk(action, selection.ids.map(Number))
    if (so2) selection.clear()
  }
  const receivingLine = receiving ? so.lines.find((l) => l.id === receiving) : null

  const qtyInput = (l) => {
    const v = view(l)
    const n = toNum(v.qty)
    const bad = n === null || n <= 0 || n < Number(l.qty_received)
    return (
      <div className="flex items-center gap-1.5">
        <NumberInput size="sm" className="w-20" value={v.qty === null || v.qty === undefined ? '' : String(v.qty)} invalid={bad} min={String(l.qty_received || 0)} onChange={(val) => ctx.editLine(l.id, 'qty', val)} onBlur={() => ctx.saveLine(l.id)} aria-label={`Quantity of ${l.name}`} />
        <span className="text-[12px] text-muted">{l.unit}</span>
      </div>
    )
  }
  const costInput = (l) => {
    const v = view(l)
    return <MoneyInput size="sm" className="w-28" value={v.unit_cost === null || v.unit_cost === undefined ? '' : String(v.unit_cost)} min="0" onChange={(val) => ctx.editLine(l.id, 'unit_cost', val)} onBlur={() => ctx.saveLine(l.id)} aria-label={`Cost per unit of ${l.name}`} />
  }
  const actions = (l) => <LineActions line={l} onOrdered={(v) => ctx.setOrdered(l, v)} onReceive={() => openReceive(l)} onDelete={() => ctx.removeLine(l)} />

  return (
    <Card title="Lines" padded={false} actions={total > 0 ? <span className="text-[13px] font-semibold tabular-nums">Total {money(total)}</span> : null}>
      <Expand open={selection.count > 0}>
        <div className="px-4 pt-3 sm:px-5">
        <BulkBar count={selection.count} onClear={selection.clear}>
          <Btn size="sm" variant="secondary" icon={CheckIcon} isPending={ctx.busy === 'bulk-ordered'} isDisabled={!selected.some((l) => l.state === 'not_ordered')} onPress={() => bulk('ordered')}>Mark ordered</Btn>
          <Btn size="sm" variant="tertiary" isPending={ctx.busy === 'bulk-not_ordered'} isDisabled={!selected.some((l) => l.state === 'ordered' && Number(l.qty_received) === 0)} onPress={() => bulk('not_ordered')}>Mark not ordered</Btn>
          <Btn size="sm" variant="secondary" icon={ArrowDownToLine} isPending={ctx.busy === 'bulk-receive'} isDisabled={!selected.some((l) => l.state !== 'received')} onPress={() => bulk('receive')}>Receive</Btn>
          <Btn size="sm" variant="danger-soft" icon={TrashBin} isPending={ctx.busy === 'bulk-delete'} isDisabled={!selected.some((l) => Number(l.qty_received) === 0)} onPress={() => bulk('delete')}>Remove</Btn>
        </BulkBar>
        </div>
      </Expand>
      {so.lines.length === 0 ? (
        <Empty compact icon={ShoppingCart} title="No lines yet" description="Add an item below or create stock orders from the Required page." />
      ) : wide ? (
        <table className="cjs-table mt-2">
          <thead>
            <tr>
              <th className="w-10"><SelectAll selection={selection} /></th>
              <th>Item</th>
              <th className="w-[26%]">Comment</th>
              <th className="w-[130px]">Quantity</th>
              <th className="w-[140px]">Cost / unit</th>
              <th className="w-[300px] text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {so.lines.map((l) => (
              <tr key={l.id}>
                <td className="align-middle"><Check checked={selection.isSelected(l.id)} onChange={() => selection.toggle(l.id)} ariaLabel={`Select ${l.name}`} /></td>
                <td>
                  <div className="flex flex-wrap items-center gap-1">
                    <StockLineBadge line={{ ...l, qty: view(l).qty }} />
                    <PlannedPieces line={l} onChanged={ctx.reload} />
                  </div>
                  {(l.code || l.item_free > 0) && (
                    <div className="mt-1 text-[11.5px] text-muted">{[l.code, l.item_free > 0 ? `${qty(l.item_free, l.unit)} available` : null].filter(Boolean).join(' · ')}</div>
                  )}
                  <Demands line={l} />
                </td>
                <td className="align-top"><LineComments line={l} onChanged={ctx.reload} /></td>
                <td className="align-middle">{qtyInput(l)}</td>
                <td className="align-middle">{costInput(l)}</td>
                <td className="align-middle">{actions(l)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <>
          <div className="mt-2 flex items-center gap-2 border-b border-separator px-4 py-2 text-[12px] text-muted">
            <SelectAll selection={selection} />
            Select all
          </div>
          <ul className="divide-y divide-separator">
            {so.lines.map((l) => (
              <li key={l.id} className="flex flex-col gap-2 px-4 py-3">
                <div className="flex items-start gap-2">
                  <Check checked={selection.isSelected(l.id)} onChange={() => selection.toggle(l.id)} ariaLabel={`Select ${l.name}`} />
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1">
                      <StockLineBadge line={{ ...l, qty: view(l).qty }} />
                      <PlannedPieces line={l} onChanged={ctx.reload} />
                    </div>
                    {l.code && <div className="mt-1 text-[11.5px] text-muted">{l.code}</div>}
                    <Demands line={l} />
                    <div className="mt-2"><LineComments line={l} onChanged={ctx.reload} /></div>
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                  {qtyInput(l)}
                  {costInput(l)}
                </div>
                {actions(l)}
              </li>
            ))}
          </ul>
        </>
      )}
      {ctx.so?.status === 'received' && ctx.so?.lines?.length > 0 ? (
        <div className="border-t border-separator px-4 py-3 text-[12.5px] text-muted sm:px-5">Fully received. This stock order is closed, start a new one for more items.</div>
      ) : (
      <div className="border-t border-separator px-4 py-3 sm:px-5">
        <Field label="Add item">
          <div className="flex items-center gap-2">
            <div className="min-w-0 flex-1">
              <ItemPicker key={pickerKey} value={pick} onChange={setPick} onCreated={add} />
            </div>
            <Btn variant="secondary" icon={Plus} isPending={ctx.busy === 'add'} isDisabled={!pick} onPress={() => add(pick)}>Add item</Btn>
          </div>
        </Field>
      </div>
      )}
      {receivingLine && <ReceiveDialog line={view(receivingLine)} onClose={() => setReceiving(null)} onReceive={(body) => ctx.receive(receivingLine, body)} />}
    </Card>
  )
}

function Side({ ctx }) {
  const { so, headerDraft } = ctx
  const h = { number: so.number || '', order_date: so.order_date || '', supplier: so.supplier || '', ...headerDraft }
  const received = so.orders.filter((o) => o.status_for_order?.key === 'received').length
  return (
    <div className="flex flex-col gap-4">
      <Card title="Details">
        <div className="flex flex-col gap-3">
          <Field label="Number" required error={!String(h.number).trim() ? 'Enter a number' : null}>
            <TextInput value={h.number} invalid={!String(h.number).trim()} onChange={(v) => ctx.editHeader('number', v)} onBlur={() => ctx.saveHeader()} />
          </Field>
          <Field label="Date" required>
            <DateInput value={h.order_date} onChange={(v) => v && ctx.editHeader('order_date', v, true)} aria-label="Date" />
          </Field>
          <Field label="Supplier">
            <TextInput value={h.supplier} onChange={(v) => ctx.editHeader('supplier', v)} onBlur={() => ctx.saveHeader()} />
          </Field>
        </div>
      </Card>
      {so.orders.length > 0 && (
        <Card title="Customer orders" description={`${received} of ${so.orders.length} fully received`}>
          <ul className="flex flex-col gap-2">
            {so.orders.map((o) => (
              <li key={o.id} className="flex items-center justify-between gap-2">
                <OrderLink id={o.id} number={o.number} customer={o.customer} inList={o.in_list !== false} className="min-w-0 truncate" />
                {o.status_for_order && <HexChip color={o.status_for_order.color} label={o.status_for_order.label} />}
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function WhatsappDialog({ isOpen, onClose, text }) {
  return (
    <Dialog isOpen={isOpen} onClose={onClose} size="lg" fill title="WhatsApp message" footer={<CopyButton text={text || ''} label="Copy to clipboard" variant="primary" size="md" />}>
      <TextArea fill readOnly aria-label="WhatsApp message" className="text-[13.5px] leading-relaxed" value={text || 'Add lines first.'} />
    </Dialog>
  )
}

function Body({ ctx }) {
  return (
    <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
      <Lines ctx={ctx} />
      <Side ctx={ctx} />
    </div>
  )
}

function Subtitle({ so }) {
  return (
    <span className="flex flex-wrap items-center gap-2">
      <HexChip color={so.status_color} label={so.status_label} />
      <span>{so.order_date}</span>
      {so.created_by_name && <span>by {so.created_by_name}</span>}
    </span>
  )
}

export function StockOrderModal({ id, isOpen, onClose, onChanged }) {
  const ctx = useStockOrder(isOpen ? id : 0, { onChanged, onDeleted: onClose })
  const [whatsapp, setWhatsapp] = useState(false)
  const close = async () => {
    try {
      await ctx.flush()
    } finally {
      onClose()
    }
  }
  const so = ctx.so
  return (
    <>
      <Dialog
        isOpen={!!isOpen && !!id}
        onClose={close}
        size="full"
        title={so ? `Stock order #${so.number}` : 'Stock order'}
        description={so ? <Subtitle so={so} /> : null}
      >
        {ctx.q.isError ? (
          <Notice tone="danger">{ctx.q.error.message}</Notice>
        ) : !so ? (
          <Spin />
        ) : (
          <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-end gap-2">
              <Actions ctx={ctx} onWhatsapp={() => setWhatsapp(true)} />
            </div>
            <Body ctx={ctx} />
          </div>
        )}
      </Dialog>
      {so && <WhatsappDialog isOpen={whatsapp} onClose={() => setWhatsapp(false)} text={so.whatsapp} />}
    </>
  )
}

export default function StockOrderDetail({ id, onBack, onChanged }) {
  const ctx = useStockOrder(id, { onChanged, onDeleted: onBack })
  const [whatsapp, setWhatsapp] = useState(false)
  const back = async () => {
    try {
      await ctx.flush()
    } finally {
      onBack()
    }
  }
  const so = ctx.so
  if (ctx.q.isError) return <Page title="Stock order" back={<BackLink onPress={onBack}>Stock Orders</BackLink>}><Notice tone="danger">{ctx.q.error.message}</Notice></Page>
  if (!so) return <Spin />
  return (
    <Page
      back={<BackLink onPress={back}>Stock Orders</BackLink>}
      title={`Stock order #${so.number}`}
      subtitle={<Subtitle so={so} />}
      actions={<Actions ctx={ctx} onWhatsapp={() => setWhatsapp(true)} />}
    >
      <Body ctx={ctx} />
      <WhatsappDialog isOpen={whatsapp} onClose={() => setWhatsapp(false)} text={so.whatsapp} />
    </Page>
  )
}
