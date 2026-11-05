import { boot } from './api'

export function qty(value, unit) {
  if (value === null || value === undefined || value === '') return ''
  const n = Number(value)
  const text = Number.isInteger(n) ? String(n) : String(Math.round(n * 1000) / 1000)
  return unit ? `${text} ${unit}` : text
}

export function money(value, digits = 2) {
  if (value === null || value === undefined || value === '') return '-'
  const symbol = boot.currency || '€'
  return `${Number(value).toFixed(digits).replace(/\.00$/, '')} ${symbol}`
}

export function hours(value) {
  if (value === null || value === undefined) return '0'
  return String(Math.round(Number(value) * 10) / 10)
}

export function date(value) {
  if (!value) return ''
  return String(value).slice(0, 10)
}

export function dateTime(value) {
  if (!value) return ''
  return String(value).slice(0, 16).replace('T', ' ')
}

export function daysLabel(days) {
  if (days === null || days === undefined) return ''
  if (days < 0) return `Vėluojama ${Math.abs(days)} d.`
  if (days === 0) return 'Šiandien'
  if (days === 1) return '1 d.'
  return `${days} d.`
}

export function daysTone(days) {
  if (days === null || days === undefined) return 'default'
  if (days < 5) return 'danger'
  if (days < 14) return 'warning'
  return 'success'
}

export function relative(value) {
  if (!value) return ''
  const then = new Date(String(value).replace(' ', 'T'))
  const diff = (Date.now() - then.getTime()) / 1000
  if (Number.isNaN(diff)) return value
  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)} min ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)} h ago`
  if (diff < 86400 * 30) return `${Math.floor(diff / 86400)} d ago`
  return date(value)
}

export function bytes(n) {
  if (!n) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  let v = n
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

export function addDays(ymd, days) {
  if (!ymd) return ''
  const [y, m, d] = ymd.split('-').map(Number)
  const dt = new Date(Date.UTC(y, m - 1, d))
  dt.setUTCDate(dt.getUTCDate() + days)
  return dt.toISOString().slice(0, 10)
}

export function plural(n, one, many) {
  return `${n} ${n === 1 ? one : many}`
}

export function hexAlpha(hex, alpha = 0.14) {
  const h = String(hex || '#6b7280').replace('#', '')
  if (h.length !== 6) return `rgba(107,114,128,${alpha})`
  const r = parseInt(h.slice(0, 2), 16)
  const g = parseInt(h.slice(2, 4), 16)
  const b = parseInt(h.slice(4, 6), 16)
  return `rgba(${r},${g},${b},${alpha})`
}

export const MOVEMENT_LABELS = {
  receipt: 'Received',
  opening: 'Opening stock',
  count: 'Stock count',
  reserve: 'Reserved',
  release: 'Released',
  use: 'Used',
  out: 'Sent out',
  return: 'Returned',
  shortfall: 'Used without stock',
  lost: 'Lost',
  demand: 'Order line',
  legacy: 'History',
  note: 'Note',
  cost: 'Cost changed',
}

export const LINE_STATUS = {
  reserved: { label: 'Assigned', color: 'success' },
  assigned: { label: 'Assigned', color: 'success' },
  used: { label: 'Assigned', color: 'success' },
  to_send: { label: 'To send', color: 'accent' },
  out: { label: 'Sent', color: 'accent' },
  sent: { label: 'Sent', color: 'accent' },
  returned: { label: 'Returned', color: 'default' },
  not_returned: { label: 'Lost', color: 'danger' },
  ordered: { label: 'Ordered', color: 'accent' },
  partial: { label: 'Missing', color: 'danger' },
  missing: { label: 'Missing', color: 'danger' },
}

export function hoursLt(value) {
  return hours(value).replace('.', ',')
}

export function ordersWordLt(n) {
  const m10 = n % 10
  const m100 = n % 100
  if (m10 === 1 && m100 !== 11) return 'užsakymas'
  if (m10 >= 2 && m10 <= 9 && (m100 < 11 || m100 > 19)) return 'užsakymai'
  return 'užsakymų'
}

export function ceil3(value) {
  return Math.ceil(Math.round((Number(value) || 0) * 1e7) / 1e4) / 1000
}

export const PRABA_REF = 999

export function hasFineness(line, category) {
  return !!((line && line.fineness === true) || (category && category.has_fineness))
}

export function defaultFineness(line, category) {
  if (!hasFineness(line, category)) return null
  return PRABA_REF
}

export function fineQty(value, unit, fineness) {
  const text = qty(value, unit)
  return fineness ? `${text} at ${qty(fineness)}` : text
}

export function grams3(value, unit) {
  const text = (Math.round((Number(value) || 0) * 1000) / 1000).toFixed(3)
  return unit ? `${text} ${unit}` : text
}

export function neededOf(atRef, fineness) {
  const f = Number(fineness) || 0
  if (!(f > 0)) return 0
  return ceil3((Math.max(0, Number(atRef) || 0) * PRABA_REF) / f)
}

export function atRefOf(amount, fineness) {
  return ceil3(((Math.max(0, Number(amount) || 0)) * (Number(fineness) || 0)) / PRABA_REF)
}

export function missingAtDefault(line, category) {
  const l = line || {}
  if (l.missing_at_default !== undefined && l.missing_at_default !== null) return Math.max(0, Number(l.missing_at_default) || 0)
  const missing = Math.max(0, Number(l.missing) || 0)
  if (!missing || !hasFineness(l, category)) return missing
  return ceil3(missing)
}

export function itemMissingAtDefault(item, category) {
  if (item && item.missing_at_default !== undefined && item.missing_at_default !== null) return Math.max(0, Number(item.missing_at_default) || 0)
  if (!item || !hasFineness(item.fineness === true ? { fineness: true } : null, category)) return Math.max(0, Number(item && item.missing) || 0)
  const demands = Array.isArray(item.demands) ? item.demands.filter((d) => Number(d.missing) > 0) : null
  if (demands && demands.length) return Math.round(demands.reduce((s, d) => s + missingAtDefault(d, category), 0) * 1000) / 1000
  return missingAtDefault({ missing: item.missing }, category)
}
