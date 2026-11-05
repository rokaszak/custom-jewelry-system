import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Checkbox, Radio, RadioGroup } from '@heroui/react'
import { get, post } from '../../lib/api'
import { notifyError } from '../../lib/hooks'
import { toast } from '../../lib/toast'
import { CategoryIcon } from '../../components/icons'
import { Btn, Card, Check, Field, Loading, TextArea, TextInput, Toggle, cx } from '../../components/ui'
import SortableList from '../../components/SortableList'
import WpEditor from './WpEditor'

export function CategoryChecklist({ categories, selected, onChange, disabled = new Set() }) {
  const [filter, setFilter] = useState('')
  const shown = categories.filter((c) => c.name.toLowerCase().includes(filter.toLowerCase()))
  const pickable = shown.filter((c) => !disabled.has(c.id) || selected.includes(c.id)).map((c) => c.id)
  const picked = pickable.filter((id) => selected.includes(id)).length
  const state = picked === 0 ? 'none' : picked === pickable.length ? 'all' : 'some'
  const toggleAll = () => {
    if (state === 'all') onChange(selected.filter((id) => !pickable.includes(id)))
    else onChange([...selected, ...pickable.filter((id) => !selected.includes(id))])
  }
  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-3">
        {categories.length > 12 && <TextInput size="sm" className="min-w-0 flex-1 sm:max-w-xs" value={filter} onChange={setFilter} placeholder="Filter categories…" aria-label="Filter categories" />}
        {pickable.length > 1 && (
          <Checkbox isSelected={state === 'all'} isIndeterminate={state === 'some'} onChange={toggleAll} aria-label="Select all shown categories">
            <Checkbox.Content>
              <Checkbox.Control>
                <Checkbox.Indicator />
              </Checkbox.Control>
              <span className="text-[12.5px] text-muted">{filter ? 'All shown' : 'All'} ({picked}/{pickable.length})</span>
            </Checkbox.Content>
          </Checkbox>
        )}
      </div>
      <div className="grid max-h-[320px] gap-1 overflow-auto pr-1 sm:grid-cols-2 lg:grid-cols-3">
        {shown.map((c) => (
          <Check key={c.id} checked={selected.includes(c.id)} isDisabled={disabled.has(c.id) && !selected.includes(c.id)} onChange={(v) => onChange(v ? [...selected, c.id] : selected.filter((x) => x !== c.id))}>
            <span className="text-[13px]">{c.name} <span className="text-muted">({c.count})</span></span>
          </Check>
        ))}
      </div>
    </div>
  )
}

function offerForm(offer) {
  const o = offer || {}
  return {
    enabled: !!o.enabled,
    product_categories: Array.isArray(o.product_categories) ? o.product_categories.map(Number) : [],
    checkbox_text: o.checkbox_text || '',
    modal_title: o.modal_title || '',
    modal_text: o.modal_text || '',
    items: Array.isArray(o.items) ? o.items.map(Number) : [],
    assign: o.assign === 'reserve' ? 'reserve' : 'add',
  }
}

const ASSIGN_MODES = [
  { value: 'add', label: 'Only add to order inventory' },
  { value: 'reserve', label: 'Add to order inventory and assign automatically if available' },
]

export default function CheckoutOfferCard({ category, onSaved }) {
  const qc = useQueryClient()
  const settings = useQuery({ queryKey: ['settings'], queryFn: () => get('settings') })
  const itemsQ = useQuery({ queryKey: ['items', 'offer', category.id], queryFn: () => get('items', { category_id: category.id, per_page: 200 }) })
  const saved = useMemo(() => offerForm(category.checkout_offer), [category.checkout_offer])
  const savedKey = JSON.stringify(saved)
  const [form, setForm] = useState(saved)
  const [editorKey, setEditorKey] = useState(0)
  const [saving, setSaving] = useState(false)
  const loaded = useRef(`${category.id}:${savedKey}`)
  useEffect(() => {
    const key = `${category.id}:${savedKey}`
    if (loaded.current === key) return
    loaded.current = key
    setForm(saved)
    setEditorKey((k) => k + 1)
  }, [category.id, savedKey])
  const items = itemsQ.data?.items || []
  const itemsOrdered = useMemo(() => {
    const byId = Object.fromEntries(items.map((i) => [i.id, i]))
    const ordered = form.items.map((id) => byId[id]).filter(Boolean)
    const rest = items.filter((i) => !form.items.includes(i.id))
    return [...ordered.map((i) => ({ ...i, enabled: true })), ...rest.map((i) => ({ ...i, enabled: false }))]
  }, [items, form.items])
  const dirty = JSON.stringify(form) !== savedKey
  const set = (p) => setForm((f) => ({ ...f, ...p }))
  const save = async () => {
    setSaving(true)
    try {
      const res = await post(`categories/${category.id}/checkout-offer`, { checkout_offer: form })
      qc.invalidateQueries({ queryKey: ['settings'] })
      qc.invalidateQueries({ queryKey: ['bootstrap'] })
      toast.success('Saved!')
      if (onSaved) onSaved(res.catalog)
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Card
      title={<span className="inline-flex items-center gap-2"><CategoryIcon icon={category.icon} className="size-5 shrink-0 text-muted" />{category.name}</span>}
      description="Checkout offer: a checkbox and consent window at checkout. When the customer ticks it, the order gets a line from this category."
      actions={<Btn size="sm" variant="primary" isPending={saving} isDisabled={!dirty} onPress={save}>Save checkout offer</Btn>}
    >
      <div className="flex flex-col gap-4">
        <Toggle checked={form.enabled} onChange={(v) => set({ enabled: v })}>Show the checkbox and consent modal at checkout</Toggle>
        <div className={cx('flex flex-col gap-4', !form.enabled && 'opacity-70')}>
          <Field label="Mode" hint={category.auto_assign ? 'This category has Auto assign on, so the queue assigns it anyway.' : 'Assigning is tried once, when the order gets the line. If nothing is available, the line waits for you to assign it.'}>
            <RadioGroup value={form.assign} onChange={(v) => set({ assign: v })} isDisabled={!!category.auto_assign} aria-label="Mode" className="flex flex-col gap-1.5">
              {ASSIGN_MODES.map((m) => (
                <Radio key={m.value} value={m.value} className="cjs-choice">
                  <Radio.Content className="flex items-center gap-2">
                    <Radio.Control className="shrink-0">
                      <Radio.Indicator />
                    </Radio.Control>
                    <span className="text-[13px] text-foreground">{m.label}</span>
                  </Radio.Content>
                </Radio>
              ))}
            </RadioGroup>
          </Field>
          <Field label="Qualifying product categories" hint="The checkbox appears when the cart has a product in one of these categories.">
            {settings.data ? <CategoryChecklist categories={settings.data.product_categories || []} selected={form.product_categories} onChange={(v) => set({ product_categories: v })} /> : <Loading compact />}
          </Field>
          <div className="grid gap-4 xl:grid-cols-2">
            <Field label="Checkbox text" hint="HTML. Put the link to the terms here.">
              <TextArea rows={3} autoGrow value={form.checkbox_text} onChange={(v) => set({ checkbox_text: v })} aria-label="Checkbox text" />
            </Field>
            <Field label="Consent modal title">
              <TextInput value={form.modal_title} onChange={(v) => set({ modal_title: v })} />
            </Field>
          </div>
          <div className="flex min-w-0 flex-col gap-1.5">
            <span className="text-[12px] font-medium text-foreground/80">Consent modal text</span>
            <WpEditor key={editorKey} value={form.modal_text} onChange={(v) => setForm((f) => ({ ...f, modal_text: v }))} rows={9} ariaLabel="Consent modal text" />
            <span className="text-[12px] text-muted">Shown in the consent window when the customer ticks the checkbox.</span>
          </div>
          <Field label="Items, in order of preference" hint="The first enabled item with free stock is used. Drag to reorder.">
            {itemsQ.isLoading ? (
              <Loading compact />
            ) : itemsOrdered.length === 0 ? (
              <div className="text-[13px] text-muted">No items in this category yet.</div>
            ) : (
              <SortableList
                items={itemsOrdered}
                className="flex flex-col gap-1.5"
                onReorder={(next) => set({ items: next.filter((i) => i.enabled).map((i) => i.id) })}
                renderItem={(it, handle) => (
                  <div className={cx('flex items-center gap-2 bg-surface-secondary/70 px-1.5 py-1', !it.enabled && 'opacity-60')}>
                    {handle}
                    <Check checked={it.enabled} onChange={(v) => set({ items: v ? [...form.items, it.id] : form.items.filter((x) => x !== it.id) })}>
                      <span className="inline-flex items-center gap-1.5 text-[13px] font-medium"><CategoryIcon categoryId={it.category_id} className="size-4 shrink-0 text-muted" />{it.name}</span>
                    </Check>
                    {it.code && <span className="text-[11.5px] text-muted">{it.code}</span>}
                    <span className="ml-auto text-[12px] text-muted">{it.free} available</span>
                  </div>
                )}
              />
            )}
          </Field>
        </div>
      </div>
    </Card>
  )
}
