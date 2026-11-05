import { useCallback, useEffect, useRef, useState } from 'react'
import { Button } from '@heroui/react'
import { get, post } from '../../lib/api'
import { notifyError } from '../../lib/hooks'
import { Btn, Dialog, Field, SelectField } from '../../components/ui'
import { useStatuses, useStockFlow, previewTransition } from '../../components/StockModal'

const NEGATIVE = ['cancelled', 'refunded', 'failed']

const BULK_TARGETS = {
  trash: 'trash',
  delete: 'delete',
}

function wooKey(v) {
  return String(v || '').replace(/^wc-/, '')
}

function bulkTarget(action) {
  if (BULK_TARGETS[action]) return BULK_TARGETS[action]
  const m = /^mark_(.+)$/.exec(String(action || ''))
  return m ? wooKey(m[1]) : null
}

function rowStatus(id) {
  const row = document.getElementById(`order-${id}`) || document.getElementById(`post-${id}`)
  if (!row) return ''
  const m = /(?:^|\s)status-(?:wc-)?([a-z0-9_-]+)/.exec(row.className || '')
  return m ? m[1] : ''
}

function useLatestRef(value) {
  const ref = useRef(value)
  ref.current = value
  return ref
}

function useCompleteFlow() {
  const flow = useStockFlow()
  const { label } = useStatuses()
  return useCallback(async (ids) => {
    const preview = await previewTransition(ids.length === 1 ? { order_id: ids[0], target: 'done' } : { order_ids: ids, target: 'done' })
    const notDone = (o) => o.cjs_status !== 'done' && o.wc_status !== 'completed'
    const shown = (preview.orders || []).filter((o) => notDone(o) || o.needs_decision)
    const done = label('done')
    const single = shown.length === 1 ? shown[0] : null
    const message = single && notDone(single) ? `The production status is ${label(single.cjs_status)}, not ${done}. Completing the order sets it to ${done}.` : shown.some(notDone) ? `Completing an order whose production status is not ${done} sets it to ${done}.` : undefined
    return flow.resolve({ ...preview, orders: shown }, {
      orderIds: ids,
      target: 'done',
      force: shown.some(notDone),
      title: ids.length === 1 ? 'Complete order' : 'Complete orders',
      confirmLabel: 'Continue',
      message,
      extra: { woo_status: 'completed', perform: false },
    })
  }, [flow, label])
}

function useStatusPrompt() {
  const { list } = useStatuses()
  const [state, setState] = useState(null)
  const resolver = useRef(null)
  const ask = useCallback((count = 1) => new Promise((resolve) => {
    resolver.current = resolve
    setState({ value: 'done', open: true, count })
  }), [])
  const finish = (v) => {
    const r = resolver.current
    resolver.current = null
    setState((s) => (s ? { ...s, open: false } : s))
    if (r) r(v)
  }
  const many = state && state.count > 1
  const element = state ? (
    <Dialog
      isOpen={state.open}
      onClose={() => finish(null)}
      isDismissable={false}
      size="sm"
      title={many ? `${state.count} orders leave Completed` : 'The order leaves Completed'}
      description={many ? 'Which status do these CJS orders go back to?' : 'Which status does the CJS order go back to?'}
      footer={
        <>
          <Button variant="tertiary" onPress={() => finish(null)}>Cancel</Button>
          <Btn variant="primary" isDisabled={!state.value} onPress={() => finish(state.value)}>Continue</Btn>
        </>
      }
    >
      <Field label="Statusas">
        <SelectField value={state.value} onChange={(v) => setState((s) => ({ ...s, value: v }))} options={list.map((s) => ({ value: s.key, label: s.label }))} ariaLabel="Statusas" />
      </Field>
    </Dialog>
  ) : null
  return [ask, element]
}

function targetFromLink(a) {
  let url
  try {
    url = new URL(a.href, window.location.href)
  } catch {
    return null
  }
  const action = url.searchParams.get('action')
  if (action !== 'trash' && action !== 'delete') return null
  const id = Number(url.searchParams.get('id') || url.searchParams.get('post') || url.searchParams.get('order_id')) || 0
  return id ? { target: action, ids: [id] } : null
}

function markStatusLink(a) {
  let url
  try {
    url = new URL(a.href, window.location.href)
  } catch {
    return null
  }
  if (url.searchParams.get('action') !== 'woocommerce_mark_order_status') return null
  const id = Number(url.searchParams.get('order_id')) || 0
  const status = wooKey(url.searchParams.get('status'))
  return id && status ? { id, status } : null
}

function moneyValue(v) {
  const dp = (window.woocommerce_admin && window.woocommerce_admin.mon_decimal_point) || '.'
  if (window.accounting && typeof window.accounting.unformat === 'function') return Number(window.accounting.unformat(v, dp)) || 0
  const clean = String(v || '').split(dp).map((p) => p.replace(/[^0-9-]/g, '')).join('.')
  return Number(clean) || 0
}

function priceDecimals() {
  const p = window.woocommerce_admin_meta_boxes
  const n = p ? Number(p.currency_format_num_decimals) : NaN
  return Number.isFinite(n) && n >= 0 ? n : 2
}

async function isFullRefund(orderId, data) {
  const amount = moneyValue(data && data.refund_amount !== undefined ? data.refund_amount : document.getElementById('refund_amount')?.value)
  if (!(amount > 0)) return false
  const refunded = Number(data && data.refunded_amount !== undefined ? data.refunded_amount : document.getElementById('refunded_amount')?.value) || 0
  const order = await get(`orders/${orderId}`)
  const total = Number(order && order.total)
  if (!Number.isFinite(total)) return false
  const dec = priceDecimals()
  const remaining = Number((total - refunded).toFixed(dec))
  return Number((remaining - amount).toFixed(dec)) <= 0
}

const refundGate = { hook: null, pass: false, installed: false }

function requestData(data) {
  if (!data) return null
  if (typeof data === 'string') {
    try {
      return Object.fromEntries(new URLSearchParams(data))
    } catch {
      return null
    }
  }
  return typeof data === 'object' ? data : null
}

function installRefundGate() {
  const jq = window.jQuery
  if (refundGate.installed || !jq || typeof jq.ajaxPrefilter !== 'function') return
  refundGate.installed = true
  jq.ajaxPrefilter((options, original, jqXHR) => {
    if (refundGate.pass) {
      refundGate.pass = false
      return
    }
    const hook = refundGate.hook
    if (!hook) return
    const data = requestData(original && original.data !== undefined ? original.data : options.data)
    if (!data || data.action !== 'woocommerce_refund_line_items') return
    jqXHR.abort()
    hook(original, data)
  })
}

function sendRefund(original) {
  const jq = window.jQuery
  refundGate.pass = true
  try {
    jq.ajax(original)
  } finally {
    refundGate.pass = false
  }
}

function unblockItems() {
  const jq = window.jQuery
  const box = jq ? jq('#woocommerce-order-items') : null
  if (box && typeof box.unblock === 'function') box.unblock()
}

export function WooEditGuard({ orderId }) {
  const flow = useStockFlow()
  const complete = useCompleteFlow()
  const [askStatus, statusDialog] = useStatusPrompt()
  const deps = useLatestRef({ flow, complete, askStatus })
  useEffect(() => {
    if (!orderId) return undefined
    const form = document.getElementById('order') || document.querySelector('form#post')
    const select = document.getElementById('order_status')
    if (!form || !select) return undefined
    const initial = wooKey(select.value)
    let allowed = false
    let running = false
    let refunding = false
    const decide = async (next) => {
      const { flow: f, complete: c, askStatus: ask } = deps.current
      if (NEGATIVE.includes(next) || next === 'trash') {
        return !!(await f.transition({ orderIds: [orderId], target: next, extra: { perform: false } }))
      }
      if (next === 'completed') return !!(await c([orderId]))
      if (initial === 'completed') {
        const key = await ask()
        if (!key) return false
        if (key === 'done') return true
        return !!(await f.transition({ orderIds: [orderId], target: key, extra: { woo_status: next, perform: false } }))
      }
      return true
    }
    const needsModal = (next) => NEGATIVE.includes(next) || next === 'trash' || next === 'completed' || initial === 'completed'
    const onSubmit = async (e) => {
      if (allowed) return
      const next = wooKey(select.value)
      if (next === initial || !next || !needsModal(next)) return
      e.preventDefault()
      e.stopImmediatePropagation()
      if (running) return
      running = true
      const submitter = e.submitter
      let ok = false
      try {
        ok = await decide(next)
      } catch (err) {
        notifyError(err)
      } finally {
        running = false
      }
      if (!ok) return
      allowed = true
      setTimeout(() => {
        if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined)
        else form.submit()
      }, 0)
    }
    const onRefundRequest = async (original, data) => {
      if (refunding) return
      refunding = true
      let ok = false
      let full = false
      let held = null
      try {
        full = await isFullRefund(orderId, data)
        ok = full ? !!(await deps.current.flow.transition({ orderIds: [orderId], target: 'refunded', run: async (d) => { held = d || {}; return { ok: true } } })) : true
      } catch (err) {
        notifyError(err)
      } finally {
        refunding = false
      }
      if (!ok) {
        unblockItems()
        return
      }
      if (!full) {
        sendRefund(original)
        return
      }
      const done = original && original.success
      sendRefund({
        ...original,
        success: async function (response, ...rest) {
          if (response && response.success === true && response.data && response.data.status === 'fully_refunded') {
            try {
              const decisions = (held && (held[orderId] || held[String(orderId)])) || {}
              await post('inventory/transition/apply', { order_id: orderId, target: 'refunded', decisions, perform: false })
              deps.current.flow.refresh()
            } catch (err) {
              notifyError(err)
            }
          }
          if (typeof done === 'function') done.call(this, response, ...rest)
        },
      })
    }
    const onClick = async (e) => {
      const a = e.target && e.target.closest ? e.target.closest('a.submitdelete') : null
      if (!a || allowed) return
      e.preventDefault()
      e.stopImmediatePropagation()
      if (running) return
      running = true
      let ok = false
      try {
        ok = await decide(targetFromLink(a)?.target || 'trash')
      } catch (err) {
        notifyError(err)
      } finally {
        running = false
      }
      if (!ok) return
      allowed = true
      window.location.href = a.href
    }
    form.addEventListener('submit', onSubmit, true)
    document.addEventListener('click', onClick, true)
    installRefundGate()
    refundGate.hook = onRefundRequest
    return () => {
      form.removeEventListener('submit', onSubmit, true)
      document.removeEventListener('click', onClick, true)
      if (refundGate.hook === onRefundRequest) refundGate.hook = null
    }
  }, [orderId, deps])
  return statusDialog
}

export function WooListGuard() {
  const flow = useStockFlow()
  const complete = useCompleteFlow()
  const [askStatus, statusDialog] = useStatusPrompt()
  const deps = useLatestRef({ flow, complete, askStatus })
  useEffect(() => {
    const form = document.getElementById('wc-orders-filter') || document.getElementById('posts-filter')
    let allowed = false
    let running = false
    const needsModal = (ids, target, from) => {
      if (target === 'completed' || target === 'trash' || target === 'delete' || NEGATIVE.includes(target)) return true
      return ids.some((id) => (from ? from(id) : rowStatus(id)) === 'completed')
    }
    const decide = async (ids, target, from) => {
      const { flow: f, complete: c, askStatus: ask } = deps.current
      if (target === 'completed') return !!(await c(ids))
      if (target === 'trash' || target === 'delete' || NEGATIVE.includes(target)) {
        return !!(await f.transition({ orderIds: ids, target, extra: { perform: false } }))
      }
      const back = ids.filter((id) => (from ? from(id) : rowStatus(id)) === 'completed')
      if (!back.length) return true
      const key = await ask(back.length)
      if (!key) return false
      if (key === 'done') return true
      return !!(await f.transition({ orderIds: back, target: key, extra: { woo_status: target, perform: false } }))
    }
    const run = async (ids, target, from, proceed) => {
      if (running) return
      running = true
      let ok = false
      try {
        ok = await decide(ids, target, from)
      } catch (err) {
        notifyError(err)
      } finally {
        running = false
      }
      if (!ok) return
      allowed = true
      setTimeout(proceed, 0)
    }
    const onSubmit = (e) => {
      if (allowed) return
      const submitter = e.submitter
      const top = form.querySelector('#bulk-action-selector-top')
      const bottom = form.querySelector('#bulk-action-selector-bottom')
      let action = ''
      if (submitter && submitter.id === 'doaction2') action = bottom ? bottom.value : ''
      else if (submitter && submitter.id === 'doaction') action = top ? top.value : ''
      else action = top && top.value !== '-1' ? top.value : bottom ? bottom.value : ''
      const target = bulkTarget(action)
      if (!target) return
      const ids = Array.from(form.querySelectorAll('input[name="id[]"]:checked, input[name="post[]"]:checked')).map((i) => Number(i.value)).filter(Boolean)
      if (!ids.length || !needsModal(ids, target)) return
      e.preventDefault()
      e.stopImmediatePropagation()
      run(ids, target, null, () => {
        if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined)
        else form.submit()
      })
    }
    const onClick = (e) => {
      if (allowed) return
      const a = e.target && e.target.closest ? e.target.closest('a') : null
      if (!a) return
      let hit = null
      const mark = markStatusLink(a)
      if (mark) {
        const modal = a.closest('.wc-backbone-modal')
        const shown = modal ? modal.querySelector('mark.order-status') : null
        const m = shown ? /(?:^|\s)status-(?:wc-)?([a-z0-9_-]+)/.exec(shown.className || '') : null
        const fromModal = m ? m[1] : ''
        const from = (id) => rowStatus(id) || fromModal
        if (needsModal([mark.id], mark.status, from)) hit = { target: mark.status, ids: [mark.id], from }
      } else if (form && form.contains(a) && a.closest('.row-actions')) {
        hit = targetFromLink(a)
      }
      if (!hit) return
      e.preventDefault()
      e.stopImmediatePropagation()
      run(hit.ids, hit.target, hit.from || null, () => {
        window.location.href = a.href
      })
    }
    if (form) form.addEventListener('submit', onSubmit, true)
    document.addEventListener('click', onClick, true)
    return () => {
      if (form) form.removeEventListener('submit', onSubmit, true)
      document.removeEventListener('click', onClick, true)
    }
  }, [deps])
  return statusDialog
}
