import { Fragment, useEffect, useMemo, useState } from 'react'
import { useQueries, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { Button, Spinner } from '@heroui/react'
import { toast } from '../../lib/toast'
import { TrashBin, BarsDescendingAlignLeft, Plus, Check as CheckIcon, Xmark } from '@gravity-ui/icons'
import { get, post } from '../../lib/api'
import { notifyError, useCatalog } from '../../lib/hooks'
import { CategoryIcon, categoryOptions } from '../../components/icons'
import { qty } from '../../lib/format'
import { BulkBar, Btn, Card, Check, Field, NumberInput, MoneyInput, TextArea, IconButton, SelectAll, SelectField, useConfirm, useSelection, cx } from '../../components/ui'
import ItemPicker from '../../components/ItemPicker'
import { useDraft } from '../../lib/draft'

const EMPTY = { counted: '', unit_cost: '', missing: [], found: [] }

function PieceChecklist({ detail, state, onChange }) {
  const inStock = detail.pieces.filter((p) => p.status === 'in' || p.status === 'reserved')
  const removed = detail.pieces.filter((p) => p.status === 'removed')
  return (
    <div className="flex flex-col gap-2 px-3 py-2.5">
      <div className="text-[12px] text-muted">Untick pieces that are not on the shelf. Pieces that are with customers are not counted here.</div>
      {inStock.length === 0 && <div className="text-[12.5px] text-muted">No pieces should be on the shelf.</div>}
      <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-4">
        {inStock.map((p) => (
          <Check key={p.id} checked={!state.missing.includes(p.id)} onChange={(v) => onChange({ ...state, missing: v ? state.missing.filter((x) => x !== p.id) : [...state.missing, p.id] })}>
            <span className="text-[12.5px]">{p.identifier || `#${p.id}`}</span>
            {p.status === 'reserved' && <span className="ml-1 text-[11px] text-warning">{detail.returnable ? 'to send' : 'assigned'}</span>}
          </Check>
        ))}
      </div>
      {removed.length > 0 && (
        <div className="mt-1 flex flex-col gap-1.5">
          <div className="text-[12px] font-medium text-muted">Removed earlier: tick the ones you found again</div>
          <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-4">
            {removed.map((p) => (
              <Check key={p.id} checked={state.found.includes(p.id)} onChange={(v) => onChange({ ...state, found: v ? [...state.found, p.id] : state.found.filter((x) => x !== p.id) })}>
                <span className="text-[12.5px]">{p.identifier || `#${p.id}`}</span>
              </Check>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

export default function CountForm({ presetItems = [], embedded, onDone, onCancel, draftKey }) {
  const draft = useDraft(draftKey || '')
  const [restored] = useState(() => (draftKey ? draft.read() : null))
  const qc = useQueryClient()
  const confirm = useConfirm()
  const { catalog } = useCatalog()
  const [ids, setIds] = useState(() => presetItems.map((i) => i.id))
  const [seed, setSeed] = useState(() => Object.fromEntries(presetItems.map((i) => [i.id, i])))
  const [rows, setRows] = useState(() => (restored?.rows && typeof restored.rows === 'object' ? restored.rows : {}))
  const [note, setNote] = useState(() => restored?.note || '')
  const dirty = !!note || Object.values(rows).some((r) => r && (r.counted !== '' || r.unit_cost !== '' || r.missing?.length || r.found?.length))
  useEffect(() => {
    if (draftKey) dirty ? draft.save({ rows, note }) : draft.clear()
  }, [draftKey, dirty, rows, note, draft])
  const cancel = () => {
    if (draftKey) draft.clear()
    if (onCancel) onCancel()
  }
  const [saving, setSaving] = useState(false)
  const [pick, setPick] = useState(null)
  const [pickKey, setPickKey] = useState(0)
  const [category, setCategory] = useState('')
  const [loadingCat, setLoadingCat] = useState(false)
  const fixed = embedded && presetItems.length > 0

  const fresh = useQuery({
    queryKey: ['items', 'count', ids.join(',')],
    queryFn: () => get('items', { ids, per_page: 500, archived: 'all' }),
    enabled: ids.length > 0,
    placeholderData: keepPreviousData,
  })
  const byId = useMemo(() => {
    const map = { ...seed }
    for (const it of fresh.data?.items || []) map[it.id] = it
    return map
  }, [seed, fresh.data])
  const items = ids.map((id) => byId[id]).filter((it) => it && it.on_hand !== undefined)
  const waiting = items.length < ids.length && fresh.isFetching
  const pieceIds = items.filter((i) => i.per_piece).map((i) => i.id)
  const pieceQueries = useQueries({ queries: pieceIds.map((id) => ({ queryKey: ['item', id], queryFn: () => get(`items/${id}`) })) })
  const details = Object.fromEntries(pieceIds.map((id, i) => [id, pieceQueries[i]]))
  const selection = useSelection(ids)

  const add = (list) => {
    setSeed((s) => ({ ...s, ...Object.fromEntries(list.map((i) => [i.id, i])) }))
    setIds((cur) => [...cur, ...list.map((i) => i.id).filter((id) => !cur.includes(id))])
  }
  const addCategory = async () => {
    if (!category) return
    setLoadingCat(true)
    try {
      const res = await get('items', { category_id: category, per_page: 500 })
      add(res.items || [])
      if (!res.items?.length) toast.info('This category has no active items')
    } catch (e) {
      notifyError(e)
    } finally {
      setLoadingCat(false)
    }
  }
  const rowOf = (id) => rows[id] || EMPTY
  const setRow = (id, patch) => setRows((s) => ({ ...s, [id]: { ...(s[id] || EMPTY), ...patch } }))

  const bulkMatch = () => setRows((s) => {
    const next = { ...s }
    for (const it of items) {
      if (!selection.isSelected(it.id)) continue
      next[it.id] = it.per_piece ? { ...EMPTY } : { ...(s[it.id] || EMPTY), counted: String(it.on_hand) }
    }
    return next
  })
  const bulkClear = () => setRows((s) => {
    const next = { ...s }
    for (const id of selection.ids) delete next[id]
    return next
  })
  const bulkRemove = () => {
    const drop = new Set(selection.ids)
    setIds((cur) => cur.filter((id) => !drop.has(String(id))))
    selection.clear()
  }

  const lines = []
  const releasing = []
  for (const item of items) {
    const r = rowOf(item.id)
    if (item.per_piece) {
      const detail = details[item.id]?.data
      if (r.missing.length || r.found.length) lines.push({ item_id: item.id, missing_piece_ids: r.missing, found_piece_ids: r.found })
      const reservedMissing = detail ? detail.pieces.filter((p) => p.status === 'reserved' && r.missing.includes(p.id)).length : 0
      if (reservedMissing) releasing.push(`${item.name}: ${reservedMissing} ${reservedMissing === 1 ? 'piece' : 'pieces'} set aside for orders`)
    } else if (r.counted !== '') {
      const diff = Number(r.counted) - item.on_hand
      const cost = r.unit_cost !== '' ? r.unit_cost : (diff > 0 && item.default_cost !== null && item.default_cost !== undefined ? item.default_cost : '')
      lines.push({ item_id: item.id, counted: r.counted, unit_cost: cost })
      if (item.free + diff < 0) releasing.push(`${item.name}: ${qty(-(item.free + diff), item.unit)} set aside for orders`)
    }
  }
  const invalid = items.some((it) => !it.per_piece && rowOf(it.id).counted !== '' && Number(rowOf(it.id).counted) < 0)

  const submit = async () => {
    if (!lines.length || invalid) return
    if (releasing.length) {
      const ok = await confirm({
        title: 'Some orders lose their assigned stock',
        tone: 'warning',
        confirmLabel: 'Apply count',
        message: (
          <div className="flex flex-col gap-2 text-[13.5px] text-muted">
            <p>You counted less than what is set aside for orders. These orders will show the stock as missing again:</p>
            <ul className="ml-4 list-disc">{releasing.map((t, i) => <li key={i}>{t}</li>)}</ul>
          </div>
        ),
      })
      if (!ok) return
    }
    setSaving(true)
    try {
      const res = await post('counts', { note, lines })
      const changed = (res.changes || []).filter((c) => c.difference !== 0).length
      toast.success(changed ? `Stock count applied: ${changed} ${changed === 1 ? 'item' : 'items'} changed` : 'Stock count saved: everything matched')
      for (const k of ['items', 'item', 'movements', 'required', 'orders', 'order', 'bootstrap', 'documents', 'discrepancies', 'item-search']) qc.invalidateQueries({ queryKey: [k] })
      if (draftKey) draft.clear()
      setRows({})
      setNote('')
      if (!fixed) {
        setIds([])
        selection.clear()
      }
      if (onDone) onDone()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }

  const body = (
    <div className="flex flex-col gap-3">
      {!fixed && (
        <div className="flex flex-col gap-3">
          <Field label="Add an item">
            <div className="flex gap-2">
              <div className="min-w-0 flex-1">
                <ItemPicker key={pickKey} value={pick} allowCreate={false} onChange={setPick} placeholder="Search an item to count" />
              </div>
              <Btn variant="secondary" icon={Plus} isDisabled={!pick} onPress={() => { add([pick]); setPick(null); setPickKey((k) => k + 1) }}>Add item</Btn>
            </div>
          </Field>
          <Field label="Add a whole category" hint="Adds every active item of the category. Untick or remove the ones you do not count.">
            <div className="flex gap-2">
              <SelectField className="min-w-0 flex-1" value={category} onChange={setCategory} options={categoryOptions(catalog.categories)} placeholder="Choose a category" ariaLabel="Category" />
              <Btn variant="secondary" icon={Plus} isPending={loadingCat} isDisabled={!category} onPress={addCategory}>Add category</Btn>
            </div>
          </Field>
        </div>
      )}
      {ids.length === 0 && <div className="bg-surface-secondary/60 p-6 text-center text-[13px] text-muted">Add the items you are counting. Enter what is physically there and the system records the difference.</div>}
      {ids.length > 0 && (
        <>
          {!fixed && (
            <BulkBar count={selection.count} onClear={selection.clear}>
              <Btn size="sm" variant="secondary" icon={CheckIcon} onPress={bulkMatch}>Counted = expected</Btn>
              <Btn size="sm" variant="secondary" icon={Xmark} onPress={bulkClear}>Clear counted</Btn>
              <Btn size="sm" variant="danger-soft" icon={TrashBin} onPress={bulkRemove}>Remove from count</Btn>
            </BulkBar>
          )}
          <div className={cx('cjs-scroll-x', embedded ? '' : 'border border-separator')}>
            <table className="cjs-table min-w-[640px]">
              <thead>
                <tr>
                  {!fixed && <th className="w-10"><SelectAll selection={selection} /></th>}
                  <th>Item</th>
                  <th className="text-right">Expected</th>
                  <th className="w-36">Counted</th>
                  <th className="text-right">Difference</th>
                  <th className="w-44">Cost for the extra</th>
                  {!fixed && <th className="w-px"><span className="sr-only">Remove</span></th>}
                </tr>
              </thead>
              <tbody>
                {items.map((item) => {
                  const r = rowOf(item.id)
                  const dq = details[item.id]
                  const detail = dq?.data
                  const diff = item.per_piece ? (r.missing.length || r.found.length ? r.found.length - r.missing.length : null) : r.counted === '' ? null : Number(r.counted) - item.on_hand
                  const shelf = detail ? detail.pieces.filter((p) => p.status === 'in' || p.status === 'reserved').length : item.on_hand
                  return (
                    <Fragment key={item.id}>
                      <tr className={cx('[&>td]:align-middle', item.per_piece && 'cjs-row-open')}>
                        {!fixed && (
                          <td><Check checked={selection.isSelected(item.id)} onChange={() => selection.toggle(item.id)} ariaLabel={`Select ${item.name}`} /></td>
                        )}
                        <td>
                          <div className="flex items-start gap-1.5 font-medium text-foreground"><span className="flex h-[19px] shrink-0 items-center text-muted"><CategoryIcon categoryId={item.category_id ?? detail?.category_id} className="size-4" /></span><span className="min-w-0">{item.name}</span></div>
                          {item.code && <div className="text-[11.5px] text-muted">{item.code}</div>}
                        </td>
                        <td className="text-right tabular-nums">{qty(item.per_piece ? shelf : item.on_hand, item.unit)}</td>
                        <td>
                          {item.per_piece ? (
                            <span className="text-[12.5px] text-muted">{detail ? `${shelf - r.missing.length + r.found.length} on the shelf` : <Spinner size="sm" />}</span>
                          ) : (
                            <NumberInput size="sm" value={r.counted} onChange={(v) => setRow(item.id, { counted: v })} min="0" step="any" aria-label={`Counted ${item.name}`} placeholder={String(item.on_hand)} />
                          )}
                        </td>
                        <td className={cx('text-right font-semibold tabular-nums', diff > 0 ? 'text-success-strong' : diff < 0 ? 'text-danger' : 'text-muted')}>
                          {diff === null ? '' : diff === 0 ? 'Matches' : `${diff > 0 ? '+' : ''}${qty(diff, item.unit)}`}
                        </td>
                        <td>
                          {!item.per_piece && diff > 0 && (
                            <MoneyInput size="sm" value={r.unit_cost} onChange={(v) => setRow(item.id, { unit_cost: v })} min="0" aria-label="Cost per unit" placeholder={item.default_cost !== null && item.default_cost !== undefined ? String(item.default_cost) : 'Optional'} />
                          )}
                        </td>
                        {!fixed && (
                          <td className="py-1.5">
                            <IconButton icon={TrashBin} label="Remove from count" onPress={() => { setIds((cur) => cur.filter((x) => x !== item.id)); setRows((s) => { const n = { ...s }; delete n[item.id]; return n }) }} />
                          </td>
                        )}
                      </tr>
                      {item.per_piece && (
                        <tr className="cjs-row-expanded">
                          <td colSpan={fixed ? 5 : 7}>
                            {detail ? <PieceChecklist detail={detail} state={r} onChange={(s) => setRow(item.id, s)} /> : <div className="flex justify-center py-3"><Spinner size="sm" /></div>}
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
            {waiting && <div className="flex justify-center py-4"><Spinner size="sm" /></div>}
          </div>
          <Field label="Note" hint='Optional, e.g. "Year-end count"'>
            <TextArea rows={2} value={note} onChange={setNote} />
          </Field>
          <div className="flex flex-wrap items-center justify-between gap-2 border-t border-separator pt-3">
            <span className="text-[13px] text-muted">{ids.length} {ids.length === 1 ? 'item' : 'items'} in this count, {lines.length} counted</span>
            <div className="flex flex-wrap items-center gap-2">
              {onCancel && <Button variant="tertiary" onPress={cancel}>Cancel</Button>}
              <Btn variant="primary" icon={BarsDescendingAlignLeft} isPending={saving} isDisabled={!lines.length || invalid} onPress={submit}>Apply count</Btn>
            </div>
          </div>
        </>
      )}
    </div>
  )
  if (embedded) return body
  return (
    <Card title="Stock count" description="Enter what is actually on the shelf. Differences are saved as adjustments in the history.">
      {body}
    </Card>
  )
}
