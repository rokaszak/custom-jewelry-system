import { useEffect, useMemo, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Button, ListBox, Select } from '@heroui/react'
import { Plus } from '@gravity-ui/icons'
import { Dialog, Field, TextInput, NumberInput, MoneyInput, SelectField, Toggle, Btn, Notice } from './ui'
import { useCatalog, notifyError } from '../lib/hooks'
import { toast } from '../lib/toast'
import { categoryOptions } from './icons'
import { patch, post } from '../lib/api'
import { qty as fmtQty } from '../lib/format'
import { useDraft } from '../lib/draft'

function previewName(category, values, unitById) {
  if (!category) return ''
  const parts = []
  for (const f of category.fields) {
    if (!f.in_name) continue
    const v = values[f.id]
    if (!v) continue
    if (f.type === 'select' && v.choice_id) {
      const c = f.choices.find((x) => x.id === Number(v.choice_id))
      if (c) parts.push(c.label)
    } else if (f.type === 'number' && v.value_number !== undefined && v.value_number !== '' && v.value_number !== null) {
      parts.push(`${fmtQty(v.value_number)} ${unitById[v.unit_id]?.label || ''}`.trim())
    } else if (f.type === 'text' && v.value_text) {
      parts.push(v.value_text)
    }
  }
  return parts.join(' · ')
}

function norm(s) {
  return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
}

function words(s) {
  return ` ${norm(s).replace(/[^a-z0-9.,]+/g, ' ').replace(/\s+/g, ' ').trim()} `
}

function guessFromText(text, categories, unitById, fixedId, preferredId) {
  if (!text || !categories.length) return null
  let rest = words(text)
  let cat = fixedId ? categories.find((c) => c.id === Number(fixedId)) : null
  if (!cat) {
    cat = [...categories].sort((a, b) => b.name.length - a.name.length).find((c) => words(c.name).trim() && rest.includes(words(c.name)))
    if (cat) rest = rest.replace(words(cat.name), ' ')
  }
  if (!cat) cat = categories.find((c) => c.id === Number(preferredId)) || categories[0]
  const values = {}
  for (const f of cat.fields || []) {
    if (f.type !== 'select') continue
    const hit = f.choices
      .filter((c) => !c.archived && words(c.label).trim())
      .sort((a, b) => b.label.length - a.label.length)
      .find((c) => rest.includes(words(c.label)))
    if (hit) {
      values[f.id] = { choice_id: hit.id }
      rest = rest.replace(words(hit.label), ' ')
    }
  }
  for (const f of cat.fields || []) {
    if (f.type !== 'number' || !f.unit_ids.length) continue
    const m = rest.match(/(\d+(?:[.,]\d+)?)\s*([a-z]+)?/)
    if (!m) continue
    let unitId = f.unit_ids[0]
    if (m[2]) {
      const u = f.unit_ids.find((id) => norm(unitById[id]?.label) === m[2])
      if (u) unitId = u
    }
    values[f.id] = { value_number: m[1].replace(',', '.'), unit_id: unitId }
    rest = rest.replace(m[0], ' ')
  }
  return { category_id: cat.id, values }
}

function freshForm({ item, categoryId, defaultCategoryId, initialName, catalog, categoryById, unitById }) {
  if (item?.id) {
    return {
      category_id: item.category_id,
      values: { ...(item.values || {}) },
      name: item.name_custom ? (item.metal || item.name) : '',
      name_edited: !!item.name_custom,
      praba: item.praba !== null && item.praba !== undefined ? String(item.praba) : '',
      code: item.code || '',
      unit_id: item.unit_id || '',
      per_piece: !!item.per_piece,
      returnable: !!item.returnable,
      default_cost: item.default_cost ?? '',
      default_price: item.default_price ?? '',
      reorder_level: item.reorder_level ?? '',
    }
  }
  const text = (initialName || '').trim()
  const guess = text ? guessFromText(text, catalog.categories, unitById, categoryId, defaultCategoryId) : null
  const catId = Number(categoryId || guess?.category_id || defaultCategoryId || catalog.categories[0]?.id || 0) || ''
  const cat = categoryById[catId]
  const values = guess && guess.category_id === catId ? guess.values : {}
  const matched = Object.keys(values).length > 0
  return {
    category_id: catId,
    values,
    name: text && !matched ? text : '',
    name_edited: !!(text && !matched),
    praba: '',
    code: '',
    unit_id: cat?.unit_id || '',
    per_piece: !!cat?.per_piece,
    returnable: !!cat?.returnable,
    default_cost: '',
    default_price: '',
    reorder_level: '',
  }
}

function ChoiceSelect({ field, value, onChange, onAdded }) {
  const [adding, setAdding] = useState(false)
  const [label, setLabel] = useState('')
  const [busy, setBusy] = useState(false)
  const options = field.choices.filter((c) => !c.archived || c.id === Number(value))
  const noun = field.name.toLowerCase()
  const cancel = () => {
    setAdding(false)
    setLabel('')
  }
  const add = async () => {
    if (!label.trim()) return
    setBusy(true)
    try {
      const res = await post(`fields/${field.id}/choices`, { label: label.trim() })
      await onAdded(res.catalog)
      onChange(String(res.id))
      cancel()
    } catch (e) {
      notifyError(e)
    } finally {
      setBusy(false)
    }
  }
  if (adding) {
    return (
      <div className="flex flex-col gap-1.5">
        <TextInput
          autoFocus
          value={label}
          onChange={setLabel}
          placeholder={`New ${noun}`}
          aria-label={`New ${noun}`}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              add()
            } else if (e.key === 'Escape') {
              e.preventDefault()
              e.stopPropagation()
              cancel()
            }
          }}
        />
        <div className="grid grid-cols-2 gap-1.5">
          <Btn size="sm" variant="primary" isPending={busy} isDisabled={!label.trim()} onPress={add}>Save</Btn>
          <Button size="sm" variant="tertiary" onPress={cancel}>Cancel</Button>
        </div>
      </div>
    )
  }
  return (
    <Select
      className="min-w-0"
      placeholder="Choose"
      aria-label={field.name}
      selectedKey={value ? String(value) : null}
      onSelectionChange={(key) => {
        if (key === '__add') {
          setAdding(true)
          return
        }
        onChange(!key || key === '__none' ? '' : String(key))
      }}
    >
      <Select.Trigger>
        <Select.Value />
        <Select.Indicator />
      </Select.Trigger>
      <Select.Popover className="w-(--trigger-width)">
        <ListBox>
          <ListBox.Item id="__add" textValue={`Add new ${noun}`}>
            <span className="flex items-center gap-2 font-medium text-accent">
              <Plus className="size-4 shrink-0" />
              Add new {noun}
            </span>
          </ListBox.Item>
          {!field.required && value ? (
            <ListBox.Item id="__none" textValue="Not set">
              <span className="text-muted">Not set</span>
            </ListBox.Item>
          ) : null}
          {options.map((c) => (
            <ListBox.Item key={c.id} id={String(c.id)} textValue={c.label}>
              <span className="min-w-0 flex-1">{c.label}</span>
              <ListBox.ItemIndicator />
            </ListBox.Item>
          ))}
        </ListBox>
      </Select.Popover>
    </Select>
  )
}

function hasValue(field, v) {
  if (!v) return false
  if (field.type === 'select') return !!v.choice_id
  if (field.type === 'number') return v.value_number !== undefined && v.value_number !== null && v.value_number !== ''
  return !!(v.value_text && String(v.value_text).trim())
}

export function FieldValueInput({ field, value, onChange, unitById, onCatalog }) {
  if (field.type === 'select') {
    return <ChoiceSelect field={field} value={value?.choice_id} onChange={(id) => onChange(id ? { choice_id: Number(id) } : null)} onAdded={onCatalog} />
  }
  if (field.type === 'number') {
    const units = field.unit_ids.length ? field.unit_ids : []
    const unitId = value?.unit_id || units[0]
    return (
      <div className="flex gap-1.5">
        <NumberInput className="flex-1" aria-label={field.name} value={value?.value_number ?? ''} onChange={(v) => onChange(v === '' ? null : { value_number: v, unit_id: unitId })} />
        {units.length > 1 ? (
          <SelectField className="w-24" value={String(unitId)} onChange={(u) => onChange({ value_number: value?.value_number ?? '', unit_id: Number(u) })} options={units.map((u) => ({ value: String(u), label: unitById[u]?.label || u }))} ariaLabel="Unit" />
        ) : units.length === 1 ? (
          <span className="flex items-center px-1 text-[13px] text-muted">{unitById[units[0]]?.label}</span>
        ) : null}
      </div>
    )
  }
  return <TextInput aria-label={field.name} value={value?.value_text ?? ''} onChange={(v) => onChange(v === '' ? null : { value_text: v })} />
}

export function ContextNote({ context }) {
  const meta = Array.isArray(context?.meta) ? context.meta : []
  const comment = String(context?.comment || '').trim()
  if (!meta.length && !comment) return null
  return (
    <div className="sticky top-0 z-10 flex max-h-[30vh] flex-col gap-2 overflow-y-auto border border-separator bg-surface-secondary px-3 py-2 shadow-sm">
      {meta.length > 0 && (
        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[12.5px] leading-snug">
          {meta.map((m, i) => (
            <div key={i} className="contents">
              <dt className="whitespace-nowrap text-muted">{m.key}</dt>
              <dd className="min-w-0 break-words text-foreground">{m.value}</dd>
            </div>
          ))}
        </dl>
      )}
      {comment && (
        <div>
          <div className="mb-0.5 text-[11px] font-semibold uppercase tracking-wide text-muted">Komentaras</div>
          <div className="whitespace-pre-wrap break-words text-[13px] leading-relaxed text-foreground">{comment}</div>
        </div>
      )}
    </div>
  )
}

export default function ItemDialog({ isOpen, onClose, item, categoryId, defaultCategoryId, initialName = '', context, onSaved }) {
  const qc = useQueryClient()
  const { catalog, unitById, categoryById } = useCatalog()
  const editing = !!item?.id
  const draft = useDraft(editing ? `item:${item.id}` : categoryId ? `item:new:${categoryId}` : 'item:new')
  const [form, setForm] = useState(null)
  const [dirty, setDirty] = useState(false)
  const [restored, setRestored] = useState(false)
  const [nameCleared, setNameCleared] = useState(false)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const ready = catalog.categories.length > 0

  useEffect(() => {
    if (!isOpen) {
      setDirty(false)
      return
    }
    if (!ready) return
    setError(null)
    setDirty(false)
    setNameCleared(false)
    const saved = draft.read()
    if (saved?.form && (editing || (categoryById[saved.form.category_id] && (!defaultCategoryId || Number(saved.form.category_id) === Number(defaultCategoryId))))) {
      setForm(saved.form)
      setRestored(true)
      return
    }
    setRestored(false)
    setForm(freshForm({ item, categoryId, defaultCategoryId, initialName, catalog, categoryById, unitById }))
  }, [isOpen, item?.id, ready])

  useEffect(() => {
    if (isOpen && dirty && form) draft.save({ form })
  }, [form, dirty, isOpen, draft])

  const category = form ? categoryById[form.category_id] : null
  const preview = useMemo(() => (form && category ? previewName(category, form.values, unitById) : ''), [form, category, unitById])
  const hasStock = editing && (item.on_hand > 0 || (item.batches && item.batches.length > 0))

  if (!form) return null

  const fields = category?.fields || []
  const set = (patchObj) => {
    setDirty(true)
    setForm((f) => ({ ...f, ...patchObj }))
  }
  const setValue = (fieldId, v) => {
    setDirty(true)
    setForm((f) => {
      const values = { ...f.values }
      if (v === null) delete values[fieldId]
      else values[fieldId] = v
      return { ...f, values }
    })
  }
  const changeCategory = (id) => {
    const cat = categoryById[id]
    set({ category_id: Number(id), values: {}, unit_id: cat?.unit_id || '', per_piece: !!cat?.per_piece, returnable: !!cat?.returnable })
  }
  const setName = (v) => {
    if (!v.trim()) {
      set({ name: '', name_edited: false })
      setNameCleared(true)
    } else {
      set({ name: v, name_edited: true })
      setNameCleared(false)
    }
  }

  const refreshCatalog = async (newCatalog) => {
    if (newCatalog) qc.setQueryData(['catalog'], { units: newCatalog.units, categories: newCatalog.categories.filter((c) => !c.archived) })
    await qc.invalidateQueries({ queryKey: ['catalog'] })
  }

  const customName = form.name_edited && form.name.trim() && form.name.trim() !== preview ? form.name.trim() : ''
  const finalName = customName || preview
  const missing = fields.filter((f) => f.required && !hasValue(f, form.values[f.id])).map((f) => f.name)
  const tracked = !!category?.has_fineness
  const gramUnit = tracked ? catalog.units.find((u) => u.label === 'g') || null : null
  const unitId = gramUnit ? gramUnit.id : form.unit_id
  const prabaText = String(form.praba ?? '').trim().replace(',', '.')
  const prabaNum = prabaText === '' ? null : Number(prabaText)
  const prabaOk = prabaNum !== null && Number.isFinite(prabaNum) && prabaNum > 0 && prabaNum <= 1000
  const prabaLocked = tracked && hasStock && item?.praba !== null && item?.praba !== undefined
  const prabaSuffix = prabaOk ? fmtQty(prabaNum) : ''
  const baseName = tracked && prabaOk && finalName.endsWith(` ${prabaSuffix}`) && finalName.length > prabaSuffix.length + 1 ? finalName.slice(0, -(prabaSuffix.length + 1)).replace(/[\s·]+$/, '') : finalName
  const shownName = tracked && baseName && prabaOk ? `${baseName} ${prabaSuffix}` : finalName
  const reason = !form.category_id
    ? 'Choose a category.'
    : missing.length
      ? `Fill in ${missing.join(', ')}.`
      : !finalName
        ? fields.length ? 'Fill in the details or type a name.' : 'Type a name.'
        : tracked && !prabaOk
          ? 'Enter the praba, greater than 0 and at most 1000.'
          : !unitId
            ? 'Choose a stock unit.'
            : ''

  const close = () => onClose()
  const cancel = () => {
    draft.clear()
    onClose()
  }

  const save = async () => {
    if (reason) return
    setSaving(true)
    setError(null)
    const payload = {
      category_id: form.category_id,
      values: form.values,
      name: customName || (fields.length ? '' : form.name.trim()),
      name_custom: customName ? 1 : 0,
      code: form.code,
      unit_id: unitId,
      per_piece: form.per_piece ? 1 : 0,
      returnable: form.returnable ? 1 : 0,
      default_cost: form.default_cost,
      default_price: form.default_price,
      reorder_level: form.reorder_level,
      ...(tracked ? { praba: prabaNum } : {}),
    }
    try {
      const res = editing ? await patch(`items/${item.id}`, payload) : await post('items', payload)
      draft.clear()
      qc.invalidateQueries({ queryKey: ['items'] })
      qc.invalidateQueries({ queryKey: ['item'] })
      qc.invalidateQueries({ queryKey: ['item-search'] })
      qc.invalidateQueries({ queryKey: ['item-suggest'] })
      qc.invalidateQueries({ queryKey: ['number-facets'] })
      qc.invalidateQueries({ queryKey: ['catalog'] })
      if (res && res.notice) toast.info(res.notice)
      if (onSaved) onSaved(res.item)
      onClose()
    } catch (e) {
      setError(e)
    } finally {
      setSaving(false)
    }
  }

  const restore = async (id) => {
    try {
      const res = await post(`items/${id}/archive`, { archived: false })
      draft.clear()
      qc.invalidateQueries({ queryKey: ['items'] })
      qc.invalidateQueries({ queryKey: ['item-suggest'] })
      qc.invalidateQueries({ queryKey: ['number-facets'] })
      if (onSaved) onSaved(res.item)
      onClose()
    } catch (e) {
      notifyError(e)
    }
  }

  const nameValue = form.name_edited ? form.name : nameCleared ? '' : preview
  const nameHint = fields.length
    ? form.name_edited
      ? 'Your own name. Clear the field to build it from the details again.'
      : 'Built from the details above. Type here to use your own name.'
    : undefined

  return (
    <Dialog
      isOpen={isOpen}
      onClose={close}
      isDismissable={false}
      size="md"
      title={editing ? 'Edit item' : 'New inventory item'}
      description={editing ? item.name : 'Things you stock and use: stones, metals, packaging, kits.'}
      footer={
        <>
          {reason && <span className="mr-auto self-center text-[12px] text-muted">{reason}</span>}
          <Button variant="tertiary" onPress={cancel}>Cancel</Button>
          <Btn variant="primary" isPending={saving} isDisabled={!!reason} onPress={save}>{editing ? 'Save item' : 'Create item'}</Btn>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <ContextNote context={context} />
        {restored && <div className="text-[12px] text-muted">Restored what you entered last time. Cancel clears it.</div>}
        {error && (
          <Notice tone="danger" title={error.message}>
            {error.code === 'cjs_duplicate' && error.data?.item_id ? (
              error.data.archived ? (
                <button type="button" className="font-medium underline" onClick={() => restore(error.data.item_id)}>Restore the archived item instead</button>
              ) : (
                'Change a value or use the existing item.'
              )
            ) : null}
          </Notice>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Category" required>
            <SelectField isDisabled={editing} value={form.category_id ? String(form.category_id) : ''} onChange={changeCategory} options={categoryOptions(catalog.categories)} ariaLabel="Category" />
          </Field>
          <Field label="Item code" hint={!tracked && (category?.returnable || form.per_piece) ? "Optional. Pieces get identifiers like CODE-01." : "Optional."}>
            <TextInput value={form.code} onChange={(v) => set({ code: v.toUpperCase() })} placeholder="e.g. JJ-MR" />
          </Field>
        </div>
        {fields.length > 0 && (
          <div className="grid gap-3 bg-surface-secondary/60 p-3 sm:grid-cols-2">
            {fields.map((f) => (
              <Field key={f.id} label={f.name} required={f.required}>
                <FieldValueInput field={f} value={form.values[f.id]} onChange={(v) => setValue(f.id, v)} unitById={unitById} onCatalog={refreshCatalog} />
              </Field>
            ))}
          </div>
        )}
        {tracked ? (
          <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
            <Field label="Name" required={!fields.length} hint={nameHint || 'Items with the same name are the same metal.'}>
              <TextInput value={nameValue} onChange={setName} onBlur={() => setNameCleared(false)} placeholder={preview || (fields.length ? 'Fill in the details above' : 'e.g. Auksas')} />
            </Field>
            <Field label="Praba" required error={form.praba !== '' && !prabaOk ? 'Greater than 0 and at most 1000.' : undefined} hint={prabaLocked ? 'Locked: item has stock' : undefined}>
              <NumberInput value={form.praba} onChange={(v) => set({ praba: v })} min="0" disabled={prabaLocked} invalid={form.praba !== '' && !prabaOk} aria-label="Praba" placeholder="e.g. 950" />
            </Field>
            {shownName && prabaOk && <div className="text-[12.5px] text-muted sm:col-span-2">Shown as <span className="font-medium text-foreground">{shownName}</span></div>}
          </div>
        ) : (
          <Field label="Name" required={!fields.length} hint={nameHint}>
            <TextInput value={nameValue} onChange={setName} onBlur={() => setNameCleared(false)} placeholder={preview || (fields.length ? 'Fill in the details above' : 'Item name')} />
          </Field>
        )}
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Stock unit" required hint={gramUnit ? 'Metal is kept in grams' : hasStock ? 'Locked: item has stock' : undefined}>
            <SelectField isDisabled={hasStock || !!gramUnit} value={unitId ? String(unitId) : ''} onChange={(v) => set({ unit_id: Number(v) })} options={catalog.units.map((u) => ({ value: String(u.id), label: u.name ? `${u.label} (${u.name})` : u.label }))} ariaLabel="Unit" />
          </Field>
          <Field label="Default cost per unit" hint="Optional. Used as the cost when stock is received without a price.">
            <MoneyInput value={form.default_cost} onChange={(v) => set({ default_cost: v })} min="0" aria-label="Default cost per unit" />
          </Field>
          <Field label="Reorder level" hint="Optional. Warns when available stock drops to this.">
            <NumberInput value={form.reorder_level} onChange={(v) => set({ reorder_level: v })} min="0" />
          </Field>
        </div>
        {!tracked && !category?.returnable && (
          <div className="flex flex-wrap gap-x-6 gap-y-2">
            <Toggle checked={form.per_piece} isDisabled={hasStock} onChange={(v) => set({ per_piece: v })}>Track each piece individually</Toggle>
          </div>
        )}
      </div>
    </Dialog>
  )
}
