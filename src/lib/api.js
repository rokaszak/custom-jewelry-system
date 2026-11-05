export const boot = window.CJS_BOOT || {}

const writeListeners = new Set()

export function onWrite(fn) {
  writeListeners.add(fn)
  return () => writeListeners.delete(fn)
}

function emitWrite(method, path, quiet) {
  if (quiet) return
  writeListeners.forEach((fn) => {
    try {
      fn(method, path)
    } catch {
      return
    }
  })
}

const AJAX_WRITES = /_(save|delete|split)/

function buildUrl(path, query) {
  let url = (boot.rest || '/wp-json/cjs/v2/') + String(path).replace(/^\//, '')
  if (query) {
    const qs = new URLSearchParams()
    for (const [k, v] of Object.entries(query)) {
      if (v === undefined || v === null || v === '') continue
      if (Array.isArray(v)) {
        if (v.length) qs.set(k, v.join(','))
      } else if (typeof v === 'object') {
        qs.set(k, JSON.stringify(v))
      } else {
        qs.set(k, String(v))
      }
    }
    const s = qs.toString()
    if (s) url += (url.includes('?') ? '&' : '?') + s
  }
  return url
}

export class ApiError extends Error {
  constructor(message, { code, data, status } = {}) {
    super(message)
    this.code = code
    this.data = data
    this.status = status
  }
}

export async function api(path, { method = 'GET', body, query, signal, quiet } = {}) {
  const headers = { 'X-WP-Nonce': boot.nonce }
  let payload = body
  if (body !== undefined && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }
  let res
  try {
    res = await fetch(buildUrl(path, query), { method, headers, body: payload, credentials: 'same-origin', signal })
  } catch (e) {
    if (e.name === 'AbortError') throw e
    throw new ApiError('Network error. Check your connection and try again.', { code: 'network' })
  }
  const text = await res.text()
  let data = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = null
  }
  if (!res.ok) {
    if (data && data.code === 'rest_cookie_invalid_nonce') {
      throw new ApiError('Your session expired. Reload the page to continue.', { code: data.code, status: res.status })
    }
    const message = (data && data.message) || `Request failed (${res.status})`
    throw new ApiError(message, { code: data && data.code, data: data && data.data, status: res.status })
  }
  if (method !== 'GET') emitWrite(method, path, quiet)
  return data
}

export const get = (path, query, opts) => api(path, { ...opts, query })
export const post = (path, body) => api(path, { method: 'POST', body: body ?? {} })
export const patch = (path, body) => api(path, { method: 'PATCH', body: body ?? {} })
export const del = (path, body) => api(path, { method: 'DELETE', body })

export async function ajax(action, data = {}) {
  const form = new FormData()
  form.set('action', action)
  form.set('nonce', boot.nonce)
  for (const [k, v] of Object.entries(data)) {
    if (v === undefined || v === null) continue
    form.set(k, typeof v === 'object' ? JSON.stringify(v) : String(v))
  }
  const res = await fetch(boot.ajax, { method: 'POST', body: form, credentials: 'same-origin' })
  let json = null
  try {
    json = await res.json()
  } catch {
    throw new ApiError('Unexpected server response.')
  }
  if (!json || !json.success) {
    throw new ApiError((json && json.data && json.data.message) || 'Request failed.')
  }
  if (AJAX_WRITES.test(String(action))) emitWrite('POST', action)
  return json.data
}

export function uploadWithProgress(path, form, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', buildUrl(path))
    xhr.setRequestHeader('X-WP-Nonce', boot.nonce)
    xhr.withCredentials = true
    xhr.timeout = 600000
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && onProgress) onProgress(Math.round((e.loaded / e.total) * 100))
    }
    xhr.onload = () => {
      let data = null
      try {
        data = JSON.parse(xhr.responseText)
      } catch {
        data = null
      }
      if (xhr.status >= 200 && xhr.status < 300) {
        emitWrite('POST', path)
        resolve(data)
      } else reject(new ApiError((data && data.message) || `Upload failed (${xhr.status})`, { code: data && data.code }))
    }
    xhr.onerror = () => reject(new ApiError('Upload failed. Check your connection.'))
    xhr.ontimeout = () => reject(new ApiError('Upload timed out.'))
    xhr.send(form)
  })
}

export const itemSuggestions = (query, opts) => get('items/suggestions', query, opts)
export const setBatchCost = (batchId, unitCost) => post(`batches/${batchId}/cost`, { unit_cost: unitCost === '' || unitCost === undefined ? null : unitCost })
export const sendAllocation = (allocationId) => post(`allocations/${allocationId}/send`)
