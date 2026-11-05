import { useMemo, useState } from 'react'
import { Button } from '@heroui/react'
import { Plus, Xmark, Copy } from '@gravity-ui/icons'
import { ajax } from '../../lib/api'
import { Btn, Check, Dialog, Expand, Field, IconButton, Notice, NumberInput, SelectField, cx } from '../../components/ui'
import TimeInput from './TimeInput'

const DAYS = [['mon', 'Pirmadienis'], ['tue', 'Antradienis'], ['wed', 'Trečiadienis'], ['thu', 'Ketvirtadienis'], ['fri', 'Penktadienis'], ['sat', 'Šeštadienis'], ['sun', 'Sekmadienis']]
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/

function minutes(t) {
  const [h, m] = t.split(':').map(Number)
  return h * 60 + m
}

function blockMinutes(b) {
  if (!TIME.test(b.from) || !TIME.test(b.to)) return 0
  const a = minutes(b.from)
  const z = minutes(b.to)
  return z > a ? z - a : 1440 - a + z
}

function Time({ value, onChange, label }) {
  return <TimeInput size="sm" value={value} onChange={onChange} invalid={!TIME.test(value || '')} label={label} />
}

function Blocks({ blocks, onChange, addDefault, addLabel = 'Pridėti' }) {
  return (
    <div className="flex flex-col gap-1.5">
      {blocks.map((b, i) => (
        <div key={i} className="flex items-center gap-1.5">
          <Time value={b.from} onChange={(v) => onChange(blocks.map((x, j) => (j === i ? { ...x, from: v } : x)))} />
          <span className="text-muted">-</span>
          <Time value={b.to} onChange={(v) => onChange(blocks.map((x, j) => (j === i ? { ...x, to: v } : x)))} />
          <IconButton icon={Xmark} label="Pašalinti" onPress={() => onChange(blocks.filter((_, j) => j !== i))} tooltip={false} />
        </div>
      ))}
      <Button size="sm" variant="tertiary" className="self-start" onPress={() => onChange([...blocks, addDefault])}><Plus className="size-4" />{addLabel}</Button>
    </div>
  )
}

function normTime(t) {
  const m = /^(\d{1,2}):(\d{2})/.exec(String(t || '').trim())
  return m ? `${m[1].padStart(2, '0')}:${m[2]}` : String(t || '')
}

function normBlocks(list) {
  return (Array.isArray(list) ? list : []).map((b) => ({ ...b, from: normTime(b?.from), to: normTime(b?.to) }))
}

function normalize(settings) {
  const s = JSON.parse(JSON.stringify(settings || { week: {}, rest: [], rest_hours: 8, algorithm: 'fcfs', overflow: 'delay', aggro: 1, auto_split: 0 }))
  const week = {}
  for (const [k, d] of Object.entries(s.week || {})) {
    week[k] = d && typeof d === 'object' ? { ...d, start: normTime(d.start), end: normTime(d.end), breaks: normBlocks(d.breaks) } : d
  }
  return { ...s, week, rest: normBlocks(s.rest) }
}

export default function SettingsDialog({ settings, onClose, onSaved }) {
  const [s, setS] = useState(() => normalize(settings))
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const setDay = (key, patch) => setS((x) => ({ ...x, week: { ...x.week, [key]: { ...x.week[key], ...patch } } }))
  const restUsed = useMemo(() => (s.rest || []).reduce((sum, b) => sum + blockMinutes(b), 0), [s.rest])
  const restRequired = Math.round(Number(s.rest_hours || 0) * 60)
  const copyToAll = (key) => {
    const src = s.week[key]
    setS((x) => {
      const week = { ...x.week }
      for (const [k] of DAYS) {
        if (k !== key && Number(week[k]?.enabled)) week[k] = { ...week[k], start: src.start, end: src.end, breaks: JSON.parse(JSON.stringify(src.breaks || [])) }
      }
      return { ...x, week }
    })
  }
  const save = async () => {
    setError('')
    const times = []
    for (const [k] of DAYS) {
      const d = s.week[k] || {}
      if (Number(d.enabled)) times.push(d.start, d.end, ...(d.breaks || []).flatMap((b) => [b.from, b.to]))
    }
    ;(s.rest || []).forEach((b) => times.push(b.from, b.to))
    if (times.some((t) => !TIME.test(t || ''))) {
      setError('Neteisingas laiko formatas. Naudokite 24 val. formatą HH:MM.')
      return
    }
    if (restUsed !== restRequired) {
      setError(`Poilsio blokai sudaro ${Math.round(restUsed / 6) / 10} val., o privaloma lygiai ${restRequired / 60} val. Pakoreguokite blokus arba privalomų valandų skaičių.`)
      return
    }
    setSaving(true)
    try {
      await ajax('cjs_calendar_save_settings', { settings: s })
      onSaved()
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Dialog
      isOpen
      onClose={onClose}
      size="lg"
      title="Kalendoriaus nustatymai"
      footer={<><Button variant="tertiary" onPress={onClose}>Atšaukti</Button><Btn variant="primary" isPending={saving} onPress={save}>Išsaugoti</Btn></>}
    >
      <div className="flex flex-col gap-5">
        {error && <Notice tone="danger">{error}</Notice>}
        <section className="flex flex-col gap-2">
          <h3 className="text-[12px] font-semibold uppercase tracking-wide text-muted">Darbo savaitė</h3>
          {DAYS.map(([key, label]) => {
            const d = s.week[key] || { enabled: 0, start: '08:00', end: '17:00', breaks: [] }
            const on = !!Number(d.enabled)
            return (
              <div key={key} className="bg-surface-secondary/70 p-2.5">
                <div className="flex flex-wrap items-center gap-3">
                  <Check checked={on} onChange={(v) => setDay(key, { ...d, enabled: v ? 1 : 0 })}><span className="w-24 text-[13px] font-medium">{label}</span></Check>
                  <div className={cx('flex flex-wrap items-center gap-3 transition-opacity duration-200 ease-out', !on && 'pointer-events-none opacity-0')} aria-hidden={!on}>
                    <div className="flex items-center gap-1.5">
                      <Time value={d.start} label={`${label}: pradžia`} onChange={(v) => setDay(key, { start: v })} />
                      <span className="text-muted">-</span>
                      <Time value={d.end} label={`${label}: pabaiga`} onChange={(v) => setDay(key, { end: v })} />
                    </div>
                    <Button size="sm" variant="tertiary" onPress={() => copyToAll(key)}><Copy className="size-4" />Kopijuoti kitoms dienoms</Button>
                  </div>
                </div>
                <Expand open={on}>
                  <div className="mt-2 pl-7">
                    <div className="mb-1 text-[11.5px] text-muted">Pertraukos</div>
                    <Blocks blocks={d.breaks || []} onChange={(b) => setDay(key, { breaks: b })} addDefault={{ from: '12:00', to: '13:00' }} addLabel="Pridėti pertrauką" />
                  </div>
                </Expand>
              </div>
            )
          })}
        </section>
        <section className="flex flex-col gap-2">
          <h3 className="text-[12px] font-semibold uppercase tracking-wide text-muted">Privalomas poilsis</h3>
          <div className="flex flex-wrap items-end gap-3">
            <Field label="Privalomo poilsio valandos per parą" className="w-60" required>
              <NumberInput value={s.rest_hours} onChange={(v) => setS({ ...s, rest_hours: v })} min="0" step="0.5" />
            </Field>
            <span className={cx('pb-2 text-[12.5px] font-medium', restUsed === restRequired ? 'text-success-strong' : 'text-danger')}>Panaudota: {Math.round(restUsed / 6) / 10} val. / {restRequired / 60} val.</span>
          </div>
          <Blocks blocks={s.rest || []} onChange={(b) => setS({ ...s, rest: b })} addDefault={{ from: '22:00', to: '06:00' }} addLabel="Poilsio laikas" />
        </section>
        <section className="grid gap-3 sm:grid-cols-2">
          <Field label="Algoritmas" required>
            <SelectField value={s.algorithm} onChange={(v) => setS({ ...s, algorithm: v })} ariaLabel="Algorithm" options={[{ value: 'fcfs', label: 'Eiliškumas pagal terminą (FCFS)' }, { value: 'sjf', label: 'Trumpiausias darbas pirmiau (SJF)' }, { value: 'rr', label: 'Round Robin (du darbai per dieną)' }]} />
          </Field>
          <Field label="Automatiškai skaidyti darbą į segmentus" required>
            <SelectField value={String(s.auto_split)} onChange={(v) => setS({ ...s, auto_split: Number(v) })} ariaLabel="Auto split" options={[{ value: '0', label: 'Išjungta' }, { value: '30', label: '30 min' }, { value: '60', label: '1 val.' }, { value: '120', label: '2 val.' }, { value: '240', label: '4 val.' }]} />
          </Field>
          <Field label="Netelpantis darbas" required>
            <SelectField value={s.overflow} onChange={(v) => setS({ ...s, overflow: v })} ariaLabel="Overflow" options={[{ value: 'delay', label: 'Vėlinti užsakymus' }, { value: 'increase', label: 'Didinti darbo valandas' }]} />
          </Field>
          <Expand open={s.overflow === 'increase'}>
            <Field label="Valandų didinimo lygis" required>
              <SelectField value={String(s.aggro)} onChange={(v) => setS({ ...s, aggro: Number(v) })} ariaLabel="Level" options={[{ value: '1', label: '1 - ilginti darbo dienas' }, { value: '2', label: '2 - dirbti ir poilsio dienomis' }, { value: '3', label: '3 - palikti tik ilgiausią pertrauką' }, { value: '4', label: '4 - be pertraukų' }]} />
            </Field>
          </Expand>
        </section>
      </div>
    </Dialog>
  )
}
