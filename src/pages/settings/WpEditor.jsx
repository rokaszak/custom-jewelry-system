import { useEffect, useRef } from 'react'
import { TextArea } from '../../components/ui'
import './settings.css'

let seq = 0

export function hasWpEditor() {
  return typeof window !== 'undefined' && !!(window.wp && window.wp.editor && typeof window.wp.editor.initialize === 'function')
}

function readContent(id, textarea) {
  const ed = window.tinymce && window.tinymce.get ? window.tinymce.get(id) : null
  if (ed && !ed.isHidden()) return ed.getContent()
  const raw = textarea.value || ''
  const autop = window.wp && window.wp.editor && window.wp.editor.autop
  return typeof autop === 'function' ? autop(raw) : raw
}

export default function WpEditor({ value, onChange, rows = 10, ariaLabel }) {
  const host = useRef(null)
  const latest = useRef(onChange)
  latest.current = onChange
  const initial = useRef(value)
  const available = hasWpEditor()

  useEffect(() => {
    const el = host.current
    if (!available || !el) return undefined
    seq += 1
    const id = `cjswpeditor${seq}`
    const textarea = document.createElement('textarea')
    textarea.id = id
    textarea.rows = rows
    textarea.className = 'wp-editor-area'
    if (ariaLabel) textarea.setAttribute('aria-label', ariaLabel)
    textarea.value = initial.current || ''
    el.appendChild(textarea)
    let last = null
    const emit = () => {
      let next
      try {
        next = readContent(id, textarea)
      } catch {
        return
      }
      if (next === last) return
      last = next
      if (latest.current) latest.current(next)
    }
    const later = () => setTimeout(emit, 0)
    try {
      window.wp.editor.initialize(id, {
        tinymce: {
          toolbar1: 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
          toolbar2: '',
          wpautop: true,
          height: rows * 22,
          setup: (ed) => {
            ed.on('change keyup input undo redo SetContent ExecCommand', emit)
            ed.on('blur', emit)
          },
        },
        quicktags: true,
        mediaButtons: false,
      })
    } catch {}
    textarea.addEventListener('input', emit)
    textarea.addEventListener('change', emit)
    textarea.addEventListener('blur', emit)
    el.addEventListener('click', later)
    return () => {
      el.removeEventListener('click', later)
      textarea.removeEventListener('input', emit)
      textarea.removeEventListener('change', emit)
      textarea.removeEventListener('blur', emit)
      try {
        window.wp.editor.remove(id)
      } catch {}
      el.innerHTML = ''
    }
  }, [available])

  if (!available) {
    return <TextArea rows={rows} autoGrow value={value} onChange={onChange} aria-label={ariaLabel} />
  }
  return <div ref={host} className="cjs-wpeditor" />
}
