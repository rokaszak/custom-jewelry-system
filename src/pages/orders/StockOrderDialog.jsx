import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@heroui/react'
import { Plus, Xmark } from '@gravity-ui/icons'
import { toast } from '../../lib/toast'
import { get, post } from '../../lib/api'
import { notifyError, useBootstrap, useCatalog } from '../../lib/hooks'
import { useDraft } from '../../lib/draft'
import { Btn, DateInput, Dialog, Field, IconButton, NumberInput, SegmentedFilter, SelectField, SkBox, TextInput } from '../../components/ui'
import { CategoryIcon } from '../../components/icons'
import InventoryBadge from '../../components/InventoryBadge'
import ItemDialog from '../../components/ItemDialog'
import ItemPicker from '../../components/ItemPicker'
import { PRABA_REF, atRefOf, ceil3, grams3, neededOf, qty } from '../../lib/format'

export function DraftNote({ show }) {
  if (!show) return null
  return <div className="text-[12px] text-muted">Restored what you entered last time. Cancel clears it.</div>
}

function notOnStockOrder(l) {
  return !(l.stock_order && l.stock_order.id) || Number(l.unordered) > 0
}

export function orderableLines(lines) {
  return (lines || []).filter((l) => l && Number(l.id) > 0 && (l.state === undefined || l.state === 'open' || l.state === 'waiting' || l.state === 'partially_available') && Number(l.missing) > 0 && notOnStockOrder(l))
}

function lineName(l) {
  return String(l?.metal ?? l?.name ?? l?.item_name ?? '')
}

function lineNeed(l) {
  return Math.max(0, Number(Array.isArray(l?.parts) ? l.unordered : l?.missing) || 0)
}

function sameOrder(a, b) {
  return !!(a && b && Number(a.id) > 0 && Number(a.id) === Number(b.id))
}

function metalKey(categoryId, metal) {
  return metal ? `${Number(categoryId) || 0}|${String(metal).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim().toLowerCase()}` : ''
}

function numOf(v) {
  if (v === null || v === undefined || String(v).trim() === '') return null
  const n = Number(String(v).replace(',', '.'))
  return Number.isFinite(n) ? n : NaN
}

let rowSeq = 0

function newKey() {
  rowSeq += 1
  return `r${Date.now()}-${rowSeq}`
}

function metalItemsOf(l) {
  return Array.isArray(l?.metal_items) ? l.metal_items : []
}

function rowFromLine(l, order) {
  const o = l.order && Number(l.order.id) > 0 ? l.order : order && Number(order.id) > 0 ? order : null
  const metal = l.metal || null
  const items = metalItemsOf(l)
  return {
    key: newKey(),
    demandId: Number(l.id) || null,
    itemId: metal ? Number(items[0]?.id) || 0 : Number(l.item_id) || 0,
    categoryId: Number(l.category_id) || 0,
    name: lineName(l),
    unit: l.unit || '',
    metal,
    metalItems: items,
    unitNote: l.metal_unit_note || '',
    orderId: o ? Number(o.id) : null,
    orderNumber: o ? String(o.number || o.id) : '',
    need: lineNeed(l),
    qty: null,
  }
}

function rowFromItem(it) {
  return {
    key: newKey(),
    demandId: null,
    itemId: Number(it.id) || 0,
    categoryId: Number(it.category_id) || 0,
    name: String(it.name || ''),
    unit: it.unit || '',
    metal: null,
    metalItems: [],
    orderId: null,
    orderNumber: '',
    need: 0,
    qty: null,
  }
}

function validRow(r) {
  return r && typeof r === 'object' && typeof r.key === 'string' && (Number(r.itemId) > 0 || (!!r.metal && Number(r.demandId) > 0))
}

function plan(rows, needOf) {
  const state = new Map()
  const views = rows.map((r) => {
    const metal = !!r.metal
    const item = metal ? r.metalItems.find((m) => Number(m.id) === Number(r.itemId)) || null : null
    const p = item ? Number(item.praba) || PRABA_REF : PRABA_REF
    const noItem = metal && !item
    const typed = r.qty === null || r.qty === undefined ? null : String(r.qty)
    let suggestion = null
    let help = ''
    let s = null
    if (r.demandId) {
      s = state.get(r.demandId) || { need: needOf(r), covered: 0, number: r.orderNumber, unit: r.unit, metal, name: r.name }
      const left = Math.max(0, ceil3(Math.round((s.need - s.covered) * 1e4) / 1e4))
      if (left > 0 && !noItem) {
        suggestion = metal ? neededOf(left, p) : left
        if (metal && typed === null) help = p === PRABA_REF ? `Order #${s.number} needs ${grams3(left, r.unit)} at ${PRABA_REF}` : `Order #${s.number} needs ${grams3(left, r.unit)} at ${PRABA_REF}, which is ${grams3(suggestion, r.unit)} of ${item.name}`
      }
    }
    const amount = typed === null ? suggestion : numOf(typed)
    const amountOk = !noItem && amount !== null && !Number.isNaN(amount) && amount > 0
    if (s) {
      if (amountOk) s.covered += metal ? atRefOf(amount, p) : amount
      state.set(r.demandId, s)
    }
    return {
      metal,
      item,
      noItem,
      amount,
      amountOk,
      display: typed !== null ? typed : suggestion !== null ? String(suggestion) : '',
      help,
      error: noItem || amountOk ? '' : 'Enter an amount above 0.',
      ok: amountOk,
    }
  })
  const still = []
  const covered = new Set()
  for (const [id, s] of state.entries()) {
    const left = Math.max(0, ceil3(Math.round((s.need - s.covered) * 1e4) / 1e4))
    if (left <= 0) covered.add(id)
    if (left > 0 && left < s.need) still.push(s.metal ? `Order #${s.number} still needs ${grams3(left, s.unit)} of ${s.name} at ${PRABA_REF}` : `Order #${s.number} still needs ${qty(left, s.unit)} of ${s.name}`)
  }
  return { views, still, covered }
}

function LineView({ line, order }) {
  const o = line.order ? (Number(line.order.id) > 0 ? line.order : null) : order
  return (
    <span className="flex min-w-0 items-start gap-2">
      <span className="w-14 shrink-0 pt-[3px] text-[11.5px] tabular-nums text-muted">{o && o.number ? `#${o.number}` : 'No order'}</span>
      <InventoryBadge line={line} />
    </span>
  )
}

function squash(v) {
  return String(v ?? '').toLowerCase().replace(/\s+/g, ' ').trim()
}

function namesItem(term, name) {
  const t = squash(term)
  return /\d/.test(t) && squash(name).includes(t)
}

function metalMatches(term, l) {
  if (!l?.metal) return false
  const t = squash(term)
  return t.startsWith(squash(l.metal)) || metalItemsOf(l).some((m) => squash(m.name).includes(t))
}

function matches(term, values) {
  return values.some((v) => v !== undefined && v !== null && String(v).toLowerCase().includes(term))
}

function RowPicker({ lines, missingItemIds, missingMetals, hiddenItemIds, order, loading, onAdd }) {
  const { catalog, categoryById } = useCatalog()
  const [pick, setPick] = useState(null)
  const extraSections = useCallback((text, view) => {
    if (view?.fieldFilters) return []
    const term = text.toLowerCase()
    const inCat = (o) => !view?.categoryId || Number(o.category_id) === Number(view.categoryId)
    const lineEntry = (o) => ({ kind: 'line', id: `d${o.id}`, label: lineName(o), value: o, render: () => <LineView line={o} order={order} /> })
    const mine = order ? lines.filter((o) => inCat(o) && sameOrder(o.order, order) && (!term || matches(term, [lineName(o), o.code]) || metalMatches(term, o))) : []
    const others = term || view?.categoryId ? lines.filter((o) => inCat(o) && !(order && sameOrder(o.order, order)) && (matches(term, [lineName(o), o.code, o.order?.number, o.order?.customer]) || metalMatches(term, o))) : []
    const rank = new Map((catalog.categories || []).map((c, i) => [Number(c.id), i]))
    const rest = new Map()
    for (const o of others) {
      const cid = Number(o.category_id) || 0
      if (!rest.has(cid)) rest.set(cid, [])
      rest.get(cid).push(o)
    }
    const groups = [...rest.entries()]
      .sort((a, b) => (rank.has(a[0]) ? rank.get(a[0]) : 9999) - (rank.has(b[0]) ? rank.get(b[0]) : 9999))
      .map(([cid, list]) => ({ key: `c${cid}`, categoryId: cid || null, title: categoryById[cid]?.name || 'Other', entries: list.map(lineEntry) }))
    return mine.length ? [{ key: 'mine', categoryId: null, title: 'From this order', entries: mine.map(lineEntry) }, ...groups] : groups
  }, [lines, order, catalog.categories, categoryById])
  const hideItem = useCallback((it, cid, term) => {
    const id = Number(it.id)
    return missingItemIds.has(id) || hiddenItemIds.has(id) || (!!it.metal && missingMetals.has(metalKey(cid, it.metal)) && !namesItem(term, it.name))
  }, [missingItemIds, missingMetals, hiddenItemIds])
  const add = () => {
    if (!pick) return
    onAdd(pick)
    setPick(null)
  }
  const empty = (text, filtering) => (text ? `Nothing matches “${text}”.` : filtering ? "Nothing matches these filters." : order ? 'Nothing else on this order is missing. Type to find missing items of other orders or any item.' : 'Type to find missing items of orders or any item.')
  return (
    <div className="flex gap-2">
      <ItemPicker
        className="min-w-0 flex-1"
        label="Search missing items or any item"
        placeholder="Search missing items or any item"
        value={pick ? { id: pick.id, name: pick.label } : null}
        onChange={(it) => setPick(it ? { kind: 'item', id: String(it.id), label: String(it.name || ''), value: it } : null)}
        onPickExtra={setPick}
        extraSections={extraSections}
        hideItem={hideItem}
        itemsTitle="Other items, for stock"
        itemsOnType
        openOnFocus
        clearOnType
        allowCreate={false}
        loading={loading}
        emptyText={empty}
      />
      <Btn variant="secondary" icon={Plus} isDisabled={!pick} onPress={add}>Add item</Btn>
    </div>
  )
}

function RowView({ row, view, onChange, onRemove, onCreateItem }) {
  return (
    <li className="flex flex-col gap-1 py-2">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <span className="flex min-w-0 flex-1 basis-48 items-center gap-2">
          <span className="w-14 shrink-0 text-[11.5px] tabular-nums text-muted">{row.orderNumber ? `#${row.orderNumber}` : 'For stock'}</span>
          <CategoryIcon categoryId={row.categoryId} className="size-4 shrink-0 text-muted" />
          {view.metal && !view.noItem ? (
            <SelectField size="sm" className="min-w-0 flex-1" value={String(view.item.id)} onChange={(v) => onChange({ itemId: Number(v) })} options={row.metalItems.map((m) => ({ value: String(m.id), label: m.name }))} ariaLabel={`Item of ${row.name}`} />
          ) : (
            <span className="min-w-0 truncate text-[13px] font-medium text-foreground" title={row.name}>{row.name}</span>
          )}
        </span>
        <span className="flex items-center gap-2">
          {view.noItem ? (
            row.unitNote ? null : <Btn size="sm" variant="secondary" icon={Plus} onPress={onCreateItem}>Create item</Btn>
          ) : (
            <span className="flex items-center gap-1.5">
              <NumberInput size="sm" className="w-24" min="0" value={view.display} invalid={!view.amountOk} onChange={(v) => onChange({ qty: String(v).trim() === '' ? null : v })} aria-label={`Amount of ${view.item ? view.item.name : row.name}`} />
              <span className="w-6 text-[12px] text-muted">{row.unit}</span>
            </span>
          )}
          <IconButton icon={Xmark} label={`Remove ${row.name}`} variant="tertiary" onPress={onRemove} />
        </span>
      </div>
      {view.noItem && <div className="text-[12px] text-muted">{row.unitNote || `There is no ${row.name} item yet. Create one with its praba to order it.`}</div>}
      {view.help && <div className="text-[12px] tabular-nums text-muted">{view.help}</div>}
      {view.error && <div className="text-[12px] text-danger">{view.error}</div>}
    </li>
  )
}

export default function StockOrderDialog({ lines = [], order = null, draftKey, title = 'Add to a stock order', isOpen, onClose, onDone }) {
  const qc = useQueryClient()
  const boot = useBootstrap()
  const draft = useDraft(draftKey)
  const [initial] = useState(() => draft.read())
  const open = useQuery({ queryKey: ['stock-orders', 'open-picker'], queryFn: () => get('stock-orders', { open: 'yes', per_page: 50 }), enabled: isOpen, staleTime: 0 })
  const missing = useQuery({ queryKey: ['required', '', 'no', ''], queryFn: () => get('required', { category_id: '', ordered: 'no', search: '' }), enabled: isOpen })
  const available = useMemo(
    () => (missing.data?.groups || []).flatMap((g) => (g.demands || []).filter(notOnStockOrder).map((d) => ({ ...d, item_id: g.item_id, category_id: g.category_id, name: g.name, code: g.code, unit: g.unit, metal: g.metal, metal_items: g.metal_items, metal_unit_note: g.metal_unit_note }))),
    [missing.data],
  )
  const [rows, setRows] = useState(() => {
    const saved = Array.isArray(initial?.rows) ? initial.rows.filter(validRow).map((r) => ({ ...r, metal: r.metal || null, metalItems: Array.isArray(r.metalItems) ? r.metalItems : [] })) : []
    return saved.length ? saved : lines.filter((l) => l && Number(l.id) > 0).map((l) => rowFromLine(l, order))
  })
  const [creating, setCreating] = useState(null)
  const [mode, setMode] = useState(initial?.mode === 'existing' ? 'existing' : 'new')
  const [target, setTarget] = useState(typeof initial?.target === 'string' ? initial.target : '')
  const [form, setForm] = useState(() => ({ number: '', order_date: boot.data?.today || '', supplier: '', ...(initial?.form && typeof initial.form === 'object' ? initial.form : {}) }))
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  const list = open.data?.stock_orders || []
  const given = useMemo(() => new Set(lines.map((l) => Number(l.id))), [lines])
  const liveById = useMemo(() => new Map(available.map((a) => [Number(a.id), a])), [available])
  const shown = rows.map((r) => {
    const live = r.demandId ? liveById.get(r.demandId) : null
    const base = r.demandId && missing.data && !live && !given.has(r.demandId) ? { ...r, demandId: null, orderId: null, orderNumber: '' } : r
    const metal = base.metal || live?.metal || null
    const metalItems = live && Array.isArray(live.metal_items) ? live.metal_items : base.metalItems || []
    const unitNote = live ? live.metal_unit_note || '' : base.unitNote || ''
    if (!metal) return base
    const known = metalItems.some((m) => Number(m.id) === Number(base.itemId))
    return { ...base, metal, metalItems, unitNote, itemId: known ? base.itemId : Number(metalItems[0]?.id) || 0, name: metal }
  })
  const needOf = (r) => (liveById.has(r.demandId) ? lineNeed(liveById.get(r.demandId)) : Number(r.need) || 0)
  const { views, still, covered } = plan(shown, needOf)
  const usedLines = new Set([...shown.filter((r) => r.demandId && !r.metal).map((r) => r.demandId), ...covered])
  const options = available.filter((a) => !usedLines.has(Number(a.id)))
  const missingItemIds = useMemo(() => new Set(available.filter((a) => !a.metal).map((a) => Number(a.item_id))), [available])
  const missingMetals = useMemo(() => new Set(available.filter((a) => a.metal).map((a) => metalKey(a.category_id, a.metal))), [available])
  const hiddenItemIds = new Set(shown.filter((r) => !r.demandId && !r.metal).map((r) => r.itemId))
  const sameKeys = shown.filter((r, i) => !views[i].noItem).map((r) => String(r.itemId))
  const merged = new Set(sameKeys).size < sameKeys.length
  const today = boot.data?.today || ''
  useEffect(() => {
    if (today) setForm((f) => (f.order_date ? f : { ...f, order_date: today }))
  }, [today])
  useEffect(() => {
    if (dirty) draft.save({ mode, target, form, rows })
  }, [mode, target, form, rows, dirty, draft])
  const touch = (fn) => (v) => {
    setDirty(true)
    fn(v)
  }
  const setF = (k) => touch((v) => setForm((f) => ({ ...f, [k]: v })))
  const addRow = touch((e) => setRows((cur) => [...cur, e.kind === 'line' ? rowFromLine(e.value, order) : rowFromItem(e.value)]))
  const changeRow = (key) => touch((patch) => setRows((cur) => cur.map((r) => (r.key === key ? { ...r, ...patch } : r))))
  const removeRow = (key) => touch(() => setRows((cur) => cur.filter((r) => r.key !== key)))
  const itemCreated = (row) => (it) => {
    if (!it || !it.id) return
    const entry = { id: Number(it.id), name: it.name, praba: it.praba ?? null, unit: it.unit || row.unit, free: Number(it.free) || 0 }
    if (row.unit && entry.unit !== row.unit) {
      qc.invalidateQueries({ queryKey: ['required'] })
      return
    }
    const k = metalKey(row.categoryId, row.metal)
    setDirty(true)
    setRows((cur) => cur.map((r) => (metalKey(r.categoryId, r.metal) === k ? { ...r, metal: row.metal, metalItems: [...(r.metalItems || []).filter((m) => Number(m.id) !== entry.id), entry].sort((a, b) => (Number(b.praba) || 0) - (Number(a.praba) || 0)), itemId: Number(r.itemId) > 0 && r.metalItems.some((m) => Number(m.id) === Number(r.itemId)) ? r.itemId : entry.id } : r)))
    qc.invalidateQueries({ queryKey: ['required'] })
  }
  const valid = shown.length > 0 && views.every((v) => v.ok) && (mode === 'new' ? !!form.order_date : !!target)
  const cancel = () => {
    draft.clear()
    onClose()
  }
  const submit = async () => {
    if (!valid) return
    setSaving(true)
    try {
      const payload = shown.map((r, i) => ({ demand_id: r.demandId || 0, item_id: views[i].item ? views[i].item.id : r.itemId, qty: views[i].amount }))
      const body = mode === 'existing' ? { rows: payload, stock_order_id: Number(target) } : { rows: payload, ...form }
      const so = await post('stock-orders/from-demands', body)
      toast.success(mode === 'existing' ? `Added to stock order #${so.number}` : `Stock order #${so.number} created`)
      const skipped = Array.isArray(so?.skipped) ? so.skipped : []
      if (skipped.length) toast.warning(skipped.length === 1 ? '1 line was not linked to its order' : `${skipped.length} lines were not linked to their orders`, { description: skipped.map((k) => `${k.order_number ? `#${k.order_number} ` : ''}${k.name ? `${k.name}: ` : ''}${k.reason}`).join(' ') })
      draft.clear()
      qc.invalidateQueries({ queryKey: ['required'] })
      qc.invalidateQueries({ queryKey: ['stock-orders'] })
      if (onDone) onDone(so)
      onClose()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Dialog
      isOpen={isOpen}
      onClose={onClose}
      isDismissable={false}
      size="md"
      title={title}
      footer={
        <>
          <Button variant="tertiary" onPress={cancel}>Cancel</Button>
          <Btn variant="primary" isPending={saving} isDisabled={!valid} onPress={submit}>{mode === 'existing' ? 'Add to stock order' : 'Create stock order'}</Btn>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <DraftNote show={!!initial && !dirty} />
        <div className="flex min-w-0 flex-col gap-1.5">
          <span className="cjs-field-label text-[12px] font-medium text-foreground/80">Items</span>
          <div className="flex flex-col gap-2">
            {shown.length > 0 ? (
              <ul className="flex flex-col divide-y divide-separator">
                {shown.map((r, i) => (
                  <RowView key={r.key} row={r} view={views[i]} onChange={changeRow(r.key)} onRemove={removeRow(r.key)} onCreateItem={() => setCreating(r)} />
                ))}
              </ul>
            ) : (
              <div className="text-[12.5px] text-muted">Add at least one item.</div>
            )}
            {still.length > 0 && (
              <div className="flex flex-col gap-0.5">
                {still.map((t) => (
                  <div key={t} className="text-[12.5px] tabular-nums text-muted">{t}</div>
                ))}
              </div>
            )}
            {merged && <div className="text-[12px] text-muted">Quantities of the same item are added together.</div>}
            <RowPicker lines={options} missingItemIds={missingItemIds} missingMetals={missingMetals} hiddenItemIds={hiddenItemIds} order={order} loading={!missing.data} onAdd={addRow} />
          </div>
        </div>
        <SegmentedFilter value={mode} onChange={touch(setMode)} items={[{ value: 'new', label: 'New stock order' }, { value: 'existing', label: 'Existing stock order' }]} />
        {mode === 'existing' ? (
          <Field label="Stock order" required hint="Only stock orders that are not fully received">
            {open.isLoading ? (
              <SkBox w="100%" h={32} />
            ) : (
              <SelectField value={target} onChange={touch(setTarget)} options={list.map((s) => ({ value: String(s.id), label: `#${s.number} · ${s.order_date}${s.supplier ? ` · ${s.supplier}` : ''}` }))} placeholder="Choose a stock order" ariaLabel="Stock order" />
            )}
          </Field>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Number" hint="Leave empty for the next number">
              <TextInput value={form.number} onChange={setF('number')} />
            </Field>
            <Field label="Order date" required>
              <DateInput value={form.order_date} onChange={setF('order_date')} required />
            </Field>
            <Field label="Supplier" hint="Optional" className="sm:col-span-2">
              <TextInput value={form.supplier} onChange={setF('supplier')} />
            </Field>
          </div>
        )}
      </div>
      {creating && <ItemDialog isOpen categoryId={creating.categoryId} initialName={creating.metal || ''} onClose={() => setCreating(null)} onSaved={itemCreated(creating)} />}
    </Dialog>
  )
}
