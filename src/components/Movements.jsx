import { useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { ClockArrowRotateLeft } from '@gravity-ui/icons'
import { get } from '../lib/api'
import { MOVEMENT_LABELS, money, qty } from '../lib/format'
import { Empty, Pager, SkBox, SkChip, SkeletonList, SkLine, skw } from './ui'
import { OrderLink } from './OrderLink'
import { ItemChip, PieceChips } from './InventoryBadge'
import UserStamp from './UserStamp'

const TONE = {
  receipt: 'success',
  opening: 'success',
  return: 'success',
  count: 'warning',
  use: 'default',
  out: 'accent',
  reserve: 'default',
  release: 'default',
  shortfall: 'danger',
  lost: 'danger',
}

const COST_TYPES = ['receipt', 'opening', 'count', 'use']

const ROW = 'flex flex-col gap-2 px-4 py-3 sm:px-5 @xl:flex-row @xl:gap-4'
const STAMP = '@xl:w-[190px] @xl:shrink-0 @xl:items-start'

const SIGNED = ['receipt', 'opening', 'count']

const VERB = {
  receipt: ['Received', ''],
  opening: ['Opening stock', ''],
  count: ['Stock count', ''],
  reserve: ['Assigned', 'to'],
  release: ['Unassigned', 'from'],
  use: ['Used', 'in'],
  out: ['Sent', 'with'],
  return: ['Returned', 'from'],
  shortfall: ['Used without stock', 'in'],
  lost: ['Lost', 'on'],
  legacy: ['Migrated from 1.x', ''],
  note: ['Note', ''],
  cost: ['Cost changed', ''],
}

const LEGACY_PREFIX = /^(Migration note: |Migrated from 1\.x: )/

function describe(m) {
  const plain = Math.abs(Number(m.qty) || 0)
  if (m.type === 'demand') {
    const need = /^Needs (.+)$/.exec(m.note || '')
    if (need) return { label: 'Added to order', prep: '', amount: need[1], text: '' }
    return { label: m.note || 'Order line changed', prep: '', amount: '', text: '' }
  }
  const [label, prep] = VERB[m.type] || [MOVEMENT_LABELS[m.type] || m.type, '']
  const amount = m.qty !== 0 ? (SIGNED.includes(m.type) ? `${m.qty > 0 ? '+' : '−'}${qty(plain, m.unit)}` : qty(plain, m.unit)) : ''
  let text = m.note || ''
  if (m.type === 'legacy') text = text.replace(LEGACY_PREFIX, '')
  if (m.type === 'receipt' && !m.stock_order_number && m.document_note && !text) text = m.document_note
  return { label, prep, amount, text }
}

export function MovementRow({ m, showItem, onOpenItem }) {
  const d = describe(m)
  const chip = <ItemChip tone={TONE[m.type] || 'default'} status={d.label} name={showItem ? m.item_name : ''} amount={d.amount ? (SIGNED.includes(m.type) ? d.amount : `× ${d.amount}`) : ''} categoryId={m.category_id} />
  const open = showItem && onOpenItem && m.item_id ? () => onOpenItem(m.item_id) : null
  const cost = m.unit_cost !== null && m.unit_cost !== undefined && COST_TYPES.includes(m.type)
  const order = m.order || (m.order_id ? { id: m.order_id, number: String(m.order_id) } : null)
  return (
    <li className={ROW}>
      <UserStamp user={m.user} avatar={m.avatar} at={m.created_at} className={STAMP} />
      <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px]">
        {open ? (
          <button type="button" className="inline-flex max-w-full cursor-pointer text-left hover:opacity-80" title="Open item" onClick={open}>{chip}</button>
        ) : (
          <span className="inline-flex max-w-full">{chip}</span>
        )}
        {order && (
          <span className="inline-flex items-center gap-1">
            {d.prep && <span className="text-muted">{d.prep}</span>}
            {order.status === 'deleted' ? <span className="text-muted">#{order.number} (deleted)</span> : <OrderLink id={order.id} number={order.number} customer={order.customer} inList={order.in_list !== false} />}
          </span>
        )}
        {m.stock_order_number && <span className="text-muted">stock order #{m.stock_order_number}</span>}
        {m.identifier && <PieceChips pieces={[{ id: m.piece_id, identifier: m.identifier }]} />}
        {cost && <span className="text-muted">at {money(m.unit_cost)}</span>}
        {d.text && <span className="min-w-0 break-words text-foreground">{d.text}</span>}
      </div>
    </li>
  )
}

export function MovementsSkeleton({ rows = 6 }) {
  return (
    <SkeletonList rows={rows} itemClassName={ROW}>
      {(i) => (
        <>
          <div className={`flex min-w-0 items-center gap-2.5 ${STAMP}`}>
            <SkBox w={32} h={32} className="rounded-full" />
            <div className="min-w-0 flex-1">
              <SkLine size={13} w={70} />
              <SkLine size={11.5} w={104} />
            </div>
          </div>
          <div className="min-w-0 flex-1">
            <SkChip w={skw(i, 46)} />
            <SkLine size={12.5} w={skw(i + 2, 24)} className="mt-1" />
          </div>
        </>
      )}
    </SkeletonList>
  )
}

export default function Movements({ params, showItem, onOpenItem, emptyText = 'No stock history yet.', perPage = 30 }) {
  const [page, setPage] = useState(1)
  const q = useQuery({
    queryKey: ['movements', params, page],
    queryFn: () => get('movements', { ...params, page, per_page: perPage }),
    placeholderData: keepPreviousData,
  })
  if (q.isLoading) return <div className="@container"><MovementsSkeleton /></div>
  const list = q.data?.movements || []
  if (!list.length) return <Empty compact icon={ClockArrowRotateLeft} title={emptyText} />
  return (
    <div className="@container">
      <ul className="divide-y divide-separator">
        {list.map((m) => <MovementRow key={m.id} m={m} showItem={showItem} onOpenItem={onOpenItem} />)}
      </ul>
      <div className="border-t border-separator px-4 pb-3 sm:px-5">
        <Pager page={page} pages={q.data.pages} total={q.data.total} onChange={setPage} label="entries" />
      </div>
    </div>
  )
}
