import { useState } from 'react'
import { Button } from '@heroui/react'
import { Scissors, TrashBin, ArrowUpRightFromSquare, ArrowRotateLeft } from '@gravity-ui/icons'
import { ajax } from '../../lib/api'
import { adminViewUrl } from '../../lib/nav'
import { Btn, Check, ColorInput, DateInput, Dialog, Field, SelectField, TextInput, useConfirm, Notice, Tone, cx } from '../../components/ui'
import { DOT, pad, statusOf, statusText } from './calendarUtils'
import TimeInput from './TimeInput'
import { useStockFlow } from '../../components/StockModal'
import { LOCK_TEXT } from '../orders/orderActions'

const toDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const toTime = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`

export default function IntervalDialog({ event, selection, types, onClose, onSaved }) {
  const confirm = useConfirm()
  const { resolveConflicts } = useStockFlow()
  const props = event?.extendedProps || {}
  const isWork = props.type === 'work'
  const orderLocked = isWork && !!props.order_locked
  const otherTypes = Object.entries(types).filter(([k]) => k !== 'work')
  const initialStart = event ? event.start : selection ? selection.start : (() => { const d = new Date(); d.setHours(9, 0, 0, 0); return d })()
  const initialEnd = event ? event.end || new Date(event.start.getTime() + 3600000) : selection ? selection.end : new Date(initialStart.getTime() + 3600000)
  const mid = new Date(initialStart.getTime() + Math.round((initialEnd.getTime() - initialStart.getTime()) / 2))
  mid.setMinutes(mid.getMinutes() - (mid.getMinutes() % 15))
  const firstType = otherTypes[0]?.[0] || 'kita'
  const [form, setForm] = useState({
    name: event ? event.title : '',
    type: event ? props.type : firstType,
    startDate: toDate(initialStart),
    startTime: toTime(initialStart),
    endDate: toDate(initialEnd),
    endTime: toTime(initialEnd),
    locked: props.is_locked === 1,
    done: props.is_done === 1,
    customColor: !!props.raw_color && !isWork,
    color: props.raw_color || types[event ? props.type : firstType]?.color || '#616161',
    split: toTime(mid),
  })
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const set = (p) => setForm((f) => ({ ...f, ...p }))
  const typeColor = (t) => types[t]?.color || '#616161'
  const pickColor = (v) => setForm((f) => ({ ...f, color: v, customColor: String(v).toLowerCase() !== String(typeColor(f.type)).toLowerCase() }))
  const resetColor = () => setForm((f) => ({ ...f, color: typeColor(f.type), customColor: false }))
  const status = statusOf(props)

  const save = async () => {
    setError('')
    if (!form.startTime || !form.endTime || !form.startDate || !form.endDate) {
      setError('Nurodykite teisingą pradžią ir pabaigą.')
      return
    }
    setSaving(true)
    try {
      const data = {
        id: event ? event.id : 0,
        name: form.name,
        start: `${form.startDate} ${form.startTime}`,
        end: `${form.endDate} ${form.endTime}`,
        is_locked: form.locked ? 1 : 0,
        color: form.customColor ? form.color : '',
      }
      if (isWork) {
        data.is_done = form.done ? 1 : 0
        data.color = props.raw_color || ''
      } else {
        data.type = form.type
      }
      const saved = await ajax('cjs_calendar_interval_save', data)
      if (saved && saved.stock_conflicts && saved.stock_conflicts.length) await resolveConflicts(saved.stock_conflicts)
      onSaved()
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }
  const remove = async () => {
    const ok = await confirm({ title: 'Ištrinti šį įrašą?', confirmLabel: 'Ištrinti', cancelLabel: 'Atšaukti', tone: 'danger', message: isWork && props.is_done === 1 ? 'Užsakymo atliktos valandos nepasikeis.' : 'Šio veiksmo atšaukti negalėsite.' })
    if (!ok) return
    try {
      await ajax('cjs_calendar_interval_delete', { id: event.id })
      onSaved()
    } catch (e) {
      setError(e.message)
    }
  }
  const split = async () => {
    if (!form.split) {
      setError('Neteisingas dalinimo laikas (HH:MM).')
      return
    }
    try {
      await ajax('cjs_calendar_interval_split', { id: event.id, at: `${form.startDate} ${form.split}` })
      onSaved()
    } catch (e) {
      setError(e.message)
    }
  }

  return (
    <Dialog
      isOpen
      onClose={onClose}
      isDismissable={false}
      size="md"
      title={
        <span className="flex items-center gap-2">
          {status && <span className={cx('cjs-dot size-2.5', DOT[status])} />}
          {event ? (isWork ? event.title : 'Įrašas') : 'Naujas įrašas'}
        </span>
      }
      footer={
        <div className="flex w-full flex-wrap items-center justify-between gap-2">
          <div>{event && !orderLocked && <Button variant="danger-soft" size="sm" onPress={remove}><TrashBin className="size-4" />Ištrinti</Button>}</div>
          <div className="flex gap-2">
            <Button variant="tertiary" onPress={onClose}>Atšaukti</Button>
            {!orderLocked && <Btn variant="primary" isPending={saving} isDisabled={!form.startTime || !form.endTime || !form.startDate || !form.endDate} onPress={save}>Išsaugoti</Btn>}
          </div>
        </div>
      }
    >
      <div className="flex flex-col gap-3">
        {error && <Notice tone="danger">{error}</Notice>}
        {orderLocked && <Notice tone="info">{props.order_lock_text || LOCK_TEXT}</Notice>}
        <div inert={orderLocked || undefined} className={cx('flex flex-col gap-3', orderLocked && 'opacity-70')}>
        <Field label="Pavadinimas"><TextInput value={form.name} disabled={orderLocked || undefined} onChange={(v) => set({ name: v })} /></Field>
        {!isWork && (
          <Field label="Tipas" required>
            <SelectField value={form.type} onChange={(v) => set({ type: v, color: form.customColor ? form.color : typeColor(v) })} options={otherTypes.map(([k, t]) => ({ value: k, label: t.label, color: t.color }))} ariaLabel="Tipas" />
          </Field>
        )}
        <div className="grid gap-3">
          <Field label="Pradžia" required>
            <div className="flex min-w-0 gap-1.5"><DateInput className="min-w-0 flex-1" aria-label="Pradžios data" value={form.startDate} isDisabled={orderLocked} onChange={(v) => set({ startDate: v })} /><TimeInput label="Pradžios laikas" value={form.startTime} isDisabled={orderLocked} onChange={(v) => set({ startTime: v })} /></div>
          </Field>
          <Field label="Pabaiga" required>
            <div className="flex min-w-0 gap-1.5"><DateInput className="min-w-0 flex-1" aria-label="Pabaigos data" value={form.endDate} isDisabled={orderLocked} onChange={(v) => set({ endDate: v })} /><TimeInput label="Pabaigos laikas" value={form.endTime} isDisabled={orderLocked} onChange={(v) => set({ endTime: v })} /></div>
          </Field>
        </div>
        {isWork && props.deadline && (
          <div className="flex flex-wrap items-center gap-2 bg-surface-secondary/70 px-3 py-2 text-[12.5px]">
            <span>Terminas: <strong>{props.deadline}</strong></span>
            {props.finish && <span>Baigiama: <strong>{props.finish}</strong></span>}
            {status && <Tone tone={status === 'late' ? 'danger' : status === 'tight' ? 'warning' : 'success'}>{statusText(props)}</Tone>}
          </div>
        )}
        {!isWork && (
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-[12px] font-medium text-foreground/80">Spalva</span>
            <ColorInput value={form.color} onChange={pickColor} aria-label="Spalva" />
            <span className="text-[12.5px] text-muted">{form.customColor ? 'Pasirinkta ranka' : 'Pagal tipą'}</span>
            {form.customColor && (
              <Button size="sm" variant="ghost" onPress={resetColor}>
                <ArrowRotateLeft className="size-4" />
                Grąžinti tipo spalvą
              </Button>
            )}
          </div>
        )}
        <div className="flex flex-wrap gap-x-5 gap-y-2">
          <Check checked={form.locked} isDisabled={orderLocked} onChange={(v) => set({ locked: v })}><span className="text-[13px]">Užrakinta</span></Check>
          {isWork && <Check checked={form.done} isDisabled={orderLocked} onChange={(v) => set({ done: v })}><span className="text-[13px]">Atlikta</span></Check>}
        </div>
        </div>
        {isWork && props.order_id && (
          <a href={adminViewUrl('orders', { order: props.order_id })} target="_blank" rel="noopener" className="inline-flex items-center gap-1 self-start text-[13px] font-medium text-accent hover:underline">
            Atidaryti užsakymų sąraše
            <ArrowUpRightFromSquare className="size-3.5" />
          </a>
        )}
        {event && !orderLocked && (
          <div className="flex flex-wrap items-end gap-2 border-t border-separator pt-3">
            <Field label="Padalinti ties">
              <TimeInput value={form.split} onChange={(v) => set({ split: v })} />
            </Field>
            <Button size="sm" variant="secondary" onPress={split}><Scissors className="size-4" />Padalinti</Button>
          </div>
        )}
      </div>
    </Dialog>
  )
}
