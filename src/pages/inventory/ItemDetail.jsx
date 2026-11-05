import { lazy, Suspense, use, useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Button, ComboBox, Dropdown, Header, Input, Label, ListBox, Spinner } from '@heroui/react'
import { toast } from '../../lib/toast'
import { ComboBoxStateContext } from 'react-aria-components/ComboBox'
import { Pencil, ArrowDownToLine, BarsDescendingAlignLeft, Archive, TrashBin, EllipsisVertical, ArrowUturnCcwLeft, CircleExclamation, ArrowShapeTurnUpRight, PaperPlane } from '@gravity-ui/icons'
import { get, post, patch, del, setBatchCost, sendAllocation } from '../../lib/api'
import { notifyError, useCatalog, useDebounced } from '../../lib/hooks'
import { CategoryIcon } from '../../components/icons'
import { defaultFineness, fineQty, itemMissingAtDefault, missingAtDefault, money, qty, date as fmtDate } from '../../lib/format'
import { BackLink, Page, Card, Stat, Loading, Tone, Btn, Dialog, Field, TextInput, NumberInput, MoneyInput, SelectField, SegmentedFilter, useConfirm, Empty, IconButton, cx } from '../../components/ui'
import ItemDialog from '../../components/ItemDialog'
import Movements from '../../components/Movements'
import { OrderLink } from '../../components/OrderLink'
import { StockBadges } from './ItemsList'
import ReceiveForm from './ReceiveForm'
import { useDraft } from '../../lib/draft'
import CountForm from './CountForm'
import InventoryBadge, { PieceChips } from '../../components/InventoryBadge'
import { useAskRemoveReturned } from '../../components/StockModal'

const StockOrderModal = lazy(() => import('../stock-orders/StockOrderDetail').then((m) => ({ default: m.StockOrderModal })))

const SOURCE = { receipt: 'Received', opening: 'Opening stock', count: 'Found in count', stock_order: 'Stock order', adjustment: 'Adjustment', migration: 'Migrated' }

const INVALIDATE = ['item', 'items', 'movements', 'required', 'orders', 'order', 'bootstrap', 'documents', 'discrepancies', 'stock-orders', 'item-search']

const STATE_ORDER = ['in', 'to_send', 'sent', 'assigned']

function pieceState(p, item) {
  if (p.status === 'in') return { key: 'in', label: 'In stock', tone: 'success', where: 'On the shelf, available to assign' }
  if (p.status === 'removed') return { key: 'lost', label: 'Lost', tone: 'danger', where: 'Not here. If it turns up, a stock count brings it back.', old: true }
  if (item.returnable) {
    if (p.status === 'reserved') return { key: 'to_send', label: 'To send', tone: 'warning', where: 'On the shelf, send it to', order: true }
    if (p.status === 'out') return { key: 'sent', label: 'Sent', tone: 'accent', where: 'With the customer of', order: true }
    if (p.status === 'used') return { key: 'lost', label: 'Lost', tone: 'danger', where: 'Not sent back by', order: true, old: true }
  }
  const openOrder = !!p.order && (item.demands || []).some((d) => String(d.order?.id) === String(p.order.id))
  if (p.status === 'reserved' || p.status === 'out' || (p.status === 'used' && openOrder && p.allocation_id)) return { key: 'assigned', label: 'Assigned', tone: 'accent', where: 'Taken for', order: true }
  if (p.status === 'used') return { key: 'used', label: 'Used', tone: 'default', where: 'Used in', order: true, old: true }
  return { key: p.status, label: p.status, tone: 'default', where: '' }
}

function useRefresh(itemId, onChanged) {
  const qc = useQueryClient()
  return () => {
    for (const k of INVALIDATE) qc.invalidateQueries({ queryKey: [k] })
    if (onChanged) onChanged()
  }
}

function useDraftValue(key, initial) {
  const draft = useDraft(key)
  const [value, setValue] = useState(() => {
    const d = draft.read()
    return d && typeof d.value === 'string' ? d.value : initial
  })
  useEffect(() => {
    if (value !== initial) draft.save({ value })
    else draft.clear()
  }, [value])
  return [value, setValue, draft.clear]
}

function PieceEditDialog({ piece, onClose, onDone }) {
  const [identifier, setIdentifier, clearDraft] = useDraftValue(`piece:${piece.id}`, piece.identifier || '')
  const [note, setNote, clearNote] = useDraftValue(`piece-note:${piece.id}`, piece.note || '')
  const cancel = () => {
    clearDraft()
    clearNote()
    onClose()
  }
  const [saving, setSaving] = useState(false)
  const save = async () => {
    setSaving(true)
    try {
      await patch(`pieces/${piece.id}`, { identifier, note })
      clearDraft()
      clearNote()
      onDone()
      onClose()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Dialog isOpen onClose={onClose} isDismissable={false} size="sm" title="Edit piece" footer={<><Button variant="tertiary" onPress={cancel}>Cancel</Button><Btn variant="primary" isPending={saving} onPress={save}>Save</Btn></>}>
      <div className="flex flex-col gap-3">
        <Field label="Identifier" hint="Must be unique for this item">
          <TextInput value={identifier} onChange={setIdentifier} autoFocus />
        </Field>
        <Field label="Note" hint="Optional">
          <TextInput value={note} onChange={setNote} />
        </Field>
      </div>
    </Dialog>
  )
}

function OpenOnFocusInput(props) {
  const state = use(ComboBoxStateContext)
  const open = () => {
    if (state && !state.isOpen) state.open(null, 'manual')
  }
  useEffect(() => {
    const t = setTimeout(open, 150)
    return () => clearTimeout(t)
  }, [])
  return <Input {...props} onClick={open} onFocus={open} />
}

function WaitingOrderCombo({ item, onPick, isDisabled }) {
  const [input, setInput] = useState('')
  const [picked, setPicked] = useState('')
  const term = useDebounced(input, 220)
  const search = useQuery({
    queryKey: ['order-search', term],
    queryFn: () => get('orders/search', { term }),
    enabled: term.trim().length >= 1,
    staleTime: 15_000,
  })
  const { categoryById } = useCatalog()
  const waiting = useMemo(() => (item.demands || []).filter((d) => d.missing > 0 && d.order?.id), [item.demands])
  const waitingByOrder = useMemo(() => new Map(waiting.map((d) => [String(d.order.id), d])), [waiting])
  const results = term.trim() ? search.data || [] : []
  const pick = (key) => {
    const k = String(key || '')
    let choice = null
    if (k.startsWith('d-')) {
      const d = waiting.find((x) => `d-${x.id}` === k)
      if (d) choice = { demand: d, order: d.order }
    } else if (k.startsWith('o-')) {
      const o = results.find((r) => `o-${r.id}` === k)
      const d = o ? waitingByOrder.get(String(o.id)) : null
      if (o) choice = d ? { demand: d, order: d.order } : { order: o }
    }
    if (!choice) return
    const label = `#${choice.order.number} ${choice.order.customer || ''}`
    setPicked(label)
    setInput(label)
    onPick(choice)
  }
  const type = (v) => {
    setInput(v)
    if (picked && v !== picked) {
      setPicked('')
      onPick(null)
    }
  }
  return (
    <ComboBox
      className="w-full"
      aria-label="Order"
      inputValue={input}
      onInputChange={type}
      defaultFilter={() => true}
      allowsEmptyCollection
      menuTrigger="manual"
      isDisabled={isDisabled}
      onSelectionChange={pick}
    >
      <ComboBox.InputGroup>
        <OpenOnFocusInput placeholder="Search order # or customer" autoFocus />
        <ComboBox.Trigger>{search.isFetching ? <Spinner size="sm" /> : undefined}</ComboBox.Trigger>
      </ComboBox.InputGroup>
      <ComboBox.Popover>
        <ListBox renderEmptyState={() => <div className="px-3 py-2 text-[13px] text-muted">{term ? (search.isFetching ? <Spinner size="sm" /> : 'No orders found') : 'Type an order number or customer name'}</div>}>
          {results.length > 0 && (
            <ListBox.Section>
              <Header className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Search results</Header>
              {results.map((o) => (
                <ListBox.Item key={`o-${o.id}`} id={`o-${o.id}`} textValue={`#${o.number} ${o.customer || ''}`}>
                  <div className="flex w-full items-center justify-between gap-3 text-[13px]">
                    <span><span className="font-semibold">#{o.number}</span> <span className="text-muted">{o.customer}</span></span>
                    <span className="text-[11px] text-muted">{waitingByOrder.has(String(o.id)) ? 'Waiting for this item' : o.status_label}</span>
                  </div>
                </ListBox.Item>
              ))}
            </ListBox.Section>
          )}
          {waiting.length > 0 && (
            <ListBox.Section>
              <Header className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Waiting for this item</Header>
              {waiting.map((d) => (
                <ListBox.Item key={`d-${d.id}`} id={`d-${d.id}`} textValue={`#${d.order.number} ${d.order.customer || ''}`}>
                  <div className="flex w-full items-center justify-between gap-3 text-[13px]">
                    <span><span className="font-semibold">#{d.order.number}</span> <span className="text-muted">{d.order.customer}</span></span>
                    <span className="text-[11px] text-danger">{fineQty(missingAtDefault(d, categoryById[item.category_id]), item.unit, defaultFineness(null, categoryById[item.category_id]))} missing</span>
                  </div>
                </ListBox.Item>
              ))}
            </ListBox.Section>
          )}
        </ListBox>
      </ComboBox.Popover>
    </ComboBox>
  )
}

function AssignPieceDialog({ piece, item, onClose, onDone }) {
  const [saving, setSaving] = useState(false)
  const [choice, setChoice] = useState(null)
  const assign = async () => {
    if (!choice) return
    const { demand, order } = choice
    setSaving(true)
    try {
      if (demand) await post(`demands/${demand.id}/assign`, { piece_id: piece.id })
      else await post(`orders/${order.id}/demands`, { item_id: item.id, qty: 1, piece_id: piece.id })
      toast.success(`${piece.identifier || 'Piece'} assigned to #${order.number || order.id}`)
      onDone()
      onClose()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  const waiting = (item.demands || []).filter((d) => d.missing > 0 && d.order?.id).length
  return (
    <Dialog
      isOpen
      onClose={onClose}
      size="md"
      isDismissable={false}
      footer={
        <>
          <Button variant="tertiary" onPress={onClose}>Cancel</Button>
          <Btn variant="primary" isPending={saving} isDisabled={!choice} onPress={assign}>Assign</Btn>
        </>
      }
      title={`Assign ${piece.identifier || 'piece'} to an order`}
      description={waiting ? 'Orders waiting for this item are listed first. Type to find any other order.' : 'Type an order number or customer name.'}
    >
      <div className="flex min-h-[64px] flex-col gap-2">
        <Field label="Order" required>
          <WaitingOrderCombo item={item} onPick={setChoice} isDisabled={saving} />
        </Field>
      </div>
    </Dialog>
  )
}

function AssignDemandDialog({ demand, item, onClose, onDone }) {
  const freePieces = (item.pieces || []).filter((p) => p.status === 'in')
  const max = Math.max(0, Math.min(demand.missing, item.free))
  const [pieceId, setPieceId, clearPiece] = useDraftValue(`assign:${demand.id}:piece`, freePieces[0] ? String(freePieces[0].id) : '')
  const [amount, setAmount, clearAmount] = useDraftValue(`assign:${demand.id}:qty`, max > 0 ? String(max) : '')
  const cancel = () => {
    clearPiece()
    clearAmount()
    onClose()
  }
  const [saving, setSaving] = useState(false)
  const n = Number(amount)
  const valid = item.per_piece ? freePieces.some((p) => String(p.id) === pieceId) : amount !== '' && n > 0 && n <= max
  const submit = async () => {
    if (!valid) return
    setSaving(true)
    try {
      await post(`demands/${demand.id}/assign`, item.per_piece ? { piece_id: Number(pieceId) } : { qty: n })
      toast.success(`Assigned to #${demand.order.number}`)
      clearPiece()
      clearAmount()
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
      isOpen
      onClose={onClose}
      size="sm"
      isDismissable={false}
      title={`Assign to #${demand.order.number}`}
      description={`${demand.order.customer ? `${demand.order.customer}: ` : ''}${qty(demand.missing, item.unit)} still missing.`}
      footer={<><Button variant="tertiary" onPress={cancel}>Cancel</Button><Btn variant="primary" isPending={saving} isDisabled={!valid} onPress={submit}>Assign</Btn></>}
    >
      {item.per_piece ? (
        <Field label="Piece" required hint={freePieces.length ? `${freePieces.length} in stock` : 'No pieces in stock'}>
          <SelectField value={pieceId} onChange={setPieceId} options={freePieces.map((p) => ({ value: String(p.id), label: p.identifier || `#${p.id}` }))} placeholder="Choose a piece" ariaLabel="Piece" isDisabled={!freePieces.length} />
        </Field>
      ) : (
        <Field label={`Quantity (${item.unit})`} required error={amount !== '' && n > max ? `Only ${qty(max, item.unit)} can be assigned` : null} hint={`${qty(item.free, item.unit)} free in stock`}>
          <NumberInput value={amount} onChange={setAmount} min="0" max={max} autoFocus />
        </Field>
      )}
    </Dialog>
  )
}

function BatchCostDialog({ batch, item, onClose, onDone }) {
  const initial = batch.unit_cost ?? item.default_cost ?? ''
  const [cost, setCost, clearCost] = useDraftValue(`batchcost:${batch.id}`, initial === '' ? '' : String(initial))
  const cancel = () => {
    clearCost()
    onClose()
  }
  const [saving, setSaving] = useState(false)
  const valid = cost !== '' && Number(cost) >= 0
  const save = async () => {
    if (!valid) return
    setSaving(true)
    try {
      await setBatchCost(batch.id, Number(cost))
      toast.success('Cost saved')
      clearCost()
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
      isOpen
      onClose={onClose}
      size="sm"
      isDismissable={false}
      title={`Set cost: ${fmtDate(batch.received_at)}`}
      description="This updates the value of this delivery and the cost of goods already used from it."
      footer={<><Button variant="tertiary" onPress={cancel}>Cancel</Button><Btn variant="primary" isPending={saving} isDisabled={!valid} onPress={save}>Save cost</Btn></>}
    >
      <div className="flex flex-col gap-3">
        <Field label={`Cost per ${item.unit}`} required hint={batch.unit_cost === null && item.default_cost !== null ? 'Filled in from the item default cost' : null}>
          <MoneyInput value={cost} onChange={setCost} min="0" autoFocus />
        </Field>
        <div className="grid grid-cols-2 gap-2 text-[13px]">
          <div className="bg-surface-secondary/70 px-3 py-2">
            <div className="text-[11px] text-muted">Received</div>
            <div className="tabular-nums">{qty(batch.received, item.unit)}</div>
          </div>
          <div className="bg-surface-secondary/70 px-3 py-2">
            <div className="text-[11px] text-muted">Delivery value</div>
            <div className="tabular-nums">{valid ? money(Number(cost) * batch.received) : 'Not set'}</div>
          </div>
        </div>
      </div>
    </Dialog>
  )
}

function PiecesCard({ item, refresh }) {
  const confirm = useConfirm()
  const askRemove = useAskRemoveReturned()
  const { categoryById } = useCatalog()
  const byHand = categoryById[item.category_id]?.auto_assign === false
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(null)
  const [state, setState] = useState('')
  const states = item.pieces.map((p) => [p, pieceState(p, item)]).filter(([, st]) => st.key !== 'lost' && !st.old)
  const counts = states.reduce((acc, [, st]) => ({ ...acc, [st.key]: (acc[st.key] || 0) + 1 }), {})
  const filters = STATE_ORDER.filter((k) => counts[k]).map((k) => ({ value: k, label: `${states.find(([, st]) => st.key === k)[1].label} (${counts[k]})` }))
  const visible = states.filter(([, st]) => !state || st.key === state)
  const run = async (p, path, message, fn) => {
    setBusy(p.id)
    let res = null
    try {
      res = await (fn ? fn() : post(path))
      if (message) toast.success(message)
      refresh()
    } catch (e) {
      notifyError(e)
    } finally {
      setBusy(null)
    }
    if (res && res.ask_remove && p.demand_id && (await askRemove(p.demand_id, p.identifier || item.name))) refresh()
  }
  const act = async (key, p) => {
    const name = p.identifier || 'This piece'
    if (key === 'edit') setDialog({ type: 'edit', piece: p })
    else if (key === 'assign') setDialog({ type: 'assign', piece: p })
    else if (key === 'send') run(p, null, `${name} marked as sent`, () => sendAllocation(p.allocation_id))
    else if (key === 'return') run(p, `allocations/${p.allocation_id}/return`, `${name} is back in stock`)
    else if (key === 'unassign') run(p, `allocations/${p.allocation_id}/unassign`, `${name} is back in stock`)
    else if (key === 'lost') {
      const ok = await confirm({
        title: `Mark ${name} as lost?`,
        message: `Use this when the customer${p.order ? ` of #${p.order.number}` : ''} will not send it back. It is written off: removed from stock for good and no longer listed anywhere. The order's line is removed too, so it does not show as missing and no other piece is assigned. If it turns up later, add it back with a stock count.`,
        confirmLabel: 'Mark as lost',
        tone: 'danger',
      })
      if (ok) run(p, `allocations/${p.allocation_id}/lost`, `${name} marked as lost`)
    }
  }
  return (
    <Card
      title="Pieces"
      description={item.returnable ? 'Where each piece is right now and what you can do with it' : 'Each piece of this item'}
      padded={false}
    >
      {filters.length > 1 && (
        <div className="px-4 pt-3 sm:px-5">
          <SegmentedFilter value={state} onChange={setState} items={[{ value: '', label: `All (${states.length})` }, ...filters]} />
        </div>
      )}
      {visible.length === 0 ? (
        <Empty compact title={state ? 'No pieces in this state' : 'No pieces in stock'} description={state ? null : 'Receive stock to add pieces.'} />
      ) : (
        <ul className="mt-1 divide-y divide-separator">
          {visible.map(([p, st]) => {
            const hasAlloc = !!p.allocation_id
            const pending = busy === p.id
            return (
              <li key={p.id} className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5 sm:px-5">
                <span className="flex min-w-[96px] items-center gap-1.5 text-[13px] font-semibold"><CategoryIcon categoryId={item.category_id} className="size-4 shrink-0 text-muted" />{p.identifier || `#${p.id}`}{p.note && <span className="font-normal text-muted">· {p.note}</span>}</span>
                <Tone tone={st.tone} className="min-w-[78px] justify-center">{st.label}</Tone>
                <span className="flex min-w-0 flex-wrap items-center gap-1.5 text-[12.5px] text-muted">
                  {st.where}
                  {st.order && (p.order ? <OrderLink id={p.order.id} number={p.order.number} customer={p.order.customer} inList={p.order.in_list !== false} /> : <span>an order</span>)}
                </span>
                <div className="ml-auto flex flex-wrap items-center justify-end gap-1.5">
                  {st.key === 'in' && byHand && <Button size="sm" variant="secondary" onPress={() => act('assign', p)}><ArrowShapeTurnUpRight className="size-4" />Assign</Button>}
                  {st.key === 'to_send' && hasAlloc && (
                    <>
                      {p.can_unassign && <Btn size="sm" variant="tertiary" icon={ArrowUturnCcwLeft} isDisabled={pending} onPress={() => act('unassign', p)}>Unassign</Btn>}
                      <Btn size="sm" variant="primary" icon={PaperPlane} isPending={pending} onPress={() => act('send', p)}>Mark as sent</Btn>
                    </>
                  )}
                  {st.key === 'sent' && hasAlloc && (
                    <>
                      <Btn size="sm" variant="danger-soft" icon={CircleExclamation} isDisabled={pending} onPress={() => act('lost', p)}>Mark as lost</Btn>
                      <Btn size="sm" variant="primary" icon={ArrowUturnCcwLeft} isPending={pending} onPress={() => act('return', p)}>Mark as returned</Btn>
                    </>
                  )}
                  {st.key === 'assigned' && hasAlloc && p.can_unassign && <Btn size="sm" variant="tertiary" icon={ArrowUturnCcwLeft} isPending={pending} onPress={() => act('unassign', p)}>Unassign</Btn>}
                  <IconButton icon={Pencil} label="Edit identifier" onPress={() => act('edit', p)} />
                </div>
              </li>
            )
          })}
        </ul>
      )}
      {dialog?.type === 'edit' && <PieceEditDialog piece={dialog.piece} onClose={() => setDialog(null)} onDone={refresh} />}
      {dialog?.type === 'assign' && <AssignPieceDialog piece={dialog.piece} item={item} onClose={() => setDialog(null)} onDone={refresh} />}
    </Card>
  )
}

function WaitingCard({ item, refresh }) {
  const { categoryById } = useCatalog()
  const byHand = categoryById[item.category_id]?.auto_assign === false
  const [assigning, setAssigning] = useState(null)
  const [soId, setSoId] = useState(null)
  if (!item.demands.length) return null
  const canAssign = item.free > 0
  const orderPieces = (d) => {
    if (!item.per_piece) return []
    if (d.stock_line_id) return d.pieces || []
    if (!d.order?.id) return []
    const held = (item.pieces || []).filter((x) => x.order && String(x.order.id) === String(d.order.id) && (x.status === 'reserved' || x.status === 'out')).map((x) => ({ id: x.id, identifier: x.identifier, state: x.status }))
    return [...held, ...(d.planned || [])]
  }
  return (
    <Card title="Waiting orders" description="Open orders that need this item" padded={false}>
      <ul className="divide-y divide-separator">
        {item.demands.map((d) => (
          <li key={d.id} className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5 sm:px-5">
            {d.stock_line_id ? <span className="text-[12.5px]">Stock order</span> : <OrderLink id={d.order.id} number={d.order.number} customer={d.order.customer} inList={d.order.in_list !== false} />}
            <InventoryBadge line={{ ...d, item_id: item.id, category_id: item.category_id, name: d.metal || item.name, unit: item.unit, returnable: d.returnable ?? item.returnable }} onOpenStockOrder={setSoId} />
            <PieceChips pieces={orderPieces(d)} />
            {d.missing > 0 && d.order?.id && byHand ? (
              <div className="ml-auto">
                <Button size="sm" variant={canAssign ? 'secondary' : 'tertiary'} isDisabled={!canAssign} onPress={() => setAssigning(d)}>
                  <ArrowShapeTurnUpRight className="size-4" />
                  {canAssign ? 'Assign' : 'Nothing in stock'}
                </Button>
              </div>
            ) : null}
          </li>
        ))}
      </ul>
      {assigning && <AssignDemandDialog demand={assigning} item={item} onClose={() => setAssigning(null)} onDone={refresh} />}
      <Suspense fallback={null}>
        {soId && <StockOrderModal id={soId} isOpen onClose={() => setSoId(null)} onChanged={refresh} />}
      </Suspense>
    </Card>
  )
}

function BatchesCard({ item, refresh }) {
  const [costing, setCosting] = useState(null)
  const showReserved = item.returnable || item.batches.some((b) => b.reserved > 0)
  const showFine = item.batches.some((b) => b.fineness !== null && b.fineness !== undefined)
  const costLabel = (b) => (b.unit_cost !== null && b.unit_cost !== undefined ? money(b.unit_cost) : <span className="text-muted">Not set</span>)
  const valueLabel = (b) => (b.unit_cost !== null && b.unit_cost !== undefined ? money(b.unit_cost * b.on_hand) : <span className="text-muted">Not set</span>)
  return (
    <Card title="Batches" description="Each delivery of this item, oldest is used first" padded={false}>
      {item.batches.length === 0 ? (
        <Empty compact title="No stock received yet" />
      ) : (
        <>
          <ul className="divide-y divide-separator sm:hidden">
            {item.batches.map((b) => (
              <li key={b.id} className={cx('px-4 py-2.5', b.on_hand <= 0 && 'opacity-70')}>
                <div className="flex items-center justify-between gap-2 text-[13px]">
                  <span className="font-medium tabular-nums">{fmtDate(b.received_at)}</span>
                  <span className="truncate text-[12px] text-muted">{SOURCE[b.source_type] || b.source_type}</span>
                  <Button size="sm" variant="tertiary" onPress={() => setCosting(b)}>Set cost</Button>
                </div>
                <dl className={cx('mt-1.5 grid gap-2 text-[12.5px]', showFine ? 'grid-cols-5' : 'grid-cols-4')}>
                  {[['Received', qty(b.received)], ...(showFine ? [['Praba', qty(b.fineness)]] : []), ['Stock', qty(b.on_hand)], ['Cost', costLabel(b)], ['Value', valueLabel(b)]].map(([k, v]) => (
                    <div key={k} className="min-w-0">
                      <dt className="text-[11px] text-muted">{k}</dt>
                      <dd className="truncate tabular-nums">{v}</dd>
                    </div>
                  ))}
                </dl>
              </li>
            ))}
          </ul>
          <div className="cjs-table-scroll hidden sm:block">
            <table className="cjs-table min-w-[620px]">
              <thead>
                <tr>
                  <th>Received</th>
                  <th>Source</th>
                  <th className="text-right">Qty</th>
                  {showFine && <th className="text-right">Praba</th>}
                  <th className="text-right">Stock</th>
                  {showReserved && <th className="text-right">Reserved</th>}
                  <th className="text-right">Cost / {item.unit}</th>
                  <th className="text-right">Stock value</th>
                  <th className="w-px"><span className="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody>
                {item.batches.map((b) => (
                  <tr key={b.id} className={cx('[&>td]:align-middle', b.on_hand <= 0 && 'opacity-70')}>
                    <td className="tabular-nums">{fmtDate(b.received_at)}</td>
                    <td>{SOURCE[b.source_type] || b.source_type}</td>
                    <td className="text-right tabular-nums">{qty(b.received)}</td>
                    {showFine && <td className="text-right tabular-nums">{qty(b.fineness)}</td>}
                    <td className="text-right tabular-nums">{qty(b.on_hand)}</td>
                    {showReserved && <td className="text-right tabular-nums">{qty(b.reserved || 0)}</td>}
                    <td className="text-right tabular-nums">{costLabel(b)}</td>
                    <td className="text-right tabular-nums">{valueLabel(b)}</td>
                    <td className="whitespace-nowrap py-1.5 text-right">
                      <Button size="sm" variant="tertiary" onPress={() => setCosting(b)}><Pencil className="size-3.5" />Set cost</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      {costing && <BatchCostDialog batch={costing} item={item} onClose={() => setCosting(null)} onDone={refresh} />}
    </Card>
  )
}

function ItemStats({ item }) {
  const { categoryById } = useCatalog()
  const needed = itemMissingAtDefault(item, categoryById[item.category_id])
  const kits = item.returnable || item.reserved > 0
  return (
    <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
      <Stat label="Stock" value={qty(item.on_hand, item.unit)} hint={kits ? 'Physically here' : 'Here and ready to use'} />
      {kits && <Stat label="Reserved" value={qty(item.reserved, item.unit)} hint="Assigned, not sent yet" />}
      {kits && <Stat label="Available" value={qty(item.free, item.unit)} tone={item.free > 0 ? 'success' : undefined} hint="Ready to assign" />}
      <Stat label="Needed by orders" value={fineQty(needed, item.unit, defaultFineness(null, categoryById[item.category_id]))} tone={item.missing > 0 ? 'danger' : undefined} hint={item.missing > 0 ? 'Missing for open orders' : 'Nothing missing'} />
      <Stat label="Stock value" value={item.stock_value > 0 || item.on_hand <= 0 ? money(item.stock_value || 0) : 'Not set'} hint={item.stock_value > 0 || item.on_hand <= 0 ? 'Cost of what is in stock' : 'Set a cost on the batches'} />
      {item.default_cost !== null && item.default_cost !== undefined && <Stat label="Default cost" value={money(item.default_cost)} hint={`per ${item.unit}, used when receiving without a price`} />}
      {item.reorder_level !== null && item.reorder_level !== undefined && <Stat label="Reorder level" value={qty(item.reorder_level, item.unit)} />}
    </div>
  )
}

function ItemValues({ item }) {
  const { categoryById, unitById } = useCatalog()
  const category = categoryById[item.category_id]
  const values = category ? category.fields.filter((f) => item.values?.[f.id]).map((f) => {
    const v = item.values[f.id]
    if (f.type === 'select') return [f.name, f.choices.find((c) => c.id === v.choice_id)?.label]
    if (f.type === 'number') return [f.name, `${qty(v.value_number)} ${unitById[v.unit_id]?.label || ''}`]
    return [f.name, v.value_text]
  }) : []
  if (!values.length) return null
  return (
    <Card title="Details">
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[13px]">
        {values.map(([k, v]) => (
          <div key={k} className="contents"><dt className="text-muted">{k}</dt><dd>{v}</dd></div>
        ))}
      </dl>
    </Card>
  )
}

function useItemActions(item, refresh, onDeleted) {
  const confirm = useConfirm()
  const [dialog, setDialog] = useState(null)
  const archive = async () => {
    try {
      await post(`items/${item.id}/archive`, { archived: !item.archived })
      refresh()
    } catch (e) {
      notifyError(e)
    }
  }
  const remove = async () => {
    const ok = await confirm({ title: 'Delete item?', message: 'Items that were never used are deleted. Items with history are archived instead.', confirmLabel: 'Delete', tone: 'danger' })
    if (!ok) return
    try {
      const res = await del(`items/${item.id}`)
      refresh()
      if (res?.result === 'deleted' && onDeleted) onDeleted()
    } catch (e) {
      notifyError(e)
    }
  }
  const actions = (
    <>
      <Button variant="secondary" onPress={() => setDialog('receive')}><ArrowDownToLine className="size-4" />Receive</Button>
      <Button variant="secondary" onPress={() => setDialog('count')}><BarsDescendingAlignLeft className="size-4" />Count</Button>
      <Button variant="secondary" onPress={() => setDialog('edit')}><Pencil className="size-4" />Edit</Button>
      <Dropdown>
        <Button isIconOnly variant="tertiary" aria-label="More"><EllipsisVertical className="size-4" /></Button>
        <Dropdown.Popover>
          <Dropdown.Menu aria-label="More" onAction={(k) => (k === 'archive' ? archive() : remove())}>
            <Dropdown.Item id="archive" textValue="Archive"><Archive className="size-4" /><Label>{item.archived ? 'Restore' : 'Archive'}</Label></Dropdown.Item>
            <Dropdown.Item id="delete" textValue="Delete" variant="danger"><TrashBin className="size-4" /><Label>Delete</Label></Dropdown.Item>
          </Dropdown.Menu>
        </Dropdown.Popover>
      </Dropdown>
    </>
  )
  const dialogs = (
    <>
      <ItemDialog isOpen={dialog === 'edit'} onClose={() => setDialog(null)} item={item} onSaved={refresh} />
      <Dialog isOpen={dialog === 'receive'} onClose={() => setDialog(null)} isDismissable={false} size="lg" title={`Receive ${item.name}`}>
        <ReceiveForm presetItem={item} embedded draftKey={`receive:item:${item.id}`} onCancel={() => setDialog(null)} onDone={() => { setDialog(null); refresh() }} />
      </Dialog>
      <Dialog isOpen={dialog === 'count'} onClose={() => setDialog(null)} isDismissable={false} size="lg" title={`Count ${item.name}`}>
        <CountForm presetItems={[item]} embedded draftKey={`count:item:${item.id}`} onCancel={() => setDialog(null)} onDone={() => { setDialog(null); refresh() }} />
      </Dialog>
    </>
  )
  return { actions, dialogs }
}

function ItemSubtitle({ item }) {
  const { categoryById } = useCatalog()
  return (
    <span className="flex flex-wrap items-center gap-2">
      {categoryById[item.category_id]?.name}
      {item.code && <span className="bg-surface-secondary px-1.5 text-[12px]">{item.code}</span>}
      <StockBadges item={item} />
    </span>
  )
}

function ItemContent({ item, refresh }) {
  return (
    <>
      <ItemStats item={item} />
      <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div className="flex min-w-0 flex-col gap-4">
          {item.per_piece && <PiecesCard item={item} refresh={refresh} />}
          <WaitingCard item={item} refresh={refresh} />
          <BatchesCard item={item} refresh={refresh} />
        </div>
        <div className="flex min-w-0 flex-col gap-4">
          <ItemValues item={item} />
          <Card title="History" padded={false} bodyClassName="pt-2">
            <Movements params={{ item_id: item.id }} />
          </Card>
        </div>
      </div>
    </>
  )
}

function useItem(itemId, enabled = true) {
  return useQuery({ queryKey: ['item', Number(itemId)], queryFn: () => get(`items/${itemId}`), enabled: !!itemId && enabled })
}

function ModalBody({ item, onChanged, onClose }) {
  const refresh = useRefresh(item.id, onChanged)
  const { actions, dialogs } = useItemActions(item, refresh, onClose)
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 text-[13px] text-muted"><ItemSubtitle item={item} /></div>
        <div className="flex flex-wrap items-center gap-2">{actions}</div>
      </div>
      <div><ItemContent item={item} refresh={refresh} /></div>
      {dialogs}
    </div>
  )
}

export function ItemDetailModal({ itemId, isOpen, onClose, onChanged }) {
  const [shownId, setShownId] = useState(itemId || null)
  useEffect(() => {
    if (itemId) setShownId(itemId)
  }, [itemId])
  const q = useItem(itemId || shownId, isOpen || !!shownId)
  return (
    <Dialog isOpen={isOpen} onClose={onClose} size="full" title={q.data ? <span className="inline-flex items-center gap-2"><CategoryIcon categoryId={q.data.category_id} className="size-5 shrink-0 text-muted" />{q.data.name}</span> : 'Item'}>
      {q.data ? (
        <ModalBody item={q.data} onChanged={onChanged} onClose={onClose} />
      ) : q.isError ? (
        <div className="text-danger">{q.error.message}</div>
      ) : (
        <Loading />
      )}
    </Dialog>
  )
}

function PageBody({ item, onBack }) {
  const refresh = useRefresh(item.id)
  const { actions, dialogs } = useItemActions(item, refresh, onBack)
  return (
    <Page back={<BackLink onPress={onBack}>Stock</BackLink>} title={<span className="inline-flex items-center gap-2"><CategoryIcon categoryId={item.category_id} className="size-5 shrink-0 text-muted" />{item.name}</span>} subtitle={<ItemSubtitle item={item} />} actions={actions}>
      <ItemContent item={item} refresh={refresh} />
      {dialogs}
    </Page>
  )
}

export default function ItemDetail({ itemId, onBack }) {
  const q = useItem(itemId)
  if (q.isLoading) return <Loading />
  if (q.isError || !q.data) return <Page title="Item" back={<BackLink onPress={onBack}>Stock</BackLink>}><div className="text-danger">{q.error?.message || 'Item not found.'}</div></Page>
  return <PageBody item={q.data} onBack={onBack} />
}
