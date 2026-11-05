import { boot } from './api'
import { notifyUrl } from './hooks'

export const VIEW_TO_SLUG = Object.fromEntries(Object.entries(boot.pages || {}).map(([slug, view]) => [view, slug]))

export const VIEW_TITLES = {
  orders: 'Orders',
  inventory: 'Stock',
  required: 'Missing Stock',
  'stock-orders': 'Stock Orders',
  discrepancies: 'Discrepancies',
  calendar: 'Manufacturing Calendar',
  timeline: 'Order Calendar',
  settings: 'Settings and Logs',
}

export function viewTitle(view) {
  return VIEW_TITLES[view] || ''
}

function syncTitle(view) {
  const title = VIEW_TITLES[view]
  if (!title || typeof document === 'undefined') return
  const current = document.title || ''
  const i = current.indexOf(' ‹ ')
  document.title = i >= 0 ? title + current.slice(i) : title
}

const listeners = new Set()

export function onViewChange(fn) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

export function currentView() {
  const page = new URL(window.location.href).searchParams.get('page')
  return (boot.pages && boot.pages[page]) || boot.view || 'orders'
}

export function viewUrl(view, params = {}) {
  const url = new URL(window.location.origin + window.location.pathname)
  url.searchParams.set('page', VIEW_TO_SLUG[view] || view)
  for (const [k, v] of Object.entries(params)) {
    if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, String(v))
  }
  return url.toString()
}

export function adminViewUrl(view, params = {}) {
  const url = new URL((boot.admin || '/wp-admin/') + 'admin.php', window.location.origin)
  url.searchParams.set('page', VIEW_TO_SLUG[view] || view)
  for (const [k, v] of Object.entries(params)) {
    if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, String(v))
  }
  return url.toString()
}

function syncMenu(view) {
  syncTitle(view)
  const slug = VIEW_TO_SLUG[view]
  const menu = document.getElementById('toplevel_page_cjs-orders-list')
  if (!menu || !slug) return
  menu.querySelectorAll('li').forEach((li) => {
    const a = li.querySelector('a')
    if (!a) return
    const match = a.getAttribute('href') && a.getAttribute('href').includes(`page=${slug}`) && !a.getAttribute('href').includes(`page=${slug}-`)
    li.classList.toggle('current', !!match)
    a.classList.toggle('current', !!match)
    if (match) a.setAttribute('aria-current', 'page')
    else a.removeAttribute('aria-current')
  })
}

export function navigate(view, params = {}) {
  if (!document.getElementById('cjs-root')) {
    window.location.href = adminViewUrl(view, params)
    return
  }
  window.history.pushState({ cjs: true }, '', viewUrl(view, params))
  syncMenu(view)
  listeners.forEach((fn) => fn(view))
  notifyUrl()
  window.scrollTo({ top: 0 })
}

export function interceptMenu() {
  const menu = document.getElementById('toplevel_page_cjs-orders-list')
  if (!menu) return
  menu.addEventListener('click', (e) => {
    const a = e.target.closest('a')
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
    const href = a.getAttribute('href') || ''
    const m = href.match(/page=([a-z0-9-]+)/)
    if (!m) return
    const view = boot.pages && boot.pages[m[1]]
    if (!view) return
    e.preventDefault()
    navigate(view)
  })
  window.addEventListener('popstate', () => {
    const view = currentView()
    syncMenu(view)
    listeners.forEach((fn) => fn(view))
  })
}
