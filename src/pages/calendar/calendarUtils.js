export function statusOf(props) {
  if (!props || props.type !== 'work' || !props.deadline || !props.finish) return null
  if (props.days_late === null || props.days_late === undefined || props.days_late === '') return null
  const late = parseInt(props.days_late, 10)
  if (late > 0) return 'late'
  const deadline = new Date(`${props.deadline}T23:59:59`).getTime()
  const finish = new Date(String(props.finish).replace(' ', 'T')).getTime()
  if (Number.isNaN(deadline) || Number.isNaN(finish)) return null
  return deadline - finish < 10 * 86400000 ? 'tight' : 'ok'
}

export const DOT = { late: 'bg-danger cjs-blink-late', tight: 'bg-warning cjs-blink-tight', ok: 'bg-success' }

export const BADGE = { late: 'bg-danger', tight: 'bg-warning', ok: 'bg-success' }

export function statusText(props) {
  const late = parseInt(props?.days_late, 10)
  return late > 0 ? `Vėluoja ${late} d.` : 'Spėjama laiku'
}

export function pad(n) {
  return String(n).padStart(2, '0')
}

export function fmtDT(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}
