import { useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Button, ProgressBar, Modal } from '@heroui/react'
import { ArrowUpFromLine, ArrowDownToLine, TrashBin, File, FileText, Picture, Cube, Archive, Plus, Minus } from '@gravity-ui/icons'
import { get, del, uploadWithProgress } from '../../lib/api'
import { notifyError } from '../../lib/hooks'
import { bytes, dateTime } from '../../lib/format'
import { Btn, Expand, Field, TextInput, TextArea, useConfirm, IconButton, SkBox, SkLine, skw, cx } from '../../components/ui'

const ICONS = { image: Picture, pdf: FileText, model: Cube, archive: Archive, file: File, video: File }

function Lightbox({ src, onClose }) {
  const [zoom, setZoom] = useState(1)
  useEffect(() => setZoom(1), [src])
  return (
    <Modal.Backdrop isOpen={!!src} onOpenChange={(o) => !o && onClose()} isDismissable>
      <Modal.Container size="full">
        <Modal.Dialog className="bg-black/90">
          <Modal.CloseTrigger className="text-white" />
          <Modal.Body className="flex items-center justify-center overflow-auto p-4" onWheel={(e) => setZoom((z) => Math.min(5, Math.max(0.5, z - e.deltaY * 0.0015)))}>
            {src && <img src={src} alt="" style={{ transform: `scale(${zoom})`, transition: 'transform .12s ease-out' }} className="max-h-[85vh] max-w-full object-contain" />}
          </Modal.Body>
          <Modal.Footer className="justify-center gap-2">
            <Button size="sm" variant="secondary" isIconOnly aria-label="Zoom out" onPress={() => setZoom((z) => Math.max(0.5, z - 0.25))}><Minus className="size-4" /></Button>
            <Button size="sm" variant="secondary" onPress={() => setZoom(1)}>{Math.round(zoom * 100)}%</Button>
            <Button size="sm" variant="secondary" isIconOnly aria-label="Zoom in" onPress={() => setZoom((z) => Math.min(5, z + 0.25))}><Plus className="size-4" /></Button>
          </Modal.Footer>
        </Modal.Dialog>
      </Modal.Container>
    </Modal.Backdrop>
  )
}

function FileThumb({ file, onPreview }) {
  const [broken, setBroken] = useState(false)
  const Icon = ICONS[file.kind] || File
  if (!file.thumb_url || broken) {
    return (
      <div className="flex size-12 shrink-0 items-center justify-center bg-surface-secondary text-muted">
        <Icon className="size-5" />
      </div>
    )
  }
  return (
    <button type="button" className="size-12 shrink-0 cursor-zoom-in overflow-hidden bg-surface-secondary" onClick={() => onPreview(file.thumb_url)}>
      <img src={file.thumb_url} alt="" className="size-full object-cover" loading="lazy" onError={() => setBroken(true)} />
    </button>
  )
}

function uploadHint(limits) {
  if (!limits) return ''
  const parts = []
  if (limits.effective_label) parts.push(`Max ${limits.effective_label}`)
  const types = (limits.types || []).map((t) => `.${t}`).join(' ')
  if (types) parts.push(`${types} and more`)
  return parts.join(' · ')
}

export default function FilesBox({ orderId, onChanged }) {
  const qc = useQueryClient()
  const confirm = useConfirm()
  const q = useQuery({ queryKey: ['files', orderId], queryFn: () => get(`orders/${orderId}/files`) })
  const [file, setFile] = useState(null)
  const [thumb, setThumb] = useState(null)
  const [name, setName] = useState('')
  const [comment, setComment] = useState('')
  const [progress, setProgress] = useState(null)
  const [drag, setDrag] = useState(false)
  const [preview, setPreview] = useState(null)
  const input = useRef(null)
  const thumbInput = useRef(null)
  const lastFile = useRef(null)
  if (file) lastFile.current = file
  const shown = file || lastFile.current
  const limits = q.data?.limits
  const limit = limits?.effective || 0

  const pick = (f) => {
    if (!f) return
    const ext = (f.name.split('.').pop() || '').toLowerCase()
    if (f.name.includes('.') && (limits?.blocked || []).includes(ext)) {
      notifyError(new Error(`.${ext} files cannot be uploaded.`))
      return
    }
    if (limit && f.size > limit) {
      notifyError(new Error(`File is too large. Maximum is ${limits.effective_label}, this file is ${bytes(f.size)}.`))
      return
    }
    setFile(f)
  }
  const reset = () => {
    setFile(null)
    setThumb(null)
    setName('')
    setComment('')
    if (input.current) input.current.value = ''
    if (thumbInput.current) thumbInput.current.value = ''
  }
  const upload = async () => {
    if (!file) return
    const form = new FormData()
    form.append('file', file)
    if (thumb) form.append('thumbnail', thumb)
    form.append('file_name', name)
    form.append('comment', comment)
    setProgress(0)
    try {
      const res = await uploadWithProgress(`orders/${orderId}/files`, form, setProgress)
      qc.setQueryData(['files', orderId], res)
      reset()
      if (onChanged) onChanged()
    } catch (e) {
      notifyError(e)
    } finally {
      setProgress(null)
    }
  }
  const remove = async (f) => {
    const ok = await confirm({ title: 'Delete file?', message: `“${f.name}” will be removed permanently.`, confirmLabel: 'Delete', tone: 'danger' })
    if (!ok) return
    try {
      const res = await del(`files/${f.id}`)
      qc.setQueryData(['files', orderId], res)
      if (onChanged) onChanged()
    } catch (e) {
      notifyError(e)
    }
  }
  const files = q.data?.files || []
  const dropProps = {
    onDragEnter: (e) => {
      e.preventDefault()
      setDrag(true)
    },
    onDragOver: (e) => {
      e.preventDefault()
      e.dataTransfer.dropEffect = 'copy'
      if (!drag) setDrag(true)
    },
    onDragLeave: (e) => {
      if (!e.currentTarget.contains(e.relatedTarget)) setDrag(false)
    },
    onDrop: (e) => {
      e.preventDefault()
      setDrag(false)
      pick(e.dataTransfer.files?.[0])
    },
  }
  return (
    <div className="flex flex-col gap-3">
      {q.isLoading ? (
        <div className="grid grid-cols-[repeat(auto-fill,minmax(min(240px,100%),1fr))] gap-2" aria-hidden="true">
          {[0, 1].map((i) => (
            <div key={i} className="flex min-w-0 items-center gap-3 bg-surface p-2.5 ring-1 ring-separator">
              <SkBox w={48} h={48} />
              <div className="min-w-0 flex-1">
                <SkLine size={13} w={skw(i, 70)} />
                <SkLine size={11} w={skw(i + 2, 45)} />
              </div>
            </div>
          ))}
        </div>
      ) : files.length === 0 ? null : (
        <ul className="grid grid-cols-[repeat(auto-fill,minmax(min(240px,100%),1fr))] gap-2">
          {files.map((f) => (
            <li key={f.id} className="flex min-w-0 items-center gap-3 bg-surface p-2.5 ring-1 ring-separator">
              <FileThumb key={f.thumb_url || 'none'} file={f} onPreview={setPreview} />
              <div className="min-w-0 flex-1">
                <div className="truncate text-[13px] font-medium" title={f.name}>{f.name}</div>
                {f.comment && <div className="truncate text-[12px] text-muted">{f.comment}</div>}
                <div className="truncate text-[11px] text-muted">{bytes(f.size)} · {dateTime(f.uploaded_at)}</div>
              </div>
              <a href={f.url} target="_blank" rel="noopener" className="flex size-8 shrink-0 items-center justify-center hover:bg-surface-secondary" aria-label="Download">
                <ArrowDownToLine className="size-4" />
              </a>
              <IconButton icon={TrashBin} label="Delete file" className="shrink-0" onPress={() => remove(f)} />
            </li>
          ))}
        </ul>
      )}
      <div
        className={cx('border-2 border-dashed transition-colors duration-200', drag ? 'border-accent bg-accent-soft' : file ? 'border-separator' : 'border-separator hover:border-accent/60 hover:bg-surface-secondary/60')}
        {...dropProps}
      >
        <input ref={input} type="file" className="hidden" onChange={(e) => pick(e.target.files?.[0])} />
        <input ref={thumbInput} type="file" accept="image/*" className="hidden" onChange={(e) => setThumb(e.target.files?.[0] || null)} />
        <Expand open={!file}>
          <button type="button" className={cx('flex w-full cursor-pointer flex-col items-center gap-1 px-3 py-3 text-[13px] text-muted', drag && 'text-accent')} onClick={() => input.current?.click()}>
            <ArrowUpFromLine className={cx('size-5 transition-transform duration-200', drag && '-translate-y-0.5')} />
            {drag ? (
              <span className="font-medium text-accent">Drop the file to add it</span>
            ) : files.length === 0 && !q.isLoading ? (
              <span>No files yet, <span className="font-medium text-accent">choose a file</span> or drop it here</span>
            ) : (
              <span><span className="font-medium text-accent">Choose a file</span> or drop it here</span>
            )}
            {limits && <span className="text-[11.5px]">{uploadHint(limits)}</span>}
          </button>
        </Expand>
        <Expand open={!!file}>
          {shown && (
            <div className="flex flex-col gap-2.5 p-3">
              <div className="flex items-center justify-between gap-2 text-[13px]">
                <span className="truncate font-medium">{shown.name} <span className="font-normal text-muted">({bytes(shown.size)})</span></span>
                <Button size="sm" variant="tertiary" isDisabled={progress !== null} onPress={reset}>Cancel</Button>
              </div>
              <div className="grid gap-2 sm:grid-cols-2">
                <Field label="Name">
                  <TextInput value={name} onChange={setName} placeholder={shown.name} size="sm" />
                </Field>
                <Field label="Thumbnail">
                  <Button size="sm" variant="secondary" className="justify-start" onPress={() => thumbInput.current?.click()}>
                    <Picture className="size-4" />
                    <span className="truncate">{thumb ? thumb.name : 'Choose image'}</span>
                  </Button>
                </Field>
              </div>
              <Field label="Comment">
                <TextArea rows={2} value={comment} onChange={setComment} />
              </Field>
              {progress !== null && (
                <ProgressBar aria-label="Upload progress" value={progress}>
                  <ProgressBar.Track>
                    <ProgressBar.Fill />
                  </ProgressBar.Track>
                </ProgressBar>
              )}
              <div className="flex justify-end">
                <Btn size="sm" variant="primary" icon={ArrowUpFromLine} isPending={progress !== null} onPress={upload}>
                  {progress !== null && progress < 100 ? `${progress}%` : 'Upload'}
                </Btn>
              </div>
            </div>
          )}
        </Expand>
      </div>
      <Lightbox src={preview} onClose={() => setPreview(null)} />
    </div>
  )
}
