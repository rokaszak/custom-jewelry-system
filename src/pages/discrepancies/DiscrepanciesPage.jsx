import { Fragment, useCallback, useEffect, useState } from 'react'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { Button } from '@heroui/react'
import { toast } from '../../lib/toast'
import { CircleCheck, ShieldCheck, BarsDescendingAlignLeft, ChevronDown, ChevronUp, ArrowUpRightFromSquare } from '@gravity-ui/icons'
import { get, post, onWrite } from '../../lib/api'
import { navigate } from '../../lib/nav'
import { notifyError, useBootstrap, useElementWidth } from '../../lib/hooks'
import { dateTime, qty } from '../../lib/format'
import { Btn, Dialog, Empty, Expand, Field, IconButton, Page, Pager, SkBox, SkChip, SkeletonList, SkLine, skw, Tone, fromInside, cx } from '../../components/ui'
import { OrderLink } from '../../components/OrderLink'
import { ItemChip } from '../../components/InventoryBadge'
import { FixButton } from '../../components/StockModal'
import { ItemDetailModal } from '../inventory/ItemDetail'
import CountForm from '../inventory/CountForm'
import { OrderActionsProvider } from '../orders/orderActions'
import { StatusSelect, TypeSelect, InventoryList } from '../orders/OrderFields'
import { OrderCell, CardExpand, Lock, usePresence, isInteractive } from '../orders/OrdersPage'
import OrderPanel from '../orders/OrderPanel'
import StockOrderDialog from '../orders/StockOrderDialog'

const TYPES = {
  used_without_stock: { label: 'Used without stock', tone: 'danger' },
  integrity: { label: 'Numbers do not add up', tone: 'warning' },
  unattended: { label: 'Status changed without a person', tone: 'danger' },
}

const RESOLUTION = { count: 'Fixed by a stock count', fixed: 'Fixed', manual: 'Closed' }

const WIDE = 960

const CLOSED_WC = ['cancelled', 'refunded', 'failed', 'trash']

function DiscStatusSelect({ o, statuses }) {
  if (!CLOSED_WC.includes(o.wc_status)) return <StatusSelect order={o} statuses={statuses} />
  const canFix = !o.discrepancy?.resolved && !!o.fix
  const text = `WooCommerce status: ${o.wc_status_label || o.wc_status}. The CJS status cannot be changed here.${canFix ? ' Use Fix to fix this order.' : ''}`
  return (
    <div className="flex min-w-0 flex-col gap-0.5" title={text}>
      <StatusSelect order={o} statuses={statuses} isDisabled />
      {canFix && <span className="text-[11.5px] text-muted">Use Fix to fix this order.</span>}
    </div>
  )
}

function howToFix(d) {
  if (d.type === 'used_without_stock') {
    return `An order used ${d.qty !== null ? qty(d.qty, d.unit) : 'stock'} while the system had none. Count this item so the stock matches the shelf.`
  }
  if (d.type === 'integrity') return 'The stock numbers for this item do not match its history. Count this item to set them right.'
  if (d.type === 'unattended') return ''
  return 'Count this item to set the stock right.'
}

function Row({ d, onOpenItem, onCount, onFixed }) {
  const t = TYPES[d.type] || { label: d.type, tone: 'default' }
  const hint = howToFix(d)
  return (
    <li className={cx('flex flex-wrap items-start gap-x-3 gap-y-2 px-4 py-3 sm:px-5', d.resolved && 'opacity-80')}>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          {d.item_name ? (
            <button type="button" className="inline-flex max-w-full cursor-pointer text-left hover:opacity-80" title="Open item" onClick={() => onOpenItem(d.item_id)}>
              <ItemChip tone={d.resolved ? 'default' : t.tone} status={t.label} name={d.item_name} amount={d.qty !== null && d.qty !== undefined ? `× ${qty(d.qty, d.unit)}` : ''} categoryId={d.category_id} />
            </button>
          ) : (
            <Tone tone={d.resolved ? 'default' : t.tone}>{t.label}</Tone>
          )}
          {d.order && (d.order.status === 'deleted' ? <span className="text-[13px] text-muted">#{d.order.number} · {d.order.status_label || 'Deleted'}</span> : <OrderLink id={d.order.id} number={d.order.number} customer={d.order.customer} inList={d.order.in_list !== false} />)}
        </div>
        {!d.resolved && hint && <div className="mt-1 text-[12.5px] text-foreground/80">{hint}</div>}
        {d.details && <div className="mt-1 text-[12px] text-muted">{d.details}</div>}
        <div className="mt-1 text-[11.5px] text-muted">
          {dateTime(d.created_at)}
          {d.resolved && d.resolved_at && ` · ${RESOLUTION[d.resolution] || 'Fixed'} ${dateTime(d.resolved_at)}${d.resolved_by ? ` by ${d.resolved_by}` : ''}`}
        </div>
      </div>
      {!d.resolved && d.item_id && (
        <Button size="sm" variant="secondary" onPress={() => onCount(d)}>
          <BarsDescendingAlignLeft className="size-4" />
          Count this item
        </Button>
      )}
      {!d.resolved && d.fix && <FixButton order={{ order_id: d.order_id ?? d.order?.id, fix: d.fix }} onDone={onFixed} />}
    </li>
  )
}

function DiscrepancyInfo({ d }) {
  if (!d) return null
  const t = TYPES[d.type] || { label: d.type, tone: 'default' }
  return (
    <div className="flex min-w-0 flex-col items-start gap-1">
      <Tone tone={d.resolved ? 'default' : t.tone}>{t.label}</Tone>
      {d.details && <div className="text-[12.5px] text-foreground/80">{d.details}</div>}
      <div className="text-[11.5px] text-muted">
        {dateTime(d.created_at)}
        {d.resolved && d.resolved_at && ` · ${RESOLUTION[d.resolution] || 'Fixed'} ${dateTime(d.resolved_at)}${d.resolved_by ? ` by ${d.resolved_by}` : ''}`}
      </div>
    </div>
  )
}

function Actions({ o, open, onToggle, onFixed, vertical }) {
  return (
    <div className={cx('flex items-center gap-1', vertical && 'flex-col items-stretch')} data-no-expand>
      {!o.discrepancy?.resolved && o.fix && <FixButton order={o} onDone={onFixed} />}
      <div className="flex items-center justify-center gap-1">
        <IconButton icon={ArrowUpRightFromSquare} label="Open in WooCommerce" variant="secondary" onPress={() => o.edit_url && window.open(o.edit_url, '_blank', 'noopener')} />
        <IconButton icon={open ? ChevronUp : ChevronDown} label={open ? 'Hide details' : 'Show details'} variant="secondary" onPress={onToggle} />
      </div>
    </div>
  )
}

function TableExpand({ o, open, onChanged }) {
  const [mounted, shown] = usePresence(open)
  if (!mounted) return null
  return (
    <tr className="cjs-row-expanded">
      <td colSpan={5}>
        <Expand open={shown}>
          <OrderPanel orderId={o.id} onChanged={onChanged} />
        </Expand>
      </td>
    </tr>
  )
}

function OrderStatus({ o, statuses, types }) {
  return (
    <Lock o={o}>
      <div className="flex min-w-0 flex-col gap-1.5">
        <DiscStatusSelect o={o} statuses={statuses} />
        <TypeSelect order={o} types={types} />
      </div>
    </Lock>
  )
}

function OrdersTable({ orders, statuses, types, expanded, toggle, onRowChanged, onFixed, handlers }) {
  return (
    <table className="cjs-table cjs-orders-table table-fixed">
      <thead>
        <tr>
          <th style={{ width: 180 }}>Order</th>
          <th>Inventorius</th>
          <th style={{ width: '30%' }}>Discrepancy</th>
          <th style={{ width: 170 }}>Statusas · tipas</th>
          <th className="px-2!" style={{ width: 96 }}><span className="sr-only">Veiksmai</span></th>
        </tr>
      </thead>
      <tbody>
        {orders.map((o) => {
          const open = expanded.has(o.id)
          return (
            <Fragment key={o.id}>
              <tr id={`order${o.id}`} className={cx('cjs-row-click', open && 'cjs-row-open', o.discrepancy?.resolved && 'opacity-80')} onClick={(e) => fromInside(e) && !isInteractive(e.target) && toggle(o.id)}>
                <td><OrderCell o={o} /></td>
                <td><InventoryList order={o} onOpen={() => toggle(o.id, true)} {...handlers} /></td>
                <td><DiscrepancyInfo d={o.discrepancy} /></td>
                <td><OrderStatus o={o} statuses={statuses} types={types} /></td>
                <td className="px-2!"><Actions o={o} open={open} onToggle={() => toggle(o.id)} onFixed={() => onFixed(o.id)} vertical /></td>
              </tr>
              <TableExpand o={o} open={open} onChanged={() => onRowChanged(o.id)} />
            </Fragment>
          )
        })}
      </tbody>
    </table>
  )
}

function OrderCards({ orders, statuses, types, expanded, toggle, onRowChanged, onFixed, handlers }) {
  return (
    <div className="grid grid-cols-1 items-start gap-3 p-3">
      {orders.map((o) => {
        const open = expanded.has(o.id)
        return (
          <article id={`order${o.id}`} key={o.id} className={cx('cjs-card @container min-w-0 overflow-hidden', o.discrepancy?.resolved && 'opacity-80')}>
            <div className="flex items-start justify-between gap-2 p-3.5 pb-2">
              <div className="min-w-0"><OrderCell o={o} /></div>
              <Actions o={o} open={open} onToggle={() => toggle(o.id)} onFixed={() => onFixed(o.id)} />
            </div>
            <div className="grid gap-3 px-3.5 pb-3.5 @md:grid-cols-2">
              <div className="@md:col-span-2"><DiscrepancyInfo d={o.discrepancy} /></div>
              <Lock o={o}><Field label="Statusas"><DiscStatusSelect o={o} statuses={statuses} /></Field></Lock>
              <Lock o={o}><Field label="Užsakymo tipas"><TypeSelect order={o} types={types} /></Field></Lock>
              <div className="@md:col-span-2">
                <Field label="Inventorius"><InventoryList order={o} onOpen={() => toggle(o.id, true)} {...handlers} /></Field>
              </div>
            </div>
            <CardExpand o={o} open={open} onChanged={() => onRowChanged(o.id)} />
          </article>
        )
      })}
    </div>
  )
}

function OrdersSkeleton() {
  return (
    <SkeletonList rows={4} itemClassName="flex flex-wrap items-start gap-x-3 gap-y-2 px-4 py-3 sm:px-5">
      {(i) => (
        <>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <SkLine size={13.5} w={30} />
              <SkChip w={70} />
            </div>
            <SkLine size={12.5} w={skw(i, 55)} className="mt-1" />
            <SkChip w={[220, 180, 260][i % 3]} className="mt-1" />
          </div>
          <SkBox w={64} h={32} />
        </>
      )}
    </SkeletonList>
  )
}

function useOrderList(state, page, extraPage) {
  return useQuery({
    queryKey: ['discrepancies', 'orders', state, page, extraPage],
    queryFn: () => get('discrepancies/orders', { state, page, per_page: state === 'open' ? 50 : 30, extra_page: extraPage, extra_per_page: 50 }),
    placeholderData: keepPreviousData,
  })
}

export default function DiscrepanciesPage() {
  const qc = useQueryClient()
  const boot = useBootstrap()
  const [measureRef, width] = useElementWidth()
  const wide = width >= WIDE
  const [page, setPage] = useState(1)
  const [resolvedPage, setResolvedPage] = useState(1)
  const [extraPage, setExtraPage] = useState(1)
  const [resolvedExtraPage, setResolvedExtraPage] = useState(1)
  const [showResolved, setShowResolved] = useState(false)
  const [checking, setChecking] = useState(false)
  const [itemId, setItemId] = useState(null)
  const [counting, setCounting] = useState(null)
  const [expanded, setExpanded] = useState(() => new Set())
  const [ordering, setOrdering] = useState(null)
  const open = useOrderList('open', page, extraPage)
  const resolved = useOrderList('resolved', resolvedPage, resolvedExtraPage)
  const statuses = boot.data?.statuses || []
  const types = boot.data?.order_types || []
  const refresh = useCallback(() => {
    qc.invalidateQueries({ queryKey: ['discrepancies'] })
    qc.invalidateQueries({ queryKey: ['bootstrap'] })
  }, [qc])
  const check = async () => {
    setChecking(true)
    try {
      const res = await post('discrepancies/check')
      if (res.issues) toast.warning(`${res.issues} ${res.issues === 1 ? 'problem' : 'problems'} found (${res.added} new)`)
      else toast.success('All stock numbers add up')
      refresh()
    } catch (e) {
      notifyError(e)
    } finally {
      setChecking(false)
    }
  }
  const toggle = (id, forceOpen) => {
    setExpanded((s) => {
      const n = new Set(s)
      if (n.has(id) && !forceOpen) n.delete(id)
      else n.add(id)
      return n
    })
  }
  const refreshRow = useCallback(() => qc.invalidateQueries({ queryKey: ['discrepancies'] }), [qc])
  useEffect(() => {
    let t = 0
    const off = onWrite(() => {
      clearTimeout(t)
      t = setTimeout(() => qc.invalidateQueries({ queryKey: ['discrepancies'] }), 400)
    })
    return () => {
      off()
      clearTimeout(t)
    }
  }, [qc])
  const handlers = {
    onOpenItem: (id) => setItemId(id),
    onOpenStockOrder: (id) => navigate('stock-orders', { so: id }),
    onOrderMissing: (line, order) => setOrdering({ line, order }),
  }
  const orders = open.data?.orders || []
  const extra = open.data?.extra || []
  const doneOrders = resolved.data?.orders || []
  const doneExtra = resolved.data?.extra || []
  const extraPager = (d, onChange) => d && <Pager page={d.extra_page || 1} pages={d.extra_pages || 1} total={d.extra_total ?? (d.extra || []).length} onChange={onChange} label="entries" />
  const doneTotal = (resolved.data?.total || 0) + (resolved.data?.extra_total ?? doneExtra.length)
  const listProps = { statuses, types, expanded, toggle, onRowChanged: refreshRow, onFixed: refresh, handlers }
  const renderOrders = (list) => (wide ? <OrdersTable orders={list} {...listProps} /> : <OrderCards orders={list} {...listProps} />)
  return (
    <OrderActionsProvider onCompleted={refresh}>
      <Page
        title="Stock that did not add up"
        actions={<Btn variant="secondary" icon={ShieldCheck} isPending={checking} onPress={check}>Check stock numbers</Btn>}
      >
        <div ref={measureRef} className="flex flex-col gap-4">
          <div className="cjs-card overflow-hidden">
            {open.isLoading ? (
              <OrdersSkeleton />
            ) : orders.length === 0 && extra.length === 0 ? (
              <Empty icon={CircleCheck} title="Nothing to fix" description="Stock matches what happened." />
            ) : (
              <>
                {orders.length > 0 && renderOrders(orders)}
                {extra.length > 0 && (
                  <ul className={cx('divide-y divide-separator', orders.length > 0 && 'border-t border-separator')}>
                    {extra.map((d) => <Row key={d.id} d={d} onOpenItem={setItemId} onCount={setCounting} onFixed={refresh} />)}
                  </ul>
                )}
              </>
            )}
          </div>
        </div>
        {open.data && <Pager page={open.data.page} pages={open.data.pages} total={open.data.total} onChange={setPage} label="orders" />}
        {extraPager(open.data, setExtraPage)}
        {doneTotal > 0 && (
          <div className="mt-4">
            <Button variant="tertiary" size="sm" onPress={() => setShowResolved((s) => !s)} aria-expanded={showResolved}>
              <ChevronDown className={cx('size-4 transition-transform duration-200', showResolved && 'rotate-180')} />
              Fixed earlier ({doneTotal})
            </Button>
            <Expand open={showResolved}>
              <div className="cjs-card mt-2 overflow-hidden">
                {doneOrders.length > 0 && renderOrders(doneOrders)}
                {doneExtra.length > 0 && (
                  <ul className={cx('divide-y divide-separator', doneOrders.length > 0 && 'border-t border-separator')}>
                    {doneExtra.map((d) => <Row key={d.id} d={d} onOpenItem={setItemId} onCount={setCounting} onFixed={refresh} />)}
                  </ul>
                )}
              </div>
              {resolved.data && <Pager page={resolved.data.page} pages={resolved.data.pages} total={resolved.data.total} onChange={setResolvedPage} label="orders" />}
              {extraPager(resolved.data, setResolvedExtraPage)}
            </Expand>
          </div>
        )}
        <ItemDetailModal itemId={itemId} isOpen={!!itemId} onClose={() => setItemId(null)} onChanged={refresh} />
        <Dialog isOpen={!!counting} onClose={() => setCounting(null)} isDismissable={false} size="lg" title={counting ? `Count ${counting.item_name}` : 'Count'} description="Enter what is on the shelf right now.">
          {counting && <CountForm presetItems={[{ id: counting.item_id, name: counting.item_name }]} embedded draftKey={`count:item:${counting.item_id}`} onCancel={() => setCounting(null)} onDone={() => setCounting(null)} />}
        </Dialog>
        {ordering && (
          <StockOrderDialog
            lines={[ordering.line]}
            order={{ id: ordering.order.id, number: ordering.order.number }}
            draftKey={`so:new:line:${ordering.line.id}`}
            isOpen
            onClose={() => setOrdering(null)}
            onDone={refreshRow}
          />
        )}
      </Page>
    </OrderActionsProvider>
  )
}
