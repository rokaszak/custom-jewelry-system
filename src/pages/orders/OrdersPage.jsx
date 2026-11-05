import { Fragment, Suspense, lazy, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { Button } from '@heroui/react'
import { toast } from '../../lib/toast'
import { Magnifier, Funnel, FunnelXmark, ArrowUpRightFromSquare, ChevronDown, ChevronUp, ArrowsRotateRight, Paperclip, CircleCheck } from '@gravity-ui/icons'
import { get, post } from '../../lib/api'
import { useBootstrap, useDebounced, setUrlParams, useElementWidth, notifyError } from '../../lib/hooks'
import { hoursLt, ordersWordLt } from '../../lib/format'
import { WooLink } from '../../components/OrderLink'
import { navigate } from '../../lib/nav'
import { Page, TextInput, MultiFilter, SelectField, DateInput, Field, Empty, SkBox, SkChip, SkeletonRows, SkLine, skw, Pager, Stat, IconButton, HexChip, Check, Expand, useSelection, SelectAll, BulkBar, useConfirm, fromInside, cx } from '../../components/ui'
import { OrderActionsProvider, useOrderActions, patchOrderCaches, LOCK_TEXT } from './orderActions'
import { useStockFlow, previewTransition } from '../../components/StockModal'
import { DatesEditor, HoursEditor, FlagsEditor, StatusSelect, TypeSelect, CastingNotes, InventoryList } from './OrderFields'
import OrderPanel from './OrderPanel'
import StockOrderDialog from './StockOrderDialog'

function StockOrderFallback({ id, isOpen }) {
  useEffect(() => {
    if (isOpen && id) navigate('stock-orders', { so: id })
  }, [isOpen, id])
  return null
}

const ItemDetailModal = lazy(() => import('../inventory/ItemDetail').then((m) => ({ default: m.ItemDetailModal })))
const StockOrderModal = lazy(() => import('../stock-orders/StockOrderDetail').then((m) => ({ default: m.StockOrderModal || StockOrderFallback })))

const KEYS = ['search', 'status', 'wc_status', 'order_type', 'order_model', 'order_production', 'order_printing', 'inventory', 'stock_order_status', 'size_kit', 'manufacture_from', 'manufacture_to', 'finish_from', 'finish_to', 'deliver_from', 'deliver_to']
const LIST_KEYS = ['status', 'wc_status', 'stock_order_status']

function readFilters() {
  const p = new URL(window.location.href).searchParams
  const f = {}
  for (const k of KEYS) {
    const v = p.get(k)
    f[k] = LIST_KEYS.includes(k) ? (v ? v.split(',').filter((x) => k !== 'wc_status' || x !== 'completed') : []) : v || ''
  }
  return f
}

const TRI = [
  { value: '', label: 'Any' },
  { value: '1', label: 'Yes' },
  { value: '0', label: 'No' },
]

const INVENTORY_FILTER = [
  { value: 'missing', label: 'Missing, not ordered' },
  { value: 'ordered', label: 'Ordered, not received' },
  { value: 'waiting', label: 'Missing or ordered' },
  { value: 'covered', label: 'Everything assigned' },
  { value: 'any', label: 'Has inventory' },
  { value: 'none', label: 'No inventory' },
]

const KIT_FILTER = [
  { value: 'missing', label: 'Requested, not assigned' },
  { value: 'to_send', label: 'To send' },
  { value: 'sent', label: 'Sent' },
  { value: 'returned', label: 'Returned' },
]

const W = { sel: 34, terms: 268, work: 146, status: 160, actions: 48, order: 128, inv: 132, liej: 132 }
const TABLE_MIN = W.sel + W.terms + W.work + W.status + W.actions + W.order + W.inv + W.liej

function columnWidths(width) {
  const extra = Math.max(0, (width || TABLE_MIN) - TABLE_MIN)
  return {
    order: Math.round(W.order + Math.min(92, extra * 0.15)),
    inv: Math.round(W.inv + Math.min(268, extra * 0.4)),
  }
}

export function isInteractive(target) {
  return !!target.closest('input,button,a,select,textarea,label,[role="button"],[role="listbox"],[role="option"],[role="checkbox"],[data-no-expand]')
}

export function usePresence(open, ms = 320) {
  const [mounted, setMounted] = useState(open)
  const [shown, setShown] = useState(false)
  useEffect(() => {
    if (open) {
      setMounted(true)
      let inner = 0
      const outer = requestAnimationFrame(() => {
        inner = requestAnimationFrame(() => setShown(true))
      })
      return () => {
        cancelAnimationFrame(outer)
        cancelAnimationFrame(inner)
      }
    }
    setShown(false)
    const t = setTimeout(() => setMounted(false), ms)
    return () => clearTimeout(t)
  }, [open, ms])
  return [mounted, shown]
}

function WooStatus({ o }) {
  if (!o.wc_status) return null
  return <HexChip color={o.wc_status_color} bg={o.wc_status_bg} label={o.wc_status_label || o.wc_status} />
}

export function OrderCell({ o }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <div className="flex flex-wrap items-center gap-1.5">
        <WooLink href={o.edit_url} id={o.id} className="text-[13.5px] font-semibold text-foreground hover:text-accent">#{o.number}</WooLink>
        <WooStatus o={o} />
      </div>
      {o.customer && <div className="truncate text-[12.5px] text-foreground/80">{o.customer}</div>}
      {o.items?.length > 0 && (
        <div className="line-clamp-2 text-[11.5px] text-muted">{o.items.map((i) => `${i.name}${i.qty > 1 ? ` ×${i.qty}` : ''}`).join(', ')}</div>
      )}
      {o.file_count > 0 && (
        <span className="inline-flex items-center gap-0.5 text-[11.5px] text-muted">
          <Paperclip className="size-3" />
          {o.file_count}
        </span>
      )}
    </div>
  )
}

export function Lock({ o, children }) {
  if (!o.locked) return children
  return (
    <div inert className="opacity-70" title={LOCK_TEXT}>
      {children}
    </div>
  )
}

function RowActions({ o, expanded, onToggle, vertical }) {
  const { completeOrder } = useOrderActions()
  return (
    <div className={cx('flex items-center gap-1', vertical && 'flex-col')} data-no-expand>
      {!o.locked && <IconButton icon={CircleCheck} label="Complete order" variant="secondary" className="bg-success text-white hover:bg-success/90 hover:text-white" onPress={() => completeOrder(o)} />}
      <IconButton icon={ArrowUpRightFromSquare} label="Open in WooCommerce" variant="secondary" onPress={() => o.edit_url && window.open(o.edit_url, '_blank', 'noopener')} />
      <IconButton icon={expanded ? ChevronUp : ChevronDown} label={expanded ? 'Hide details' : 'Show details'} variant="secondary" onPress={onToggle} />
    </div>
  )
}

function ExpandedRow({ o, open, viewWidth, onChanged }) {
  const [mounted, shown] = usePresence(open)
  if (!mounted) return null
  return (
    <tr className="cjs-row-expanded">
      <td colSpan={8}>
        <Expand open={shown}>
          <div className="sticky left-0" style={viewWidth ? { width: viewWidth } : undefined}>
            <OrderPanel orderId={o.id} onChanged={onChanged} />
          </div>
        </Expand>
      </td>
    </tr>
  )
}

export function CardExpand({ o, open, onChanged }) {
  const [mounted, shown] = usePresence(open)
  if (!mounted) return null
  return (
    <Expand open={shown}>
      <div className="border-t border-separator bg-surface-secondary/50">
        <OrderPanel orderId={o.id} onChanged={onChanged} />
      </div>
    </Expand>
  )
}

function SkTerms() {
  return (
    <div className="flex gap-3.5">
      <div className="w-[41px] shrink-0"><SkLine size={13} w={32} /></div>
      <div className="flex min-w-0 flex-1 flex-col">
        {[0, 1, 2, 3].map((n) => (
          <div key={n} className="flex h-[38px] items-center gap-2">
            <SkLine size={11} w={[52, 70, 74, 62][n]} className="w-[76px] shrink-0" />
            <SkBox w="100%" h={32} className="min-w-0 flex-1" />
          </div>
        ))}
        <div className="flex h-[26px] items-end"><SkLine size={13} w={90} /></div>
      </div>
    </div>
  )
}

function SkWork() {
  return (
    <div className="flex flex-col gap-1.5">
      <SkBox w="100%" h={32} />
      <SkBox w="100%" h={8} />
    </div>
  )
}

function SkFlags({ vertical }) {
  return (
    <div className={cx('flex gap-x-4 gap-y-1.5', vertical ? 'flex-col' : 'flex-wrap')}>
      {[78, 86, 48].map((w, n) => (
        <div key={n} className="flex h-[18px] items-center gap-2">
          <SkBox w={16} h={16} />
          <SkLine size={13} w={w} />
        </div>
      ))}
    </div>
  )
}

function SkOrderCell({ i }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <div className="flex items-center gap-1.5">
        <SkLine size={13.5} w={30} />
        <SkChip w={70} />
      </div>
      <SkLine size={12.5} w={skw(i, 70)} />
      <SkLine size={11.5} w={skw(i + 2, 85)} />
      <SkLine size={11.5} w={skw(i + 5, 40)} />
    </div>
  )
}

function SkInventory({ i }) {
  return (
    <div className="flex flex-col items-start gap-1">
      {Array.from({ length: [1, 2, 1, 3][i % 4] }).map((_, n) => <SkChip key={n} w={skw(i + n, 88)} />)}
    </div>
  )
}

function DesktopTable({ orders, loading, statuses, types, expanded, toggle, highlight, viewWidth, selection, onRowChanged, onOpenStockOrder, onOpenItem, onOrderMissing }) {
  const cols = columnWidths(viewWidth)
  return (
    <table className="cjs-table cjs-orders-table table-fixed">
      <thead>
        <tr>
          <th style={{ width: W.sel }}><SelectAll selection={selection} ariaLabel="Select all orders" /></th>
          <th style={{ width: cols.order }}>Order</th>
          <th style={{ width: W.terms }}>Terminai</th>
          <th style={{ width: W.work }}>Darbas (h) · užsakyta</th>
          <th style={{ width: cols.inv }}>Inventorius</th>
          <th>Liejimas</th>
          <th style={{ width: W.status }}>Statusas · tipas</th>
          <th className="sticky right-0 z-[4] px-2!" style={{ width: W.actions }}><span className="sr-only">Veiksmai</span></th>
        </tr>
      </thead>
      <tbody>
        {loading && (
          <SkeletonRows rows={6}>
            {(i) => (
              <>
                <td><SkBox w={16} h={16} /></td>
                <td><SkOrderCell i={i} /></td>
                <td><SkTerms /></td>
                <td>
                  <div className="flex flex-col gap-2.5">
                    <SkWork />
                    <SkFlags vertical />
                  </div>
                </td>
                <td><SkInventory i={i} /></td>
                <td><SkBox w="100%" h={178} /></td>
                <td>
                  <div className="flex flex-col gap-1.5">
                    <SkBox w="100%" h={32} />
                    <SkBox w="100%" h={32} />
                  </div>
                </td>
                <td className="sticky right-0 z-[1] bg-surface px-2! shadow-[-1px_0_0_var(--separator)]">
                  <div className="flex flex-col items-center gap-1">
                    <SkBox w={32} h={32} />
                    <SkBox w={32} h={32} />
                    <SkBox w={32} h={32} />
                  </div>
                </td>
              </>
            )}
          </SkeletonRows>
        )}
        {orders.map((o) => {
          const open = expanded.has(o.id)
          return (
            <Fragment key={o.id}>
              <tr
                id={`order${o.id}`}
                className={cx('cjs-row-click', open && 'cjs-row-open', highlight === o.id && 'cjs-row-highlight')}
                onClick={(e) => fromInside(e) && !isInteractive(e.target) && toggle(o.id)}
              >
                <td>
                  <Check checked={selection.isSelected(o.id)} onChange={() => selection.toggle(o.id)} ariaLabel={`Select order #${o.number}`} />
                </td>
                <td><OrderCell o={o} /></td>
                <td><Lock o={o}><DatesEditor order={o} /></Lock></td>
                <td>
                  <Lock o={o}>
                    <div className="flex flex-col gap-2.5">
                      <HoursEditor order={o} />
                      <FlagsEditor order={o} vertical />
                    </div>
                  </Lock>
                </td>
                <td><InventoryList order={o} onOpen={() => toggle(o.id, true)} onOpenStockOrder={onOpenStockOrder} onOpenItem={onOpenItem} onOrderMissing={onOrderMissing} /></td>
                <td className="cjs-fill-cell"><Lock o={o}><CastingNotes order={o} rows={1} fill /></Lock></td>
                <td>
                  <Lock o={o}>
                    <div className="flex min-w-0 flex-col gap-1.5">
                      <StatusSelect order={o} statuses={statuses} />
                      <TypeSelect order={o} types={types} />
                    </div>
                  </Lock>
                </td>
                <td className="sticky right-0 z-[1] bg-surface px-2! shadow-[-1px_0_0_var(--separator)]"><RowActions o={o} expanded={open} onToggle={() => toggle(o.id)} vertical /></td>
              </tr>
              <ExpandedRow o={o} open={open} viewWidth={viewWidth} onChanged={() => onRowChanged(o.id)} />
            </Fragment>
          )
        })}
      </tbody>
    </table>
  )
}

function SkField({ label = 60, children, className }) {
  return (
    <div className={cx('flex min-w-0 flex-col gap-1.5', className)}>
      <SkLine size={12} w={label} />
      {children}
    </div>
  )
}

function MobileSkeleton() {
  return (
    <div className="grid grid-cols-1 items-start gap-3 p-3" aria-hidden="true">
      {[0, 1, 2, 3].map((i) => (
        <article key={i} className="cjs-card @container min-w-0 overflow-hidden">
          <div className="flex items-start justify-between gap-2 p-3.5 pb-2">
            <div className="flex min-w-0 flex-1 items-start gap-2">
              <div className="pt-0.5"><SkBox w={16} h={16} /></div>
              <div className="min-w-0 flex-1"><SkOrderCell i={i} /></div>
            </div>
            <div className="flex items-center gap-1">
              <SkBox w={36} h={36} />
              <SkBox w={36} h={36} />
              <SkBox w={36} h={36} />
            </div>
          </div>
          <div className="grid gap-3 px-3.5 pb-3.5 @md:grid-cols-2">
            <SkField label={56}><SkBox w="100%" h={32} /></SkField>
            <SkField label={92}><SkBox w="100%" h={32} /></SkField>
            <div className="@md:col-span-2"><SkTerms /></div>
            <SkField label={64}><SkWork /></SkField>
            <SkField label={58}><SkFlags /></SkField>
            <SkField label={70} className="@md:col-span-2"><SkInventory i={i} /></SkField>
            <SkField label={56} className="@md:col-span-2"><SkBox w="100%" h={51} /></SkField>
          </div>
        </article>
      ))}
    </div>
  )
}

function MobileCards({ orders, statuses, types, expanded, toggle, highlight, selection, onRowChanged, onOpenStockOrder, onOpenItem, onOrderMissing }) {
  return (
    <div className="grid grid-cols-1 items-start gap-3 p-3">
      {orders.map((o) => {
        const open = expanded.has(o.id)
        return (
          <article id={`order${o.id}`} key={o.id} className={cx('cjs-card @container min-w-0 overflow-hidden', highlight === o.id && 'ring-2 ring-warning')}>
            <div className="flex items-start justify-between gap-2 p-3.5 pb-2">
              <div className="flex min-w-0 items-start gap-2">
                <div className="pt-0.5">
                  <Check checked={selection.isSelected(o.id)} onChange={() => selection.toggle(o.id)} ariaLabel={`Select order #${o.number}`} />
                </div>
                <OrderCell o={o} />
              </div>
              <RowActions o={o} expanded={open} onToggle={() => toggle(o.id)} />
            </div>
            <Lock o={o}>
            <div className="grid gap-3 px-3.5 pb-3.5 @md:grid-cols-2">
              <Field label="Statusas">
                <StatusSelect order={o} statuses={statuses} />
              </Field>
              <Field label="Užsakymo tipas">
                <TypeSelect order={o} types={types} />
              </Field>
              <div className="@md:col-span-2">
                <DatesEditor order={o} />
              </div>
              <Field label="Darbas (h)">
                <HoursEditor order={o} />
              </Field>
              <div className="flex min-w-0 flex-col gap-1.5">
                <span className="text-[12px] font-medium text-foreground/80">Užsakyta</span>
                <FlagsEditor order={o} />
              </div>
              <div className="@md:col-span-2">
                <Field label="Inventorius">
                  <InventoryList order={o} onOpen={() => toggle(o.id, true)} onOpenStockOrder={onOpenStockOrder} onOpenItem={onOpenItem} onOrderMissing={onOrderMissing} />
                </Field>
              </div>
              <div className="@md:col-span-2">
                <Field label="Liejimas">
                  <CastingNotes order={o} />
                </Field>
              </div>
            </div>
            </Lock>
            <CardExpand o={o} open={open} onChanged={() => onRowChanged(o.id)} />
          </article>
        )
      })}
    </div>
  )
}

function OrdersBulkBar({ selection, statuses, types, onDone }) {
  const qc = useQueryClient()
  const confirm = useConfirm()
  const flow = useStockFlow()
  const [busy, setBusy] = useState(false)
  const statusOptions = useMemo(() => statuses.filter((s) => s.key !== 'done').map((s) => ({ value: s.key, label: s.label })), [statuses])
  const typeOptions = useMemo(() => types.map((t) => ({ value: t, label: t })), [types])
  const apply = async (field, raw) => {
    if (!raw) return
    const value = raw
    const label = field === 'manufacturing_status' ? 'Statusas' : 'Užsakymo tipas'
    const shown = field === 'manufacturing_status' ? (statuses.find((s) => s.key === value)?.label || value) : value
    const ids = selection.ids.map(Number)
    const ok = await confirm({
      title: `Change ${label} for ${ids.length} ${ids.length === 1 ? 'order' : 'orders'}?`,
      message: `${label} becomes "${shown}" on every selected order.`,
      confirmLabel: 'Change',
      cancelLabel: 'Cancel',
    })
    if (!ok) return
    setBusy(true)
    try {
      const res = await post('orders/bulk', { ids, fields: { [field]: value } })
      for (const o of res.orders || []) patchOrderCaches(qc, o)
      let done = (res.orders || []).length
      let failed = (res.failed || []).length
      if (field === 'manufacturing_status' && res.failed?.length) {
        const preview = await previewTransition({ order_ids: res.failed, target: value })
        const need = (preview.orders || []).filter((o) => o.needs_decision && o.wc_status !== 'completed')
        if (need.length) {
          const result = await flow.resolve({ ...preview, orders: need }, { orderIds: need.map((o) => o.order_id), target: value })
          if (result) {
            done += need.length
            failed -= need.length
          }
        }
      }
      if (done > 0) toast.success(`${label} changed on ${done} ${done === 1 ? 'order' : 'orders'}`)
      if (failed > 0) toast.warning(`${failed} could not be changed`)
      selection.clear()
      if (onDone) onDone()
    } catch (e) {
      notifyError(e)
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className="sticky top-[var(--wp-admin--admin-bar--height,32px)] z-[5]">
      <BulkBar count={selection.count} onClear={selection.clear} label={(n) => `Pažymėta: ${n}`} clearLabel="Atžymėti">
        <SelectField size="sm" className="w-[200px]" value="" placeholder="Set Statusas" ariaLabel="Set Statusas" options={statusOptions} isDisabled={busy} onChange={(v) => apply('manufacturing_status', v)} />
        <SelectField size="sm" className="w-[200px]" value="" placeholder="Set Užsakymo tipas" ariaLabel="Set Užsakymo tipas" options={typeOptions} isDisabled={busy} onChange={(v) => apply('order_type', v)} />
      </BulkBar>
    </div>
  )
}

export default function OrdersPage() {
  const qc = useQueryClient()
  const boot = useBootstrap()
  const [measureRef, containerWidth] = useElementWidth()
  const wide = containerWidth >= TABLE_MIN
  const [filters, setFilters] = useState(readFilters)
  const [search, setSearch] = useState(filters.search)
  const debouncedSearch = useDebounced(search, 300)
  const [page, setPage] = useState(() => Number(new URL(window.location.href).searchParams.get('pg')) || 1)
  const [more, setMore] = useState(false)
  const [expanded, setExpanded] = useState(() => new Set())
  const [stockOrder, setStockOrder] = useState({ id: null, open: false })
  const locateRef = useRef(Number(new URL(window.location.href).searchParams.get('order') || new URL(window.location.href).searchParams.get('highlight_order')) || 0)
  const [highlight, setHighlight] = useState(locateRef.current || null)

  useEffect(() => {
    setFilters((f) => (f.search === debouncedSearch ? f : { ...f, search: debouncedSearch }))
  }, [debouncedSearch])

  useEffect(() => {
    setUrlParams({ ...filters, pg: page > 1 ? page : '' }, { replace: true })
  }, [filters, page])

  const update = (patch) => {
    setPage(1)
    setFilters((f) => ({ ...f, ...patch }))
  }

  const query = useQuery({
    queryKey: ['orders', filters, page, locateRef.current],
    queryFn: () => get('orders', { ...filters, page, per_page: 20, locate: locateRef.current || undefined }),
    placeholderData: keepPreviousData,
  })

  useEffect(() => {
    const id = locateRef.current
    if (!id || !query.data) return
    locateRef.current = 0
    setUrlParams({ order: '', highlight_order: '' }, { replace: true })
    if (query.data.page !== page) setPage(query.data.page)
    if (query.data.orders.some((o) => o.id === id)) {
      setExpanded(new Set([id]))
      setTimeout(() => document.getElementById(`order${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 150)
      setTimeout(() => setHighlight(null), 2600)
    }
  }, [query.data])

  const toggle = (id, forceOpen) => {
    setExpanded((s) => {
      const n = new Set(s)
      if (n.has(id) && !forceOpen) n.delete(id)
      else n.add(id)
      return n
    })
  }

  const refreshRow = useCallback(async (id) => {
    try {
      const row = await get(`orders/${id}/summary`)
      patchOrderCaches(qc, row)
    } catch {
      return
    }
  }, [qc])

  const openStockOrder = useCallback((id) => setStockOrder({ id, open: true }), [])
  const [itemModal, setItemModal] = useState({ id: null, open: false })
  const openItem = useCallback((id) => setItemModal({ id, open: true }), [])
  const [ordering, setOrdering] = useState(null)
  const orderMissing = useCallback((line, order) => setOrdering({ line, order }), [])

  const statuses = boot.data?.statuses || []
  const types = boot.data?.order_types || []
  const wcStatuses = (boot.data?.wc_statuses || []).filter((s) => s.value !== 'completed').map((s) => ({ value: s.value, label: s.label, color: s.color, bg: s.bg }))
  const soStatuses = (boot.data?.stock_order_statuses || []).map((s) => ({ value: s.key, label: s.label, color: s.color }))
  const stats = query.data?.stats
  const activeCount = KEYS.filter((k) => (LIST_KEYS.includes(k) ? filters[k].length : filters[k])).length
  const countdowns = useMemo(() => {
    const out = []
    const labels = { manufacture_to: 'Pagaminti iki', finish_to: 'Užprabuoti iki', deliver_to: 'Pristatyti iki' }
    const today = boot.data?.today
    for (const [k, label] of Object.entries(labels)) {
      if (filters[k] && today) {
        const days = Math.round((Date.parse(filters[k]) - Date.parse(today)) / 86400000)
        out.push({ label, days, date: filters[k] })
      }
    }
    return out
  }, [filters, boot.data?.today])

  const clear = () => {
    setSearch('')
    setPage(1)
    setFilters(Object.fromEntries(KEYS.map((k) => [k, LIST_KEYS.includes(k) ? [] : ''])))
  }

  const orders = query.data?.orders || []
  const selection = useSelection(orders.map((o) => o.id))

  return (
    <OrderActionsProvider onCompleted={() => query.refetch()}>
      <Page
        title="Open production queue, sorted by delivery deadline"
        actions={<IconButton icon={ArrowsRotateRight} label="Refresh" variant="secondary" isPending={query.isFetching} onPress={() => query.refetch()} />}
      >
        <div className="cjs-card mb-4 p-3 sm:p-4">
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-[220px] flex-1 sm:max-w-sm">
              <Magnifier className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
              <TextInput value={search} onChange={setSearch} placeholder="Search # or customer…" className="pl-8" />
            </div>
            <MultiFilter label="Statusas" options={statuses.map((s) => ({ value: s.key, label: s.label }))} value={filters.status} onChange={(v) => update({ status: v })} />
            <MultiFilter chips label="Woo status" options={wcStatuses} value={filters.wc_status} onChange={(v) => update({ wc_status: v })} />
            <SelectField size="sm" className="w-[160px]" value={filters.order_type} onChange={(v) => update({ order_type: v })} options={types} allowEmpty emptyLabel="All types" placeholder="All types" ariaLabel="Užsakymo tipas" />
            <SelectField size="sm" className="w-[200px]" value={filters.inventory} onChange={(v) => update({ inventory: v })} allowEmpty emptyLabel="Any inventory" placeholder="Any inventory" ariaLabel="Inventorius" options={INVENTORY_FILTER} />
            <Button size="sm" variant={more ? 'secondary' : 'tertiary'} onPress={() => setMore((m) => !m)}>
              <Funnel className="size-4" />
              More filters
            </Button>
            {activeCount > 0 && (
              <Button size="sm" variant="tertiary" onPress={clear}>
                <FunnelXmark className="size-4" />
                Išvalyti filtrus ({activeCount})
              </Button>
            )}
          </div>
          <Expand open={more}>
            <div className="mt-3 flex flex-col gap-3 border-t border-separator pt-3">
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <Field label="Užsakyti modelį">
                  <SelectField size="sm" value={filters.order_model} onChange={(v) => update({ order_model: v })} options={TRI} ariaLabel="Užsakyti modelį" />
                </Field>
                <Field label="Užsakyti gamybą">
                  <SelectField size="sm" value={filters.order_production} onChange={(v) => update({ order_production: v })} options={TRI} ariaLabel="Užsakyti gamybą" />
                </Field>
                <Field label="Spauda">
                  <SelectField size="sm" value={filters.order_printing} onChange={(v) => update({ order_printing: v })} options={TRI} ariaLabel="Spauda" />
                </Field>
                <Field label="Matavimo rinkinys">
                  <SelectField size="sm" value={filters.size_kit} onChange={(v) => update({ size_kit: v })} allowEmpty emptyLabel="Any" ariaLabel="Matavimo rinkinys" options={KIT_FILTER} />
                </Field>
                <Field label="Stock order status">
                  <MultiFilter chips label={filters.stock_order_status.length ? 'Selected' : 'Any'} options={soStatuses} value={filters.stock_order_status} onChange={(v) => update({ stock_order_status: v })} />
                </Field>
              </div>
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {[['manufacture', 'Pagaminti iki'], ['finish', 'Užprabuoti iki'], ['deliver', 'Pristatyti iki']].map(([k, label]) => (
                  <Field key={k} label={label}>
                    <div className="flex min-w-0 items-center gap-1.5">
                      <DateInput size="sm" className="min-w-0 flex-1" aria-label={`${label} from`} value={filters[`${k}_from`]} onChange={(v) => update({ [`${k}_from`]: v })} />
                      <span className="shrink-0 text-muted">-</span>
                      <DateInput size="sm" className="min-w-0 flex-1" aria-label={`${label} to`} value={filters[`${k}_to`]} onChange={(v) => update({ [`${k}_to`]: v })} />
                    </div>
                  </Field>
                ))}
              </div>
            </div>
          </Expand>
          {stats && (
            <div className="mt-3 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
              <Stat label={ordersWordLt(Number(stats.count) || 0)} value={stats.count} />
              <Stat label="paskirta" value={`${hoursLt(stats.assigned)}h`} />
              <Stat label="pabaigta" value={`${hoursLt(stats.done)}h`} tone="success" />
              <Stat label="likę" value={`${hoursLt(stats.left)}h`} />
              {countdowns.map((c) => (
                <Stat key={c.label} label={c.label} value={`${c.days} d.`} hint={c.date} tone={c.days < 0 ? 'danger' : undefined} />
              ))}
            </div>
          )}
        </div>

        <OrdersBulkBar selection={selection} statuses={statuses} types={types} onDone={() => query.refetch()} />

        <div ref={measureRef} className="cjs-card">
          {query.isLoading ? (
            wide ? <DesktopTable orders={[]} loading statuses={statuses} types={types} expanded={expanded} toggle={toggle} viewWidth={containerWidth} selection={selection} /> : <MobileSkeleton />
          ) : orders.length === 0 ? (
            <Empty icon={Magnifier} title="No orders match" description={activeCount ? 'Try clearing some filters.' : 'All caught up. There are no open orders.'} action={activeCount ? <Button size="sm" variant="secondary" onPress={clear}>Išvalyti filtrus</Button> : null} />
          ) : wide ? (
            <DesktopTable orders={orders} statuses={statuses} types={types} expanded={expanded} toggle={toggle} highlight={highlight} viewWidth={containerWidth} selection={selection} onRowChanged={refreshRow} onOpenStockOrder={openStockOrder} onOpenItem={openItem} onOrderMissing={orderMissing} />
          ) : (
            <MobileCards orders={orders} statuses={statuses} types={types} expanded={expanded} toggle={toggle} highlight={highlight} selection={selection} onRowChanged={refreshRow} onOpenStockOrder={openStockOrder} onOpenItem={openItem} onOrderMissing={orderMissing} />
          )}
        </div>
        {query.data && (
          <Pager
            page={query.data.page}
            pages={query.data.pages}
            total={query.data.total}
            label={query.data.total === 1 ? 'order' : 'orders'}
            onChange={(p) => {
              setPage(p)
              window.scrollTo({ top: 0, behavior: 'smooth' })
            }}
          />
        )}
        {itemModal.id && (
          <Suspense fallback={null}>
            <ItemDetailModal
              itemId={itemModal.id}
              isOpen={itemModal.open}
              onClose={() => setItemModal((s) => ({ ...s, open: false }))}
              onChanged={() => query.refetch()}
            />
          </Suspense>
        )}
        {ordering && (
          <StockOrderDialog
            lines={[ordering.line]}
            order={{ id: ordering.order.id, number: ordering.order.number }}
            draftKey={`so:new:line:${ordering.line.id}`}
            isOpen
            onClose={() => setOrdering(null)}
            onDone={() => refreshRow(ordering.order.id)}
          />
        )}
        {stockOrder.id && (
          <Suspense fallback={null}>
            <StockOrderModal
              id={stockOrder.id}
              isOpen={stockOrder.open}
              onClose={() => setStockOrder((s) => ({ ...s, open: false }))}
              onChanged={() => query.refetch()}
            />
          </Suspense>
        )}
      </Page>
    </OrderActionsProvider>
  )
}
