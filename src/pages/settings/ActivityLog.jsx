import { useMemo, useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { Avatar } from '@heroui/react'
import { Magnifier, ChevronDown, ArrowRight } from '@gravity-ui/icons'
import { get } from '../../lib/api'
import { useDebounced } from '../../lib/hooks'
import { dateTime } from '../../lib/format'
import { navigate } from '../../lib/nav'
import { CopyButton, Empty, Expand, Pager, SelectField, SkBox, SkChip, SkeletonList, SkLine, skw, TextInput, Tone, cx } from '../../components/ui'
import { initials, objectLabel, readableAction, summarize } from './logFormat'
import './settings.css'

const SEVERITY = { info: 'default', success: 'success', warning: 'warning', error: 'danger' }
const SEVERITY_LABEL = { info: 'Info', success: 'Success', warning: 'Warning', error: 'Error' }
const COLLAPSED_CHANGES = 3

function Change({ change }) {
  return (
    <li className="flex min-w-0 flex-wrap items-baseline gap-x-1.5 text-[12.5px] leading-snug">
      <span className="text-muted">{change.label}:</span>
      {change.from && <span className={cx('break-words', change.to && 'text-muted line-through decoration-1')}>{change.from}</span>}
      {change.from && change.to && <ArrowRight className="size-3 shrink-0 self-center text-muted" />}
      {change.to && <span className="break-words font-medium text-foreground">{change.to}</span>}
      {change.text && <span className="break-words text-foreground">{change.text}</span>}
    </li>
  )
}

function Trail({ groups }) {
  return (
    <ol className="cjs-log-trail flex flex-col gap-2.5">
      {groups.map((g, i) => (
        <li key={i} className="flex gap-3">
          <span className="cjs-dot mt-1.5 size-[9px] bg-accent" />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-baseline gap-x-2 text-[11.5px] text-muted">
              <span className="tabular-nums">{g.time || 'Be laiko'}</span>
              {g.user && <span className="font-medium text-foreground/80">{g.user}</span>}
            </div>
            <ul className="mt-0.5 flex flex-col gap-0.5">
              {g.changes.map((c, j) => <Change key={j} change={c} />)}
            </ul>
          </div>
        </li>
      ))}
    </ol>
  )
}

function Entry({ log, open, onToggle }) {
  const info = useMemo(() => summarize(log.details), [log.details])
  const hasDetails = info.fields.length > 0 || info.events.length > 0 || info.text !== '' || info.changes.length > COLLAPSED_CHANGES
  const expandable = hasDetails || (log.details !== null && log.details !== undefined && log.details !== '')
  const json = useMemo(() => JSON.stringify({ id: log.id, created_at: log.created_at, user: log.user, action: log.action, object_type: log.object_type, object_id: log.object_id, severity: log.severity, details: log.details }, null, 2), [log])
  const shownChanges = open ? info.changes : info.changes.slice(0, COLLAPSED_CHANGES)
  const object = objectLabel(log.object_type, log.object_id)
  return (
    <li className="cjs-log-entry flex flex-col gap-3 px-4 py-3 sm:flex-row sm:gap-4 sm:px-5">
      <div className="flex min-w-0 items-center gap-2.5 sm:w-[190px] sm:shrink-0 sm:items-start">
        <Avatar size="sm" className="shrink-0">
          {log.avatar ? <Avatar.Image src={log.avatar} alt="" /> : null}
          <Avatar.Fallback>{initials(log.user)}</Avatar.Fallback>
        </Avatar>
        <div className="min-w-0">
          <div className="truncate text-[13px] font-medium text-foreground">{log.user}</div>
          <div className="text-[11.5px] tabular-nums text-muted">{dateTime(log.created_at)}</div>
        </div>
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <span className="text-[13.5px] font-medium text-foreground">{readableAction(log.action)}</span>
          {object && (log.object_type === 'order' && log.object_id ? (
            <button type="button" className="text-[12.5px] font-medium text-accent hover:underline" onClick={() => navigate('orders', { order: log.object_id })}>{object}</button>
          ) : (
            <span className="text-[12.5px] text-muted">{object}</span>
          ))}
          <span className="ml-auto flex items-center gap-2">
            <Tone tone={SEVERITY[log.severity] || 'default'}>{SEVERITY_LABEL[log.severity] || log.severity || 'Info'}</Tone>
            {expandable && (
              <button type="button" aria-expanded={open} aria-label={open ? 'Hide details' : 'Show details'} className="flex size-7 items-center justify-center text-muted hover:bg-surface-secondary hover:text-foreground" onClick={onToggle}>
                <ChevronDown className={cx('size-4 transition-transform duration-200 ease-out', open && 'rotate-180')} />
              </button>
            )}
          </span>
        </div>
        {shownChanges.length > 0 && (
          <ul className="mt-1.5 flex flex-col gap-0.5">
            {shownChanges.map((c, i) => <Change key={i} change={c} />)}
          </ul>
        )}
        {!open && info.changes.length > COLLAPSED_CHANGES && (
          <button type="button" className="mt-1 text-[12px] text-accent hover:underline" onClick={onToggle}>+{info.changes.length - COLLAPSED_CHANGES} more</button>
        )}
        {!open && info.events.length > 0 && (
          <button type="button" className="mt-1 text-[12px] text-accent hover:underline" onClick={onToggle}>History: {info.events.length} {info.events.length === 1 ? 'entry' : 'entries'}</button>
        )}
        {expandable && (
          <Expand open={open}>
            <div className="flex flex-col gap-3 pt-3">
              {info.text && <div className="break-words text-[12.5px] text-foreground">{info.text}</div>}
              {info.fields.length > 0 && (
                <dl className="grid grid-cols-[minmax(110px,auto)_1fr] gap-x-4 gap-y-1 bg-surface-secondary/60 px-3 py-2.5 text-[12.5px]">
                  {info.fields.map(([k, v], i) => (
                    <div key={i} className="contents">
                      <dt className="text-muted">{k}</dt>
                      <dd className="min-w-0 break-words text-foreground">{v}</dd>
                    </div>
                  ))}
                </dl>
              )}
              {info.events.length > 0 && (
                <div className="flex flex-col gap-2">
                  <div className="text-[11px] font-semibold uppercase tracking-wide text-muted">History</div>
                  <Trail groups={info.events} />
                </div>
              )}
              <div>
                <CopyButton text={json} label="Copy JSON" variant="tertiary" />
              </div>
            </div>
          </Expand>
        )}
      </div>
    </li>
  )
}

export default function ActivityLog() {
  const [severity, setSeverity] = useState('')
  const [search, setSearch] = useState('')
  const term = useDebounced(search, 300)
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(() => new Set())
  const q = useQuery({ queryKey: ['log', severity, term, page], queryFn: () => get('log', { severity, search: term, page }), placeholderData: keepPreviousData })
  const toggle = (id) => setOpen((prev) => {
    const next = new Set(prev)
    next.has(id) ? next.delete(id) : next.add(id)
    return next
  })
  const logs = q.data?.logs || []
  return (
    <div className="flex flex-col gap-3">
      <div className="cjs-card sticky top-[var(--wp-admin--admin-bar--height,32px)] z-[5] flex flex-wrap items-center gap-2 p-3">
        <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
          <Magnifier className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <TextInput value={search} onChange={(v) => { setSearch(v); setPage(1) }} placeholder="Type or ID, e.g. order 9015" className="pl-8" aria-label="Search the log" />
        </div>
        <SelectField size="sm" className="w-[150px]" value={severity} onChange={(v) => { setSeverity(v); setPage(1) }} allowEmpty emptyLabel="All levels" options={Object.entries(SEVERITY_LABEL).map(([value, label]) => ({ value, label }))} ariaLabel="Level" />
        <span className="ml-auto text-[12px] text-muted">Entries older than 30 days are removed automatically.</span>
      </div>
      <div className={cx('cjs-card overflow-hidden transition-opacity duration-200', q.isFetching && !q.isLoading && 'opacity-70')}>
        {q.isLoading ? (
          <SkeletonList rows={8} divided={false} itemClassName="cjs-log-entry flex flex-col gap-3 px-4 py-3 sm:flex-row sm:gap-4 sm:px-5">
            {(i) => (
              <>
                <div className="flex min-w-0 items-center gap-2.5 sm:w-[190px] sm:shrink-0 sm:items-start">
                  <SkBox w={32} h={32} className="rounded-full" />
                  <div className="min-w-0 flex-1">
                    <SkLine size={13} w={80} />
                    <SkLine size={11.5} w={104} />
                  </div>
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex h-7 items-center gap-x-2.5">
                    <SkLine size={13.5} w={[180, 140, 220][i % 3]} />
                    <SkLine size={12.5} w={70} />
                    <SkChip w={34} className="ml-auto" />
                    <SkBox w={28} h={28} />
                  </div>
                  <SkLine size={12.5} lh={1.36} w={skw(i, 30)} className="mt-1.5" />
                </div>
              </>
            )}
          </SkeletonList>
        ) : q.isError ? <Empty title="Could not load the log" description={q.error?.message} /> : !logs.length ? <Empty title="No log entries" /> : (
          <ul>
            {logs.map((l) => <Entry key={l.id} log={l} open={open.has(l.id)} onToggle={() => toggle(l.id)} />)}
          </ul>
        )}
      </div>
      {q.data && <Pager page={page} pages={q.data.pages} total={q.data.total} onChange={setPage} label="entries" />}
    </div>
  )
}
