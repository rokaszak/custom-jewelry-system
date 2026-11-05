import { useEffect, useState } from 'react'
import { ArrowUturnCcwLeft } from '@gravity-ui/icons'
import { Btn, Card, IconButton, TextInput } from '../../components/ui'

function norm(s) {
  return String(s || '').trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
}

function labelMap(statuses) {
  return Object.fromEntries(statuses.map((s) => [s.key, s.label]))
}

export function StatusNames({ statuses, onSave }) {
  const [labels, setLabels] = useState(() => labelMap(statuses))
  const [saving, setSaving] = useState(false)
  useEffect(() => setLabels(labelMap(statuses)), [statuses])
  const shown = (s) => String(labels[s.key] ?? '').trim() || s.default_label
  const counts = {}
  for (const s of statuses) {
    const n = norm(shown(s))
    counts[n] = (counts[n] || 0) + 1
  }
  const dupe = (s) => counts[norm(shown(s))] > 1
  const tooLong = (s) => String(labels[s.key] ?? '').trim().length > 100
  const hasDupe = statuses.some(dupe)
  const hasLong = statuses.some(tooLong)
  const dirty = statuses.some((s) => shown(s) !== s.label)
  const submit = async () => {
    setSaving(true)
    try {
      await onSave('settings/statuses', { labels: Object.fromEntries(statuses.map((s) => [s.key, String(labels[s.key] ?? '').trim()])) })
    } catch {
      return
    } finally {
      setSaving(false)
    }
  }
  return (
    <Card
      title="Gamybos statusai"
      description="The statuses and their order are fixed. Only the names can be changed."
      actions={<Btn size="sm" variant="primary" isPending={saving} isDisabled={!dirty || hasDupe || hasLong} onPress={submit}>Save</Btn>}
    >
      <ol className="flex flex-col gap-1.5">
        {statuses.map((s, i) => (
          <li key={s.key} className="flex items-center gap-2 bg-surface-secondary/70 px-2 py-1">
            <span className="w-5 shrink-0 text-right text-[12px] tabular-nums text-muted">{i + 1}.</span>
            <TextInput
              size="sm"
              className="min-w-0 flex-1"
              value={labels[s.key] ?? ''}
              placeholder={s.default_label}
              aria-label={`Name of status ${i + 1}`}
              invalid={dupe(s) || tooLong(s)}
              onChange={(v) => setLabels((l) => ({ ...l, [s.key]: v }))}
            />
            <span className="w-8 shrink-0">
              {shown(s) !== s.default_label && <IconButton icon={ArrowUturnCcwLeft} label={`Reset to “${s.default_label}”`} onPress={() => setLabels((l) => ({ ...l, [s.key]: s.default_label }))} />}
            </span>
          </li>
        ))}
      </ol>
      {(hasDupe || hasLong) && (
        <p className="mt-2 text-[12px] text-danger">{hasDupe ? 'Two statuses cannot have the same name.' : 'A name can be at most 100 characters long.'}</p>
      )}
    </Card>
  )
}
