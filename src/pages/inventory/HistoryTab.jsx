import { lazy, Suspense, useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { Button } from '@heroui/react'
import { ClockArrowRotateLeft, ArrowLeft, ChevronRight } from '@gravity-ui/icons'
import { get } from '../../lib/api'
import { dateTime, plural, qty } from '../../lib/format'
import { Card, Empty, Pager, SegmentedFilter, SkBox, SkChip, SkeletonList, SkLine, skw, Tone } from '../../components/ui'
import { Icon } from '../../components/icons'
import UserStamp from '../../components/UserStamp'
import Movements from '../../components/Movements'

const StockOrderModal = lazy(() => import('../stock-orders/StockOrderDetail').then((m) => ({ default: m.StockOrderModal })))

const TYPES = { receipt: { label: 'Receipt', tone: 'success' }, count: { label: 'Stock count', tone: 'warning' }, migration: { label: 'Migration', tone: 'default' } }

const ROW = 'flex flex-col gap-3 px-4 py-3 sm:flex-row sm:gap-4 sm:px-5'
const STAMP = 'sm:w-[190px] sm:shrink-0 sm:items-start'

function signed(n, unit) {
  return `${n > 0 ? '+' : ''}${qty(n, unit)}`
}

function totalsText(group, isCount) {
  const parts = group.totals.flatMap((t) => (isCount ? [t.plus ? signed(t.plus, t.unit) : null, t.minus ? signed(t.minus, t.unit) : null] : [qty(t.plus + t.minus, t.unit)])).filter(Boolean)
  if (!parts.length) return plural(group.items, 'item', 'items')
  return `${plural(group.items, 'item', 'items')} ${isCount ? 'changed by' : 'totaling'} ${parts.join(', ')}`
}

function Summary({ doc }) {
  const isCount = doc.type === 'count'
  if (!doc.summary?.length) {
    return <div className="mt-1.5 text-[12.5px] text-muted">{isCount ? 'Everything matched, nothing changed' : 'No stock changes'}</div>
  }
  return (
    <ul className="mt-1.5 flex flex-col gap-0.5">
      {doc.summary.map((g) => (
        <li key={g.category_id} className="flex min-w-0 items-start gap-1.5 text-[12.5px]">
          <span className="flex h-[18px] w-4 shrink-0 items-center text-muted">{g.icon && <Icon name={g.icon} className="size-4" />}</span>
          <span className="min-w-0 break-words">
            <span className="font-medium text-foreground">{g.category}:</span> <span className="text-muted">{totalsText(g, isCount)}</span>
          </span>
        </li>
      ))}
    </ul>
  )
}

function Headline({ doc, onOpenStockOrder }) {
  const t = TYPES[doc.type] || { label: doc.type }
  if (doc.stock_order) {
    return (
      <span className="text-[13.5px] font-medium text-foreground">
        Received from{' '}
        <button
          type="button"
          className="font-medium text-accent hover:underline"
          onClick={(e) => {
            e.stopPropagation()
            onOpenStockOrder(doc.stock_order.id)
          }}
        >
          stock order #{doc.stock_order.number}
        </button>
      </span>
    )
  }
  return <span className="min-w-0 break-words text-[13.5px] font-medium text-foreground">{doc.note || t.label}</span>
}

function DocumentRow({ doc, onOpen, onOpenStockOrder }) {
  const t = TYPES[doc.type] || { label: doc.type, tone: 'default' }
  return (
    <li className={`${ROW} cursor-pointer hover:bg-surface-secondary/60`} onClick={onOpen}>
      <UserStamp user={doc.user} avatar={doc.avatar} at={doc.created_at} className={STAMP} />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <Headline doc={doc} onOpenStockOrder={onOpenStockOrder} />
          <span className="text-[12.5px] text-muted">#{doc.id}</span>
          <span className="ml-auto flex items-center gap-2">
            <Tone tone={t.tone}>{t.label}</Tone>
            <button
              type="button"
              aria-label={`Open ${t.label.toLowerCase()} #${doc.id}`}
              className="flex size-7 items-center justify-center text-muted hover:bg-surface-secondary hover:text-foreground"
              onClick={(e) => {
                e.stopPropagation()
                onOpen()
              }}
            >
              <ChevronRight className="size-4" />
            </button>
          </span>
        </div>
        <Summary doc={doc} />
      </div>
    </li>
  )
}

function DocumentsSkeleton() {
  return (
    <SkeletonList rows={8} itemClassName={ROW}>
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
            <div className="flex h-7 items-center gap-x-2.5">
              <SkLine size={13.5} w={[200, 150, 230][i % 3]} />
              <SkLine size={12.5} w={24} />
              <SkChip w={64} className="ml-auto" />
              <SkBox w={28} h={28} />
            </div>
            <div className="mt-1.5 flex flex-col gap-0.5">
              {Array.from({ length: [1, 2, 1][i % 3] }).map((_, n) => <SkLine key={n} size={12.5} w={skw(i + n, 36)} />)}
            </div>
          </div>
        </>
      )}
    </SkeletonList>
  )
}

export default function HistoryTab({ onOpenItem }) {
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const [doc, setDoc] = useState(null)
  const [mode, setMode] = useState('documents')
  const [soId, setSoId] = useState(0)
  const q = useQuery({ queryKey: ['documents', type, page], queryFn: () => get('documents', { type, page, per_page: 30 }), placeholderData: keepPreviousData, enabled: mode === 'documents' })
  const stockOrder = soId ? (
    <Suspense fallback={null}>
      <StockOrderModal id={soId} isOpen={!!soId} onClose={() => setSoId(0)} onChanged={() => q.refetch()} />
    </Suspense>
  ) : null
  if (doc) {
    const t = TYPES[doc.type] || { label: doc.type }
    return (
      <>
        <Card
          title={`${t.label} #${doc.id}`}
          description={
            <span className="flex flex-wrap items-center gap-x-1.5">
              <span>{dateTime(doc.created_at)} · {doc.user}</span>
              {doc.stock_order ? (
                <>
                  <span>·</span>
                  <button type="button" className="font-medium text-accent hover:underline" onClick={() => setSoId(doc.stock_order.id)}>Stock order #{doc.stock_order.number}</button>
                </>
              ) : doc.note ? (
                <span>· {doc.note}</span>
              ) : null}
            </span>
          }
          actions={<Button size="sm" variant="tertiary" onPress={() => setDoc(null)}><ArrowLeft className="size-4" />Back</Button>}
          padded={false}
          bodyClassName="pt-2"
        >
          <Movements params={{ document_id: doc.id }} showItem onOpenItem={onOpenItem} perPage={100} />
        </Card>
        {stockOrder}
      </>
    )
  }
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <SegmentedFilter value={mode} onChange={setMode} items={[{ value: 'documents', label: 'Receipts & counts' }, { value: 'all', label: 'All movements' }]} />
        {mode === 'documents' && (
          <SegmentedFilter value={type} onChange={(v) => { setType(v); setPage(1) }} items={[{ value: '', label: 'All' }, { value: 'receipt', label: 'Receipts' }, { value: 'count', label: 'Counts' }, { value: 'migration', label: 'Migration' }]} />
        )}
      </div>
      {mode === 'all' ? (
        <Card padded={false}><Movements params={{}} showItem onOpenItem={onOpenItem} perPage={50} /></Card>
      ) : (
        <Card padded={false}>
          {q.isLoading ? (
            <DocumentsSkeleton />
          ) : !q.data?.documents.length ? (
            <Empty icon={ClockArrowRotateLeft} title="No receipts or counts yet" />
          ) : (
            <ul className="divide-y divide-separator">
              {q.data.documents.map((d) => (
                <DocumentRow key={d.id} doc={d} onOpen={() => setDoc(d)} onOpenStockOrder={setSoId} />
              ))}
            </ul>
          )}
        </Card>
      )}
      {mode === 'documents' && q.data && <Pager page={q.data.page} pages={q.data.pages} total={q.data.total} onChange={setPage} label="documents" />}
      {stockOrder}
    </div>
  )
}
