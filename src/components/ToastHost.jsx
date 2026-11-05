import { useEffect, useState, useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { ChevronDown, CircleCheck, CircleInfo, CircleXmark, TriangleExclamation, Xmark } from '@gravity-ui/icons'
import { dismissToast, getToasts, pinToast, subscribe } from '../lib/toast'

const ICONS = { success: CircleCheck, danger: CircleXmark, warning: TriangleExclamation, info: CircleInfo }

function ToastCard({ t }) {
  const [open, setOpen] = useState(false)
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (t.pinned || t.leaving) return
    const timer = setInterval(() => setNow(Date.now()), 200)
    return () => clearInterval(timer)
  }, [t.pinned, t.leaving])
  const left = Math.max(1, Math.ceil((t.until - now) / 1000))
  const Icon = ICONS[t.tone] || CircleInfo
  return (
    <div role={t.tone === 'danger' ? 'alert' : 'status'} className={`cjs-toast is-${t.tone}${t.leaving ? ' is-leaving' : ''}`}>
      <div className="cjs-toast-head">
        <Icon className="cjs-toast-icon" aria-hidden="true" />
        <div className="cjs-toast-title">{t.title}</div>
        {t.description && (
          <button type="button" className={`cjs-toast-btn${open ? ' is-open' : ''}`} aria-label={open ? 'Hide details' : 'Show details'} aria-expanded={open} onClick={() => setOpen((v) => !v)}>
            <ChevronDown aria-hidden="true" />
          </button>
        )}
        <button type="button" className="cjs-toast-btn" aria-label="Close" onClick={() => dismissToast(t.id)}>
          <Xmark aria-hidden="true" />
        </button>
      </div>
      {open && t.description && <div className="cjs-toast-desc">{t.description}</div>}
      {!t.pinned && (
        <button type="button" className="cjs-toast-foot" onClick={() => pinToast(t.id)}>
          This message will close in <b>{left}</b> {left === 1 ? 'second' : 'seconds'}. <strong>Click to stop.</strong>
          <span className="cjs-toast-bar" style={{ animationDuration: `${t.duration}ms` }} aria-hidden="true" />
        </button>
      )}
    </div>
  )
}

export default function ToastHost({ container }) {
  const list = useSyncExternalStore(subscribe, getToasts)
  if (!list.length) return null
  const node = (
    <div className="cjs-toasts" aria-live="polite">
      {[...list].reverse().map((t) => (
        <ToastCard key={t.id} t={t} />
      ))}
    </div>
  )
  const target = typeof container === 'function' ? container() : container
  return target ? createPortal(node, target) : node
}
