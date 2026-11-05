import { useCallback, useEffect, useLayoutEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery, useMutation, useQueryClient, isCancelledError } from '@tanstack/react-query'
import { toast, hasToast, hasRecentToast } from './toast'
import { get, boot, onWrite } from './api'

export function useDebounced(value, delay = 300) {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])
  return v
}

export function useMediaQuery(query) {
  const subscribe = useCallback((cb) => {
    const mq = window.matchMedia(query)
    mq.addEventListener('change', cb)
    return () => mq.removeEventListener('change', cb)
  }, [query])
  return useSyncExternalStore(subscribe, () => window.matchMedia(query).matches, () => false)
}

export function useElementWidth() {
  const [el, setEl] = useState(null)
  const [width, setWidth] = useState(0)
  useLayoutEffect(() => {
    if (!el) return
    setWidth(el.clientWidth)
    if (typeof ResizeObserver === 'undefined') return
    let frame = 0
    const ro = new ResizeObserver(() => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => setWidth(el.clientWidth))
    })
    ro.observe(el)
    return () => {
      cancelAnimationFrame(frame)
      ro.disconnect()
    }
  }, [el])
  return [setEl, width]
}

const urlListeners = new Set()

export function notifyUrl() {
  urlListeners.forEach((fn) => fn())
}

window.addEventListener('popstate', notifyUrl)

export function setUrlParams(updates, { replace = false } = {}) {
  const url = new URL(window.location.href)
  for (const [k, v] of Object.entries(updates)) {
    if (v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)) url.searchParams.delete(k)
    else url.searchParams.set(k, Array.isArray(v) ? v.join(',') : String(v))
  }
  if (url.href === window.location.href) return
  if (replace) window.history.replaceState(window.history.state, '', url)
  else window.history.pushState(window.history.state, '', url)
  notifyUrl()
}

export function useUrlParam(key, fallback = '') {
  const subscribe = useCallback((cb) => {
    urlListeners.add(cb)
    return () => urlListeners.delete(cb)
  }, [])
  const value = useSyncExternalStore(subscribe, () => new URL(window.location.href).searchParams.get(key) ?? fallback)
  const set = useCallback((v, opts) => setUrlParams({ [key]: v }, opts), [key])
  return [value, set]
}

export function useBootstrap() {
  return useQuery({
    queryKey: ['bootstrap'],
    queryFn: () => get('bootstrap'),
    staleTime: 60_000,
    initialData: boot.initial || undefined,
  })
}

export function useCatalog() {
  const q = useQuery({ queryKey: ['catalog'], queryFn: () => get('catalog'), staleTime: 60_000 })
  const data = q.data || { units: [], categories: [] }
  const unitById = Object.fromEntries((data.units || []).map((u) => [u.id, u]))
  const categoryById = Object.fromEntries((data.categories || []).map((c) => [c.id, c]))
  return { ...q, catalog: data, unitById, categoryById }
}

export function errorMessage(e) {
  if (!e) return 'Something went wrong.'
  return e.message || String(e)
}

export function notifySaved(message = 'Saved!') {
  setTimeout(() => {
    if (!hasRecentToast(500, message)) toast.success(message)
  }, 200)
}

export function notifyError(e) {
  if (isCancelledError(e)) return
  const message = errorMessage(e)
  if (hasToast('danger', message)) return
  toast.danger(message)
}

onWrite((method) => notifySaved(method === 'DELETE' ? 'Deleted' : 'Saved!'))

export function useAction(fn, { success, invalidate = [], onSuccess, onError } = {}) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: (data, vars) => {
      if (success) toast.success(typeof success === 'function' ? success(data, vars) : success)
      for (const key of invalidate) qc.invalidateQueries({ queryKey: Array.isArray(key) ? key : [key] })
      if (onSuccess) onSuccess(data, vars)
    },
    onError: (e, vars) => {
      if (onError) onError(e, vars)
      else notifyError(e)
    },
  })
}

export function useLatest(value) {
  const ref = useRef(value)
  ref.current = value
  return ref
}

export function useAutoSave(save, delay = 600) {
  const timer = useRef(null)
  const latest = useLatest(save)
  useEffect(() => () => clearTimeout(timer.current), [])
  return useCallback((...args) => {
    clearTimeout(timer.current)
    timer.current = setTimeout(() => latest.current(...args), delay)
  }, [delay, latest])
}
