import { Chip } from '@heroui/react'
import { defaultFineness, fineQty, hasFineness, missingAtDefault, qty } from '../lib/format'
import { useCatalog } from '../lib/hooks'
import { cx } from './ui'
import { CategoryIcon } from './icons'

export const LINE_STATES = ['waiting', 'partially_available', 'reserved', 'done', 'cancelled']

const LINE_STATE_SET = new Set(LINE_STATES)

function num(v) {
  return qty(Math.max(0, Number(v) || 0))
}

export function lineState(line) {
  const l = line || {}
  if (LINE_STATE_SET.has(l.status)) return l.status
  if (LINE_STATE_SET.has(l.state)) return l.state
  const total = Number(l.qty) || 0
  const got = Math.max(0, Number(l.allocated) || 0)
  if (total > 0 && got >= total) return 'reserved'
  return got > 0 ? 'partially_available' : 'waiting'
}

function markerText(l) {
  const m = l.markers || {}
  if (m.sent) return ' · Sent'
  if (m.returned) return ' · Returned'
  return ''
}

export function inventoryBadge(line, category, brief = false) {
  const l = line || {}
  const s = lineState(l)
  const total = Number(l.qty) || 0
  const got = Math.max(0, Number(l.allocated) || 0)
  const so = l.stock_order && l.stock_order.id ? l.stock_order : null
  const parts = Array.isArray(l.parts) ? l.parts : null
  const unordered = parts ? Math.max(0, Number(l.unordered) || 0) : 0
  const worst = parts && unordered <= 0 && parts.length ? parts.find((p) => !p.placed) || parts[0] : null
  const ordered = parts && unordered > 0 ? null : worst ? { id: worst.stock_order_id, number: worst.number } : l.ordered && l.ordered.id ? l.ordered : so && (s === 'waiting' || s === 'partially_available') ? { id: so.id, number: so.number } : null
  const source = l.source_stock_order && l.source_stock_order.id ? l.source_stock_order : null
  const toItem = l.item_id ? { type: 'item', id: l.item_id } : null
  const fulfilled = toItem || (source ? { type: 'stock_order', id: source.id, number: source.number } : null)
  const marker = markerText(l)
  if (s === 'waiting' || s === 'partially_available') {
    const soTarget = ordered ? { type: 'stock_order', id: ordered.id, number: ordered.number } : null
    const placed = worst ? !!worst.placed : ordered && so && so.id === ordered.id ? (so.line_state ? so.line_state !== 'not_ordered' : so.ordered ?? so.in_cart ?? true) : true
    const multi = !!worst && new Set(parts.map((p) => p.stock_order_id)).size > 1
    const soText = ordered ? `${placed ? 'Ordered' : 'Not ordered'} ${multi ? '(multi-order)' : `#${ordered.number}`}` : ''
    const fine = hasFineness(l, category) ? (unordered > 0 ? unordered : missingAtDefault(l, category)) : null
    const shelf = l.auto_assign === false && l.item_free !== undefined && Number(l.item_free) >= Number(l.missing)
    if (s === 'partially_available') {
      if (brief) return { status: `${fine !== null ? 'Partially available' : `Partially available ${num(got)} of ${num(total)}`}${marker}`, tone: 'warning', target: toItem }
      return { status: `${fine !== null ? `Partially available · ${fineQty(fine, l.unit, defaultFineness(l, category))} missing` : `Partially available ${num(got)} of ${num(total)}`}${soText ? ` · ${soText}` : ''}${marker}`, tone: 'warning', target: soTarget || toItem, orderable: !ordered && !shelf }
    }
    if (ordered) return { status: `${soText}${marker}`, tone: placed ? 'accent' : 'danger', target: soTarget }
    return { status: `Waiting availability${marker}`, tone: 'danger', solid: true, target: toItem, orderable: !shelf }
  }
  if (s === 'done') return { status: marker.replace(/^ · /, ''), tone: 'success', solid: true, target: fulfilled }
  if (s === 'cancelled') return { status: `Cancelled${marker}`, tone: 'default', target: fulfilled }
  return { status: `Reserved${marker}`, tone: l.markers?.sent ? 'accent' : 'success', solid: !!l.markers?.sent, target: fulfilled }
}

export function inventoryBadgeText(line, category) {
  const b = inventoryBadge(line, category)
  const name = line?.name ?? line?.item_name ?? ''
  return `${[b.status, name].filter(Boolean).join(' · ')} × ${fineQty(b.amountQty ?? line?.qty, line?.unit, defaultFineness(line, category))}`
}

export function stockLineBadge(line) {
  const l = line || {}
  if (l.state === 'received') return { status: 'Received', tone: 'success' }
  if (l.state === 'partly_received') return { status: `Partly received ${num(l.qty_received)} of ${num(l.qty)}`, tone: 'warning' }
  if (l.state === 'ordered') return { status: 'Ordered', tone: 'accent' }
  return { status: 'Not ordered', tone: 'danger' }
}

export function piecesText(pieces) {
  return (pieces || [])
    .filter((p) => p && p.identifier && p.state !== 'released')
    .map((p) => `${p.identifier}${p.state === 'out' ? ' · sent' : p.state === 'returned' ? ' · returned' : p.state === 'writeoff' ? ' · written off' : ''}`)
}

export function ItemChip({ tone, solid, status, name, amount, extra, categoryId, className }) {
  return (
    <Chip color={tone} size="sm" variant="soft" className={cx('cjs-chip-wrap', solid && 'cjs-chip-solid', className)}>
      <Chip.Label>
        <CategoryIcon categoryId={categoryId} className="cjs-chip-icon" />
        {status}
        {name ? `${status ? ' · ' : ''}${name}` : ''}
        {amount && (status || name) ? (String(amount).startsWith('×') ? ' ' : ' · ') : ''}
        {amount ? <strong className="font-bold">{amount}</strong> : null}
        {(extra || []).map((t) => (
          <span key={t}>
            {' '}
            <span className="whitespace-nowrap bg-surface-secondary px-1.5 py-px text-[11.5px] font-medium text-foreground">{t}</span>
          </span>
        ))}
      </Chip.Label>
    </Chip>
  )
}

export function StockLineBadge({ line, className }) {
  if (!line) return null
  const b = stockLineBadge(line)
  return <ItemChip tone={b.tone} status={b.status} name={line.name} amount={`× ${qty(line.qty, line.unit)}`} categoryId={line.category_id} className={className} />
}

export function PieceChips({ pieces }) {
  const list = (pieces || []).filter((p) => p && p.identifier && p.state !== 'released')
  return list.map((p) => (
    <span key={p.id ?? p.identifier} className={cx('inline-flex items-center px-1.5 py-0.5 text-[11.5px] font-medium', p.state === 'out' ? 'bg-accent-soft text-accent' : 'bg-surface-secondary text-foreground')}>
      {p.identifier}
      {p.state === 'out' ? ' · sent' : p.state === 'returned' ? ' · returned' : p.state === 'writeoff' ? ' · written off' : ''}
    </span>
  ))
}

export function ClickChip({ onClick, title, children }) {
  if (!onClick) return <span className="inline-flex max-w-full" title={title}>{children}</span>
  return (
    <button
      type="button"
      className="inline-flex max-w-full cursor-pointer text-left hover:opacity-80"
      title={title}
      onClick={(e) => {
        e.stopPropagation()
        onClick()
      }}
    >
      {children}
    </button>
  )
}

export default function InventoryBadge({ line, pieces, onOpenItem, onOpenStockOrder, onOrderMissing, onOpenDetails, brief, className }) {
  const { categoryById } = useCatalog()
  if (!line) return null
  const category = categoryById[line.category_id]
  const b = inventoryBadge(line, category, !!brief)
  const name = line.name ?? line.item_name ?? ''
  const amount = `× ${fineQty(b.amountQty ?? line.qty, line.unit, defaultFineness(line, category))}`
  const extra = piecesText(pieces)
  const text = [b.status, name, amount, ...extra].filter(Boolean).join(' · ')
  if (onOpenDetails) {
    return <ClickChip onClick={onOpenDetails} title={`${text}. Open item`}><ItemChip tone={b.tone} solid={b.solid} status={b.status} name={name} amount={amount} extra={extra} categoryId={line.category_id} className={className} /></ClickChip>
  }
  const ordering = b.orderable && onOrderMissing && Number(line.id) > 0
  const handler = ordering ? () => onOrderMissing(line) : b.target?.type === 'stock_order' ? onOpenStockOrder : b.target?.type === 'item' ? onOpenItem : null
  const chip = <ItemChip tone={b.tone} solid={b.solid} status={b.status} name={name} amount={amount} extra={extra} categoryId={line.category_id} className={className} />
  if (!handler) {
    return <span className="inline-flex max-w-full" title={text}>{chip}</span>
  }
  const hint = ordering ? 'Add to a stock order' : b.target.type === 'stock_order' ? `Open stock order${b.target.number ? ` #${b.target.number}` : ''}` : 'Open item'
  return (
    <button
      type="button"
      className="inline-flex max-w-full cursor-pointer text-left hover:opacity-80"
      title={`${text}. ${hint}`}
      onClick={(e) => {
        e.stopPropagation()
        handler(b.target?.id)
      }}
    >
      {chip}
    </button>
  )
}
