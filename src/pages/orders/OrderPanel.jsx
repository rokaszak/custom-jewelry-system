import { useCallback, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Car } from '@gravity-ui/icons'
import { get } from '../../lib/api'
import { notifyError } from '../../lib/hooks'
import { Loading, Notice, Section, Tone, cx } from '../../components/ui'
import InventoryLines from './InventoryLines'
import FilesBox from './FilesBox'
import { patchOrderCaches } from './orderActions'

export function useOrderPanel(orderId) {
  return useQuery({ queryKey: ['order', orderId], queryFn: () => get(`orders/${orderId}`), enabled: !!orderId })
}

function ShippingBadge({ method }) {
  const [broken, setBroken] = useState(false)
  return (
    <span className="inline-flex min-h-6 max-w-full items-center gap-1.5 bg-surface-secondary px-2 py-1 text-[12px] font-medium leading-tight text-foreground" title={method.title}>
      {method.logo && !broken ? (
        <img src={method.logo} alt="" className="h-4 max-w-16 shrink-0 object-contain" loading="lazy" onError={() => setBroken(true)} />
      ) : (
        <Car className="size-3.5 shrink-0 text-muted" />
      )}
      <span className="min-w-0 break-words">{method.title}</span>
    </span>
  )
}

export function OrderBadges({ order, showKit = true }) {
  const methods = order.shipping_methods?.length ? order.shipping_methods : order.shipping ? [{ title: order.shipping, logo: '' }] : []
  const offers = showKit && Array.isArray(order.checkout_offers) ? order.checkout_offers : []
  if (!order.custom_deadline && !offers.length && !methods.length) return null
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {order.custom_deadline && <Tone tone="warning">Sutartas gamybos terminas {order.custom_deadline}</Tone>}
      {offers.map((o) => <Tone key={o.category_id} tone="accent">Klientas pasirinko: {o.name}</Tone>)}
      {methods.map((m, i) => <ShippingBadge key={`${m.method_id || ''}${i}`} method={m} />)}
    </div>
  )
}

export function ProductOptions({ items, order }) {
  return (
    <Section title="Products & options">
      {items?.length ? (
        <div className="@container">
        <ul className="grid grid-cols-1 items-start gap-2 @xl:grid-cols-2">
          {items.map((it) => (
            <li key={it.id} className="bg-surface p-3 ring-1 ring-separator">
              <div className="flex items-center gap-2.5">
                {it.image && <img src={it.image} alt="" className="size-9 shrink-0 object-cover" loading="lazy" />}
                <div className="min-w-0 flex-1">
                  <div className="truncate text-[13px] font-semibold">{it.name}</div>
                  <div className="text-[11.5px] text-muted">Qty {it.qty}</div>
                </div>
              </div>
              {it.meta.length > 0 && (
                <dl className="mt-2 grid grid-cols-[minmax(90px,auto)_1fr] gap-x-3 gap-y-1 text-[12.5px]">
                  {it.meta.map((m, i) => (
                    <div key={i} className="contents">
                      <dt className="text-muted">{m.key}</dt>
                      <dd className="min-w-0 break-words">{m.value}</dd>
                    </div>
                  ))}
                </dl>
              )}
            </li>
          ))}
        </ul>
        </div>
      ) : (
        <div className="text-[12.5px] text-muted">No products.</div>
      )}
      {order && <OrderBadges order={order} />}
    </Section>
  )
}

export default function OrderPanel({ orderId, embedded, onChanged }) {
  const qc = useQueryClient()
  const q = useOrderPanel(orderId)
  const refresh = useCallback(async () => {
    try {
      const data = await qc.fetchQuery({ queryKey: ['order', orderId], queryFn: () => get(`orders/${orderId}`), staleTime: 0 })
      patchOrderCaches(qc, data)
    } catch (e) {
      notifyError(e)
    }
    for (const k of ['required', 'items', 'item', 'item-search', 'stock-orders', 'bootstrap', 'assign-options']) qc.invalidateQueries({ queryKey: [k] })
    if (onChanged) onChanged()
  }, [qc, orderId, onChanged])
  if (q.isLoading) return <Loading className="py-10" />
  if (q.isError) return <Notice tone="danger" className="m-3">{q.error.message}</Notice>
  const o = q.data
  return (
    <div className="@container">
      <div className={cx('grid items-start gap-4 @4xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]', embedded ? 'p-0' : 'p-3 sm:p-4')}>
        <Section title="Inventorius" className="min-w-0">
          <InventoryLines locked={!!o.locked} orderId={o.id} orderNumber={o.number} lineItems={o.line_items} demands={o.demands} warnings={o.warnings} history={o.returnable_history} onChanged={refresh} comment={o.casting_notes || ''} />
        </Section>
        <div className="flex min-w-0 flex-col gap-4">
          {!embedded && <ProductOptions items={o.line_items} order={o} />}
          <Section title={`Spauda${o.file_count ? ` (${o.file_count})` : ''}`}>
            <FilesBox orderId={o.id} onChanged={refresh} />
          </Section>
        </div>
      </div>
    </div>
  )
}
