import { use, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useInfiniteQuery, useQuery, keepPreviousData } from '@tanstack/react-query'
import { ComboBox, Header, Input, ListBox, Spinner } from '@heroui/react'
import { ComboBoxStateContext } from 'react-aria-components/ComboBox'
import { ChevronDown, ChevronLeft, ChevronRight, Plus, Xmark } from '@gravity-ui/icons'
import { get, itemSuggestions } from '../lib/api'
import { useCatalog, useDebounced } from '../lib/hooks'
import { PRABA_REF, grams3, qty } from '../lib/format'
import ItemDialog from './ItemDialog'
import { NoBubble, SkBox, SkLine, cx, skw } from './ui'
import { CategoryIcon } from './icons'

export function stockTone(it) {
  const free = Number(it?.free || 0)
  if (free <= 0) return 'text-muted'
  const reorder = it.reorder_level === null || it.reorder_level === undefined ? null : Number(it.reorder_level)
  const low = it.low !== undefined ? !!it.low : reorder !== null && free <= reorder
  return low ? 'text-warning-strong' : 'text-success-strong'
}

export function stockText(it) {
  const free = Number(it?.free || 0)
  if (free > 0) return `${qty(free, it.unit)} in stock`
  return 'None in stock'
}

function PickerInput({ openOnFocus, ...props }) {
  const state = use(ComboBoxStateContext)
  const open = () => {
    if (state && !state.isOpen) state.open(null, 'manual')
  }
  return <Input {...props} onClick={open} onChange={open} onFocus={openOnFocus ? open : undefined} />
}

function withMetalRows(items, categoryId) {
  const out = []
  const seen = new Set()
  for (const it of items) {
    if (it.metal) {
      const key = String(it.metal).toLowerCase()
      if (!seen.has(key)) {
        seen.add(key)
        out.push({ id: `m:${categoryId}:${it.metal}`, metalRow: true, name: it.metal, metal: it.metal, category_id: categoryId, unit: it.unit, unit_id: it.unit_id, per_piece: false, metal_free: it.metal_free })
      }
    }
  }
  return [...out, ...items]
}

function ItemRow({ it, categoryId }) {
  if (it.metalRow) {
    const mf = Number(it.metal_free || 0)
    return (
      <div className="flex min-w-0 flex-1 items-start gap-2">
        <span className="flex h-[22px] w-4 shrink-0 items-center text-muted"><CategoryIcon categoryId={categoryId ?? it.category_id} className="size-4" /></span>
        <div className="flex min-w-0 flex-1 flex-col gap-0.5 py-0.5">
          <div className="break-words text-[13px] font-semibold leading-snug">{it.name}</div>
          <div className="text-[11.5px] leading-snug">
            <span className={`tabular-nums ${mf > 0 ? 'text-success-strong' : 'text-muted'}`}>{mf > 0 ? `${grams3(mf, it.unit)} at ${PRABA_REF} in stock` : 'None in stock'}</span>
            <span className="text-muted"> · Enter praba and weight</span>
          </div>
        </div>
      </div>
    )
  }
  return (
    <div className="flex min-w-0 flex-1 items-start gap-2">
      <span className="flex h-[22px] w-4 shrink-0 items-center text-muted"><CategoryIcon categoryId={categoryId ?? it.category_id} className="size-4" /></span>
      <div className="flex min-w-0 flex-1 flex-col gap-0.5 py-0.5">
        <div className="break-words text-[13px] font-medium leading-snug">{it.name}</div>
        {(it.code || it.free !== undefined) && (
          <div className="flex flex-wrap items-center gap-x-2 text-[11.5px] leading-snug">
            {it.free !== undefined && <span className={`tabular-nums ${stockTone(it)}`}>{stockText(it)}</span>}
            {it.code && <span className="text-muted">{it.code}</span>}
          </div>
        )}
      </div>
    </div>
  )
}

function SkeletonItems() {
  return [0, 1, 2, 3, 4].map((n) => (
    <ListBox.Item key={`__sk${n}`} id={`__sk${n}`} textValue="" isDisabled>
      <div className="flex min-w-0 flex-1 items-start gap-2">
        <SkBox w={16} h={16} className="mt-[3px]" />
        <div className="flex min-w-0 flex-1 flex-col gap-0.5 py-0.5">
          <SkLine size={13} lh={1.375} w={skw(n, 62)} />
          <SkLine size={11.5} lh={1.375} w={skw(n + 3, 22)} />
        </div>
      </div>
    </ListBox.Item>
  ))
}

function SectionHeader({ categoryId, children, aside }) {
  return (
    <Header className="flex items-baseline justify-between gap-2 px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted">
      <span className="flex min-w-0 items-center gap-1.5">{categoryId ? <CategoryIcon categoryId={categoryId} className="size-3.5 shrink-0" /> : null}<span className="truncate">{children}</span></span>
      {aside ? <span className="shrink-0 font-normal normal-case tracking-normal">{aside}</span> : null}
    </Header>
  )
}
const PAGE = 50

function FilterChip({ active, onClick, children, title, muted }) {
  return (
    <button
      type="button"
      title={title}
      aria-pressed={!!active}
      onClick={onClick}
      className={cx(
        'inline-flex h-7 max-w-[240px] shrink-0 cursor-pointer items-center gap-1 whitespace-nowrap border px-2.5 text-[12.5px] transition-colors',
        active ? 'border-accent bg-accent-soft text-accent-soft-foreground' : muted ? 'border-separator bg-surface-secondary text-foreground hover:bg-default' : 'border-separator bg-surface text-foreground hover:bg-surface-secondary',
      )}
    >
      {children}
    </button>
  )
}

function keepFocus(e) {
  if (e.target.closest('input')) return
  e.preventDefault()
}

function SlideArrow({ side, onClick }) {
  const Icon = side === 'left' ? ChevronLeft : ChevronRight
  return (
    <div className={cx('pointer-events-none absolute inset-y-0 z-10 flex items-center', side === 'left' ? 'left-0 bg-linear-to-r from-overlay from-60% to-transparent pl-2 pr-5' : 'right-0 bg-linear-to-l from-overlay from-60% to-transparent pl-5 pr-2')}>
      <button type="button" aria-label={side === 'left' ? 'Scroll left' : 'Scroll right'} className="pointer-events-auto flex size-7 cursor-pointer items-center justify-center border border-separator bg-surface text-foreground hover:bg-surface-secondary" onClick={onClick}>
        <Icon className="size-3.5" />
      </button>
    </div>
  )
}

const SLIDE_PAD = 40

function ChipSlider({ children, className, watch }) {
  const ref = useRef(null)
  const drag = useRef(null)
  const target = useRef(null)
  const slideRef = useRef(null)
  const [edges, setEdges] = useState({ left: false, right: false })
  const update = useCallback(() => {
    const el = ref.current
    if (!el) return
    const left = el.scrollLeft > 2
    const right = el.scrollLeft + el.clientWidth < el.scrollWidth - 2
    setEdges((e) => (e.left === left && e.right === right ? e : { left, right }))
  }, [])
  useEffect(() => {
    const el = ref.current
    if (!el) return
    let acc = 0
    let last = 0
    const wheel = (e) => {
      if (el.scrollWidth <= el.clientWidth || Math.abs(e.deltaX) >= Math.abs(e.deltaY)) return
      e.preventDefault()
      const now = Date.now()
      if (now - last > 250) acc = 0
      last = now
      acc += e.deltaY * (e.deltaMode === 1 ? 16 : 1)
      if (Math.abs(acc) >= 40) {
        if (slideRef.current) slideRef.current(acc > 0 ? 1 : -1)
        acc = 0
      }
    }
    el.addEventListener('wheel', wheel, { passive: false })
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(update) : null
    if (ro) ro.observe(el)
    return () => {
      el.removeEventListener('wheel', wheel)
      if (ro) ro.disconnect()
    }
  }, [update])
  useEffect(() => {
    update()
  })
  useEffect(() => {
    const el = ref.current
    const on = el?.querySelector('[aria-pressed="true"]')
    if (el && on) el.scrollLeft = Math.max(0, on.offsetLeft - el.clientWidth / 2 + on.offsetWidth / 2)
    update()
  }, [watch, update])
  const scrollTo = (left) => {
    const el = ref.current
    if (!el) return
    const max = Math.max(0, el.scrollWidth - el.clientWidth)
    const to = Math.min(max, Math.max(0, left))
    target.current = to
    el.scrollTo({ left: to, behavior: 'smooth' })
  }
  const stops = () => {
    const el = ref.current
    return el ? [...el.children].map((c, i) => (i === 0 ? 0 : c.offsetLeft - SLIDE_PAD)) : []
  }
  const slide = (dir) => {
    const el = ref.current
    if (!el) return
    const base = target.current ?? el.scrollLeft
    const list = stops()
    const next = dir > 0 ? list.find((x) => x > base + 1) : [...list].reverse().find((x) => x < base - 1)
    scrollTo(next ?? (dir > 0 ? el.scrollWidth : 0))
  }
  slideRef.current = slide
  const onScrolled = () => {
    const el = ref.current
    if (el && target.current !== null && Math.abs(el.scrollLeft - target.current) < 2) target.current = null
    update()
  }
  const onPointerDown = (e) => {
    if (e.pointerType !== 'mouse' || e.button !== 0) return
    drag.current = { x: e.clientX, left: ref.current.scrollLeft, moved: false, id: e.pointerId }
  }
  const onPointerMove = (e) => {
    const d = drag.current
    const el = ref.current
    if (!d || !el) return
    const dx = e.clientX - d.x
    if (!d.moved && Math.abs(dx) > 5) {
      d.moved = true
      target.current = null
      el.setPointerCapture(d.id)
    }
    if (d.moved) el.scrollLeft = d.left - dx
  }
  const endDrag = () => {
    const d = drag.current
    const el = ref.current
    drag.current = null
    if (!d || !el || !d.moved) return
    const list = stops()
    if (list.length) scrollTo(list.reduce((best, x) => (Math.abs(x - el.scrollLeft) < Math.abs(best - el.scrollLeft) ? x : best), list[0]))
    const block = (ev) => {
      ev.stopPropagation()
      ev.preventDefault()
    }
    el.addEventListener('click', block, { capture: true, once: true })
    setTimeout(() => el.removeEventListener('click', block, { capture: true }), 0)
  }
  return (
    <div className={cx('relative min-w-0', className)}>
      <div
        ref={ref}
        className="relative flex gap-1.5 overflow-x-auto px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        onScroll={onScrolled}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={endDrag}
        onPointerCancel={endDrag}
      >
        {children}
      </div>
      {edges.left && <SlideArrow side="left" onClick={() => slide(-1)} />}
      {edges.right && <SlideArrow side="right" onClick={() => slide(1)} />}
    </div>
  )
}

export default function ItemPicker({
  value,
  onChange,
  onCreated,
  categoryId,
  scope,
  placeholder = 'Search by name, category or detail',
  allowCreate = true,
  autoFocus,
  label,
  className,
  onlyPerPiece,
  context,
  isDisabled,
  metalRows,
  extraSections,
  onPickExtra,
  hideItem,
  itemsTitle,
  itemsOnType,
  openOnFocus,
  clearOnType,
  loading: outerLoading,
  emptyText,
  flip = true,
}) {
  const { catalog, categoryById } = useCatalog()
  const [input, setInput] = useState(value?.name || '')
  const [creating, setCreating] = useState(false)
  const [seed, setSeed] = useState('')
  const [browseCat, setBrowseCat] = useState(0)
  const [ff, setFf] = useState({})
  const [openField, setOpenField] = useState(null)
  const [inStock, setInStock] = useState(false)
  const picked = useRef('')
  const listRef = useRef(null)
  useEffect(() => {
    setInput(value?.name || '')
  }, [value?.id])

  const locked = Number(categoryId) > 0
  const cat = locked ? Number(categoryId) : browseCat
  const facetsQ = useQuery({
    queryKey: ['number-facets', cat],
    queryFn: () => get(`categories/${cat}/number-facets`),
    enabled: !!cat,
    staleTime: 300_000,
  })
  const catFields = useMemo(() => {
    const facets = facetsQ.data?.facets || {}
    const out = []
    for (const f of (cat && categoryById[cat]?.fields) || []) {
      if (f.archived) continue
      if (f.type === 'select') {
        const options = (f.choices || []).filter((c) => !c.archived).map((c) => ({ key: c.id, label: c.label }))
        if (options.length) out.push({ id: f.id, name: f.name, type: 'select', options })
      } else if (f.type === 'number' && Array.isArray(facets[f.id]) && facets[f.id].length > 1) {
        out.push({ id: f.id, name: f.name, type: 'number', options: facets[f.id].map((c) => ({ key: c.key, label: c.label, range: { min: c.min, max: c.max, unit_id: c.unit_id } })) })
      }
    }
    return out
  }, [cat, categoryById, facetsQ.data])
  const activeFf = useMemo(() => {
    const out = {}
    for (const f of catFields) {
      const keys = ff[f.id] || []
      if (!keys.length) continue
      if (f.type === 'number') {
        const ranges = f.options.filter((o) => keys.includes(o.key)).map((o) => o.range)
        if (ranges.length) out[f.id] = { ranges }
      } else {
        out[f.id] = { choice_ids: keys }
      }
    }
    return out
  }, [ff, catFields])
  const ffJson = JSON.stringify(activeFf)
  const hasFf = ffJson !== '{}'
  const filtering = (!locked && !!cat) || hasFf || inStock

  const typed = input.trim()
  const searchText = typed && typed !== (value?.name || '').trim() ? typed : ''
  const term = useDebounced(searchText, 200)
  const filters = useMemo(() => ({ ...(onlyPerPiece ? { per_piece: true } : {}), ...(scope || {}) }), [onlyPerPiece, JSON.stringify(scope || {})])
  const wantItems = !itemsOnType || !!searchText || filtering
  const q = useInfiniteQuery({
    queryKey: ['item-suggest', term, cat || 0, JSON.stringify(filters), ffJson, inStock ? 1 : 0],
    queryFn: ({ pageParam, signal }) => itemSuggestions({
      search: term,
      category_id: cat || undefined,
      ...filters,
      in_stock: inStock ? 1 : undefined,
      filters: cat && ffJson !== '{}' ? ffJson : undefined,
      per_category: cat ? PAGE : undefined,
      offset: cat ? pageParam : undefined,
    }, { signal }),
    initialPageParam: 0,
    getNextPageParam: (last) => (last?.has_more ? Number(last.offset || 0) + PAGE : undefined),
    enabled: wantItems,
    placeholderData: keepPreviousData,
    staleTime: 0,
  })
  const pages = q.data?.pages || []
  const total = Number(pages[0]?.total || 0)
  const loaded = cat ? pages.reduce((n, p) => n + (p.sections?.[0]?.items || []).length, 0) : 0
  const sections = useMemo(() => {
    if (!wantItems || !pages.length) return []
    let list
    if (cat) {
      const first = pages[0].sections?.[0]
      list = first ? [{ ...first, items: pages.flatMap((p) => p.sections?.[0]?.items || []) }] : []
    } else {
      list = pages[0].sections || []
    }
    if (hideItem) list = list.map((s) => ({ ...s, items: s.items.filter((it) => !hideItem(it, Number(it.category_id || s.category_id), term)) }))
    if (metalRows) list = list.map((s) => ({ ...s, items: withMetalRows(s.items, s.category_id) }))
    list = list.filter((s) => s.items.length > 0)
    if (itemsTitle && !cat && list.length) return [{ category_id: null, name: itemsTitle, total: 0, flat: true, items: list.flatMap((s) => s.items.map((it) => (it.category_id ? it : { ...it, category_id: s.category_id }))) }]
    return list
  }, [q.data, cat, metalRows, hideItem, itemsTitle, wantItems, term])
  const extras = useMemo(() => (extraSections ? extraSections(searchText, { categoryId: cat || 0, fieldFilters: hasFf }) : []), [extraSections, searchText, cat, hasFf])
  const itemsLoading = wantItems && (!q.data || q.isPlaceholderData || term !== searchText)

  useEffect(() => {
    if (listRef.current) listRef.current.scrollTop = 0
  }, [cat, ffJson, inStock, term])
  useEffect(() => {
    const el = listRef.current
    if (el && q.hasNextPage && !q.isFetchingNextPage && !q.isPlaceholderData && el.scrollHeight <= el.clientHeight + 160) q.fetchNextPage()
  }, [q.data, q.hasNextPage, q.isFetchingNextPage, q.isPlaceholderData])

  const byId = useMemo(() => {
    const map = new Map()
    for (const s of sections) for (const it of s.items) map.set(String(it.id), it.category_id ? it : { ...it, category_id: s.category_id })
    return map
  }, [sections])
  const extraById = useMemo(() => new Map(extras.flatMap((s) => s.entries.map((e) => [String(e.id), e]))), [extras])
  const showSelected = !!(value?.id && value?.name && !byId.has(String(value.id)) && !extraById.has(String(value.id)))

  const pickCategory = (id) => {
    setBrowseCat(id)
    setFf({})
    setOpenField(null)
    if (!id) setInStock(false)
  }
  const toggleChoice = (fieldId, choiceId) => setFf((cur) => {
    const ids = cur[fieldId] || []
    return { ...cur, [fieldId]: ids.includes(choiceId) ? ids.filter((x) => x !== choiceId) : [...ids, choiceId] }
  })
  const clearFilters = () => {
    setFf({})
    setOpenField(null)
    setInStock(false)
  }
  const loadMore = () => {
    if (q.hasNextPage && !q.isFetchingNextPage) q.fetchNextPage()
  }
  const onScroll = (e) => {
    const el = e.currentTarget
    if (el.scrollTop + el.clientHeight > el.scrollHeight - 160) loadMore()
  }

  const type = (v) => {
    setInput(v)
    if (clearOnType && value && v !== value.name && v !== picked.current) onChange(null)
  }

  const startCreate = () => {
    setSeed(searchText)
    setCreating(true)
  }

  const select = (key) => {
    if (key === null || key === undefined) return
    const k = String(key)
    if (k.startsWith('__')) return
    const extra = extraById.get(k)
    if (extra) {
      picked.current = extra.label
      if (onPickExtra) onPickExtra(extra)
      setInput(extra.label)
      return
    }
    const it = byId.get(k) || (value && String(value.id) === k ? value : null)
    if (it) {
      picked.current = it.name
      onChange(it)
      setInput(it.name)
    }
  }

  const empty = emptyText ? emptyText(searchText, filtering) : searchText ? `No items match “${searchText}”.` : filtering ? 'No items match these filters.' : 'No items yet.'
  const categories = (catalog.categories || []).filter((c) => !c.archived)
  const fieldOpen = catFields.find((f) => f.id === openField) || null
  const footerText = cat && total > 0 ? `${Math.min(loaded, total)} of ${total}` : ''

  return (
    <NoBubble>
      <ComboBox
        className={className || 'w-full'}
        aria-label={label || placeholder}
        inputValue={input}
        onInputChange={type}
        selectedKey={value?.id ? String(value.id) : null}
        onSelectionChange={select}
        defaultFilter={() => true}
        menuTrigger="manual"
        allowsEmptyCollection
        isDisabled={isDisabled}
      >
        <ComboBox.InputGroup>
          <PickerInput placeholder={placeholder} autoFocus={autoFocus} openOnFocus={openOnFocus} />
          <ComboBox.Trigger />
        </ComboBox.InputGroup>
        <ComboBox.Popover placement="bottom start" shouldFlip={flip} className="flex h-[460px] w-(--trigger-width) min-w-[min(460px,calc(100vw-16px))] max-w-[calc(100vw-16px)] flex-col !overflow-hidden">
          <div className="flex shrink-0 flex-col gap-1.5 border-b border-separator bg-overlay py-2" onMouseDown={keepFocus}>
            {!locked && (
              <ChipSlider watch={cat}>
                {(hasFf || inStock) && (
                  <FilterChip muted onClick={clearFilters}>
                    <Xmark className="size-3 shrink-0" />
                    <span>Clear filters</span>
                  </FilterChip>
                )}
                <FilterChip active={!cat} onClick={() => pickCategory(0)}>All</FilterChip>
                {categories.map((c) => (
                  <FilterChip key={c.id} active={cat === c.id} onClick={() => pickCategory(cat === c.id ? 0 : c.id)}>
                    <CategoryIcon categoryId={c.id} className="size-3.5 shrink-0" />
                    <span className="truncate">{c.name}</span>
                  </FilterChip>
                ))}
              </ChipSlider>
            )}
            {!!cat && (
              <ChipSlider watch={cat}>
                {locked && (hasFf || inStock) && (
                  <FilterChip muted onClick={clearFilters}>
                    <Xmark className="size-3 shrink-0" />
                    <span>Clear filters</span>
                  </FilterChip>
                )}
                <FilterChip active={inStock} onClick={() => setInStock((v) => !v)}>In stock</FilterChip>
                {catFields.map((f) => {
                  const keys = ff[f.id] || []
                  const names = f.options.filter((o) => keys.includes(o.key)).map((o) => o.label)
                  return (
                    <FilterChip key={f.id} active={keys.length > 0 || openField === f.id} title={names.join(', ')} onClick={() => setOpenField(openField === f.id ? null : f.id)}>
                      <span className="truncate">{names.length ? `${f.name}: ${names.join(', ')}` : f.name}</span>
                      <ChevronDown className={cx('size-3 shrink-0 transition-transform', openField === f.id && 'rotate-180')} />
                    </FilterChip>
                  )
                })}
              </ChipSlider>
            )}
            {fieldOpen && (
              <ChipSlider className="-mb-2 border-t border-separator bg-surface-secondary/60 py-2" watch={openField}>
                {fieldOpen.options.map((o) => (
                  <FilterChip key={o.key} active={(ff[fieldOpen.id] || []).includes(o.key)} onClick={() => toggleChoice(fieldOpen.id, o.key)}>
                    <span className="truncate">{o.label}</span>
                  </FilterChip>
                ))}
              </ChipSlider>
            )}
          </div>
          <div ref={listRef} className="min-h-0 flex-1 overflow-y-auto overscroll-contain" onScroll={onScroll}>
            <ListBox renderEmptyState={() => <div className="px-3 py-3 text-[13px] text-muted">{empty}</div>}>
              {outerLoading ? (
                <SkeletonItems />
              ) : (
                <>
                  {extras.map((s) => (
                    <ListBox.Section key={`__x${s.key}`} id={`__x${s.key}`}>
                      <SectionHeader categoryId={s.categoryId}>{s.title}</SectionHeader>
                      {s.entries.map((e) => (
                        <ListBox.Item key={String(e.id)} id={String(e.id)} textValue={e.label}>
                          {e.render()}
                        </ListBox.Item>
                      ))}
                    </ListBox.Section>
                  ))}
                  {itemsLoading ? (
                    <SkeletonItems />
                  ) : (
                    <>
                      {showSelected && (
                        <ListBox.Section key="__selected" id="__selected">
                          <SectionHeader>Selected</SectionHeader>
                          <ListBox.Item key={String(value.id)} id={String(value.id)} textValue={value.name}>
                            <ItemRow it={value} />
                          </ListBox.Item>
                        </ListBox.Section>
                      )}
                      {sections.map((s) => {
                        const shown = s.items.filter((i) => !i.metalRow).length
                        const more = !cat && !s.flat && s.total > shown
                        return (
                          <ListBox.Section key={s.flat ? '__items' : `c${s.category_id}`} id={s.flat ? '__items' : `c${s.category_id}`}>
                            <SectionHeader
                              categoryId={s.category_id}
                              aside={more && !locked ? (
                                <button type="button" className="cursor-pointer text-accent hover:underline" onMouseDown={keepFocus} onClick={() => pickCategory(Number(s.category_id))}>{`See all ${s.total}`}</button>
                              ) : null}
                            >
                              {s.name}
                            </SectionHeader>
                            {s.items.map((it) => (
                              <ListBox.Item key={String(it.id)} id={String(it.id)} textValue={it.name}>
                                <ItemRow it={it} categoryId={s.flat ? it.category_id : s.category_id} />
                              </ListBox.Item>
                            ))}
                          </ListBox.Section>
                        )
                      })}
                    </>
                  )}
                </>
              )}
            </ListBox>
            {q.isFetchingNextPage && (
              <div className="flex justify-center py-3" aria-label="Loading more">
                <Spinner size="sm" />
              </div>
            )}
          </div>
          {(footerText || allowCreate) && (
            <div className="flex shrink-0 items-center gap-2 border-t border-separator bg-overlay px-3 py-1.5" onMouseDown={keepFocus}>
              <span className="min-w-0 flex-1 truncate text-[12px] text-muted">{footerText}</span>
              {allowCreate && (
                <button type="button" className="inline-flex h-7 shrink-0 cursor-pointer items-center gap-1.5 px-2 text-[12.5px] font-medium text-accent hover:bg-accent-soft" onClick={startCreate}>
                  <Plus className="size-4 shrink-0" />
                  <span className="max-w-[200px] truncate">{searchText ? `Create “${searchText}”` : 'Create new item'}</span>
                </button>
              )}
            </div>
          )}
        </ComboBox.Popover>
      </ComboBox>
      {allowCreate && (
        <ItemDialog
          isOpen={creating}
          onClose={() => setCreating(false)}
          categoryId={categoryId}
          defaultCategoryId={cat || sections[0]?.category_id}
          initialName={seed}
          context={context}
          onSaved={(it) => {
            if (!it) return
            if (onCreated) {
              onCreated(it)
              return
            }
            onChange(it)
            setInput(it.name)
          }}
        />
      )}
    </NoBubble>
  )
}
