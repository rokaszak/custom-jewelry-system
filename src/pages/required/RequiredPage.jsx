import { lazy, Suspense, useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { Button, Dropdown, Label } from '@heroui/react'
import { toast } from '../../lib/toast'
import { Magnifier, ShoppingCart, CircleCheck, Box, ChevronDown, ChevronUp, TrashBin, Comment, CommentSlash, EllipsisVertical } from '@gravity-ui/icons'
import { get, post, patch, del } from '../../lib/api'
import { notifyError, useCatalog, useDebounced, useUrlParam } from '../../lib/hooks'
import { CategoryIcon, categoryOptions } from '../../components/icons'
import { defaultFineness, fineQty, plural, qty } from '../../lib/format'
import { BulkBar, Btn, Check, IconButton, Empty, Expand, Page, SegmentedFilter, SelectAll, SelectField, SkBox, SkChip, SkeletonList, SkLine, skw, TextInput, Tone, useConfirm, useSelection } from '../../components/ui'
import InventoryBadge, { PieceChips } from '../../components/InventoryBadge'
import { Breakdown, breakdownRows, needsBreakdown } from '../orders/InventoryLines'
import { CommentDialog, CommentText, saveDemandNote } from '../../components/LineComment'
import StockOrderDialog from '../orders/StockOrderDialog'
import { OrderLink } from '../../components/OrderLink'
import { ItemDetailModal } from '../inventory/ItemDetail'

const INVALIDATE = ['required', 'stock-orders', 'orders', 'order', 'items', 'item', 'bootstrap']

const notOnStockOrder = (d) => !d.stock_order || Number(d.unordered) > 0

const StockOrderModal = lazy(() => import('../stock-orders/StockOrderDetail').then((m) => ({ default: m.StockOrderModal })))

function DemandBadge({ line, onOpenItem, onOpenStockOrder, onOrderMissing }) {
  const { categoryById } = useCatalog()
  const fineness = defaultFineness(line, categoryById[line.category_id])
  const rows = fineness && Array.isArray(line.allocations) ? breakdownRows(line, fineness) : []
  const showRows = fineness ? needsBreakdown(rows) : false
  if (!showRows) return <InventoryBadge line={line} onOpenItem={onOpenItem} onOpenStockOrder={onOpenStockOrder} onOrderMissing={onOrderMissing} />
  return (
    <div className="min-w-0">
      <InventoryBadge line={line} brief onOpenDetails={line.item_id > 0 ? () => onOpenItem(line.item_id) : undefined} />
      <Breakdown rows={rows} categoryId={line.category_id} onOpenItem={onOpenItem} onOpenStockOrder={onOpenStockOrder} onOrder={() => onOrderMissing(line)} />
    </div>
  )
}

const saveLineNote = (line, text) => (line.stock_line_id ? patch(`stock-order-lines/${line.stock_line_id}`, { note: text }) : saveDemandNote(line.id, text))

function CommentMenu({ line, onChanged }) {
  const [editing, setEditing] = useState(false)
  const act = async (key) => {
    if (key === 'comment') return setEditing(true)
    try {
      await saveLineNote(line, '')
      onChanged()
    } catch (e) {
      notifyError(e)
    }
  }
  return (
    <>
      <Dropdown>
        <Button isIconOnly size="sm" variant="tertiary" aria-label="Line actions">
          <EllipsisVertical className="size-4" />
        </Button>
        <Dropdown.Popover className="min-w-52">
          <Dropdown.Menu aria-label="Line actions" onAction={(k) => act(String(k))}>
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
          </Dropdown.Menu>
        </Dropdown.Popover>
      </Dropdown>
      {editing && <CommentDialog title={line.note ? 'Edit comment' : 'Add comment'} value={line.note || ''} onClose={() => setEditing(false)} onSave={async (text) => { await saveLineNote(line, text); onChanged() }} />}
    </>
  )
}

function Group({ group, selection, expanded, onExpand, onAssign, onRemove, onOpenItem, onOpenStockOrder, onOrderMissing, onChanged, busy }) {
  const selectable = group.demands.filter(notOnStockOrder).map((d) => d.id)
  const { categoryById } = useCatalog()
  const fine = !!group.fineness
  const fineness = fine ? defaultFineness({ fineness: true }, categoryById[group.category_id]) : null
  const missing = fine ? Number(group.missing_at_default) || 0 : group.missing
  const notOrdered = fine ? Math.round((Number(group.not_ordered) || 0) * 1000) / 1000 : group.not_ordered
  const picked = selectable.filter((id) => selection.isSelected(id)).length
  const groupSel = {
    state: picked === 0 ? 'none' : picked === selectable.length ? 'all' : 'some',
    toggleAll: () => {
      const on = picked !== selectable.length
      for (const id of selectable) if (selection.isSelected(id) !== on) selection.toggle(id)
    },
  }
  return (
    <li>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 sm:px-4">
        {selectable.length > 0 ? <SelectAll selection={groupSel} ariaLabel={`Select all lines of ${group.name}`} /> : <span className="w-5" />}
        <div className="min-w-0 flex-1">
          {group.item_id > 0 ? (
            <button type="button" className="flex max-w-full items-center gap-1.5 text-left text-[13.5px] font-semibold hover:text-accent hover:underline" onClick={() => onOpenItem(group.item_id)}><CategoryIcon categoryId={group.category_id} className="size-4 shrink-0 text-muted" /><span className="truncate">{group.name}</span></button>
          ) : (
            <span className="flex max-w-full items-center gap-1.5 text-[13.5px] font-semibold"><CategoryIcon categoryId={group.category_id} className="size-4 shrink-0 text-muted" /><span className="truncate">{group.name}</span></span>
          )}
          <button type="button" className="text-left text-[11.5px] text-muted" onClick={onExpand}>{plural(group.demands.length, 'order line', 'order lines')}{group.code ? ` · ${group.code}` : ''}</button>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          {missing > 0 && <Tone tone="danger">{fineQty(missing, group.unit, fineness)} missing</Tone>}
          {group.not_ordered > 0 && group.not_ordered < group.missing && <Tone tone="danger">{fineQty(notOrdered, group.unit, fineness)} not ordered</Tone>}
          {group.free > 0 && <Tone tone="success">{qty(group.free, group.unit)} in stock</Tone>}
        </div>
        <IconButton icon={expanded ? ChevronUp : ChevronDown} label={expanded ? 'Hide order lines' : 'Show order lines'} variant="secondary" onPress={onExpand} />
      </div>
      <Expand open={expanded}>
        <ul className="mx-3 mb-2 divide-y divide-separator bg-surface-secondary/60 sm:mx-4">
          {group.demands.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
              {notOnStockOrder(d) ? <Check checked={selection.isSelected(d.id)} onChange={() => selection.toggle(d.id)} ariaLabel="Select line" /> : <span className="w-5 shrink-0" />}
              {d.stock_line_id ? <span className="text-[12.5px]">Stock order</span> : <OrderLink id={d.order.id} number={d.order.number} customer={d.order.customer} inList={d.order.in_list !== false} />}
              <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                <DemandBadge line={{ ...d, item_id: group.item_id, category_id: group.category_id, name: group.name, unit: group.unit, metal: group.metal, metal_items: group.metal_items }} onOpenItem={onOpenItem} onOpenStockOrder={onOpenStockOrder} onOrderMissing={onOrderMissing} />
                <PieceChips pieces={d.pieces} />
                <CommentText text={d.note} />
              </div>

              <div className="ml-auto flex items-center gap-1.5">
                {group.free > 0 && !!d.order.id && group.auto_assign === false && (
                  <Btn size="sm" variant="secondary" icon={Box} isPending={busy === d.id} onPress={() => onAssign(d)}>Assign from stock</Btn>
                )}
                {!d.order.id && !d.stock_line_id && (
                  <Button size="sm" variant="tertiary" onPress={() => onRemove([d])}>
                    <TrashBin className="size-4" />
                    Remove
                  </Button>
                )}
                <CommentMenu line={d} onChanged={onChanged} />
              </div>
            </li>
          ))}
        </ul>
      </Expand>
    </li>
  )
}

export default function RequiredPage() {
  const qc = useQueryClient()
  const confirm = useConfirm()
  const { catalog } = useCatalog()
  const [preItem, setPreItem] = useUrlParam('item', '')
  const [category, setCategory] = useState('')
  const [ordered, setOrdered] = useState('no')
  const [search, setSearch] = useState('')
  const term = useDebounced(search, 250)
  const [collapsed, setCollapsed] = useState(() => new Set())
  const [creating, setCreating] = useState(null)
  const [itemId, setItemId] = useState(null)
  const [busy, setBusy] = useState(null)
  const q = useQuery({ queryKey: ['required', category, ordered, term], queryFn: () => get('required', { category_id: category, ordered, search: term }), placeholderData: keepPreviousData })
  const groups = q.data?.groups || []
  const selectableIds = useMemo(() => groups.flatMap((g) => g.demands.filter(notOnStockOrder).map((d) => d.id)), [groups])
  const selection = useSelection(selectableIds)
  const byId = useMemo(() => new Map(groups.flatMap((g) => g.demands.map((d) => [String(d.id), d]))), [groups])
  const pickedOrphans = selection.ids.map((id) => byId.get(String(id))).filter((d) => d && !d.order.id)

  useEffect(() => {
    if (!preItem || !q.data) return
    const g = groups.find((x) => String(x.item_id) === preItem)
    if (g) {
      setCollapsed((s) => {
        const n = new Set(s)
        n.delete(g.item_id)
        return n
      })
      for (const d of g.demands) if (notOnStockOrder(d) && !selection.isSelected(d.id)) selection.toggle(d.id)
    }
    setPreItem('', { replace: true })
  }, [q.data])

  const totals = useMemo(() => ({ lines: groups.reduce((a, g) => a + g.demands.length, 0), items: groups.length }), [groups])
  const refresh = () => {
    for (const k of INVALIDATE) qc.invalidateQueries({ queryKey: [k] })
  }
  const [soId, setSoId] = useState(null)
  const openStockOrder = (id) => setSoId(id)
  const [ordering, setOrdering] = useState(null)
  const lineViews = useMemo(() => groups.flatMap((g) => g.demands.map((d) => ({ ...d, item_id: g.item_id, category_id: g.category_id, name: g.name, unit: g.unit, metal: g.metal, metal_items: g.metal_items }))), [groups])
  const lineById = useMemo(() => new Map(lineViews.map((d) => [String(d.id), d])), [lineViews])
  const assign = async (d) => {
    setBusy(d.id)
    try {
      await post(`demands/${d.id}/assign`, {})
      toast.success(`Assigned to #${d.order.number}`)
      refresh()
    } catch (e) {
      notifyError(e)
    } finally {
      setBusy(null)
    }
  }
  const remove = async (list) => {
    if (!list.length) return
    const ok = await confirm({
      title: list.length === 1 ? 'Remove this line?' : `Remove ${list.length} lines?`,
      message: 'These lines are not linked to any order. They disappear from the Required list.',
      confirmLabel: 'Remove',
      tone: 'danger',
    })
    if (!ok) return
    try {
      for (const d of list) await del(`demands/${d.id}`)
      selection.clear()
      refresh()
    } catch (e) {
      notifyError(e)
      refresh()
    }
  }

  return (
    <Page title="Stock that open orders need but is not in inventory yet">
      <div className="cjs-card mb-3 flex flex-wrap items-center gap-2 p-3">
        <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
          <Magnifier className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <TextInput value={search} onChange={setSearch} placeholder="Search item or order #" className="pl-8" />
        </div>
        <SelectField size="sm" className="w-[170px]" value={category} onChange={setCategory} allowEmpty emptyLabel="All categories" options={categoryOptions(catalog.categories)} ariaLabel="Category" />
        <SegmentedFilter value={ordered} onChange={setOrdered} items={[{ value: 'no', label: 'Not ordered' }, { value: 'yes', label: 'Ordered' }, { value: '', label: 'All' }]} />
        <div className="ml-auto text-[12.5px] text-muted">
          {plural(totals.lines, 'line', 'lines')} · {plural(totals.items, 'item', 'items')}
        </div>
      </div>
      <div className="mb-3">
        <BulkBar count={selection.count} onClear={selection.clear}>
          <Btn size="sm" variant="primary" icon={ShoppingCart} onPress={() => setCreating(selection.ids.map((id) => lineById.get(String(id))).filter((d) => d && notOnStockOrder(d)))}>Add to a stock order</Btn>
          {pickedOrphans.length > 0 && <Btn size="sm" variant="danger-soft" icon={TrashBin} onPress={() => remove(pickedOrphans)}>Remove {pickedOrphans.length} without order</Btn>}
        </BulkBar>
      </div>
      <div className="cjs-card overflow-hidden">
        {q.isLoading ? (
          <>
            <div className="flex items-center gap-3 border-b border-separator bg-surface-secondary px-3 py-2 sm:px-4" aria-hidden="true">
              <SkBox w={16} h={16} />
              <SkLine size={11} w={250} />
            </div>
            <SkeletonList rows={8} itemClassName="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 sm:px-4">
              {(i) => (
                <>
                  <SkBox w={16} h={16} />
                  <div className="min-w-0 flex-1">
                    <SkLine size={13.5} w={skw(i, 34)} />
                    <SkLine size={11.5} lh={1.75} w={72} />
                  </div>
                  <SkChip w={[84, 92, 78][i % 3]} />
                  <SkBox w={32} h={32} />
                </>
              )}
            </SkeletonList>
          </>
        ) : groups.length === 0 ? (
          <Empty icon={CircleCheck} title="Nothing missing" description="Every open order line has stock or is already ordered." />
        ) : (
          <>
            {selectableIds.length > 0 && (
              <div className="flex items-center gap-3 border-b border-separator bg-surface-secondary px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-muted sm:px-4">
                <SelectAll selection={selection} />
                <span>Select all lines that are missing</span>
              </div>
            )}
            <ul className="divide-y divide-separator">
              {groups.map((g) => (
                <Group
                  key={g.item_id}
                  group={g}
                  selection={selection}
                  busy={busy}
                  expanded={!collapsed.has(g.item_id)}
                  onExpand={() => setCollapsed((s) => {
                    const n = new Set(s)
                    n.has(g.item_id) ? n.delete(g.item_id) : n.add(g.item_id)
                    return n
                  })}
                  onAssign={assign}
                  onRemove={remove}
                  onOpenItem={setItemId}
                  onOpenStockOrder={openStockOrder}
                  onOrderMissing={setOrdering}
                  onChanged={refresh}
                />
              ))}
            </ul>
          </>
        )}
      </div>
      {ordering && (
        <StockOrderDialog
          lines={[ordering]}
          order={ordering.order?.id ? ordering.order : null}
          draftKey={`so:new:line:${ordering.id}`}
          isOpen
          onClose={() => setOrdering(null)}
          onDone={() => { refresh(); q.refetch() }}
        />
      )}
      {creating && (
        <StockOrderDialog
          lines={creating}
          draftKey={`so:new:sel:${creating.map((l) => l.id).join(',')}`}
          isOpen
          onClose={() => setCreating(null)}
          onDone={(so) => {
            selection.clear()
            refresh()
            openStockOrder(so.id)
          }}
        />
      )}
      <ItemDetailModal itemId={itemId} isOpen={!!itemId} onClose={() => setItemId(null)} onChanged={refresh} />
      <Suspense fallback={null}>
        {soId && <StockOrderModal id={soId} isOpen onClose={() => setSoId(null)} onChanged={refresh} />}
      </Suspense>
    </Page>
  )
}
