import { useMemo, useState } from 'react'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { Button } from '@heroui/react'
import { toast } from '../../lib/toast'
import { Magnifier, Box, Archive, ArrowUturnCcwLeft, BarsDescendingAlignLeft, Funnel, FunnelXmark } from '@gravity-ui/icons'
import { get, post } from '../../lib/api'
import { notifyError, useCatalog, useDebounced, useMediaQuery, useUrlParam } from '../../lib/hooks'
import { CategoryIcon } from '../../components/icons'
import { defaultFineness, fineQty, itemMissingAtDefault, money, qty } from '../../lib/format'
import { BulkBar, Btn, Check, Dialog, Empty, Expand, Field, MultiFilter, NumberInput, fromInside, Pager, SelectAll, SelectField, SkBox, SkChip, SkeletonList, SkeletonRows, SkLine, skw, TextInput, Tone, cx, useConfirm, useSelection } from '../../components/ui'
import CountForm from './CountForm'

const STOCK = [
  { value: '', label: 'All stock levels' },
  { value: 'in', label: 'Free stock' },
  { value: 'low', label: 'Low stock' },
  { value: 'out', label: 'Nothing available' },
  { value: 'missing', label: 'Needed by orders' },
]

const PIECE_STATES = [
  { value: 'in', label: 'Pieces in stock' },
  { value: 'to_send', label: 'Pieces to send' },
  { value: 'out', label: 'Pieces sent' },
]

export function StockBadges({ item, properties = true }) {
  const { categoryById } = useCatalog()
  return (
    <div className="flex flex-wrap items-center gap-1">
      {item.missing > 0 && <Tone tone="danger">{fineQty(itemMissingAtDefault(item, categoryById[item.category_id]), item.unit, defaultFineness(null, categoryById[item.category_id]))} needed</Tone>}
      {item.low && <Tone tone="warning">Low</Tone>}
      {item.archived && <Tone tone="default">Archived</Tone>}
      {properties && item.per_piece && <Tone tone="default">Per piece</Tone>}
      {properties && item.returnable && <Tone tone="accent">Returnable</Tone>}
    </div>
  )
}

function activeFilters(ff) {
  const out = {}
  for (const [id, v] of Object.entries(ff || {})) {
    if (!v) continue
    if (Array.isArray(v.choice_ids) && v.choice_ids.length) out[id] = { choice_ids: v.choice_ids.map(Number) }
    else if ((v.min ?? '') !== '' || (v.max ?? '') !== '') out[id] = { min: v.min ?? '', max: v.max ?? '', ...(v.unit_id ? { unit_id: Number(v.unit_id) } : {}) }
    else if (typeof v.text === 'string' && v.text.trim() !== '') out[id] = { text: v.text.trim() }
  }
  return out
}

function FieldFilters({ fields, units, value, onChange }) {
  const set = (id, patch) => onChange({ ...value, [id]: { ...(value[id] || {}), ...patch } })
  const unitLabel = (id) => units.find((u) => u.id === id)?.label || ''
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {fields.map((fl) => {
        const v = value[fl.id] || {}
        if (fl.type === 'select') {
          const options = fl.choices.filter((c) => !c.archived).map((c) => ({ value: String(c.id), label: c.label }))
          const picked = (v.choice_ids || []).map(String)
          return (
            <Field key={fl.id} label={fl.name}>
              <MultiFilter chips label={picked.length ? options.filter((o) => picked.includes(o.value)).map((o) => o.label).join(', ') : 'Any'} options={options} value={picked} onChange={(ids) => set(fl.id, { choice_ids: ids })} />
            </Field>
          )
        }
        if (fl.type === 'number') {
          const many = fl.unit_ids.length > 1
          const one = fl.unit_ids.length === 1 ? unitLabel(fl.unit_ids[0]) : ''
          return (
            <Field key={fl.id} label={one ? `${fl.name} (${one})` : fl.name}>
              <div className="flex min-w-0 items-center gap-1.5">
                <NumberInput size="sm" className="min-w-0 flex-1" aria-label={`${fl.name} from`} placeholder="From" value={v.min ?? ''} onChange={(x) => set(fl.id, { min: x })} />
                <span className="shrink-0 text-muted">-</span>
                <NumberInput size="sm" className="min-w-0 flex-1" aria-label={`${fl.name} to`} placeholder="To" value={v.max ?? ''} onChange={(x) => set(fl.id, { max: x })} />
                {many && <SelectField size="sm" className="w-[90px] shrink-0" value={v.unit_id ? String(v.unit_id) : ''} onChange={(x) => set(fl.id, { unit_id: x })} allowEmpty emptyLabel="Any unit" options={fl.unit_ids.map((u) => ({ value: String(u), label: unitLabel(u) }))} ariaLabel={`${fl.name} unit`} />}
              </div>
            </Field>
          )
        }
        return (
          <Field key={fl.id} label={fl.name}>
            <TextInput size="sm" value={v.text || ''} onChange={(x) => set(fl.id, { text: x })} placeholder="Contains" />
          </Field>
        )
      })}
    </div>
  )
}

function valueCell(it) {
  if (it.stock_value) return money(it.stock_value)
  return ''
}

export default function ItemsList({ onOpen }) {
  const qc = useQueryClient()
  const confirm = useConfirm()
  const { catalog, categoryById } = useCatalog()
  const wide = useMediaQuery('(min-width: 900px)')
  const [category, setCategory] = useUrlParam('cat', '')
  const [stock, setStock] = useUrlParam('stock', '')
  const [archived, setArchived] = useUrlParam('archived', 'active')
  const [pieceState, setPieceState] = useUrlParam('piece', '')
  const [search, setSearch] = useState('')
  const term = useDebounced(search, 250)
  const [more, setMore] = useState(false)
  const [ff, setFf] = useState({})
  const catFields = useMemo(() => (category ? categoryById[category]?.fields || [] : []), [category, categoryById])
  const ffJson = JSON.stringify(category ? activeFilters(ff) : {})
  const filtersParam = useDebounced(ffJson, 300)
  const ffCount = Object.keys(JSON.parse(ffJson)).length
  const pickCategory = (v) => {
    setCategory(v)
    setFf({})
    setPage(1)
  }
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState(false)
  const [counting, setCounting] = useState(null)
  const q = useQuery({
    queryKey: ['items', category, stock, archived, term, page, pieceState, filtersParam],
    queryFn: () => get('items', { category_id: category, stock, archived, search: term, page, per_page: 50, piece_state: pieceState, filters: filtersParam !== '{}' ? filtersParam : undefined }),
    placeholderData: keepPreviousData,
  })
  const items = q.data?.items || []
  const selection = useSelection(items.map((i) => i.id))
  const picked = items.filter((i) => selection.isSelected(i.id))
  const toArchive = picked.filter((i) => !i.archived)
  const toRestore = picked.filter((i) => i.archived)

  const setArchivedBulk = async (list, value) => {
    if (!list.length) return
    if (value) {
      const ok = await confirm({ title: `Archive ${list.length} ${list.length === 1 ? 'item' : 'items'}?`, message: 'Archived items are hidden from lists and pickers. Their stock and history stay. You can restore them any time.', confirmLabel: 'Archive' })
      if (!ok) return
    }
    setBusy(true)
    try {
      for (const it of list) await post(`items/${it.id}/archive`, { archived: value })
      toast.success(`${list.length} ${list.length === 1 ? 'item' : 'items'} ${value ? 'archived' : 'restored'}`)
      selection.clear()
      for (const k of ['items', 'item', 'item-search', 'bootstrap']) qc.invalidateQueries({ queryKey: [k] })
    } catch (e) {
      notifyError(e)
      qc.invalidateQueries({ queryKey: ['items'] })
    } finally {
      setBusy(false)
    }
  }

  const head = (
    <thead>
      <tr>
        <th className="w-10"><SelectAll selection={selection} /></th>
        <th>Item</th>
        <th className="text-right" title="Physically here">Stock</th>
        <th className="text-right" title="In stock and not reserved for an order">Available</th>
        <th className="text-right" title="Cost of what is in stock">Value</th>
        <th>Status</th>
      </tr>
    </thead>
  )
  return (
    <div className="flex flex-col gap-3">
      <div className="cjs-card flex flex-wrap items-center gap-2 p-3">
        <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
          <Magnifier className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <TextInput value={search} onChange={(v) => { setSearch(v); setPage(1) }} placeholder="Search name or code" className="pl-8" />
        </div>
        <div className="flex flex-wrap gap-1.5">
          <Button size="sm" variant={!category ? 'primary' : 'tertiary'} onPress={() => pickCategory('')}>All</Button>
          {catalog.categories.map((c) => (
            <Button key={c.id} size="sm" variant={String(c.id) === category ? 'primary' : 'tertiary'} onPress={() => pickCategory(String(c.id))}>
              <CategoryIcon icon={c.icon} className="size-4" />
              {c.name}
              <span className="text-[11px] opacity-70">{c.item_count}</span>
            </Button>
          ))}
        </div>
        <div className="flex w-full gap-2 sm:ml-auto sm:w-auto">
          <SelectField size="sm" className="flex-1 sm:w-[170px] sm:flex-none" value={stock} onChange={(v) => { setStock(v); setPage(1) }} options={STOCK} ariaLabel="Stock level" />
          <SelectField size="sm" className="flex-1 sm:w-[170px] sm:flex-none" value={pieceState} onChange={(v) => { setPieceState(v); setPage(1) }} allowEmpty emptyLabel="All pieces" options={PIECE_STATES} ariaLabel="Piece state" />
          <SelectField size="sm" className="flex-1 sm:w-[130px] sm:flex-none" value={archived} onChange={(v) => { setArchived(v); setPage(1) }} options={[{ value: 'active', label: 'Active' }, { value: 'archived', label: 'Archived' }, { value: 'all', label: 'All' }]} ariaLabel="Archived" />
        </div>
        <div className="flex w-full flex-wrap items-center gap-2">
            <Button size="sm" variant={more && catFields.length ? 'secondary' : 'tertiary'} isDisabled={!catFields.length} onPress={() => setMore((m) => !m)}>
              <Funnel className="size-4" />
              More filters
              {ffCount > 0 && <span className="bg-accent px-1.5 text-[11px] font-semibold text-accent-foreground">{ffCount}</span>}
            </Button>
            {!catFields.length && <span className="text-[12px] text-muted">{category ? 'This category has no fields to filter by.' : 'Pick a category to filter by its fields.'}</span>}
            {ffCount > 0 && (
              <Button size="sm" variant="tertiary" onPress={() => { setFf({}); setPage(1) }}>
                <FunnelXmark className="size-4" />
                Clear filters ({ffCount})
              </Button>
            )}
        </div>
        {catFields.length > 0 && (
          <div className="w-full">
            <Expand open={more}>
              <div className="border-t border-separator pt-3">
                <FieldFilters fields={catFields} units={catalog.units || []} value={ff} onChange={(v) => { setFf(v); setPage(1) }} />
              </div>
            </Expand>
          </div>
        )}
      </div>
      {wide && (
        <BulkBar count={selection.count} onClear={selection.clear}>
          <Btn size="sm" variant="secondary" icon={BarsDescendingAlignLeft} onPress={() => setCounting(picked)}>Count selected</Btn>
          {toArchive.length > 0 && <Btn size="sm" variant="secondary" icon={Archive} isPending={busy} onPress={() => setArchivedBulk(toArchive, true)}>Archive {toArchive.length}</Btn>}
          {toRestore.length > 0 && <Btn size="sm" variant="secondary" icon={ArrowUturnCcwLeft} isPending={busy} onPress={() => setArchivedBulk(toRestore, false)}>Restore {toRestore.length}</Btn>}
        </BulkBar>
      )}
      <div className="cjs-card">
        {q.isLoading ? (
          wide ? (
            <div className="cjs-scroll-x">
              <table className="cjs-table min-w-[860px]">
                {head}
                <tbody>
                  <SkeletonRows rows={10}>
                    {(i) => (
                      <>
                        <td><SkBox w={16} h={16} /></td>
                        <td>
                          <SkLine size={13} w={skw(i, 52)} />
                          <SkLine size={11.5} w={skw(i + 3, 14)} />
                        </td>
                        <td><SkLine size={13} w={40} className="justify-end" /></td>
                        <td><SkLine size={13} w={40} className="justify-end" /></td>
                        <td><SkLine size={13} w={i % 2 ? 56 : 0} className="justify-end" /></td>
                        <td>{i % 3 === 0 && <SkChip w={74} />}</td>
                      </>
                    )}
                  </SkeletonRows>
                </tbody>
              </table>
            </div>
          ) : (
            <SkeletonList rows={10} itemClassName="flex items-center justify-between gap-3 px-4 py-3">
              {(i) => (
                <>
                  <div className="min-w-0 flex-1">
                    <SkLine size={13.5} w={i % 2 ? "100%" : skw(i, 72)} />
                    {i % 2 === 1 && <SkLine size={13.5} w={skw(i, 50)} />}
                    <SkLine size={11.5} w={skw(i + 3, 24)} />
                    <SkChip w={74} className="mt-1" />
                  </div>
                  <div className="flex shrink-0 flex-col items-end">
                    <SkLine size={14} w={44} />
                    <SkLine size={11} w={40} />
                  </div>
                </>
              )}
            </SkeletonList>
          )
        ) : items.length === 0 ? (
          <Empty icon={Box} title="No items" description={term || stock || category || pieceState ? 'Nothing matches these filters.' : 'Create your first item or receive stock.'} />
        ) : wide ? (
          <div className="cjs-scroll-x">
            <table className="cjs-table min-w-[860px]">
              {head}
              <tbody>
                {items.map((it) => (
                  <tr key={it.id} className="cjs-row-click" onClick={(e) => fromInside(e) && onOpen(it.id)}>
                    <td onClick={(e) => e.stopPropagation()}>
                      <Check checked={selection.isSelected(it.id)} onChange={() => selection.toggle(it.id)} ariaLabel={`Select ${it.name}`} />
                    </td>
                    <td>
                      <div className="flex items-start gap-1.5 font-medium text-foreground"><span className="flex h-[19px] shrink-0 items-center text-muted"><CategoryIcon categoryId={it.category_id} className="size-4" /></span><span className="min-w-0">{it.name}</span></div>
                      <div className="text-[11.5px] text-muted">
                        {categoryById[it.category_id]?.name}
                        {it.code && ` · ${it.code}`}
                      </div>
                    </td>
                    <td className="text-right tabular-nums">{qty(it.on_hand, it.unit)}</td>
                    <td className="text-right tabular-nums">
                      <div className={cx('font-semibold', it.free <= 0 ? 'text-muted' : 'text-success-strong')}>{qty(it.free, it.unit)}</div>
                      {it.reserved > 0 && <div className="text-[11.5px] text-muted">{qty(it.reserved)} reserved</div>}
                    </td>
                    <td className="text-right tabular-nums text-muted">{valueCell(it)}</td>
                    <td><StockBadges item={it} properties={false} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <ul className="divide-y divide-separator">
            {items.map((it) => (
              <li key={it.id}>
                <button type="button" className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left" onClick={() => onOpen(it.id)}>
                  <div className="min-w-0">
                    <div className="flex items-start gap-1.5 text-[13.5px] font-medium" title={it.name}><span className="flex h-5 shrink-0 items-center text-muted"><CategoryIcon categoryId={it.category_id} className="size-4" /></span><span className="line-clamp-2 min-w-0 break-words">{it.name}</span></div>
                    <div className="text-[11.5px] text-muted">{categoryById[it.category_id]?.name}{it.code && ` · ${it.code}`}</div>
                    <div className="mt-1"><StockBadges item={it} properties={false} /></div>
                  </div>
                  <div className="shrink-0 text-right">
                    <div className={cx('text-[14px] font-semibold tabular-nums', it.free > 0 ? 'text-success-strong' : 'text-muted')}>{qty(it.on_hand, it.unit)}</div>
                    <div className="text-[11px] text-muted">in stock</div>
                    {it.reserved > 0 && <div className="text-[11px] text-muted">{qty(it.reserved)} reserved</div>}
                  </div>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
      {q.data && <Pager page={q.data.page} pages={q.data.pages} total={q.data.total} onChange={(p) => { setPage(p); selection.clear() }} label="items" />}
      <Dialog isOpen={!!counting} onClose={() => setCounting(null)} isDismissable={false} size="lg" title="Count selected items">
        {counting && <CountForm presetItems={counting} embedded draftKey={`count:sel:${counting.map((i) => i.id).sort((a, b) => a - b).join(',')}`} onCancel={() => setCounting(null)} onDone={() => { setCounting(null); selection.clear() }} />}
      </Dialog>
    </div>
  )
}
