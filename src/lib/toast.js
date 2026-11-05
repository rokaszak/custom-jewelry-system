const SUCCESS_MS = 1000
const DEFAULT_MS = 20000
const MAX_TOASTS = 8
const LEAVE_MS = 220

let toasts = []
let seq = 0
const listeners = new Set()
const timers = new Map()

function emit() {
  listeners.forEach((l) => l())
}

function patch(id, values) {
  toasts = toasts.map((t) => (t.id === id ? { ...t, ...values } : t))
  emit()
}

export function subscribe(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function getToasts() {
  return toasts
}

export function dismissToast(id) {
  clearTimeout(timers.get(id))
  timers.delete(id)
  if (!toasts.some((t) => t.id === id && !t.leaving)) return
  patch(id, { leaving: true })
  setTimeout(() => {
    toasts = toasts.filter((t) => t.id !== id)
    emit()
  }, LEAVE_MS)
}

export function pinToast(id) {
  clearTimeout(timers.get(id))
  timers.delete(id)
  patch(id, { pinned: true })
}

export function showToast(tone, title, opts = {}) {
  const id = ++seq
  const duration = tone === 'success' ? SUCCESS_MS : DEFAULT_MS
  const item = {
    id,
    tone,
    title: String(title ?? ''),
    description: opts.description ? String(opts.description) : '',
    duration,
    until: Date.now() + duration,
    pinned: false,
    leaving: false,
  }
  toasts = [...toasts, item].slice(-MAX_TOASTS)
  emit()
  timers.set(id, setTimeout(() => dismissToast(id), duration))
  return id
}

export function hasRecentToast(ms, exceptTitle) {
  const since = Date.now() - ms
  return toasts.some((t) => !t.leaving && t.title !== exceptTitle && t.until - t.duration >= since)
}

export function hasToast(tone, title) {
  return toasts.some((t) => t.tone === tone && t.title === title && !t.leaving)
}

export const toast = {
  success: (title, opts) => showToast('success', title, opts),
  info: (title, opts) => showToast('info', title, opts),
  warning: (title, opts) => showToast('warning', title, opts),
  danger: (title, opts) => showToast('danger', title, opts),
  error: (title, opts) => showToast('danger', title, opts),
  close: dismissToast,
}
