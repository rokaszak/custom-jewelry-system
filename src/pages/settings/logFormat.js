const LABELS = {
  casting_notes: 'Komentaras',
  order_model: 'Užsakyti modelį',
  order_production: 'Užsakyti gamybą',
  order_printing: 'Spauda',
  manufacturing_status: 'Statusas',
  order_type: 'Užsakymo tipas',
  manufacture_by_date: 'Pagaminti iki',
  finish_by_date: 'Užprabuoti iki',
  deliver_by_date: 'Pristatyti iki',
  assigned_hours: 'Paskirta valandų',
  completed_hours: 'Padaryta valandų',
  name: 'Pavadinimas',
  identifier: 'Identifikatorius',
  order_id: 'Užsakymas',
  order_item_id: 'Užsakymo prekė',
  item_status: 'Būsena',
  item_category: 'Kategorija',
  status: 'Būsena',
  file: 'Failas',
  number: 'Numeris',
  qty: 'Kiekis',
  lines: 'Eilutės',
  line_id: 'Eilutė',
  stone_id: 'Akmuo',
  stone_type: 'Akmens tipas',
  stone_origin: 'Kilmė',
  stone_shape: 'Forma',
  stone_quantity: 'Kiekis',
  stone_size_value: 'Dydis',
  stone_size_unit: 'Matas',
  stone_color: 'Spalva',
  stone_setting: 'Įtvirtinimas',
  stone_clarity: 'Grynumas',
  stone_cut_grade: 'Šlifavimas',
  origin_country: 'Šalis',
  certificate: 'Sertifikatas',
  custom_comment: 'Komentaras',
  values: 'Reikšmės',
  value: 'Reikšmė',
  label: 'Pavadinimas',
  options: 'Reikšmės',
  map: 'Priskyrimai',
  error: 'Klaida',
  errors: 'Klaidos',
  counts: 'Kiekiai',
  to: 'Nauja būsena',
  in_cart: 'Krepšelyje',
  received: 'Gauta',
  checkout_enabled: 'Klausti prie apmokėjimo',
  min_days: 'Anksčiausiai (d.)',
  max_days: 'Vėliausiai (d.)',
  default_days: 'Standartinis terminas (d.)',
  supplier: 'Tiekėjas',
  order_date: 'Užsakymo data',
  stones: 'Akmenys',
  scheduled_orders: 'Suplanuota užsakymų',
  created: 'Sukurta intervalų',
  late: 'Vėluoja',
  algorithm: 'Planavimo tvarka',
  overflow: 'Kai netelpa',
  orders: 'Užsakymai',
  intervals_created: 'Sukurta intervalų',
  late_orders: 'Vėluojantys užsakymai',
  category_id: 'Kategorija',
  legacy: 'Iš 1.x',
}

const BOOLEAN_FIELDS = new Set(['order_model', 'order_production', 'order_printing', 'in_cart', 'received', 'checkout_enabled'])
const HIDDEN_FIELDS = new Set(['events', 'created_by', 'id'])
const ORDER_FIELDS = new Set(['order_id'])

export function fieldLabel(key) {
  if (LABELS[key]) return LABELS[key]
  const s = String(key || '').replace(/_/g, ' ').trim()
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''
}

function isEmpty(v) {
  return v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)
}

export function formatValue(key, v) {
  if (isEmpty(v)) return ''
  if (BOOLEAN_FIELDS.has(key) || typeof v === 'boolean') {
    const on = v === true || v === 1 || v === '1' || v === 'true' || v === 'yes'
    return on ? 'Taip' : 'Ne'
  }
  if (ORDER_FIELDS.has(key)) return `#${v}`
  if (Array.isArray(v)) {
    if (v.every((x) => x === null || ['string', 'number', 'boolean'].includes(typeof x))) return v.filter((x) => !isEmpty(x)).join(', ')
    return `${v.length} įrašai`
  }
  if (typeof v === 'object') {
    return Object.entries(v)
      .filter(([, x]) => !isEmpty(x))
      .map(([k, x]) => `${fieldLabel(k)}: ${typeof x === 'object' ? formatValue(k, x) : x}`)
      .join(', ')
  }
  return String(v)
}

export function describeChange(field, from, to) {
  const label = fieldLabel(field)
  const a = formatValue(field, from)
  const b = formatValue(field, to)
  if (ORDER_FIELDS.has(field)) {
    if (!a && b) return { label, text: `priskirtas ${b}` }
    if (a && !b) return { label, text: `nuimtas ${a}` }
  }
  if (!a && b) return { label, to: b }
  if (a && !b) return { label, from: a, text: 'išvalyta' }
  return { label, from: a, to: b }
}

export function parseJson(v) {
  if (typeof v !== 'string') return v
  const t = v.trim()
  if (!t || (t[0] !== '{' && t[0] !== '[')) return v
  try {
    return JSON.parse(t)
  } catch {
    return v
  }
}

function parseEvents(raw) {
  const list = parseJson(raw)
  if (!Array.isArray(list)) return []
  return list.filter((e) => e && typeof e === 'object' && e.field)
}

function groupEvents(events) {
  const groups = []
  for (const e of events) {
    const time = String(e.timestamp || e.time || '').slice(0, 16).replace('T', ' ')
    const user = e.user || ''
    const last = groups[groups.length - 1]
    const change = describeChange(e.field, e.old, e.new)
    if (last && last.time === time && last.user === user) last.changes.push(change)
    else groups.push({ time, user, changes: [change] })
  }
  return groups
}

export function readableAction(action) {
  const a = String(action || '')
  const m = /^Order field '([a-z_]+)' updated( via AJAX)?$/.exec(a)
  if (m) return `${fieldLabel(m[1])} pakeista`
  const o = /^Options (?:updated|order updated) for ([a-z_]+)$/.exec(a)
  if (o) return `${o[1] === 'order_types' ? 'Užsakymo tipai' : o[1] === 'manufacturing_statuses' ? 'Gamybos statusai' : fieldLabel(o[1])}: sąrašas atnaujintas`
  return a
}

const OBJECTS = {
  order: 'Užsakymas',
  stock_order: 'Stock order',
  stone_order: 'Akmenų užsakymas',
  stone: 'Akmuo',
  inventory: 'Inventory',
  inventory_item: 'Inventoriaus prekė',
  settings: 'Nustatymai',
  calendar: 'Kalendorius',
}

export function objectLabel(type, id) {
  if (!type) return ''
  const name = OBJECTS[type] || fieldLabel(type)
  if (type === 'calendar' || type === 'settings') return name
  return id ? `${name} #${id}` : name
}

export function summarize(details) {
  const d = parseJson(details)
  const out = { changes: [], fields: [], events: [], text: '' }
  if (isEmpty(d)) return out
  if (typeof d !== 'object') {
    out.text = String(d)
    return out
  }
  if (Array.isArray(d)) {
    out.text = formatValue('', d)
    return out
  }
  if (d.field && ('value' in d) && Object.keys(d).length <= 3) {
    out.changes.push(describeChange(d.field, null, d.value))
    return out
  }
  if (d.field && ('old' in d || 'new' in d)) {
    out.changes.push(describeChange(d.field, d.old, d.new))
  }
  out.events = groupEvents(parseEvents(d.events))
  for (const [k, v] of Object.entries(d)) {
    if (HIDDEN_FIELDS.has(k) || isEmpty(v)) continue
    if (d.field && (k === 'field' || k === 'old' || k === 'new' || k === 'value')) continue
    const text = formatValue(k, parseJson(v))
    if (text !== '') out.fields.push([fieldLabel(k), text])
  }
  return out
}

export function initials(name) {
  const parts = String(name || '').replace(/^#/, '').trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return '?'
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase()
}
