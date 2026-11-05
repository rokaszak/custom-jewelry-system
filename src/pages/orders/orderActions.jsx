import { createContext, useCallback, useContext, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Button } from '@heroui/react'
import { toast } from '../../lib/toast'
import { post, get } from '../../lib/api'
import { notifyError } from '../../lib/hooks'
import { addDays } from '../../lib/format'
import { readDraft, saveDraft, clearDraft } from '../../lib/draft'
import { Btn, Check, DateInput, Dialog, useConfirm } from '../../components/ui'
import { useStatuses, useStockFlow, previewTransition } from '../../components/StockModal'

const Ctx = createContext(null)

export const DATE_FIELDS = {
  manufacture_by_date: 'Pagaminti iki',
  finish_by_date: 'Užprabuoti iki',
  deliver_by_date: 'Pristatyti iki',
}

const OFFSETS = {
  manufacture_by_date: { finish_by_date: 7, deliver_by_date: 14 },
  finish_by_date: { manufacture_by_date: -7, deliver_by_date: 7 },
  deliver_by_date: { manufacture_by_date: -14, finish_by_date: -7 },
}

export function patchOrderCaches(qc, order) {
  if (!order) return
  qc.setQueriesData({ queryKey: ['orders'] }, (old) => {
    if (!old || !old.orders) return old
    return { ...old, orders: old.orders.map((o) => (o.id === order.id ? { ...o, ...order } : o)) }
  })
  qc.setQueriesData({ queryKey: ['order', order.id] }, (old) => (old ? { ...old, ...order } : old))
}

export const LOCK_TEXT = 'This order is Completed in WooCommerce, so it cannot be changed here. Change its WooCommerce status first.'

export class FlowCancelled extends Error {
  constructor() {
    super('Cancelled')
    this.code = 'cjs_cancelled'
  }
}

function recalcKey(r) {
  return r ? `recalc:${r.order.id}:${r.field}:${r.value}` : ''
}

export function OrderActionsProvider({ children, onCompleted }) {
  const qc = useQueryClient()
  const confirm = useConfirm()
  const flow = useStockFlow()
  const { label: statusLabel } = useStatuses()
  const [recalc, setRecalc] = useState(null)
  const [saving, setSaving] = useState(false)

  const refreshOrder = useCallback(async (orderId) => {
    qc.invalidateQueries({ queryKey: ['order', orderId] })
    try {
      const row = await get(`orders/${orderId}/summary`)
      patchOrderCaches(qc, row)
      return row
    } catch {
      return null
    }
  }, [qc])

  const saveField = useCallback(async (orderId, field, value, { silent } = {}) => {
    try {
      const res = await post(`orders/${orderId}/field`, { field, value })
      patchOrderCaches(qc, res.order)
      if (res.auto_advanced) toast.info(`Statusas pakeistas į „${statusLabel('pagaminta')}“: visos valandos atliktos.`)
      return res.order
    } catch (e) {
      if (e && e.code === 'cjs_stock_conflicts' && e.data && e.data.preview) {
        const fromHours = !!e.data.from_hours
        if (e.data.order) patchOrderCaches(qc, e.data.order)
        let done = null
        try {
          done = await flow.resolve(e.data.preview, { orderIds: [orderId], message: fromHours ? e.data.message || e.message : undefined })
        } catch (err) {
          if (!silent) notifyError(err)
        }
        if (done) return (await refreshOrder(orderId)) || e.data.order || null
        if (fromHours) return e.data.order || null
        throw new FlowCancelled()
      }
      if (!silent) notifyError(e)
      throw e
    }
  }, [qc, flow, refreshOrder, statusLabel])

  const changeDate = useCallback(async (order, field, value) => {
    const updated = await saveField(order.id, field, value)
    if (value) {
      const base = { order: updated || order, field, value }
      const draft = readDraft(recalcKey(base))
      const rows = Array.isArray(draft?.rows) ? draft.rows : Object.entries(OFFSETS[field]).map(([f, offset]) => ({ field: f, value: addDays(value, offset), checked: true }))
      setRecalc({ ...base, rows })
    }
    return updated
  }, [saveField])

  const closeRecalcKeep = () => {
    if (recalc) saveDraft(recalcKey(recalc), { rows: recalc.rows })
    setRecalc(null)
  }

  const closeRecalcDiscard = () => {
    if (recalc) clearDraft(recalcKey(recalc))
    setRecalc(null)
  }

  const applyRecalc = async () => {
    if (!recalc) return
    setSaving(true)
    try {
      const fields = Object.fromEntries(recalc.rows.filter((r) => r.checked && r.value).map((r) => [r.field, r.value]))
      if (Object.keys(fields).length) {
        const res = await post(`orders/${recalc.order.id}/fields`, { fields })
        patchOrderCaches(qc, res.order)
      }
      clearDraft(recalcKey(recalc))
      setRecalc(null)
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }

  const completeOrder = useCallback(async (order, { fromStatus } = {}) => {
    let preview = null
    try {
      preview = await previewTransition({ order_id: order.id, target: 'done' })
    } catch (e) {
      notifyError(e)
      return false
    }
    const head = (preview.orders || [])[0]
    const notDone = !!head && head.cjs_status !== 'done' && head.wc_status !== 'completed'
    if (notDone) {
      try {
        const res = await flow.resolve(preview, {
          orderIds: [order.id],
          target: 'done',
          force: true,
          title: 'Complete order',
          confirmLabel: 'Complete order',
          message: `The production status is ${statusLabel(head.cjs_status)}, not ${statusLabel('done')}. Completing the order sets it to ${statusLabel('done')}.`,
          run: async (decisions) => {
            await post(`orders/${order.id}/complete`, { confirmed: true, decisions: decisions[order.id] || {} })
            return { ok: true }
          },
        })
        if (!res) return false
        flow.refresh()
        toast.success('Užsakymas užbaigtas.')
        if (onCompleted) onCompleted(order)
        return true
      } catch (e) {
        notifyError(e)
        return false
      }
    }
    if (head && head.needs_decision && head.wc_status !== 'completed') {
      try {
        const res = await flow.resolve(preview, { orderIds: [order.id], target: 'done', title: 'Complete order' })
        if (!res) return false
      } catch (e) {
        notifyError(e)
        return false
      }
    }
    const ok = await confirm({
      title: 'Užbaigti užsakymą?',
      tone: 'accent',
      confirmLabel: 'Taip, užbaigti',
      cancelLabel: 'Ne',
      message: (
        <div className="flex flex-col gap-3 text-[13.5px] text-muted">
          <p>{fromStatus ? 'Gamybos statusas nustatytas kaip DONE. ' : ''}Ar nustatyti WooCommerce užsakymo statusą į „Completed“? Užsakymas dings iš šio sąrašo, o klientui bus išsiųstas įvykdyto užsakymo el. laiškas.</p>
        </div>
      ),
    })
    if (!ok) return false
    try {
      await post(`orders/${order.id}/complete`)
      toast.success('Užsakymas užbaigtas.')
      qc.invalidateQueries({ queryKey: ['orders'] })
      qc.invalidateQueries({ queryKey: ['bootstrap'] })
      if (onCompleted) onCompleted(order)
      return true
    } catch (e) {
      notifyError(e)
      return false
    }
  }, [confirm, qc, onCompleted, flow, statusLabel])

  return (
    <Ctx.Provider value={{ saveField, changeDate, completeOrder, refreshOrder }}>
      {children}
      <Dialog
        isOpen={!!recalc}
        onClose={closeRecalcKeep}
        isDismissable={false}
        size="sm"
        title="Atnaujinti kitas datas?"
        description="Pakeitus datą, kitos dvi datos gali būti perskaičiuotos pagal standartinius tarpus. Pažymėtos datos bus išsaugotos."
        footer={
          <>
            <Button variant="tertiary" onPress={closeRecalcDiscard}>Atšaukti</Button>
            <Btn variant="primary" isPending={saving} onPress={applyRecalc}>Išsaugoti</Btn>
          </>
        }
      >
        <div className="flex flex-col gap-2.5">
          {recalc?.rows.map((r, i) => (
            <div key={r.field} className="flex items-center justify-between gap-3 bg-surface-secondary px-3 py-2">
              <Check checked={r.checked} onChange={(v) => setRecalc((s) => ({ ...s, rows: s.rows.map((x, j) => (j === i ? { ...x, checked: v } : x)) }))}>
                <span className="text-[13px] font-medium">{DATE_FIELDS[r.field]}</span>
              </Check>
              <DateInput size="sm" className="w-40" aria-label={DATE_FIELDS[r.field]} value={r.value} onChange={(v) => setRecalc((s) => ({ ...s, rows: s.rows.map((x, j) => (j === i ? { ...x, value: v } : x)) }))} />
            </div>
          ))}
        </div>
      </Dialog>
    </Ctx.Provider>
  )
}

export function useOrderActions() {
  return useContext(Ctx)
}
