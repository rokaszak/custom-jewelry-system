import { useState } from 'react'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { ProgressBar } from '@heroui/react'
import { Plus, Magnifier, ShoppingCart } from '@gravity-ui/icons'
import { get } from '../../lib/api'
import { useBootstrap, useDebounced, useUrlParam, useMediaQuery } from '../../lib/hooks'
import { qty, money, plural } from '../../lib/format'
import { Btn, Empty, HexChip, MultiFilter, Page, Pager, SegmentedFilter, SkBox, SkChip, SkeletonList, SkeletonRows, SkLine, skw, TextInput, fromInside } from '../../components/ui'
import { OrderLink } from '../../components/OrderLink'
import StockOrderDetail, { StockOrderModal } from './StockOrderDetail'
import { StockLineBadge } from '../../components/InventoryBadge'
import StockOrderDialog from '../orders/StockOrderDialog'


const HEAD = (
  <thead>
    <tr>
      <th className="w-[150px]">Number</th>
      <th className="w-[150px]">Status</th>
      <th>Lines</th>
      <th className="w-[170px]">Received</th>
      <th className="w-[26%]">For orders</th>
    </tr>
  </thead>
)

function SkProgress() {
  return (
    <div className="flex min-w-[120px] flex-col gap-1">
      <SkLine size={12} w={96} />
      <SkBox w="100%" h={8} />
    </div>
  )
}

function Progress({ so }) {
  const pct = so.qty_total > 0 ? Math.round((so.qty_received / so.qty_total) * 100) : 0
  return (
    <div className="flex min-w-[120px] flex-col gap-1">
      <span className="text-[12px] tabular-nums text-muted">{pct === 100 ? 'All received' : `${qty(so.qty_received)} / ${qty(so.qty_total)} received`}</span>
      <ProgressBar aria-label="Received" value={pct} size="sm" color={pct === 100 ? 'success' : 'accent'}>
        <ProgressBar.Track>
          <ProgressBar.Fill />
        </ProgressBar.Track>
      </ProgressBar>
    </div>
  )
}

function LinesPreview({ so }) {
  const list = so.lines_preview || []
  return (
    <div className="flex flex-col items-start gap-1">
      {list.map((l, i) => (
        <div key={i} className="flex min-w-0 max-w-full flex-wrap items-center gap-x-2 gap-y-1">
          <StockLineBadge line={l} />
          {(l.comments || []).map((c, k) => (
            <span key={k} className="min-w-0 whitespace-pre-line break-words text-[13px] leading-snug">
              {c.label && <span className="mr-1 text-muted">{c.label}</span>}
              {c.text}
            </span>
          ))}
        </div>
      ))}
      {so.cost_total > 0 && <span className="text-[11.5px] text-muted">Total {money(so.cost_total)}</span>}
    </div>
  )
}

function Orders({ so }) {
  if (!so.orders.length) return <span className="text-[12px] text-muted">For stock</span>
  return (
    <div className="flex flex-wrap gap-x-3 gap-y-1">
      {so.orders.map((o) => (
        <span key={o.id} className="inline-flex items-center gap-1" title={o.status_for_order?.label}>
          <OrderLink id={o.id} number={o.number} inList={o.in_list !== false} />
        </span>
      ))}
    </div>
  )
}

export default function StockOrdersPage() {
  const qc = useQueryClient()
  const boot = useBootstrap()
  const wide = useMediaQuery('(min-width: 1000px)')
  const [soId, setSoId] = useUrlParam('so', '')
  const [modalId, setModalId] = useState(0)
  const [status, setStatus] = useState([])
  const [open, setOpen] = useState('')
  const [search, setSearch] = useState('')
  const term = useDebounced(search, 250)
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const statuses = boot.data?.stock_order_statuses || []
  const q = useQuery({
    queryKey: ['stock-orders', status, open, term, page],
    queryFn: () => get('stock-orders', { status, open, search: term, page, per_page: 25 }),
    placeholderData: keepPreviousData,
    enabled: !soId,
  })
  const refresh = () => qc.invalidateQueries({ queryKey: ['stock-orders'] })

  if (soId) return <StockOrderDetail id={Number(soId)} onBack={() => setSoId('')} />

  const create = () => setCreating(true)
  const list = q.data?.stock_orders || []
  const openRow = (e, id) => {
    if (!fromInside(e) || e.target.closest('a,button,input,[role="checkbox"]')) return
    setModalId(id)
  }

  return (
    <Page
      title="Purchases from suppliers"
      actions={<Btn variant="primary" icon={Plus} onPress={create}>New stock order</Btn>}
    >
      <div className="cjs-card mb-3 flex flex-wrap items-center gap-2 p-3">
        <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
          <Magnifier className="pointer-events-none absolute left-2.5 top-1/2 z-[1] size-4 -translate-y-1/2 text-muted" />
          <TextInput value={search} onChange={(v) => { setSearch(v); setPage(1) }} placeholder="Number, item, supplier or order #" className="pl-8" aria-label="Search stock orders" />
        </div>
        <MultiFilter chips label="Status" options={statuses.map((s) => ({ value: s.key, label: s.label, color: s.color }))} value={status} onChange={(v) => { setStatus(v); setPage(1) }} />
        <SegmentedFilter value={open} onChange={(v) => { setOpen(v); setPage(1) }} items={[{ value: '', label: 'All' }, { value: 'yes', label: 'Not fully received' }]} />
      </div>
      <div className="cjs-card overflow-clip">
        {q.isLoading ? (
          wide ? (
            <table className="cjs-table table-fixed">
              {HEAD}
              <tbody>
                <SkeletonRows rows={8}>
                  {(i) => (
                    <>
                      <td>
                        <SkLine size={13} w={44} />
                        <SkLine size={11.5} w={72} />
                      </td>
                      <td><SkChip w={[64, 96, 76][i % 3]} /></td>
                      <td>
                        <div className="flex flex-col items-start gap-1">
                          {Array.from({ length: [2, 1, 3][i % 3] }).map((_, n) => <SkBox key={n} w={skw(i + n, 88)} h={n % 2 ? 20 : 40} />)}
                        </div>
                      </td>
                      <td><SkProgress /></td>
                      <td>
                        <div className="flex flex-wrap gap-x-3">
                          {Array.from({ length: [2, 1, 3][i % 3] }).map((_, n) => <SkLine key={n} size={12.5} w={30} />)}
                        </div>
                      </td>
                    </>
                  )}
                </SkeletonRows>
              </tbody>
            </table>
          ) : (
            <SkeletonList rows={6} itemClassName="flex flex-col gap-2 px-4 py-3">
              {(i) => (
                <>
                  <div className="flex items-center justify-between gap-2">
                    <div>
                      <SkLine size={14} w={130} />
                      <SkLine size={12} w={50} />
                    </div>
                    <SkChip w={[64, 96, 76][i % 3]} />
                  </div>
                  <SkProgress />
                  <SkLine size={12.5} w={[60, 30, 90][i % 3]} />
                </>
              )}
            </SkeletonList>
          )
        ) : list.length === 0 ? (
          <Empty icon={ShoppingCart} title="No stock orders" description="Create one here or from the Required page." />
        ) : wide ? (
          <table className="cjs-table table-fixed">
            {HEAD}
            <tbody>
              {list.map((so) => (
                <tr key={so.id} className="cjs-row-click" onClick={(e) => openRow(e, so.id)}>
                  <td>
                    <div className="font-semibold">#{so.number}</div>
                    <div className="text-[11.5px] text-muted">{so.order_date}</div>
                    {so.supplier && <div className="text-[11.5px] text-muted">{so.supplier}</div>}
                  </td>
                  <td><HexChip color={so.status_color} label={so.status_label} /></td>
                  <td><LinesPreview so={so} /></td>
                  <td><Progress so={so} /></td>
                  <td><Orders so={so} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <ul className="divide-y divide-separator">
            {list.map((so) => (
              <li key={so.id} className="flex cursor-pointer flex-col gap-2 px-4 py-3" onClick={(e) => openRow(e, so.id)}>
                <div className="flex items-center justify-between gap-2">
                  <div>
                    <div className="font-semibold">#{so.number} <span className="font-normal text-muted">· {so.order_date}</span></div>
                    <div className="text-[12px] text-muted">{plural(so.line_count, 'line', 'lines')}</div>
                  </div>
                  <HexChip color={so.status_color} label={so.status_label} />
                </div>
                <Progress so={so} />
                <Orders so={so} />
              </li>
            ))}
          </ul>
        )}
      </div>
      {q.data && <Pager page={q.data.page} pages={q.data.pages} total={q.data.total} onChange={setPage} label="stock orders" />}
      <StockOrderModal id={modalId} isOpen={!!modalId} onClose={() => setModalId(0)} onChanged={refresh} />
      {creating && (
        <StockOrderDialog
          title="New stock order"
          draftKey="so:new:page"
          isOpen
          onClose={() => setCreating(false)}
          onDone={(so) => {
            refresh()
            if (so?.id) setModalId(so.id)
          }}
        />
      )}
    </Page>
  )
}
