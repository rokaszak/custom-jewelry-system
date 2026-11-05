import { useCallback, useMemo } from 'react'

const PREFIX = 'cjs_draft:'

function storage() {
  try {
    return window.sessionStorage
  } catch {
    return null
  }
}

export function readDraft(key) {
  const s = storage()
  if (!s || !key) return null
  try {
    const raw = s.getItem(PREFIX + key)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function saveDraft(key, data) {
  const s = storage()
  if (!s || !key) return
  try {
    s.setItem(PREFIX + key, JSON.stringify(data))
  } catch {}
}

export function clearDraft(key) {
  const s = storage()
  if (!s || !key) return
  try {
    s.removeItem(PREFIX + key)
  } catch {}
}

export function useDraft(key) {
  const read = useCallback(() => readDraft(key), [key])
  const save = useCallback((data) => saveDraft(key, data), [key])
  const clear = useCallback(() => clearDraft(key), [key])
  return useMemo(() => ({ read, save, clear }), [read, save, clear])
}
