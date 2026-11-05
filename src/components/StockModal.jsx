import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Button, Radio, RadioGroup } from '@heroui/react'
import { ChevronDown, ChevronUp } from '@gravity-ui/icons'
import { api, post } from '../lib/api'
import { notifyError, useBootstrap, useCatalog } from '../lib/hooks'
import { defaultFineness, fineQty, qty } from '../lib/format'
import { Btn, Check, Dialog, Expand, IconButton, Notice, NumberInput, SelectAll, SelectField, cx, useConfirm } from './ui'
import InventoryBadge from './InventoryBadge'

export const CANCEL_TEXT = "This order uses items from your inventory. Selected inventory items will be written off, which means they don't return to stock but are officially removed via a stock count. Unselected items will simply return to inventory stock."
export const CANCEL_TEXT_MANY = "These orders use items from your inventory. Selected inventory items will be written off, which means they don't return to stock but are officially removed via a stock count. Unselected items will simply return to inventory stock."

export const WOO_TARGETS = ['cancelled', 'refunded', 'failed', 'trash', 'delete']

const CANCEL_TITLES = {
  cancelled: 'Change status to Cancelled',
  refunded: 'Change status to Refunded',
  failed: 'Change status to Failed',
  trash: 'Move to Trash',
  delete: 'Delete permanently',
}

const DEFAULT_LABELS = {
  nepradeta: 'Nepradėta',
  uzsakyti_modeli: 'Užsakyti modelį',
  atspausdinta: 'Atspausdinta',
  iduota_lieti: 'Įduota lieti',
  atlieta: 'Atlieta',
  pagaminta: 'Pagaminta',
  isvezta_prabuoti: 'Išvežta prabuoti',
  uzprabuota: 'Užprabuota',
  done: 'DONE',
}

const USED_CHOICES = [
  { value: 'write_off', label: 'Write off', description: 'The stock this line used is officially removed via a stock count. The line needs stock again, as when the order was new: the queue sets stock aside for it if there is some, otherwise it shows Waiting availability.' },
  { value: 'return', label: 'Return to stock', description: 'The stock goes back to stock and stays Reserved for this order, because the order will use it again. It does not go to the free shelf.' },
]

const RETURNABLE_CHOICES = {
  never_sent: [
    { value: 'detach', label: 'It really was never sent', description: 'For example, the client no longer needed it. The item is detached from the order.' },
    { value: 'returned', label: 'It was sent and returned', description: 'The flow is completed: the item is back in stock and removed from the order\'s inventory.' },
    { value: 'lost', label: 'It was lost', description: 'It is written off and removed from the order\'s inventory.' },
  ],
  not_returned: [
    { value: 'returned', label: 'It was actually returned', description: 'The item is back in stock and removed from the order\'s inventory.' },
    { value: 'detach', label: 'It was actually never sent', description: 'The item is back in stock and detached from the order.' },
    { value: 'lost', label: 'It was actually lost', description: 'It is written off and removed from the order\'s inventory.' },
  ],
}

const INVALIDATE = ['orders', 'order', 'required', 'items', 'item', 'item-search', 'stock-orders', 'stock-order', 'bootstrap', 'discrepancies', 'discrepancy-orders', 'movements', 'documents', 'assign-options', 'timeline']

export function previewTransition(body) {
  return api('inventory/transition/preview', { method: 'POST', body, quiet: true })
}

export function useStatuses() {
  const boot = useBootstrap()
  return useMemo(() => {
    const list = Array.isArray(boot.data?.statuses) && boot.data.statuses.length ? boot.data.statuses : Object.entries(DEFAULT_LABELS).map(([key, label]) => ({ key, label }))
    const labels = Object.fromEntries(list.map((s) => [s.key, s.label]))
    return { list, labels, label: (key) => labels[key] || DEFAULT_LABELS[key] || key || '' }
  }, [boot.data?.statuses])
}

export function wrapPreview(p) {
  if (!p) return { target: null, needs_decision: false, orders: [], skipped: [] }
  if (Array.isArray(p.orders)) return p
  return { target: p.to ?? null, needs_decision: !!p.needs_decision, orders: [p], skipped: [] }
}

function shownOrders(preview, force) {
  return (preview.orders || []).filter((o) => (o.kind === 'cancel' ? !!o.has_inventory : force || !!o.needs_decision))
}

function badgeLine(l) {
  return { ...l, id: l.demand_id, name: l.item_name }
}

function ChoiceGroup({ value, onChange, options, ariaLabel }) {
  return (
    <RadioGroup value={value || null} onChange={onChange} aria-label={ariaLabel} className="flex flex-col gap-1.5">
      {options.map((o) => (
        <Radio key={o.value} value={o.value} className="cjs-choice">
          <Radio.Content className="flex items-start gap-2">
            <Radio.Control className="mt-0.5 shrink-0">
              <Radio.Indicator />
            </Radio.Control>
            <span className="flex min-w-0 flex-col">
              <span className="text-[13px] font-medium text-foreground">{o.label}</span>
              <span className="text-[12px] leading-snug text-muted">{o.description}</span>
            </span>
          </Radio.Content>
        </Radio>
      ))}
    </RadioGroup>
  )
}

function Part({ title, text, children }) {
  return (
    <div className="flex flex-col gap-2">
      <div>
        <div className="text-[12px] font-semibold uppercase tracking-wide text-muted">{title}</div>
        {text && <p className="mt-0.5 text-[12.5px] text-muted">{text}</p>}
      </div>
      <ul className="flex flex-col divide-y divide-separator bg-surface-secondary/60">{children}</ul>
    </div>
  )
}

function StatusOrderBody({ order, choice, setChoice, autoText }) {
  const { categoryById } = useCatalog()
  const lineQty = (v, l) => fineQty(v, l.unit, defaultFineness(l, categoryById[l.category_id]))
  const lines = choice.lines || {}
  const fixes = choice.returnables || {}
  return (
    <div className="flex flex-col gap-4">
      {order.shortages?.length > 0 && (
        <Part title="Not ready" text={autoText}>
          {order.shortages.map((l) => (
            <li key={l.demand_id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
              <InventoryBadge line={badgeLine(l)} />
              <span className="text-[12px] text-muted">Auto-correct: +{lineQty(l.found_qty, l)} found</span>
            </li>
          ))}
        </Part>
      )}
      {order.used_lines?.length > 0 && (
        <Part title="Used stock" text="The order goes back past the status where this stock was used. Decide line by line whether the used stock is written off or returned to stock.">
          {order.used_lines.map((l) => (
            <li key={l.demand_id} className="flex flex-col gap-2 px-3 py-2.5">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <InventoryBadge line={badgeLine(l)} />
                <span className="text-[12px] text-muted">Used {lineQty(l.used_qty, l)}</span>
              </div>
              <ChoiceGroup ariaLabel={`What happens to the stock of ${l.item_name}`} value={lines[l.demand_id]} options={USED_CHOICES} onChange={(v) => setChoice({ ...choice, lines: { ...lines, [l.demand_id]: v } })} />
            </li>
          ))}
        </Part>
      )}
      {order.returnables?.length > 0 && (
        <Part title="Returnable items" text="These items did not follow the expected flow (sent, then returned).">
          {order.returnables.map((l) => {
            const f = fixes[l.demand_id] || {}
            const options = RETURNABLE_CHOICES[l.case] || RETURNABLE_CHOICES.never_sent
            const askPiece = l.needs_piece && f.fix && f.fix !== 'detach'
            const askBatch = l.needs_batch && f.fix === 'lost'
            return (
              <li key={l.demand_id} className="flex flex-col gap-2 px-3 py-2.5">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                  <InventoryBadge line={badgeLine(l)} />
                  <span className="text-[12px] text-muted">{l.case === 'not_returned' ? `Sent${l.piece?.identifier ? ` (${l.piece.identifier})` : ''}, not returned` : 'Never sent'}</span>
                </div>
                <ChoiceGroup ariaLabel={`What happened to ${l.item_name}`} value={f.fix} options={options} onChange={(v) => setChoice({ ...choice, returnables: { ...fixes, [l.demand_id]: { ...f, fix: v } } })} />
                <Expand open={!!askPiece}>
                  <div className="flex flex-col gap-1.5 pt-1">
                    <span className="text-[12px] font-medium text-foreground/80">Which piece was it?</span>
                    <SelectField
                      size="sm"
                      className="max-w-[260px]"
                      value={f.piece_id ? String(f.piece_id) : ''}
                      placeholder="Choose a piece"
                      ariaLabel="Which piece was it?"
                      options={(l.piece_options || []).map((p) => ({ value: String(p.id), label: p.identifier || `#${p.id}` }))}
                      onChange={(v) => setChoice({ ...choice, returnables: { ...fixes, [l.demand_id]: { ...f, piece_id: Number(v) || null } } })}
                    />
                  </div>
                </Expand>
                <Expand open={!!askBatch}>
                  <div className="flex flex-wrap items-end gap-3 pt-1">
                    <div className="flex flex-col gap-1.5">
                      <span className="text-[12px] font-medium text-foreground/80">Which lot was it?</span>
                      <SelectField
                        size="sm"
                        className="w-[300px] max-w-full"
                        value={f.batch_id ? String(f.batch_id) : ''}
                        placeholder="Choose a lot"
                        ariaLabel="Which lot was it?"
                        options={(l.batch_options || []).map((b) => ({ value: String(b.id), label: `${qty(b.free, l.unit)} available${b.fineness ? ` · ${qty(b.fineness)}` : ''}${b.received_at ? ` · received ${String(b.received_at).slice(0, 10)}` : ''}` }))}
                        onChange={(v) => setChoice({ ...choice, returnables: { ...fixes, [l.demand_id]: { ...f, batch_id: Number(v) || null } } })}
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <span className="text-[12px] font-medium text-foreground/80">{`How much was lost? (${l.unit})`}</span>
                      <NumberInput size="sm" className="w-28" min="0" step={l.per_piece ? '1' : 'any'} value={f.qty ?? String(l.missing ?? l.qty ?? '')} onChange={(v) => setChoice({ ...choice, returnables: { ...fixes, [l.demand_id]: { ...f, qty: v } } })} aria-label="How much was lost?" />
                    </div>
                  </div>
                </Expand>
              </li>
            )
          })}
        </Part>
      )}
    </div>
  )
}

function stockIds(order) {
  return (order.products || []).flatMap((p) => p.lines.filter((l) => l.has_stock).map((l) => l.demand_id))
}

function triState(ids, set) {
  const n = ids.filter((id) => set.has(id)).length
  return n === 0 ? 'none' : n === ids.length ? 'all' : 'some'
}

function CancelOrderBody({ order, picked, setPicked }) {
  return (
    <div className="flex flex-col gap-2">
      {(order.products || []).map((p, i) => {
        const ids = p.lines.filter((l) => l.has_stock).map((l) => l.demand_id)
        const sel = {
          state: triState(ids, picked),
          toggleAll: () => {
            const on = triState(ids, picked) !== 'all'
            const next = new Set(picked)
            for (const id of ids) on ? next.add(id) : next.delete(id)
            setPicked(next)
          },
        }
        return (
          <div key={`${p.order_item_id || 'order'}-${i}`} className="bg-surface-secondary/60 px-3 py-2">
            <div className="flex items-center gap-2.5">
              {ids.length > 0 ? <SelectAll selection={sel} ariaLabel={`Select all inventory of ${p.name || 'the whole order'}`} /> : <span className="w-4 shrink-0" />}
              <span className="min-w-0 truncate text-[13px] font-semibold">{p.name || 'Whole order'}</span>
            </div>
            <ul className="mt-1.5 flex flex-col gap-1 pl-6">
              {p.lines.map((l) => (
                <li key={l.demand_id} className="flex min-w-0 items-center gap-2.5">
                  {l.has_stock ? (
                    <Check
                      checked={picked.has(l.demand_id)}
                      ariaLabel={`Write off ${l.item_name}`}
                      onChange={(v) => {
                        const next = new Set(picked)
                        v ? next.add(l.demand_id) : next.delete(l.demand_id)
                        setPicked(next)
                      }}
                    />
                  ) : (
                    <span className="w-4 shrink-0" />
                  )}
                  <InventoryBadge line={badgeLine(l)} />
                </li>
              ))}
            </ul>
          </div>
        )
      })}
    </div>
  )
}

function OrderSection({ order, open, onToggle, head, children, label }) {
  return (
    <div className="ring-1 ring-separator">
      <div className="flex items-center gap-2.5 px-3 py-2">
        {head}
        <div className="min-w-0 flex-1">
          <div className="truncate text-[13.5px] font-semibold text-foreground">
            #{order.number}
            {order.customer ? <span className="font-normal text-muted"> · {order.customer}</span> : null}
          </div>
          {label && <div className="text-[12px] text-muted">{label}</div>}
        </div>
        <IconButton icon={open ? ChevronUp : ChevronDown} label={open ? 'Hide details' : 'Show details'} variant="secondary" onPress={onToggle} />
      </div>
      <Expand open={open}>
        <div className="border-t border-separator p-3">{children}</div>
      </Expand>
    </div>
  )
}

function StockDialogBody({ req, onCancel, onConfirm, busy }) {
  const { label } = useStatuses()
  const preview = req.preview
  const orders = useMemo(() => shownOrders(preview, req.force), [preview, req.force])
  const cancelKind = orders[0]?.kind === 'cancel' || WOO_TARGETS.includes(preview.target)
  const [open, setOpen] = useState(() => new Set(orders.length ? [orders[0].order_id] : []))
  const [picked, setPicked] = useState(() => new Set())
  const [choices, setChoices] = useState({})
  const toggle = (id) => setOpen((s) => {
    const n = new Set(s)
    n.has(id) ? n.delete(id) : n.add(id)
    return n
  })
  const hasShortages = orders.some((o) => o.shortages?.length > 0)
  const missingChoice = !cancelKind && orders.some((o) => {
    const c = choices[o.order_id] || {}
    const usedOk = (o.used_lines || []).every((l) => (c.lines || {})[l.demand_id])
    const retOk = (o.returnables || []).every((l) => {
      const f = (c.returnables || {})[l.demand_id]
      if (!f || !f.fix) return false
      if (l.needs_batch && f.fix === 'lost' && (!f.batch_id || (f.qty !== undefined && !(Number(f.qty) > 0)))) return false
      return !(l.needs_piece && f.fix !== 'detach' && !f.piece_id)
    })
    return !usedOk || !retOk
  })
  const confirm = () => {
    const decisions = {}
    for (const o of preview.orders || []) {
      if (o.kind === 'cancel') {
        decisions[o.order_id] = { write_off: stockIds(o).filter((id) => picked.has(id)) }
      } else {
        const c = choices[o.order_id] || {}
        const d = {}
        if (o.shortages?.length) d.auto_correct = true
        if (o.used_lines?.length) d.lines = c.lines || {}
        if (o.returnables?.length) d.returnables = c.returnables || {}
        decisions[o.order_id] = d
      }
    }
    onConfirm(decisions)
  }
  const first = orders[0]
  const title = req.title || (cancelKind ? CANCEL_TITLES[preview.target || first?.to] || 'Inventory' : first && first.direction === 'same' ? `Inventory at ${label(first.to)}` : `Change status to ${label(first?.to || preview.target)}`)
  const cancelWord = req.cancelLabel || 'Cancel'
  const moving = orders.some((o) => o.kind !== 'cancel' && o.from && o.to && o.from !== o.to)
  const autoText = moving ? `${cancelWord} keeps the current status so you can fix the inventory by hand: fill or add to stock orders, receive, or count stock. Auto-correct officially corrects the shortages itself, and the status changes.` : `${cancelWord} leaves everything as it is so you can fix the inventory by hand: fill or add to stock orders, receive, or count stock. Auto-correct officially corrects the shortages itself.`
  const confirmLabel = !cancelKind && hasShortages ? 'Auto-correct' : req.confirmLabel || 'Continue'
  return (
    <Dialog
      isOpen={req.open}
      onClose={onCancel}
      isDismissable={false}
      size="lg"
      title={title}
      footer={
        <>
          <Button variant="tertiary" onPress={onCancel} isDisabled={busy}>{cancelWord}</Button>
          <Btn variant={cancelKind ? 'danger' : 'primary'} isPending={busy} isDisabled={missingChoice} onPress={confirm}>{confirmLabel}</Btn>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {req.message && <Notice tone="warning">{req.message}</Notice>}
        {cancelKind && <p className="text-[13.5px] text-foreground">{orders.length > 1 ? CANCEL_TEXT_MANY : CANCEL_TEXT}</p>}
        {orders.map((o) => {
          const isOpen = open.has(o.order_id)
          if (o.kind === 'cancel') {
            const ids = stockIds(o)
            const sel = {
              state: triState(ids, picked),
              toggleAll: () => {
                const on = triState(ids, picked) !== 'all'
                setPicked((s) => {
                  const next = new Set(s)
                  for (const id of ids) on ? next.add(id) : next.delete(id)
                  return next
                })
              },
            }
            return (
              <OrderSection key={o.order_id} order={o} open={isOpen} onToggle={() => toggle(o.order_id)} head={<SelectAll selection={sel} ariaLabel={`Select all inventory of #${o.number}`} />}>
                <CancelOrderBody order={o} picked={picked} setPicked={setPicked} />
              </OrderSection>
            )
          }
          const hasBody = o.shortages?.length || o.used_lines?.length || o.returnables?.length
          const statusLabel = o.from && o.to && o.from !== o.to ? `${label(o.from)} → ${label(o.to)}` : label(o.to)
          if (!hasBody) {
            return (
              <div key={o.order_id} className="px-3 py-2 text-[13px] ring-1 ring-separator">
                <span className="font-semibold">#{o.number}</span>
                {o.customer ? <span className="text-muted"> · {o.customer}</span> : null}
                <span className="text-muted"> · {statusLabel}</span>
              </div>
            )
          }
          return (
            <OrderSection key={o.order_id} order={o} open={isOpen} onToggle={() => toggle(o.order_id)} label={statusLabel}>
              <StatusOrderBody order={o} choice={choices[o.order_id] || {}} setChoice={(c) => setChoices((s) => ({ ...s, [o.order_id]: c }))} autoText={autoText} />
            </OrderSection>
          )
        })}
      </div>
    </Dialog>
  )
}

const Ctx = createContext(null)

export function StockModalProvider({ children }) {
  const [req, setReq] = useState(null)
  const [busy, setBusy] = useState(false)
  const resolver = useRef(null)
  const seq = useRef(0)
  const ask = useCallback((opts) => new Promise((resolve) => {
    if (resolver.current) resolver.current(null)
    resolver.current = resolve
    seq.current += 1
    setBusy(false)
    setReq({ ...opts, preview: wrapPreview(opts.preview), id: seq.current, open: true })
  }), [])
  const finish = (value) => {
    const r = resolver.current
    resolver.current = null
    setReq((s) => (s ? { ...s, open: false } : s))
    setBusy(false)
    if (r) r(value)
  }
  const onConfirm = async (decisions) => {
    if (!req) return
    if (!req.onConfirm) {
      finish(decisions)
      return
    }
    setBusy(true)
    try {
      const result = await req.onConfirm(decisions)
      finish(result ?? true)
    } catch (e) {
      setBusy(false)
      if (e && e.code === 'cjs_stock_conflicts' && e.data?.preview) {
        notifyError(new Error('The inventory changed in the meantime. Check the lines again.'))
        seq.current += 1
        setReq((s) => (s ? { ...s, preview: wrapPreview(e.data.preview), id: seq.current } : s))
        return
      }
      notifyError(e)
    }
  }
  return (
    <Ctx.Provider value={ask}>
      {children}
      {req && <StockDialogBody key={req.id} req={req} busy={busy} onCancel={() => finish(null)} onConfirm={onConfirm} />}
    </Ctx.Provider>
  )
}

export function useStockModal() {
  return useContext(Ctx)
}

function applyBody(ids, target, decisions, extra) {
  if (ids.length === 1) return { order_id: ids[0], target, decisions: decisions[ids[0]] || decisions[String(ids[0])] || {}, ...extra }
  return { order_ids: ids, target, decisions, ...extra }
}

export function useStockFlow() {
  const ask = useStockModal()
  const qc = useQueryClient()
  const refresh = useCallback(() => {
    for (const k of INVALIDATE) qc.invalidateQueries({ queryKey: [k] })
  }, [qc])
  const resolve = useCallback(async (rawPreview, { orderIds, target, extra = {}, force, run, ...view } = {}) => {
    const preview = wrapPreview(rawPreview)
    const ids = (orderIds && orderIds.length ? orderIds : (preview.orders || []).map((o) => o.order_id)).map(Number)
    const tgt = target || preview.target || preview.orders?.[0]?.to
    const doApply = run || (async (decisions) => {
      const res = await post('inventory/transition/apply', applyBody(ids, tgt, decisions, extra))
      refresh()
      return res || { ok: true }
    })
    if (!shownOrders(preview, force).length) return doApply({})
    return ask({ ...view, preview, force, onConfirm: doApply })
  }, [ask, refresh])
  const transition = useCallback(async ({ orderIds, target, ...rest }) => {
    const ids = orderIds.map(Number)
    const body = ids.length === 1 ? { order_id: ids[0] } : { order_ids: ids }
    if (target) body.target = target
    const preview = await previewTransition(body)
    return resolve(preview, { orderIds: ids, target, ...rest })
  }, [resolve])
  const fix = useCallback((order) => {
    const id = Number(order?.id ?? order?.order_id)
    return transition({ orderIds: [id], target: order?.fix?.target, extra: { perform: false }, cancelLabel: 'Close', title: 'Fix inventory' })
  }, [transition])
  const resolveConflicts = useCallback(async (list) => {
    let applied = 0
    for (const c of Array.isArray(list) ? list : []) {
      if (!c || !c.preview) continue
      try {
        const res = await resolve(c.preview, { orderIds: [c.order_id ?? c.preview.order_id], message: c.message })
        if (res) applied += 1
      } catch (e) {
        notifyError(e)
      }
    }
    return applied
  }, [resolve])
  return useMemo(() => ({ ask, resolve, transition, fix, refresh, resolveConflicts }), [ask, resolve, transition, fix, refresh, resolveConflicts])
}

export function useAskRemoveReturned() {
  const confirm = useConfirm()
  return async (demandId, name) => {
    const ok = await confirm({
      title: `${name || 'The item'} was returned`,
      message: "Remove it from the order's inventory too? Yes: it no longer counts as missing or as a conflict. It is never deleted, so its history stays. No: the line stays at Waiting availability so another one can be assigned.",
      confirmLabel: 'Yes, remove',
      cancelLabel: 'No',
    })
    if (!ok) return false
    try {
      await post(`demands/${demandId}/remove-returned`)
      return true
    } catch (e) {
      notifyError(e)
      return false
    }
  }
}

export function FixButton({ order, onDone, children = 'Fix', size = 'sm', variant = 'primary', className }) {
  const { fix } = useStockFlow()
  const [busy, setBusy] = useState(false)
  const run = async () => {
    setBusy(true)
    try {
      const res = await fix(order)
      if (res && onDone) onDone(res)
    } catch (e) {
      notifyError(e)
    } finally {
      setBusy(false)
    }
  }
  return <Btn size={size} variant={variant} className={cx(className)} isPending={busy} isDisabled={!order?.fix} onPress={run}>{children}</Btn>
}
