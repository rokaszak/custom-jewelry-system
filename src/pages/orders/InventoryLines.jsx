import { lazy, Suspense, useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Button, Dropdown, Label, ListBox, Select } from '@heroui/react'
import { toast } from '../../lib/toast'
import { Comment, CommentSlash, Lock, Plus, EllipsisVertical, Box, ArrowUturnCcwLeft, TrashBin, Pencil, ShoppingCart, ArrowsRotateRight, CircleExclamation, ArrowUpRightFromSquare, PaperPlane } from '@gravity-ui/icons'
import { get, post, patch, del } from '../../lib/api'
import { notifyError, useCatalog } from '../../lib/hooks'
import { PRABA_REF, ceil3, date, defaultFineness, grams3, qty } from '../../lib/format'
import { navigate } from '../../lib/nav'
import { useDraft } from '../../lib/draft'
import { Btn, Dialog, Expand, Field, NoBubble, Notice, NumberInput, useConfirm, Empty, SelectField, SkBox, cx } from '../../components/ui'
import InventoryBadge, { ClickChip, ItemChip, PieceChips } from '../../components/InventoryBadge'
import ItemPicker from '../../components/ItemPicker'
import { useAskRemoveReturned, useStatuses } from '../../components/StockModal'
import { CommentDialog, CommentText, saveDemandNote } from '../../components/LineComment'
import { ContextNote } from '../../components/ItemDialog'
import StockOrderDialog, { DraftNote } from './StockOrderDialog'

function fallbackModal(page, param) {
  return function FallbackModal({ itemId, id, isOpen, onClose }) {
    useEffect(() => {
      if (!isOpen) return
      onClose && onClose()
      navigate(page, { [param]: itemId || id })
    }, [isOpen])
    return null
  }
}

const ItemDetailModal = lazy(() => import('../inventory/ItemDetail').then((m) => ({ default: m.ItemDetailModal || fallbackModal('inventory', 'item') })))
const StockOrderModal = lazy(() => import('../stock-orders/StockOrderDetail').then((m) => ({ default: m.StockOrderModal || fallbackModal('stock-orders', 'so') })))

function addHint(item, amount, auto) {
  const need = Number(amount) || 0
  const whole = !!item.metal && item.metal_free !== undefined && item.metal_free !== null
  const free = Number(whole ? item.metal_free : item.free) || 0
  const shelf = whole ? `${grams3(free, item.unit)} at ${PRABA_REF}` : qty(free, item.unit)
  if (!auto) return 'Stock for this category is assigned by hand. The line shows as Waiting availability until you assign it.'
  if (free <= 0) return 'Nothing available on the shelf. The line shows as Waiting availability until you receive stock or add it to a stock order.'
  if (need > free) return `Only ${shelf} available on the shelf. That is reserved for this order now and the line shows as Partially available.`
  return `${shelf} available on the shelf. It is reserved for this order right away.`
}

function PiecesField({ itemId, lineId, count, onCount, value, onChange, onInit, auto }) {
  const q = useQuery({ queryKey: ['piece-options', itemId], queryFn: () => get(`items/${itemId}/piece-options`), enabled: !!itemId, staleTime: 0 })
  const pieces = q.data?.pieces || []
  const byId = new Map(pieces.map((p) => [p.id, p]))
  const own = lineId ? pieces.filter((p) => p.demand_id === lineId && (p.status === 'reserved' || p.status === 'out')) : []
  const free = (q.data?.free_ids || []).map((id) => byId.get(id)).filter(Boolean)
  const list = [...own, ...free]
  const locked = new Set(own.filter((p) => p.status === 'out').map((p) => p.id))
  const want = Math.max(0, Math.floor(Number(count) || 0))
  const ready = useRef(null)
  const lastCount = useRef(want)
  useEffect(() => {
    if (!q.data || ready.current === itemId) return
    ready.current = itemId
    lastCount.current = want
    const init = onInit || onChange
    init(lineId ? own.map((p) => p.id) : free.slice(0, want).map((p) => p.id))
  }, [q.data, itemId])
  useEffect(() => {
    if (!value || lastCount.current === want) return
    lastCount.current = want
    const next = [...value]
    while (next.length > want) {
      const i = [...next].reverse().findIndex((id) => !locked.has(id))
      if (i < 0) break
      next.splice(next.length - 1 - i, 1)
    }
    for (const p of free) {
      if (next.length >= want) break
      if (!next.includes(p.id)) next.push(p.id)
    }
    if (next.length !== value.length || next.some((id, i) => id !== value[i])) onChange(next)
  }, [want])
  if (q.isLoading || !value) return <SkBox w="100%" h={36} />
  const name = (p) => p.identifier || `Piece #${p.id}`
  const pick = (keys) => {
    const ids = Array.from(keys).map(Number)
    const kept = value.filter((id) => ids.includes(id) || locked.has(id))
    const added = ids.filter((id) => !kept.includes(id))
    const next = [...kept, ...added]
    if (next.length > want && onCount) {
      lastCount.current = next.length
      onCount(String(next.length))
    }
    onChange(next)
  }
  const left = want - value.length
  const freeLeft = free.filter((p) => !value.includes(p.id)).length
  const hint = left <= 0 ? null : !auto ? `${left} not picked: the line waits until you assign one.` : freeLeft > 0 ? `${left} not picked: the oldest free piece is used.` : `${left} not picked: none on the shelf, the line waits for stock.`
  return (
    <div className="flex flex-col gap-1.5">
      <Select className="cjs-select min-w-0" selectionMode="multiple" value={value.map(String)} onChange={pick} isDisabled={!list.length} aria-label="Pieces" placeholder={list.length ? 'Pick pieces' : 'None on the shelf'}>
        <Select.Trigger>
          <Select.Value>
            {({ isPlaceholder }) => (isPlaceholder || !value.length ? <span className="text-muted">{list.length ? 'Pick pieces' : 'None on the shelf'}</span> : (
              <span className="flex flex-wrap items-center gap-1">
                {value.map((id) => byId.get(id)).filter(Boolean).map((p) => (
                  <span key={p.id} className="bg-surface-secondary px-1.5 py-px text-[11.5px] font-medium text-foreground">{name(p)}{p.status === 'out' ? ' · sent' : ''}</span>
                ))}
              </span>
            ))}
          </Select.Value>
          <Select.Indicator />
        </Select.Trigger>
        <Select.Popover>
          <ListBox>
            {list.map((p) => (
              <ListBox.Item key={p.id} id={String(p.id)} textValue={name(p)} isDisabled={locked.has(p.id)}>
                <span className="min-w-0 flex-1 text-[13px]">
                  <span className="font-medium">{name(p)}</span>
                  {p.note && <span className="text-muted"> · {p.note}</span>}
                  {p.status === 'out' && <span className="text-muted"> · sent, mark it as returned before removing it</span>}
                </span>
                <ListBox.ItemIndicator />
              </ListBox.Item>
            ))}
          </ListBox>
        </Select.Popover>
      </Select>
      {hint && <div className="text-[12.5px] text-muted">{hint}</div>}
    </div>
  )
}

function AddLineDialog({ orderId, orderItemId, productName, context, isOpen, onClose, onDone }) {
  const { categoryById } = useCatalog()
  const draft = useDraft(`line:new:${orderId}:${orderItemId || 0}`)
  const [initial] = useState(() => draft.read())
  const [item, setItem] = useState(initial?.item || null)
  const [amount, setAmount] = useState(initial?.amount || '1')
  const [praba, setPraba] = useState(initial?.praba || '')
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    if (dirty) draft.save({ item, amount, praba })
  }, [item, amount, praba, dirty, draft])
  const metalRow = !!item?.metalRow || (!!item?.metal && item?.praba !== null && item?.praba !== undefined)
  const prabaLocked = metalRow && !item.metalRow
  const prabaVal = prabaLocked ? String(item.praba) : praba
  const pieceItem = !!item && !metalRow && !!item.per_piece
  const [pieceIds, setPieceIds] = useState(null)
  const [pieceItemId, setPieceItemId] = useState(null)
  const piecesNow = pieceItemId === item?.id ? pieceIds : null
  const prabaOk = Number(prabaVal) > 0 && Number(prabaVal) <= 1000
  const valid = !!item && Number(amount) > 0 && (!pieceItem || Number.isInteger(Number(amount))) && (!metalRow || prabaOk)
  const atRef = metalRow && prabaOk ? ceil3((Number(amount) || 0) * Number(prabaVal) / PRABA_REF) : 0
  const itemFineness = item ? defaultFineness(null, categoryById[item.category_id]) : null
  const cancel = () => {
    draft.clear()
    onClose()
  }
  const submit = async () => {
    if (!valid) return
    setSaving(true)
    try {
      await post(`orders/${orderId}/demands`, metalRow ? { category_id: item.category_id, metal: item.metal, praba: prabaVal, qty: amount, order_item_id: orderItemId || 0 } : pieceItem ? { item_id: item.id, qty: amount, order_item_id: orderItemId || 0, piece_ids: piecesNow || [] } : { item_id: item.id, qty: amount, order_item_id: orderItemId || 0 })
      draft.clear()
      onDone()
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
      title="Add inventory"
      description={productName ? `For ${productName}` : 'For the whole order'}
      footer={
        <>
          <Button variant="tertiary" onPress={cancel}>Cancel</Button>
          <Btn variant="primary" isPending={saving} isDisabled={!valid} onPress={submit}>Add line</Btn>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <ContextNote context={context} />
        <DraftNote show={!!initial && !dirty} />
        <Field label="Item" required>
          <ItemPicker value={item} onChange={(v) => { setDirty(true); setItem(v) }} autoFocus context={context} metalRows flip={false} />
        </Field>
        {pieceItem ? (
          <>
            <Field label={`Quantity (${item.unit})`} required>
              <NumberInput value={amount} onChange={(v) => { setDirty(true); setAmount(v) }} min="1" step="1" className="max-w-45" />
            </Field>
            <Field label="Pieces">
              <PiecesField itemId={item.id} count={amount} onCount={(v) => { setDirty(true); setAmount(v) }} value={piecesNow} onChange={(v) => { setPieceIds(v); setPieceItemId(item.id) }} auto={categoryById[item.category_id]?.auto_assign !== false} />
            </Field>
          </>
        ) : metalRow ? (
          <div className="flex flex-wrap items-start gap-3">
            <Field label="Praba" required>
              <NumberInput value={prabaVal} onChange={(v) => { setDirty(true); setPraba(v) }} disabled={prabaLocked} min="1" max="1000" step="any" placeholder="585" className="max-w-30" />
            </Field>
            <Field label={`Weight (${item.unit})`} required>
              <NumberInput value={amount} onChange={(v) => { setDirty(true); setAmount(v) }} min="0" step="any" className="max-w-45" />
            </Field>
          </div>
        ) : (
          <Field label={`Quantity${item ? ` (${item.unit}${itemFineness ? ` at ${qty(itemFineness)}` : ''})` : ''}`} required>
            <NumberInput value={amount} onChange={(v) => { setDirty(true); setAmount(v) }} min="0" step={item?.per_piece ? '1' : 'any'} className="max-w-45" />
          </Field>
        )}
        <Expand open={!!item && !pieceItem}>
          <div className="bg-surface-secondary px-3 py-2 text-[12.5px] text-muted">
            {metalRow && prabaOk && Number(amount) > 0 ? `${grams3(amount, item.unit)} at ${qty(Number(prabaVal))} needs ${grams3(atRef, item.unit)} at ${PRABA_REF}. ` : ''}
            {item && !pieceItem ? (metalRow && !prabaOk ? 'Enter the final praba and weight.' : addHint(item, metalRow ? atRef : amount, categoryById[item.category_id]?.auto_assign !== false)) : ' '}
          </div>
        </Expand>
      </div>
    </Dialog>
  )
}

function EditLineDialog({ line, context, isOpen, onClose, onDone }) {
  const { categoryById } = useCatalog()
  const fineness = defaultFineness(line, categoryById[line.category_id])
  const draft = useDraft(`line:${line.id}`)
  const [initial] = useState(() => draft.read())
  const [amount, setAmount] = useState(initial?.amount ?? String(line.qty))
  const [item, setItem] = useState(initial?.item || { id: line.item_id, name: line.item_name })
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    if (dirty) draft.save({ item, amount })
  }, [item, amount, dirty, draft])
  const changedItem = item && item.id !== line.item_id
  const pieceMode = changedItem ? !!item?.per_piece && !item?.metalRow : !!line.per_piece
  const [pieceIds, setPieceIds] = useState(null)
  const [pieceFor, setPieceFor] = useState(null)
  const piecesNow = pieceFor === item?.id ? pieceIds : null
  const valid = !!item && Number(amount) > 0 && (!pieceMode || Number.isInteger(Number(amount)))
  const cancel = () => {
    draft.clear()
    onClose()
  }
  const submit = async () => {
    if (!valid) return
    setSaving(true)
    try {
      let target = line.id
      if (changedItem) {
        const res = await patch(`demands/${line.id}`, { qty: amount, item_id: item.id })
        target = res?.demand_id || line.id
      } else if (!pieceMode) {
        await patch(`demands/${line.id}`, { qty: amount })
      }
      if (pieceMode) await post(`demands/${target}/pieces`, { qty: amount, piece_ids: piecesNow || [] })
      draft.clear()
      onDone()
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
      title="Edit inventory line"
      description={pieceMode ? 'Tick which pieces this order gets. Unticked pieces go back on the shelf.' : 'Fewer pieces: the extra goes back on the shelf. More pieces: they are taken from stock if available, the line shows as Partially available until the rest arrives.'}
      footer={
        <>
          <Button variant="tertiary" onPress={cancel}>Cancel</Button>
          <Btn variant="primary" isPending={saving} isDisabled={!valid} onPress={submit}>Save</Btn>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <ContextNote context={context} />
        <DraftNote show={!!initial && !dirty} />
        <Field label="Item" required hint={changedItem ? 'Stock of the old item goes back on the shelf and the new item is taken instead.' : undefined}>
          <ItemPicker value={item} onChange={(v) => { setDirty(true); setItem(v) }} context={context} flip={false} />
        </Field>
        <Field label={`Quantity (${line.unit}${fineness ? ` at ${qty(fineness)}` : ''})`} required>
          <NumberInput value={amount} onChange={(v) => { setDirty(true); setAmount(v) }} min="0" step={pieceMode ? '1' : 'any'} className="max-w-45" />
        </Field>
        {pieceMode && item && (
          <Field label="Pieces">
            <PiecesField key={item.id} itemId={item.id} lineId={changedItem ? null : line.id} count={amount} onCount={(v) => { setDirty(true); setAmount(v) }} value={piecesNow} onChange={(v) => { setDirty(true); setPieceIds(v); setPieceFor(item.id) }} onInit={(v) => { setPieceIds(v); setPieceFor(item.id) }} auto={categoryById[item.category_id ?? line.category_id]?.auto_assign !== false} />
          </Field>
        )}
      </div>
    </Dialog>
  )
}

function AssignDialog({ line, isOpen, onClose, onDone, replace }) {
  const q = useQuery({ queryKey: ['assign-options', line.id], queryFn: () => get(`demands/${line.id}/options`), enabled: isOpen, staleTime: 0 })
  const [choice, setChoice] = useState('')
  const [saving, setSaving] = useState(false)
  const submit = async () => {
    setSaving(true)
    try {
      const body = q.data?.per_piece ? { piece_id: Number(choice) } : { batch_id: Number(choice) }
      if (replace) body.replace_allocation_id = replace.id
      await post(`demands/${line.id}/assign`, body)
      onDone()
      onClose()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  const options = q.data?.per_piece
    ? (q.data.pieces || []).map((p) => ({ value: String(p.id), label: `${p.identifier || `Piece #${p.id}`}${p.note ? ` · ${p.note}` : ''}` }))
    : (q.data?.batches || []).map((b) => ({ value: String(b.id), label: `${qty(b.free, line.unit)} available · received ${String(b.received_at).slice(0, 10)}` }))
  const perPiece = q.data ? q.data.per_piece : line.per_piece
  return (
    <Dialog
      isOpen={isOpen}
      onClose={onClose}
      size="sm"
      title={replace ? 'Swap piece' : 'Assign from stock'}
      description={line.item_name}
      footer={
        <>
          <Button variant="tertiary" onPress={onClose}>Cancel</Button>
          <Btn variant="primary" isPending={saving} isDisabled={!choice} onPress={submit}>{replace ? 'Swap' : 'Assign'}</Btn>
        </>
      }
    >
      {!q.isLoading && options.length === 0 ? (
        <Empty compact icon={Box} title="Nothing available on the shelf" description="Receive stock or add this line to a stock order." />
      ) : (
        <Field label={perPiece ? 'Piece' : 'Batch (oldest first)'} required>
          {q.isLoading ? <SkBox w="100%" h={32} /> : <SelectField value={choice} onChange={setChoice} options={options} ariaLabel="Stock" />}
        </Field>
      )}
    </Dialog>
  )
}

const FINE_STATES = new Set(['reserved', 'used', 'out'])

function atRefText(physical, covers, praba, unit) {
  const p = Number(praba) || PRABA_REF
  return p === PRABA_REF ? grams3(physical, unit) : `${grams3(physical, unit)} = ${grams3(covers, unit)} at ${PRABA_REF}`
}

export function breakdownRows(line, fineness) {
  const open = line.state === 'open'
  const parts = open && Array.isArray(line.parts) ? line.parts : []
  const rows = []
  if (fineness) {
    for (const a of (line.allocations || []).filter((x) => FINE_STATES.has(x.state))) {
      rows.push({ key: `a${a.id}`, tone: a.state === 'out' ? 'accent' : 'success', solid: a.state === 'used' || a.state === 'out', plain: (Number(a.fineness) || PRABA_REF) === PRABA_REF, status: a.item_name || line.item_name, amount: `${atRefText(a.qty, a.covers, a.fineness, line.unit)}${a.state === 'out' ? ' · Sent' : ''}`, open: { type: 'item', id: a.item_id || line.item_id }, alloc: a })
    }
  } else {
    const got = Math.max(0, Number(line.allocated) || 0)
    if (open && got > 0) rows.push({ key: 'got', tone: 'success', status: 'Reserved', amount: qty(got, line.unit), open: { type: 'item', id: line.item_id }, allocs: (line.allocations || []).filter((a) => a.can_unassign && a.state !== 'out') })
  }
  for (const p of parts) {
    rows.push({ key: `p${p.line_id}`, plain: (Number(p.fineness) || PRABA_REF) === PRABA_REF, tone: p.placed ? 'accent' : 'danger', status: `${p.placed ? 'Ordered' : 'Not ordered'} #${p.number}`, name: fineness ? p.item_name : '', amount: fineness ? atRefText(p.qty, p.at_ref, p.fineness, line.unit) : qty(p.qty, line.unit), open: { type: 'stock_order', id: p.stock_order_id }, part: p })
  }
  const missing = open ? Math.max(0, Number(Array.isArray(line.parts) ? line.unordered : line.missing) || 0) : 0
  if (missing > 0) rows.push({ key: 'missing', tone: 'danger', status: 'Missing', amount: fineness ? `${grams3(missing, line.unit)} at ${PRABA_REF}` : qty(missing, line.unit), open: { type: 'order' } })
  return rows
}

export function needsBreakdown(rows) {
  if (rows.length > 1) return true
  if (rows.length === 0) return false
  const r = rows[0]
  return r.key !== 'missing' && !(r.plain && (r.alloc || r.part))
}

export function Breakdown({ rows, categoryId, onOpenItem, onOpenStockOrder, onOrder }) {
  return (
    <ul className="ml-1.5 mt-1.5 flex flex-col items-start gap-1 border-l-2 border-separator pl-2.5">
      {rows.map((r) => {
        const click = r.open.type === 'item' && r.open.id ? () => onOpenItem(r.open.id) : r.open.type === 'stock_order' ? () => onOpenStockOrder(r.open.id) : r.open.type === 'order' ? onOrder : null
        const hint = r.open.type === 'item' ? 'Open item' : r.open.type === 'stock_order' ? 'Open stock order' : 'Add to a stock order'
        return (
          <li key={r.key} className="min-w-0 max-w-full">
            <ClickChip onClick={click} title={`${[r.status, r.name, r.amount].filter(Boolean).join(' · ')}. ${hint}`}>
              <ItemChip tone={r.tone} solid={r.solid} status={r.status} name={r.name} amount={r.amount} categoryId={categoryId} />
            </ClickChip>
          </li>
        )
      })}
    </ul>
  )
}

function LineRow({ line, demands, orderNumber, context, onChanged, onOpenItem, onOpenStockOrder }) {
  const { categoryById } = useCatalog()
  const fineness = defaultFineness(line, categoryById[line.category_id])
  const confirm = useConfirm()
  const askRemove = useAskRemoveReturned()
  const statuses = useStatuses()
  const lineCategory = categoryById[line.category_id]
  const lockKey = lineCategory ? (line.returnable ? lineCategory.back_by_status : lineCategory.used_by_status) : ''
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(false)
  const outAllocs = line.allocations.filter((a) => a.state === 'out')
  const sendAllocs = line.returnable && line.state === 'open' ? line.allocations.filter((a) => a.state === 'reserved') : []
  const pieces = line.allocations.filter((a) => a.identifier && a.state !== 'released')
  const unassignable = line.allocations.filter((a) => a.can_unassign && a.state !== 'out')
  const byHand = line.auto_assign === false
  const runAll = async (fns) => {
    setBusy(true)
    let ask = false
    try {
      for (const fn of fns) {
        const res = await fn()
        if (res && res.ask_remove) ask = true
      }
    } catch (e) {
      notifyError(e)
    } finally {
      setBusy(false)
      onChanged()
    }
    if (ask && (await askRemove(line.id, line.item_name))) onChanged()
  }
  const onAction = async (key) => {
    if (key === 'edit') setDialog('edit')
    else if (key === 'assign') setDialog('assign')
    else if (key === 'swap') setDialog('swap')
    else if (key === 'order') setDialog('order')
    else if (key === 'open-so') onOpenStockOrder(line.stock_order.id)
    else if (key === 'open-item') onOpenItem(line.item_id)
    else if (key === 'send') {
      await runAll(sendAllocs.map((a) => () => post(`allocations/${a.id}/send`)))
    } else if (key === 'return') {
      await runAll(outAllocs.map((a) => () => post(`allocations/${a.id}/return`)))
    } else if (key === 'unassign') {
      const ok = await confirm({ title: 'Unassign stock?', message: byHand ? 'The stock goes back to available stock. The line stays and shows as Waiting availability.' : 'The stock goes to the next line waiting for this item. This line keeps its place in the queue.', confirmLabel: 'Unassign' })
      if (ok) await runAll(unassignable.map((a) => () => post(`allocations/${a.id}/unassign`)))
    } else if (key === 'lost') {
      const names = outAllocs.map((a) => a.identifier).filter(Boolean).join(', ') || line.item_name
      const ok = await confirm({
        title: `Mark ${names} as lost?`,
        message: `Use this when the customer of #${orderNumber || line.order_id} will not send it back. It is written off: removed from stock for good and no longer listed anywhere. The order's line is removed too, so it does not show as missing and no other piece is assigned. If it turns up later, add it back with a stock count.`,
        confirmLabel: 'Mark as lost',
        tone: 'danger',
      })
      if (ok) await runAll(outAllocs.map((a) => () => post(`allocations/${a.id}/lost`)))
    } else if (key === 'remove') {
      const assigned = Number(line.allocated) > 0
      const linked = !!line.stock_order || (Array.isArray(line.parts) && line.parts.length > 0)
      const ok = !assigned && !linked ? true : await confirm({ title: 'Remove this line?', message: assigned ? `The ${qty(line.allocated, line.unit)} assigned to it goes back on the shelf. The change is logged.` : 'The stock order keeps its line. When that stock arrives, it goes to the next order waiting for it or into stock.', confirmLabel: 'Remove line', tone: 'danger' })
      if (ok) await runAll([() => del(`demands/${line.id}`)])
    } else if (key === 'unlink') {
      await runAll([() => post(`demands/${line.id}/unlink-stock-order`)])
    } else if (key === 'comment') {
      setDialog('comment')
    } else if (key === 'comment-remove') {
      await runAll([() => saveDemandNote(line.id, '')])
    } else if (key === 'remove-returned') {
      if (await askRemove(line.id, line.item_name)) onChanged()
    } else if (key.startsWith('unlink:')) {
      await runAll([() => post(`demands/${line.id}/unlink-stock-order`, { line_id: Number(key.slice(7)) })])
    }
  }
  const open = line.state === 'open'
  const rows = breakdownRows(line, fineness)
  const linkParts = Array.isArray(line.parts) ? line.parts : []
  const showRows = fineness ? needsBreakdown(rows) : rows.length > 1
  return (
    <li className="flex flex-wrap items-start gap-x-3 gap-y-1.5 py-2.5">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          {showRows ? (
            <InventoryBadge line={line} brief onOpenDetails={line.item_id > 0 ? () => onOpenItem(line.item_id) : undefined} />
          ) : (
            <InventoryBadge line={line} onOpenItem={onOpenItem} onOpenStockOrder={onOpenStockOrder} onOrderMissing={() => setDialog('order')} />
          )}
          <PieceChips pieces={pieces} />
          <CommentText text={line.note} />
        </div>
        {showRows && <Breakdown rows={rows} categoryId={line.category_id} onOpenItem={onOpenItem} onOpenStockOrder={onOpenStockOrder} onOrder={open ? () => setDialog('order') : null} />}
        {((line.missing > 0 && open && line.item_free > 0) || line.source === 'size_kit') && (
          <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[12px] text-muted">
            {line.missing > 0 && open && line.item_free > 0 && <span className="text-success-strong">{qty(line.item_free, line.unit)} available on the shelf</span>}
            {line.source === 'size_kit' && <span>requested at checkout</span>}
          </div>
        )}
      </div>
      <NoBubble>
      <Dropdown>
        <Button isIconOnly size="sm" variant="tertiary" aria-label="Line actions" isPending={busy}>
          <EllipsisVertical className="size-4" />
        </Button>
        <Dropdown.Popover className="min-w-60">
          <Dropdown.Menu aria-label="Line actions" onAction={(k) => onAction(String(k))}>
            {line.item_id > 0 && (
              <Dropdown.Item id="open-item" textValue="Open item">
                <Box className="size-4" />
                <Label>Open item</Label>
              </Dropdown.Item>
            )}
            {open ? (
              <Dropdown.Item id="edit" textValue="Edit">
                <Pencil className="size-4" />
                <Label>Edit quantity or item</Label>
              </Dropdown.Item>
            ) : (
              <Dropdown.Item id="edit-locked" textValue="Edit" isDisabled className="opacity-60">
                <Lock className="size-4" />
                <Label>Edit quantity or item</Label>
              </Dropdown.Item>
            )}
            <Dropdown.Item id="comment" textValue={line.note ? 'Edit comment' : 'Add comment'}>
              <Comment className="size-4" />
              <Label>{line.note ? 'Edit comment' : 'Add comment'}</Label>
            </Dropdown.Item>
            {!!line.note && (
              <Dropdown.Item id="comment-remove" textValue="Remove comment">
                <CommentSlash className="size-4" />
                <Label>Remove comment</Label>
              </Dropdown.Item>
            )}
            {open && byHand && line.missing > 0 && (
              <Dropdown.Item id="assign" textValue="Assign">
                <Box className="size-4" />
                <Label>Assign from stock</Label>
              </Dropdown.Item>
            )}
            {open && byHand && line.per_piece && pieces.some((a) => a.state === 'out' || a.state === 'reserved') && (
              <Dropdown.Item id="swap" textValue="Swap">
                <ArrowsRotateRight className="size-4" />
                <Label>Swap piece</Label>
              </Dropdown.Item>
            )}
            {open && line.missing > 0 && (!line.stock_order || Number(line.unordered) > 0) && (
              <Dropdown.Item id="order" textValue="Order">
                <ShoppingCart className="size-4" />
                <Label>Add to a stock order</Label>
              </Dropdown.Item>
            )}
            {line.stock_order && (
              <Dropdown.Item id="open-so" textValue="Open stock order">
                <ArrowUpRightFromSquare className="size-4" />
                <Label>Open stock order #{line.stock_order.number}</Label>
              </Dropdown.Item>
            )}
            {open && line.missing > 0 && linkParts.length > 1
              ? linkParts.map((p) => (
                  <Dropdown.Item key={`unlink:${p.line_id}`} id={`unlink:${p.line_id}`} textValue={`Remove from stock order #${p.number}`}>
                    <ShoppingCart className="size-4" />
                    <Label>Remove from stock order #{p.number}</Label>
                  </Dropdown.Item>
                ))
              : open && line.missing > 0 && (line.stock_order || linkParts.length === 1) && (
                  <Dropdown.Item id="unlink" textValue="Unlink">
                    <ShoppingCart className="size-4" />
                    <Label>Remove from stock order{linkParts.length === 1 ? ` #${linkParts[0].number}` : ''}</Label>
                  </Dropdown.Item>
                )}
            {sendAllocs.length > 0 && (
              <Dropdown.Item id="send" textValue="Send" className="cjs-mi-primary">
                <PaperPlane className="size-4" />
                <Label>Mark as sent</Label>
              </Dropdown.Item>
            )}
            {outAllocs.length > 0 && (
              <Dropdown.Item id="return" textValue="Return" className="cjs-mi-primary">
                <ArrowUturnCcwLeft className="size-4" />
                <Label>Mark as returned</Label>
              </Dropdown.Item>
            )}
            {outAllocs.length > 0 && (
              <Dropdown.Item id="lost" textValue="Lost" className="cjs-mi-danger">
                <CircleExclamation className="size-4" />
                <Label>Mark as lost</Label>
              </Dropdown.Item>
            )}
            {unassignable.length > 0 && (
              <Dropdown.Item id="unassign" textValue="Unassign">
                <ArrowUturnCcwLeft className="size-4" />
                <Label>Unassign stock</Label>
              </Dropdown.Item>
            )}
            {open && line.returnable && outAllocs.length === 0 && line.allocations.some((a) => a.state === 'returned') && (
              <Dropdown.Item id="remove-returned" textValue="Returned, remove from inventory">
                <ArrowUturnCcwLeft className="size-4" />
                <Label>Returned, remove from inventory</Label>
              </Dropdown.Item>
            )}
            {open && outAllocs.length === 0 && (
              <Dropdown.Item id="remove" textValue="Remove" variant="danger">
                <TrashBin className="size-4" />
                <Label>Remove line</Label>
              </Dropdown.Item>
            )}
            {!open && (
              <Dropdown.Item id="remove-locked" textValue="Remove" isDisabled className="opacity-60">
                <Lock className="size-4" />
                <Label>Remove line</Label>
              </Dropdown.Item>
            )}
            {!open && lockKey && (
              <Dropdown.Item id="locked-at" textValue="Locked" isDisabled className="cjs-mi-footer">
                <Lock className="size-4" />
                <Label>Locked at {statuses.label(lockKey)}</Label>
              </Dropdown.Item>
            )}
          </Dropdown.Menu>
        </Dropdown.Popover>
      </Dropdown>
      </NoBubble>
      {dialog === 'comment' && <CommentDialog title={line.note ? 'Edit comment' : 'Add comment'} value={line.note || ''} onClose={() => setDialog(null)} onSave={async (text) => { await saveDemandNote(line.id, text); onChanged() }} />}
      {dialog === 'edit' && <EditLineDialog line={line} context={context} isOpen onClose={() => setDialog(null)} onDone={onChanged} />}
      {dialog === 'assign' && <AssignDialog line={line} isOpen onClose={() => setDialog(null)} onDone={onChanged} />}
      {dialog === 'swap' && <AssignDialog line={line} isOpen onClose={() => setDialog(null)} onDone={onChanged} replace={pieces.find((a) => a.state === 'out' || a.state === 'reserved')} />}
      {dialog === 'order' && <StockOrderDialog lines={[line]} order={{ id: line.order_id, number: orderNumber || line.order_id }} draftKey={`so:new:line:${line.id}`} isOpen onClose={() => setDialog(null)} onDone={onChanged} />}
    </li>
  )
}

const HISTORY_OUTCOME = {
  returned: { label: 'Returned', tone: 'success' },
  lost: { label: 'Lost', tone: 'danger' },
  written_off: { label: 'Written off', tone: 'default' },
  detached: { label: 'Detached', tone: 'default' },
}

function historyText(h) {
  const sent = h.sent_at ? `Sent ${date(h.sent_at)}` : 'Never sent'
  if (h.outcome === 'returned') {
    const back = h.returned_at || h.ended_at
    const days = h.sent_at && back ? Math.max(0, Math.round((Date.parse(String(back).slice(0, 10)) - Date.parse(String(h.sent_at).slice(0, 10))) / 86400000)) : null
    return `${sent}${back ? `, returned ${date(back)}` : ''}${days !== null && Number.isFinite(days) ? ` (${days} ${days === 1 ? 'day' : 'days'})` : ''}`
  }
  const end = h.ended_at ? ` ${date(h.ended_at)}` : ''
  if (h.outcome === 'lost') return `${sent}, lost${end}`
  if (h.outcome === 'written_off') return `${sent}, written off${end}`
  if (h.outcome === 'detached') return `${sent}, detached from the order${end}`
  return sent
}

function ReturnableHistory({ history }) {
  if (!Array.isArray(history) || !history.length) return null
  return (
    <div className="mt-2 border-t border-separator pt-2">
      <div className="text-[11.5px] font-medium text-muted">No longer on this order</div>
      <ul className="mt-1 flex flex-col gap-1.5">
        {history.map((h, i) => {
          const o = HISTORY_OUTCOME[h.outcome] || { label: h.outcome, tone: 'default' }
          return (
            <li key={`${h.demand_id || 0}-${i}`} className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
              <ItemChip tone={o.tone} status={o.label} name={`${h.item_name || ''}${h.piece ? ` (${h.piece})` : ''}`} categoryId={h.category_id} />
              <span className="text-[12px] text-muted">{historyText(h)}</span>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function onShelf(d) {
  return d.auto_assign === false && Number(d.item_free) >= Number(d.missing)
}

function attentionText(d) {
  const so = d.stock_order && d.stock_order.id ? d.stock_order : null
  if (!so && onShelf(d)) return 'Available on the shelf, assign it by hand'
  if (so && Number(d.unordered) > 0) return 'Partly on a stock order, the rest is not on any stock order'
  if (!so) return Number(d.allocated) > 0 ? 'Partly in stock, the rest is not on any stock order' : 'Missing, not on any stock order'
  const state = so.line_state || (so.ordered === false ? 'not_ordered' : 'ordered')
  if (state === 'not_ordered') return 'On a stock order that is not ordered yet'
  if (state === 'partly_received') return 'Ordered, only partly received'
  return 'Ordered but not received yet'
}

export default function InventoryLines({ orderId, orderNumber, lineItems = [], demands = [], warnings = [], history = [], onChanged, compact, comment, locked }) {
  const [adding, setAdding] = useState(null)
  const [itemId, setItemId] = useState(null)
  const [soId, setSoId] = useState(null)
  const [orderLines, setOrderLines] = useState(null)
  const changed = () => onChanged && onChanged()
  const unordered = demands.filter((d) => d.state === 'open' && d.missing > 0 && (!d.stock_order || Number(d.unordered) > 0) && !onShelf(d))
  const attention = demands.filter((d) => d.state === 'open' && d.missing > 0)
  const lastAttention = useRef([])
  if (attention.length > 0) lastAttention.current = attention
  const shownAttention = attention.length ? attention : lastAttention.current
  const groups = lineItems.map((li) => ({ key: `i${li.id}`, orderItemId: li.id, title: li.name, qty: li.qty, image: li.image, lines: demands.filter((d) => d.order_item_id === li.id) }))
  const known = new Set(lineItems.map((li) => li.id))
  const orphan = demands.filter((d) => d.order_item_id && !known.has(d.order_item_id))
  const orderLevel = demands.filter((d) => !d.order_item_id)
  groups.push({ key: 'order', orderItemId: null, title: 'Whole order', subtitle: 'Packaging, envelopes, sizing kits', lines: [...orderLevel, ...orphan] })
  const contextFor = (orderItemId) => ({ meta: (orderItemId && lineItems.find((li) => li.id === orderItemId)?.meta) || [], comment: comment || '' })
  return (
    <div className="flex flex-col">
      <Expand open={attention.length > 0}>
        <div className="pb-3">
          <Notice tone="danger" title="Order inventory is not finalized" action={unordered.length > 0 ? <Btn size="sm" variant="secondary" icon={ShoppingCart} onPress={() => setOrderLines(unordered)}>Add to stock order</Btn> : null}>
            <ul className="mt-1 flex flex-col items-start gap-1">
              {shownAttention.map((d) => (
                <li key={d.id} className="flex min-w-0 max-w-full flex-wrap items-center gap-x-2 gap-y-0.5">
                  <InventoryBadge line={d} onOpenItem={setItemId} onOpenStockOrder={setSoId} onOrderMissing={(l) => setOrderLines([l])} />
                  <span className="text-[12.5px] text-muted">{attentionText(d)}</span>
                </li>
              ))}
            </ul>
          </Notice>
        </div>
      </Expand>
      <div className="flex flex-col gap-3">
      {groups.map((g) => (
        <div key={g.key} className={cx('bg-surface p-3', !compact && 'ring-1 ring-separator')}>
          <div className="flex items-center justify-between gap-2">
            <div className="flex min-w-0 items-center gap-2.5">
              {g.image && <img src={g.image} alt="" className="size-9 shrink-0 object-cover" />}
              <div className="min-w-0">
                <div className="truncate text-[13px] font-semibold text-foreground">
                  {g.title}
                  {g.qty > 1 && <span className="font-normal text-muted"> × {g.qty}</span>}
                </div>
                {g.subtitle && <div className="text-[11.5px] text-muted">{g.subtitle}</div>}
              </div>
            </div>
            {!locked && (
              <Button size="sm" variant="tertiary" onPress={() => setAdding(g)}>
                <Plus className="size-4" />
                Add
              </Button>
            )}
          </div>
          {g.lines.length > 0 ? (
            <ul className="mt-1 divide-y divide-separator">
              {g.lines.map((line) => <LineRow key={line.id} line={line} demands={demands} orderNumber={orderNumber} context={contextFor(line.order_item_id)} onChanged={changed} onOpenItem={setItemId} onOpenStockOrder={setSoId} />)}
            </ul>
          ) : (
            <div className="mt-1 text-[12.5px] text-muted">No inventory lines.</div>
          )}
          {g.key === 'order' && <ReturnableHistory history={history} />}
        </div>
      ))}
      </div>
      {adding && <AddLineDialog orderId={orderId} orderItemId={adding.orderItemId} productName={adding.orderItemId ? adding.title : null} context={contextFor(adding.orderItemId)} isOpen onClose={() => setAdding(null)} onDone={changed} />}
      {orderLines && orderLines.length > 0 && (
        <StockOrderDialog
          lines={orderLines}
          order={{ id: orderId, number: orderNumber || orderId }}
          draftKey={orderLines.length === 1 ? `so:new:line:${orderLines[0].id}` : `so:new:order:${orderId}`}
          title={orderLines.length > 1 ? `Stock order for #${orderNumber || orderId}` : undefined}
          isOpen
          onClose={() => setOrderLines(null)}
          onDone={changed}
        />
      )}
      <Suspense fallback={null}>
        {itemId && <ItemDetailModal itemId={itemId} isOpen onClose={() => setItemId(null)} onChanged={changed} />}
        {soId && <StockOrderModal id={soId} isOpen onClose={() => setSoId(null)} onChanged={changed} />}
      </Suspense>
    </div>
  )
}
