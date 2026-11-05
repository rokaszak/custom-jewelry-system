import { useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '../../lib/toast'
import { Plus, TrashBin, Pencil, Check as CheckIcon, Xmark, ListUl, Tags, Calendar, ClockArrowRotateLeft, Box } from '@gravity-ui/icons'
import { get, post } from '../../lib/api'
import { notifyError, useUrlParam } from '../../lib/hooks'
import { addDays } from '../../lib/format'
import { Btn, Card, Empty, Field, IconButton, Loading, Notice, NumberInput, Page, TabBar, TextInput, Toggle, useConfirm } from '../../components/ui'
import ActivityLog from './ActivityLog'
import CheckoutOfferCard, { CategoryChecklist } from './CheckoutOffer'
import { StatusNames } from './StatusSettings'
import SortableList from '../../components/SortableList'

const TABS = [
  { id: 'lists', label: 'Production', icon: ListUl },
  { id: 'returnable-items', label: 'Returnable Items', icon: Box },
  { id: 'order-types', label: 'Order types', icon: Tags },
  { id: 'deadlines', label: 'Deadlines', icon: Calendar },
  { id: 'log', label: 'Activity log', icon: ClockArrowRotateLeft },
]

function useSettings() {
  return useQuery({ queryKey: ['settings'], queryFn: () => get('settings') })
}

function useSaver() {
  const qc = useQueryClient()
  return async (path, body, message = 'Saved!') => {
    try {
      const res = await post(path, body)
      qc.setQueryData(['settings'], res)
      qc.invalidateQueries({ queryKey: ['bootstrap'] })
      toast.success(message)
      return res
    } catch (e) {
      notifyError(e)
      throw e
    }
  }
}

function ListEditor({ title, description, type, values }) {
  const save = useSaver()
  const confirm = useConfirm()
  const [list, setList] = useState(values)
  const [adding, setAdding] = useState('')
  const [editing, setEditing] = useState(null)
  useEffect(() => setList(values), [values])
  const persist = (next) => save(`settings/list/${type}`, { values: next }).then(() => setList(next)).catch(() => setList(values))
  const items = list.map((v) => ({ id: v, value: v }))
  return (
    <Card title={title} description={description}>
      <SortableList
        items={items}
        className="flex flex-col gap-1.5"
        onReorder={(next) => persist(next.map((x) => x.value))}
        renderItem={(it, handle) => (
          <div className="flex items-center gap-1.5 bg-surface-secondary/70 px-1.5 py-1">
            {handle}
            {editing?.from === it.value ? (
              <>
                <TextInput size="sm" autoFocus value={editing.to} onChange={(v) => setEditing({ ...editing, to: v })} onKeyDown={(e) => e.key === 'Enter' && (persist(list.map((x) => (x === editing.from ? editing.to : x))), setEditing(null))} />
                <IconButton icon={CheckIcon} label="Save" onPress={() => { persist(list.map((x) => (x === editing.from ? editing.to : x))); setEditing(null) }} />
                <IconButton icon={Xmark} label="Cancel" onPress={() => setEditing(null)} />
              </>
            ) : (
              <>
                <span className="flex-1 text-[13.5px]">{it.value}</span>
                <IconButton icon={Pencil} label="Rename" onPress={() => setEditing({ from: it.value, to: it.value })} />
                <IconButton
                  icon={TrashBin}
                  label="Delete"
                  onPress={async () => {
                    const ok = await confirm({ title: `Delete “${it.value}”?`, message: 'Orders that already use this value keep it, but it disappears from the lists.', confirmLabel: 'Delete', tone: 'danger' })
                    if (ok) persist(list.filter((x) => x !== it.value))
                  }}
                />
              </>
            )}
          </div>
        )}
      />
      <div className="mt-3 flex gap-1.5">
        <TextInput size="sm" value={adding} onChange={setAdding} placeholder="Add a value…" onKeyDown={(e) => e.key === 'Enter' && adding.trim() && (persist([...list, adding.trim()]), setAdding(''))} />
        <Btn size="sm" variant="secondary" icon={Plus} isDisabled={!adding.trim()} onPress={() => { persist([...list, adding.trim()]); setAdding('') }}>Add</Btn>
      </div>
    </Card>
  )
}

function ProductionTab({ data }) {
  const save = useSaver()
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <StatusNames statuses={data.statuses || []} onSave={save} />
      <ListEditor title="Užsakymo tipai" type="order_types" values={data.order_types} />
    </div>
  )
}

function ReturnableItemsTab() {
  const qc = useQueryClient()
  const catalog = useQuery({ queryKey: ['catalog-admin'], queryFn: () => get('catalog', { archived: 1 }) })
  const onSaved = (next) => {
    if (next) qc.setQueryData(['catalog-admin'], next)
    qc.invalidateQueries({ queryKey: ['catalog'] })
  }
  if (catalog.isLoading) return <Loading />
  if (catalog.isError) return <Notice tone="danger">{catalog.error.message}</Notice>
  const categories = (catalog.data?.categories || []).filter((c) => c.returnable && !c.archived)
  if (!categories.length) {
    return (
      <Card>
        <Empty title="No returnable categories" description="Checkout offers belong to returnable categories. Mark a category as returnable in Stock › Categories & fields." />
      </Card>
    )
  }
  return (
    <div className="flex flex-col gap-4">
      {categories.map((c) => <CheckoutOfferCard key={c.id} category={c} onSaved={onSaved} />)}
    </div>
  )
}

function OrderTypesTab({ data }) {
  const save = useSaver()
  const [form, setForm] = useState(data.order_type_assign)
  useEffect(() => setForm(data.order_type_assign), [data.order_type_assign])
  const used = useMemo(() => {
    const map = new Map()
    for (const [type, ids] of Object.entries(form.map || {})) ids.forEach((id) => map.set(id, type))
    return map
  }, [form.map])
  return (
    <div className="flex flex-col gap-4">
      <Card
        title="Automatic order type"
        description="New orders get a type from their product categories. The type with the most items wins; ties go to the higher-level category, then the most expensive line."
        actions={<Btn size="sm" variant="primary" onPress={() => save('settings/order-types', form).catch(() => {})}>Save</Btn>}
      >
        <Toggle checked={form.enabled} onChange={(v) => setForm({ ...form, enabled: v })}>Assign the order type automatically</Toggle>
      </Card>
      {data.order_types.map((type) => (
        <Card key={type} title={type} description="Sub-categories are included automatically. A category can belong to one type only.">
          <CategoryChecklist
            categories={data.product_categories}
            selected={form.map?.[type] || []}
            disabled={new Set([...used.entries()].filter(([, t]) => t !== type).map(([id]) => id))}
            onChange={(ids) => setForm({ ...form, map: { ...form.map, [type]: ids } })}
          />
        </Card>
      ))}
    </div>
  )
}

function DeadlinesTab({ data }) {
  const save = useSaver()
  const [form, setForm] = useState(data.deadlines)
  useEffect(() => setForm(data.deadlines), [data.deadlines])
  const today = new Date().toISOString().slice(0, 10)
  const deliver = addDays(today, Number(form.default_days) || 0)
  return (
    <Card title="Deadlines" actions={<Btn size="sm" variant="primary" onPress={() => save('settings/deadlines', form).catch(() => {})}>Save</Btn>}>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="flex flex-col gap-3">
          <Field label="Standartinis „Pristatyti iki“ terminas (dienos nuo užsakymo)" required>
            <NumberInput value={form.default_days} onChange={(v) => setForm({ ...form, default_days: v })} min="1" step="1" />
          </Field>
          <div className="bg-surface-secondary/70 p-3 text-[12.5px] text-muted">
            An order placed today gets: manufacture by <strong className="whitespace-nowrap text-foreground">{addDays(deliver, -14)}</strong>, hallmark by <strong className="whitespace-nowrap text-foreground">{addDays(deliver, -7)}</strong>, deliver by <strong className="whitespace-nowrap text-foreground">{deliver}</strong>. The gaps are fixed at 14 and 7 days.
          </div>
        </div>
        <div className="flex flex-col gap-3">
          <Toggle checked={!!form.checkout_enabled} onChange={(v) => setForm({ ...form, checkout_enabled: v ? 1 : 0 })}>Ask “Did we agree a custom production deadline?” at checkout</Toggle>
          <Field label="Earliest allowed date (days from today)">
            <NumberInput value={form.min_days} onChange={(v) => setForm({ ...form, min_days: v })} min="0" step="1" />
          </Field>
          <Field label="Latest allowed date (days from today, 0 = no limit)">
            <NumberInput value={form.max_days} onChange={(v) => setForm({ ...form, max_days: v })} min="0" step="1" />
          </Field>
        </div>
      </div>
    </Card>
  )
}

const LEGACY_TABS = { options: 'lists', size_kit: 'returnable-items', 'size-kit': 'returnable-items', order_types: 'order-types', deadlines: 'deadlines', log: 'log' }

export default function SettingsPage() {
  const [rawTab, setTab] = useUrlParam('tab', 'lists')
  const tab = TABS.some((t) => t.id === rawTab) ? rawTab : LEGACY_TABS[rawTab] || 'lists'
  const q = useSettings()
  return (
    <Page title="Lists, statuses, checkout options and the activity log">
      <div className="mb-4">
        <TabBar value={tab} onChange={setTab} items={TABS} />
      </div>
      {tab === 'log' ? (
        <ActivityLog />
      ) : q.isLoading ? (
        <Loading />
      ) : q.isError ? (
        <Notice tone="danger">{q.error.message}</Notice>
      ) : (
        <>
          {tab === 'lists' && <ProductionTab data={q.data} />}
          {tab === 'returnable-items' && <ReturnableItemsTab />}
          {tab === 'order-types' && <OrderTypesTab data={q.data} />}
          {tab === 'deadlines' && <DeadlinesTab data={q.data} />}
        </>
      )}
    </Page>
  )
}
