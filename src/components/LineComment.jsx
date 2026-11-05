import { useState } from 'react'
import { post } from '../lib/api'
import { notifyError } from '../lib/hooks'
import { Btn, Dialog, TextArea } from './ui'

export function saveDemandNote(id, note) {
  return post(`demands/${id}/note`, { note: note.trim() })
}

export function CommentText({ text }) {
  if (!text) return null
  return <span className="min-w-0 whitespace-pre-line break-words text-[13px] leading-snug text-foreground">{text}</span>
}

export function CommentDialog({ title, value, onClose, onSave }) {
  const [text, setText] = useState(value || '')
  const [saving, setSaving] = useState(false)
  const save = async () => {
    setSaving(true)
    try {
      await onSave(text.trim())
      onClose()
    } catch (e) {
      notifyError(e)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Dialog
      isOpen
      size="sm"
      onClose={onClose}
      title={title}
      footer={
        <>
          <Btn variant="tertiary" onPress={onClose}>Cancel</Btn>
          <Btn variant="primary" isPending={saving} onPress={save}>{text.trim() || !value ? 'Save' : 'Remove comment'}</Btn>
        </>
      }
    >
      <TextArea rows={3} autoGrow autoFocus value={text} onChange={setText} aria-label="Comment" />
    </Dialog>
  )
}
