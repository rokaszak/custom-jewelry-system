import { useEffect, useState } from 'react'
import { DndContext, KeyboardSensor, PointerSensor, TouchSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core'
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { Grip } from '@gravity-ui/icons'
import { cx } from './ui'

function Row({ id, children, className }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id })
  const style = { transform: CSS.Transform.toString(transform), transition }
  const handle = (
    <button type="button" className="flex size-7 shrink-0 cursor-grab touch-none items-center justify-center text-muted hover:bg-surface-secondary active:cursor-grabbing" aria-label="Drag to reorder" {...attributes} {...listeners}>
      <Grip className="size-4" />
    </button>
  )
  return (
    <li ref={setNodeRef} style={style} className={cx('relative', isDragging && 'z-10 opacity-90 shadow-lg', className)}>
      {children(handle, isDragging)}
    </li>
  )
}

export default function SortableList({ items, getId = (x) => x.id, onReorder, renderItem, className, itemClassName }) {
  const [order, setOrder] = useState(items)
  useEffect(() => setOrder(items), [items])
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 150, tolerance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const ids = order.map((x) => String(getId(x)))
  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={({ active, over }) => {
        if (!over || active.id === over.id) return
        const next = arrayMove(order, ids.indexOf(String(active.id)), ids.indexOf(String(over.id)))
        setOrder(next)
        onReorder(next)
      }}
    >
      <SortableContext items={ids} strategy={verticalListSortingStrategy}>
        <ul className={className}>
          {order.map((item) => (
            <Row key={String(getId(item))} id={String(getId(item))} className={itemClassName}>
              {(handle, dragging) => renderItem(item, handle, dragging)}
            </Row>
          ))}
        </ul>
      </SortableContext>
    </DndContext>
  )
}
