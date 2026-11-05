import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Button, Spinner } from '@heroui/react'
import { toast } from '../../lib/toast'
import { Plus, TrashBin, ArrowDownToLine } from '@gravity-ui/icons'
import { get, post } from '../../lib/api'
import { notifyError, useCatalog } from '../../lib/hooks'
import { defaultFineness, fineQty, itemMissingAtDefault, money, qty } from '../../lib/format'
import { Btn, Card, Field, NumberInput, MoneyInput, TextArea, TextInput, IconButton, cx } from '../../components/ui'
import ItemPicker from '../../components/ItemPicker'
import { useDraft } from '../../lib/draft'

const MAX_PIECES = 200

let seq = 1
const blank = (item = null) => ({
  key: seq++,
  item,
  qty: '',
  unit_cost: item && item.default_cost !== null && item.default_cost !== undefined ? String(item.default_cost) : '',
  costFromDefault: !!(item && item.default_cost !== null && item.default_cost !== undefined),
  pieces: [],
})

function pieceCount(line) {
  if (!line.item?.per_piece) return 0
  const n = Number(line.qty)
  if (!Number.isFinite(n) || n <= 0 || Math.floor(n) !== n) return 0
  return Math.min(MAX_PIECES, n)
}

function duplicates(line) {
  const seen = new Map()
  const dup = new Set()
  line.pieces.forEach((p, i) => {
    const v = p.value.trim().toLowerCase()
    if (!v) return
    if (seen.has(v)) {
      dup.add(i)
      dup.add(seen.get(v))
    } else {
      seen.set(v, i)
    }
  })
  return dup
}

function PieceIdentifiers({ line, onChange }) {
  const count = pieceCount(line)
  const [loading, setLoading] = useState(false)
  useEffect(() => {
    if (!line.item?.per_piece) return
    let cancelled = false
    const resized = Array.from({ length: count }, (_, i) => line.pieces[i] || { value: '', auto: true })
    const needsAuto = line.item.code && resized.some((p) => p.auto)
    if (!needsAuto) {
      if (resized.length !== line.pieces.length) onChange(resized)
      return
    }
    setLoading(true)
    get(`items/${line.item.id}/next-identifiers`, { count })
      .then((res) => {
        if (cancelled) return
        const auto = res.identifiers || []
        onChange(resized.map((p, i) => (p.auto ? { value: auto[i] || '', auto: true } : p)))
      })
      .catch(() => {
        if (!cancelled && resized.length !== line.pieces.length) onChange(resized)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [line.item?.id, count])
  if (!line.item?.per_piece) return null
  if (!count) {
    return <div className="text-[12.5px] text-muted">Enter the quantity first. You then get one identifier box per piece.</div>
  }
  const dup = duplicates(line)
  const setOne = (i, value) => onChange(line.pieces.map((p, k) => (k === i ? { value, auto: false } : p)))
  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center gap-2 text-[12px] font-medium text-foreground/80">
        Piece identifiers
        {loading && <Spinner size="sm" />}
      </div>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        {line.pieces.map((p, i) => (
          <div key={i} className="flex min-w-0 items-center gap-2">
            <span className="w-7 shrink-0 text-right text-[11.5px] tabular-nums text-muted">{i + 1}.</span>
            <TextInput size="sm" value={p.value} onChange={(v) => setOne(i, v)} invalid={dup.has(i)} aria-label={`Identifier for piece ${i + 1}`} placeholder={line.item.code ? 'Automatic' : 'Optional'} />
          </div>
        ))}
      </div>
      <div className={cx('text-[12px]', dup.size ? 'text-danger' : 'text-muted')}>
        {dup.size
          ? 'Two pieces have the same identifier. Every piece needs its own.'
          : line.item.code
            ? `Numbered automatically from ${line.item.code}. Change any of them if needed.`
            : 'Optional. Give the item a code to number pieces automatically.'}
      </div>
      {Number(line.qty) > MAX_PIECES && <div className="text-[12px] text-danger">At most {MAX_PIECES} pieces per line. Add another line for the rest.</div>}
    </div>
  )
}

function lineState(l) {
  if (!l.item && l.qty === '') return 'blank'
  if (!l.item) return 'incomplete'
  const n = Number(l.qty)
  if (l.qty === '' || !(n > 0)) return 'incomplete'
  if (l.item.per_piece && (Math.floor(n) !== n || n > MAX_PIECES)) return 'incomplete'
  if (l.unit_cost !== '' && Number(l.unit_cost) < 0) return 'incomplete'
  if (duplicates(l).size) return 'incomplete'
  return 'ok'
}

export default function ReceiveForm({ presetItem, embedded, onDone, onCancel, draftKey }) {
  const draft = useDraft(draftKey || '')
  const [restored] = useState(() => (draftKey ? draft.read() : null))
  const qc = useQueryClient()
  const { categoryById } = useCatalog()
  const fineCat = (it) => (it && categoryById[it.category_id]?.has_fineness ? categoryById[it.category_id] : null)
  const [lines, setLines] = useState(() => (restored?.lines?.length ? restored.lines.map((l) => ({ ...l, key: seq++, pieces: Array.isArray(l.pieces) ? l.pieces : [] })) : [blank(presetItem || null)]))
  const [note, setNote] = useState(() => restored?.note || '')
  const dirty = !!note || lines.some((l) => (l.item && (!presetItem || l.item.id !== presetItem.id)) || l.qty !== '')
  useEffect(() => {
    if (draftKey) dirty ? draft.save({ lines, note }) : draft.clear()
  }, [draftKey, dirty, lines, note, draft])
  const cancel = () => {
    if (draftKey) draft.clear()
    if (onCancel) onCancel()
  }
  const [saving, setSaving] = useState(false)
  const setLine = (key, patch) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const states = lines.map(lineState)
  const valid = lines.filter((l, i) => states[i] === 'ok')
  const blocked = states.includes('incomplete')
  const total = valid.reduce((sum, l) => sum + (l.unit_cost !== '' ? Number(l.unit_cost) * Number(l.qty) : 0), 0)
  const pickItem = (l, it) => {
    if (!it) return
    const hasDefault = it.default_cost !== null && it.default_cost !== undefined
    const keepCost = l.unit_cost !== '' && !l.costFromDefault
    setLine(l.key, {
      item: it,
      pieces: [],
      unit_cost: keepCost ? l.unit_cost : hasDefault ? String(it.default_cost) : '',
      costFromDefault: !keepCost && hasDefault,
    })
  }
  const submit = async () => {
    if (!valid.length || blocked) return
    setSaving(true)
    try {
      await post('receipts', {
        note,
        lines: valid.map((l) => ({
          item_id: l.item.id,
          qty: l.qty,
          unit_cost: l.unit_cost,
          identifiers: l.item.per_piece ? l.pieces.map((p) => p.value.trim()) : [],
        })),
      })
      toast.success(`Received ${valid.length} ${valid.length === 1 ? 'line' : 'lines'}`)
      for (const k of ['items', 'item', 'movements', 'required', 'orders', 'order', 'bootstrap', 'documents', 'item-search', 'discrepancies']) qc.invalidateQueries({ queryKey: [k] })
      if (draftKey) draft.clear()
      setLines([blank()])
      setNote('')
      if (onDone) onDone()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  const body = (
    <div className="flex flex-col gap-3">
      {lines.map((l, idx) => (
        <div key={l.key} className="bg-surface-secondary/60 p-3">
          <div className="grid gap-2.5 md:grid-cols-[minmax(0,2fr)_120px_170px_auto] md:items-start">
            <Field label={lines.length > 1 ? `Item ${idx + 1}` : 'Item'} required>
              <ItemPicker value={l.item} onChange={(it) => pickItem(l, it)} />
            </Field>
            <Field label={`Quantity${l.item ? ` (${l.item.unit})` : ''}`} required error={l.item?.per_piece && l.qty !== '' && Math.floor(Number(l.qty)) !== Number(l.qty) ? 'Whole pieces only' : null}>
              <NumberInput value={l.qty} onChange={(v) => setLine(l.key, { qty: v })} min="0" step={l.item?.per_piece ? '1' : 'any'} />
            </Field>
            <Field label={`Cost per ${l.item?.unit || 'unit'}`} hint={l.costFromDefault ? 'Default cost of the item' : 'Optional'}>
              <MoneyInput value={l.unit_cost} onChange={(v) => setLine(l.key, { unit_cost: v, costFromDefault: false })} min="0" />
            </Field>
            <div className="flex justify-end md:pt-6">
              {lines.length > 1 && <IconButton icon={TrashBin} label="Remove line" onPress={() => setLines((ls) => ls.filter((x) => x.key !== l.key))} />}
            </div>
          </div>
          {l.item?.per_piece && (
            <div className="mt-3">
              <PieceIdentifiers line={l} onChange={(pieces) => setLine(l.key, { pieces })} />
            </div>
          )}
          {l.item && (
            <div className="mt-2 text-[12px] text-muted">
              Now {qty(l.item.on_hand, l.item.unit)} in stock.
              {l.item.missing > 0 ? ` Open orders still need ${fineQty(itemMissingAtDefault(l.item, fineCat(l.item)), l.item.unit, defaultFineness(null, fineCat(l.item)))}: they get it first, oldest order first.` : ''}
            </div>
          )}
        </div>
      ))}
      <div>
        <Button size="sm" variant="tertiary" onPress={() => setLines((ls) => [...ls, blank()])}>
          <Plus className="size-4" />
          Add another item
        </Button>
      </div>
      <Field label="Note for this delivery" hint="Optional, e.g. all deliveries for the first two weeks of October">
        <TextArea rows={2} value={note} onChange={setNote} />
      </Field>
      <div className="flex flex-wrap items-center justify-between gap-2 border-t border-separator pt-3">
        <span className="text-[13px] text-muted">
          {blocked ? 'Pick an item and enter a quantity on every line, or remove the line.' : `${valid.length} ${valid.length === 1 ? 'line' : 'lines'}${total > 0 ? `, total ${money(total)}` : ''}`}
        </span>
        <div className="flex flex-wrap items-center gap-2">
          {onCancel && <Button variant="tertiary" onPress={cancel}>Cancel</Button>}
          <Btn variant="primary" icon={ArrowDownToLine} isPending={saving} isDisabled={!valid.length || blocked} onPress={submit}>Receive stock</Btn>
        </div>
      </div>
    </div>
  )
  if (embedded) return body
  return (
    <Card title="Receive stock" description="Add new stock to inventory. Open orders waiting for these items get it automatically, oldest order first.">
      {body}
    </Card>
  )
}
