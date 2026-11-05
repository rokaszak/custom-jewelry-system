import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@heroui/react'
import { toast } from '../../lib/toast'
import { Plus, TrashBin, Pencil, Archive, Check as CheckIcon, Xmark } from '@gravity-ui/icons'
import { get, post, patch, del } from '../../lib/api'
import { notifyError, useBootstrap } from '../../lib/hooks'
import { Btn, Card, Check, Dialog, Empty, Field, IconButton, Section, SelectField, TextArea, TextInput, Toggle, Tone, useConfirm, cx } from '../../components/ui'
import { useDraft } from '../../lib/draft'
import SortableList from '../../components/SortableList'
import { CategoryIcon, IconPicker } from '../../components/icons'

const TYPE_LABEL = { select: 'Choice list', number: 'Number + unit', text: 'Free text' }

function useAdminCatalog() {
  return useQuery({ queryKey: ['catalog-admin'], queryFn: () => get('catalog', { archived: 1 }) })
}

function useCatalogSetter() {
  const qc = useQueryClient()
  return (catalog) => {
    if (catalog) qc.setQueryData(['catalog-admin'], catalog)
    qc.invalidateQueries({ queryKey: ['catalog'] })
    qc.invalidateQueries({ queryKey: ['items'] })
  }
}

function unitOption(u) {
  return { value: String(u.id), label: u.name ? `${u.label} (${u.name})` : u.label }
}

function useDraftForm(key, initial) {
  const draft = useDraft(key)
  const [saved] = useState(() => draft.read()?.form || null)
  const [form, setForm] = useState(() => (saved ? { ...initial(), ...saved } : initial()))
  const [dirty, setDirty] = useState(false)
  useEffect(() => {
    if (dirty) draft.save({ form })
  }, [form, dirty, draft])
  const update = (patchObj) => {
    setDirty(true)
    setForm((f) => ({ ...f, ...patchObj }))
  }
  return { form, update, restored: !!saved, clear: draft.clear }
}

function DialogFooter({ reason, saving, onCancel, onSave }) {
  return (
    <>
      {reason && <span className="mr-auto self-center text-[12px] text-muted">{reason}</span>}
      <Button variant="tertiary" onPress={onCancel}>Cancel</Button>
      <Btn variant="primary" isPending={saving} isDisabled={!!reason} onPress={onSave}>Save</Btn>
    </>
  )
}

function RestoredNote({ show }) {
  if (!show) return null
  return <div className="text-[12px] text-muted">Restored what you entered last time. Cancel clears it.</div>
}

function CategoryDialog({ category, units, onClose }) {
  const setCatalog = useCatalogSetter()
  const { form, update, restored, clear } = useDraftForm(category ? `category:${category.id}` : 'category:new', () => ({
    name: category?.name || '',
    unit_id: category?.unit_id || units[0]?.id || '',
    returnable: !category?.has_fineness && !!category?.returnable,
    per_piece: !category?.has_fineness && (!!category?.per_piece || !!category?.returnable),
    icon: category?.icon || '',
    auto_assign: category ? !!category.auto_assign || !!category.has_fineness : true,
    used_by_status: category?.used_by_status || 'done',
    back_by_status: category?.back_by_status || 'done',
    has_fineness: !!category?.has_fineness,
  }))
  const boot = useBootstrap()
  const statusOptions = (boot.data?.statuses || []).map((s) => ({ value: s.key, label: s.label }))
  const [saving, setSaving] = useState(false)
  const gram = units.find((u) => u.label === 'g')
  const prabaBlock = [
    form.returnable && 'turn off Returnable',
    form.per_piece && 'turn off Track pieces individually',
    !form.auto_assign && 'turn on Auto assign',
    gram && Number(form.unit_id) !== gram.id && 'choose g as the stock unit',
  ].filter(Boolean)
  const prabaLocked = !form.has_fineness && prabaBlock.length > 0
  const returnableLocked = !form.returnable && (form.has_fineness || !form.per_piece)
  const reason = !form.name.trim() ? 'Type a name.' : !form.unit_id ? 'Choose a stock unit.' : ''
  const cancel = () => {
    clear()
    onClose()
  }
  const save = async () => {
    if (reason) return
    setSaving(true)
    try {
      const body = { ...form }
      delete body.default_fineness
      const res = category ? await patch(`categories/${category.id}`, body) : await post('categories', body)
      clear()
      setCatalog(res.catalog)
      onClose(res.id)
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Dialog isOpen size="sm" isDismissable={false} onClose={() => onClose()} title={category ? 'Edit category' : 'New category'} footer={<DialogFooter reason={reason} saving={saving} onCancel={cancel} onSave={save} />}>
      <div className="flex flex-col gap-3">
        <RestoredNote show={restored} />
        <Field label="Name" required><TextInput autoFocus value={form.name} onChange={(v) => update({ name: v })} placeholder="e.g. Packaging" /></Field>
        <Field label="Default stock unit" required hint={form.has_fineness ? 'Praba metal is kept in grams.' : 'New items in this category start with this unit.'}>
          <SelectField isDisabled={form.has_fineness} value={form.unit_id ? String(form.unit_id) : ''} onChange={(v) => update({ unit_id: Number(v) })} options={units.map(unitOption)} ariaLabel="Unit" />
        </Field>
        <Field label="Icon" hint="Shown next to the category and its items."><IconPicker value={form.icon || ''} onChange={(v) => update({ icon: v })} /></Field>
        <div className="flex flex-col gap-1">
          <Toggle checked={form.per_piece} isDisabled={form.returnable || form.has_fineness} onChange={(v) => update({ per_piece: v })}>Track pieces individually by default</Toggle>
          {(form.returnable || form.has_fineness) && <span className="text-[12px] text-muted">{form.has_fineness ? 'Praba metal is tracked by weight, not by piece. Turn off Tracked by praba to change this.' : 'Returnable items are always tracked piece by piece. Turn off Returnable to change this.'}</span>}
        </div>
        <Section title="Stock rules" className="mt-2 border-t border-separator pt-3">
          <div className="flex flex-col gap-1">
            <Toggle checked={form.auto_assign} isDisabled={form.has_fineness} onChange={(v) => update({ auto_assign: v })}>Auto assign</Toggle>
            <span className="text-[12px] text-muted">{form.has_fineness ? 'Praba metal is always assigned by the queue, highest praba first. Turn off Tracked by praba to change this.' : form.auto_assign ? 'Stock reaches order lines only through the queue, oldest line first.' : 'Lines are assigned by hand, choosing the item and the piece.'}</span>
          </div>
          <div className="flex flex-col gap-1">
            <Toggle checked={form.returnable} isDisabled={returnableLocked} onChange={(v) => update({ returnable: v })}>Items come back after use (returnable)</Toggle>
            {returnableLocked && <span className="text-[12px] text-muted">{form.has_fineness ? 'Praba metal is used up, not returned. Turn off Tracked by praba to change this.' : 'Returnable items are tracked piece by piece: turn on Track pieces individually first.'}</span>}
          </div>
          {form.returnable ? (
            <Field label="Back by status (returnable)" hint="Returnable items should be back when the order reaches this status.">
              <SelectField value={form.back_by_status} onChange={(v) => v && update({ back_by_status: v })} options={statusOptions} ariaLabel="Back by status" />
            </Field>
          ) : (
            <Field label="Used by status (not returnable)" hint="Reserved stock becomes Done when the order reaches this status.">
              <SelectField value={form.used_by_status} onChange={(v) => v && update({ used_by_status: v })} options={statusOptions} ariaLabel="Used by status" />
            </Field>
          )}
          <div className="flex flex-col gap-1">
            <Toggle checked={form.has_fineness} isDisabled={prabaLocked} onChange={(v) => update({ has_fineness: v })}>Tracked by praba</Toggle>
            {prabaLocked && <span className="text-[12px] text-muted">To track by praba: {prabaBlock.join(', ')}.</span>}
            {form.has_fineness && category?.has_fineness && (category.needs_praba || []).length > 0 && (
              <span className="text-[12px] text-warning">These items need a praba: {category.needs_praba.map((i) => i.name).join(', ')}. Open each item and enter its praba.</span>
            )}
          </div>
        </Section>
      </div>
    </Dialog>
  )
}

function FieldDialog({ categoryId, field, units, onClose }) {
  const setCatalog = useCatalogSetter()
  const { form, update, restored, clear } = useDraftForm(field ? `field:${field.id}` : `field:new:${categoryId}`, () => ({
    name: field?.name || '',
    type: field?.type || 'select',
    in_name: field ? field.in_name : true,
    is_size: !!field?.is_size,
    required: !!field?.required,
    unit_ids: field?.unit_ids || [],
    choicesText: '',
  }))
  const [saving, setSaving] = useState(false)
  const reason = !form.name.trim() ? 'Type a name.' : form.type === 'number' && !form.unit_ids.length ? 'Choose at least one unit.' : ''
  const cancel = () => {
    clear()
    onClose()
  }
  const save = async () => {
    if (reason) return
    setSaving(true)
    try {
      const body = { ...form, choices: form.type === 'select' && !field ? form.choicesText.split('\n').map((s) => s.trim()).filter(Boolean) : undefined }
      const res = field ? await patch(`fields/${field.id}`, body) : await post(`categories/${categoryId}/fields`, body)
      clear()
      setCatalog(res.catalog)
      onClose()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Dialog isOpen size="sm" isDismissable={false} onClose={onClose} title={field ? `Edit field “${field.name}”` : 'New field'} footer={<DialogFooter reason={reason} saving={saving} onCancel={cancel} onSave={save} />}>
      <div className="flex flex-col gap-3">
        <RestoredNote show={restored} />
        <Field label="Name" required><TextInput autoFocus value={form.name} onChange={(v) => update({ name: v })} placeholder="e.g. Size" /></Field>
        <Field label="Type" required hint={field ? 'The type can only change while no item uses the field.' : undefined}>
          <SelectField value={form.type} onChange={(v) => update({ type: v })} options={Object.entries(TYPE_LABEL).map(([value, label]) => ({ value, label }))} ariaLabel="Type" />
        </Field>
        {form.type === 'number' && (
          <Field label="Allowed units" required hint="Every number is stored with its unit.">
            <div className="flex flex-wrap gap-3">
              {units.map((u) => (
                <Check key={u.id} checked={form.unit_ids.includes(u.id)} onChange={(v) => update({ unit_ids: v ? [...form.unit_ids, u.id] : form.unit_ids.filter((x) => x !== u.id) })}>
                  <span className="text-[13px]">{u.label}</span>
                </Check>
              ))}
            </div>
          </Field>
        )}
        {form.type === 'select' && !field && (
          <Field label="Choices" hint="One per line. You can add more later.">
            <TextArea rows={4} value={form.choicesText} onChange={(v) => update({ choicesText: v })} />
          </Field>
        )}
        <Toggle checked={form.in_name} onChange={(v) => update({ in_name: v })}>Use in the automatic item name</Toggle>
        <Toggle checked={form.is_size} onChange={(v) => update({ is_size: v })}>Size field. Up to two per category: the first is the main size (filters, larger wins when order-level lines merge), the second is the width, shown as 4 × 2.5 mm</Toggle>
        <Toggle checked={form.required} onChange={(v) => update({ required: v })}>Required</Toggle>
      </div>
    </Dialog>
  )
}

function ChoicesEditor({ field }) {
  const setCatalog = useCatalogSetter()
  const [label, setLabel] = useState('')
  const [editing, setEditing] = useState(null)
  const [showArchived, setShowArchived] = useState(false)
  const add = async () => {
    if (!label.trim()) return
    try {
      const res = await post(`fields/${field.id}/choices`, { label })
      setCatalog(res.catalog)
      setLabel('')
    } catch (e) {
      notifyError(e)
    }
  }
  const rename = async () => {
    try {
      const res = await patch(`choices/${editing.id}`, { label: editing.label })
      setCatalog(res.catalog)
      setEditing(null)
    } catch (e) {
      notifyError(e)
    }
  }
  const remove = async (c) => {
    try {
      const res = await del(`choices/${c.id}`)
      setCatalog(res.catalog)
      if (res.result === 'archived') toast.info(`“${c.label}” is used by items, so it was archived instead of deleted.`)
    } catch (e) {
      notifyError(e)
    }
  }
  const restore = async (c) => {
    try {
      const res = await patch(`choices/${c.id}`, { archived: false })
      setCatalog(res.catalog)
    } catch (e) {
      notifyError(e)
    }
  }
  const visible = field.choices.filter((c) => showArchived || !c.archived)
  return (
    <div className="flex flex-col gap-2">
      <SortableList
        items={visible}
        className="flex flex-col gap-1"
        onReorder={async (next) => {
          try {
            const res = await post(`fields/${field.id}/choices/reorder`, { ids: next.map((c) => c.id) })
            setCatalog(res.catalog)
          } catch (e) {
            notifyError(e)
          }
        }}
        renderItem={(c, handle) => (
          <div className={cx('flex items-center gap-1.5 bg-surface px-1.5 py-1 ring-1 ring-separator', c.archived && 'opacity-60')}>
            {handle}
            {editing?.id === c.id ? (
              <>
                <TextInput size="sm" autoFocus value={editing.label} onChange={(v) => setEditing({ ...editing, label: v })} onKeyDown={(e) => e.key === 'Enter' && rename()} />
                <IconButton icon={CheckIcon} label="Save" onPress={rename} />
                <IconButton icon={Xmark} label="Cancel" onPress={() => setEditing(null)} />
              </>
            ) : (
              <>
                <span className="flex-1 truncate text-[13px]">{c.label}</span>
                {c.archived ? (
                  <Button size="sm" variant="tertiary" onPress={() => restore(c)}>Restore</Button>
                ) : (
                  <>
                    <IconButton icon={Pencil} label="Rename" onPress={() => setEditing({ id: c.id, label: c.label })} />
                    <IconButton icon={TrashBin} label="Delete" onPress={() => remove(c)} />
                  </>
                )}
              </>
            )}
          </div>
        )}
      />
      <div className="flex gap-1.5">
        <TextInput size="sm" value={label} onChange={setLabel} placeholder="Add a choice" onKeyDown={(e) => e.key === 'Enter' && add()} />
        <Btn size="sm" variant="secondary" icon={Plus} onPress={add}>Add</Btn>
      </div>
      {field.choices.some((c) => c.archived) && (
        <button type="button" className="self-start text-[12px] text-muted hover:underline" onClick={() => setShowArchived((s) => !s)}>{showArchived ? 'Hide archived choices' : 'Show archived choices'}</button>
      )}
    </div>
  )
}

export default function CategoriesEditor() {
  const q = useAdminCatalog()
  const setCatalog = useCatalogSetter()
  const confirm = useConfirm()
  const [selected, setSelected] = useState(null)
  const [dialog, setDialog] = useState(null)
  const [openField, setOpenField] = useState(null)
  const catalog = q.data || { units: [], categories: [] }
  useEffect(() => {
    if (!selected && catalog.categories.length) setSelected(catalog.categories[0].id)
  }, [catalog.categories.length])
  const category = catalog.categories.find((c) => c.id === selected)
  const unitLabel = (id) => catalog.units.find((u) => u.id === id)?.label || ''

  const removeField = async (f) => {
    const ok = await confirm({ title: `Delete field “${f.name}”?`, message: 'Fields that items already use cannot be deleted.', confirmLabel: 'Delete', tone: 'danger' })
    if (!ok) return
    try {
      const res = await del(`fields/${f.id}`)
      setCatalog(res.catalog)
    } catch (e) {
      notifyError(e)
    }
  }
  const archiveCategory = async (c) => {
    try {
      const res = await post(`categories/${c.id}/archive`, { archived: !c.archived })
      setCatalog(res.catalog)
    } catch (e) {
      notifyError(e)
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[300px_minmax(0,1fr)]">
      <Card title="Categories" actions={<Btn size="sm" variant="secondary" icon={Plus} onPress={() => setDialog({ type: 'category' })}>New</Btn>} padded={false}>
        <div className="p-2">
          <SortableList
            items={catalog.categories}
            className="flex flex-col gap-1"
            onReorder={async (next) => {
              try {
                const res = await post('categories/reorder', { ids: next.map((c) => c.id) })
                setCatalog(res.catalog)
              } catch (e) {
                notifyError(e)
              }
            }}
            renderItem={(c, handle) => (
              <div className={cx('flex items-center gap-1 px-1 py-1', selected === c.id ? 'bg-accent-soft' : 'hover:bg-surface-secondary', c.archived && 'opacity-60')}>
                {handle}
                <button type="button" className="flex min-w-0 flex-1 items-center justify-between gap-2 px-1 py-1 text-left" onClick={() => setSelected(c.id)}>
                  <span className="flex min-w-0 items-center gap-2"><CategoryIcon icon={c.icon} className="size-4 text-muted" /><span className="truncate text-[13.5px] font-medium">{c.name}</span></span>
                  <span className="text-[11.5px] text-muted">{c.item_count}</span>
                </button>
              </div>
            )}
          />
        </div>
      </Card>
      {category ? (
        <Card
          title={<span className="inline-flex items-center gap-2"><CategoryIcon icon={category.icon} className="size-5 shrink-0 text-muted" />{category.name}</span>}
          description={
            <span className="flex flex-wrap gap-1.5 pt-1">
              <Tone>Unit: {unitLabel(category.unit_id)}</Tone>
              {category.per_piece && <Tone tone="accent">Per piece</Tone>}
              {category.returnable && <Tone tone="accent">Returnable</Tone>}
              {category.archived && <Tone tone="warning">Archived</Tone>}
            </span>
          }
          actions={
            <>
              <Btn size="sm" variant="tertiary" icon={Archive} onPress={() => archiveCategory(category)}>{category.archived ? 'Restore' : 'Archive'}</Btn>
              <Btn size="sm" variant="secondary" icon={Pencil} onPress={() => setDialog({ type: 'category', category })}>Edit</Btn>
              <Btn size="sm" variant="primary" icon={Plus} onPress={() => setDialog({ type: 'field' })}>Add field</Btn>
            </>
          }
        >
          {category.fields.length === 0 ? (
            <Empty compact title="No fields" description="Without fields, items are identified by their name only." />
          ) : (
            <SortableList
              items={category.fields}
              className="flex flex-col gap-2"
              onReorder={async (next) => {
                try {
                  const res = await post(`categories/${category.id}/fields/reorder`, { ids: next.map((f) => f.id) })
                  setCatalog(res.catalog)
                } catch (e) {
                  notifyError(e)
                }
              }}
              renderItem={(f, handle) => (
                <div className={cx('p-2 transition-colors', f.type === 'select' && openField === f.id ? 'bg-default' : 'bg-surface-secondary/60', f.type === 'select' && 'hover:bg-default')}>
                  <div className="flex flex-wrap items-center gap-2">
                    {handle}
                    <button type="button" className={cx('flex min-w-0 flex-1 flex-wrap items-center gap-2 self-stretch text-left', f.type === 'select' ? 'cursor-pointer' : 'cursor-default')} onClick={() => setOpenField(openField === f.id ? null : f.id)}>
                      <span className="text-[13.5px] font-semibold">{f.name}</span>
                      <Tone>{TYPE_LABEL[f.type]}</Tone>
                      {f.type === 'number' && <span className="text-[12px] text-muted">{f.unit_ids.map(unitLabel).join(', ')}</span>}
                      {f.type === 'select' && <span className="text-[12px] text-muted">{f.choices.filter((c) => !c.archived).length} choices</span>}
                      {f.in_name && <Tone tone="accent">In name</Tone>}
                      {f.is_size && <Tone tone="success">Size</Tone>}
                      {f.required && <Tone tone="warning">Required</Tone>}
                    </button>
                    <IconButton icon={Pencil} label="Edit field" onPress={() => setDialog({ type: 'field', field: f })} />
                    <IconButton icon={TrashBin} label="Delete field" onPress={() => removeField(f)} />
                  </div>
                  {openField === f.id && f.type === 'select' && (
                    <div className="mt-2 pl-9">
                      <ChoicesEditor field={f} />
                    </div>
                  )}
                </div>
              )}
            />
          )}
          <p className="mt-3 text-[12px] text-muted">Drag fields to change the order of the automatic item name. Click a choice-list field to manage its choices.</p>
        </Card>
      ) : (
        <Card><Empty title="Select a category" /></Card>
      )}
      {dialog?.type === 'category' && <CategoryDialog category={dialog.category} units={catalog.units} onClose={(id) => { setDialog(null); if (id) setSelected(id) }} />}
      {dialog?.type === 'field' && <FieldDialog categoryId={category?.id} field={dialog.field} units={catalog.units} onClose={() => setDialog(null)} />}
    </div>
  )
}
