import { createContext, forwardRef, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { AlertDialog, Button, Calendar, Checkbox, Chip, ColorArea, ColorField, ColorPicker, ColorSlider, ColorSwatch, DateField, DatePicker, Dropdown, I18nProvider, InputGroup, Label, ListBox, Modal, Select, Spinner, Switch, Tabs, Tooltip, useCalendarOrRangeState, useYearPicker } from '@heroui/react'
import { parseDate } from '@internationalized/date'
import { ChevronLeft, ChevronRight, Copy, Check as CheckIcon, ChevronDown } from '@gravity-ui/icons'

export function cx(...parts) {
  return parts.filter(Boolean).join(' ')
}

function stopBubble(e) {
  e.stopPropagation()
}

export function NoBubble({ children, title }) {
  return (
    <span className="contents" title={title} onClick={stopBubble} onDoubleClick={stopBubble}>
      {children}
    </span>
  )
}

export function fromInside(e) {
  return !!(e && e.currentTarget && e.target && e.currentTarget.contains(e.target))
}

export function BackLink({ onPress, children }) {
  return (
    <button type="button" onClick={onPress} className="mb-1.5 inline-flex cursor-pointer items-center gap-1 text-[12.5px] font-medium text-muted transition-colors duration-200 hover:text-foreground">
      <ChevronLeft className="size-3.5" />
      <span>{children}</span>
    </button>
  )
}

const reducedMotion = () => typeof window !== 'undefined' && !!window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches

export function Page({ title, subtitle, actions, children, toolbar, back }) {
  return (
    <div className="cjs-page w-full px-3 pb-10 pt-4 sm:px-5 lg:px-6 2xl:px-8">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          {back}
          <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">{title}</h1>
          {subtitle && <div className="mt-1 text-[13px] text-muted">{subtitle}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
      {toolbar && <div className="mb-4">{toolbar}</div>}
      {children}
    </div>
  )
}

export function Card({ title, description, actions, children, className, bodyClassName, padded = true }) {
  return (
    <section className={cx('cjs-card', className)}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-2 px-4 pt-4 sm:px-5">
          <div className="min-w-0">
            {title && <h2 className="text-[15px] font-semibold text-foreground">{title}</h2>}
            {description && <div className="mt-0.5 text-[13px] text-muted">{description}</div>}
          </div>
          {actions && <div className="ml-auto flex flex-wrap items-center justify-end gap-2">{actions}</div>}
        </header>
      )}
      <div className={cx(padded && 'p-4 sm:p-5', bodyClassName)}>{children}</div>
    </section>
  )
}

export function Field({ label, hint, error, children, className, required }) {
  return (
    <label className={cx('flex min-w-0 flex-col gap-1.5', className)}>
      {label && (
        <span className="cjs-field-label text-[12px] font-medium text-foreground/80">
          {label}
          {required && <RequiredMark />}
        </span>
      )}
      {children}
      {error ? <span className="text-[12px] text-danger">{error}</span> : hint ? <span className="text-[12px] text-muted">{hint}</span> : null}
    </label>
  )
}

export function RequiredMark() {
  return (
    <span className="cjs-required" aria-hidden="true">
      *
    </span>
  )
}

export const TextInput = forwardRef(function TextInput({ value, onChange, size, className, invalid, ...rest }, ref) {
  return (
    <input
      ref={ref}
      type="text"
      className={cx('input cjs-native-input', size === 'sm' && 'cjs-sm', className)}
      value={value ?? ''}
      data-invalid={invalid ? 'true' : undefined}
      aria-invalid={invalid ? true : undefined}
      onChange={(e) => onChange && onChange(e.target.value)}
      {...rest}
    />
  )
})

export const NumberInput = forwardRef(function NumberInput({ value, onChange, size, className, step = 'any', min, invalid, ...rest }, ref) {
  return (
    <input
      ref={ref}
      type="number"
      inputMode="decimal"
      step={step}
      min={min}
      className={cx('input cjs-native-input tabular-nums', size === 'sm' && 'cjs-sm', className)}
      value={value ?? ''}
      data-invalid={invalid ? 'true' : undefined}
      aria-invalid={invalid ? true : undefined}
      onChange={(e) => onChange && onChange(e.target.value)}
      onWheel={(e) => e.currentTarget.blur()}
      {...rest}
    />
  )
})

function toCalendarDate(v) {
  if (!v || !/^\d{4}-\d{2}-\d{2}$/.test(v)) return null
  try {
    return parseDate(v)
  } catch {
    return null
  }
}

const LT_MONTHS = (() => {
  try {
    const f = new Intl.DateTimeFormat('lt-LT', { month: 'long' })
    return Array.from({ length: 12 }, (_, i) => {
      const s = f.format(new Date(2024, i, 1))
      return s.charAt(0).toUpperCase() + s.slice(1)
    })
  } catch {
    return ['Sausis', 'Vasaris', 'Kovas', 'Balandis', 'Gegužė', 'Birželis', 'Liepa', 'Rugpjūtis', 'Rugsėjis', 'Spalis', 'Lapkritis', 'Gruodis']
  }
})()

function CalendarMonthGrid({ open, onPicked }) {
  const state = useCalendarOrRangeState()
  const { calendarRef } = useYearPicker()
  const ref = useRef(null)
  useEffect(() => {
    const el = ref.current
    const grid = calendarRef && calendarRef.current ? calendarRef.current.querySelector("[data-slot='calendar-grid']") : null
    if (el && grid) {
      el.style.top = `${grid.offsetTop}px`
      el.style.height = `${grid.offsetHeight}px`
    }
  }, [open, calendarRef, state.focusedDate])
  const current = state.focusedDate ? state.focusedDate.month : 0
  return (
    <div ref={ref} className="cjs-month-grid" data-open={open ? 'true' : undefined} aria-hidden={!open} role="group" aria-label="Mėnuo">
      {LT_MONTHS.map((name, i) => (
        <button
          key={name}
          type="button"
          tabIndex={open ? 0 : -1}
          className="cjs-month-cell"
          data-selected={i + 1 === current ? 'true' : undefined}
          onClick={() => {
            if (state.focusedDate) state.setFocusedDate(state.focusedDate.set({ month: i + 1 }))
            onPicked()
          }}
        >
          {name}
        </button>
      ))}
    </div>
  )
}

export function DateInput({ value, onChange, onCommit, size, className, isDisabled, isRequired, required, invalid, isInvalid, 'aria-label': ariaLabel }) {
  const latest = useRef(value || '')
  const committed = useRef(value || '')
  const picked = useRef(false)
  const [mode, setMode] = useState(null)
  useEffect(() => {
    latest.current = value || ''
    committed.current = value || ''
  }, [value])
  const commit = () => {
    if (!onCommit || latest.current === committed.current) return
    committed.current = latest.current
    onCommit(latest.current)
  }
  const bad = !!(invalid || isInvalid)
  return (
    <NoBubble>
      <I18nProvider locale="lt-LT">
        <DatePicker
          aria-label={ariaLabel || 'Data'}
          className={cx('cjs-date', size === 'sm' && 'cjs-date-sm', className || 'w-full')}
          value={toCalendarDate(value)}
          isDisabled={isDisabled}
          isRequired={!!(isRequired || required)}
          isInvalid={bad || undefined}
          granularity="day"
          shouldForceLeadingZeros
          onChange={(d) => {
            const s = d ? d.toString() : ''
            latest.current = s
            if (onChange) onChange(s)
          }}
          onOpenChange={(open) => {
            if (!open) {
              setMode(null)
              setTimeout(commit, 0)
            }
          }}
          onBlur={() => setTimeout(commit, 0)}
        >
          <DateField.Group fullWidth>
            <DateField.Input>{(segment) => <DateField.Segment segment={segment} />}</DateField.Input>
            <DateField.Suffix>
              <DatePicker.Trigger>
                <DatePicker.TriggerIndicator />
              </DatePicker.Trigger>
            </DateField.Suffix>
          </DateField.Group>
          <DatePicker.Popover className="cjs-date-popover">
            <Calendar
              aria-label={ariaLabel || 'Data'}
              firstDayOfWeek="mon"
              isYearPickerOpen={mode === 'years'}
              onYearPickerOpenChange={(open) => {
                if (open) {
                  picked.current = false
                  setMode('years')
                } else {
                  setMode(picked.current ? 'months' : null)
                  picked.current = false
                }
              }}
            >
              <Calendar.Header>
                <Calendar.YearPickerTrigger>
                  <Calendar.YearPickerTriggerHeading />
                  <Calendar.YearPickerTriggerIndicator />
                </Calendar.YearPickerTrigger>
                <Calendar.NavButton slot="previous" />
                <Calendar.NavButton slot="next" />
              </Calendar.Header>
              <Calendar.Grid>
                <Calendar.GridHeader>{(day) => <Calendar.HeaderCell>{day}</Calendar.HeaderCell>}</Calendar.GridHeader>
                <Calendar.GridBody>{(date) => <Calendar.Cell date={date} />}</Calendar.GridBody>
              </Calendar.Grid>
              <Calendar.YearPickerGrid>
                <Calendar.YearPickerGridBody>
                  {({ year }) => (
                    <Calendar.YearPickerCell
                      year={year}
                      onPress={() => {
                        picked.current = true
                      }}
                    />
                  )}
                </Calendar.YearPickerGridBody>
              </Calendar.YearPickerGrid>
              <CalendarMonthGrid open={mode === 'months'} onPicked={() => setMode(null)} />
            </Calendar>
          </DatePicker.Popover>
        </DatePicker>
      </I18nProvider>
    </NoBubble>
  )
}

export function TextArea({ value, onChange, className, rows = 3, autoGrow, fill, invalid, ...rest }) {
  const ref = useRef(null)
  useEffect(() => {
    if (autoGrow && !fill && ref.current) {
      ref.current.style.height = 'auto'
      ref.current.style.height = Math.min(ref.current.scrollHeight + 2, 600) + 'px'
    }
  }, [value, autoGrow, fill])
  return (
    <textarea
      ref={ref}
      rows={rows}
      className={cx('textarea cjs-native-input cjs-textarea', fill && 'cjs-fill', className)}
      value={value ?? ''}
      data-invalid={invalid ? 'true' : undefined}
      aria-invalid={invalid ? true : undefined}
      onChange={(e) => onChange && onChange(e.target.value)}
      {...rest}
    />
  )
}

function OptionLabel({ option }) {
  return (
    <span className="cjs-opt">
      {option.color && <span className="cjs-dot" style={{ background: option.color }} />}
      {option.icon && <span className="cjs-opt-icon">{option.icon}</span>}
      <span className="cjs-opt-label">{option.label}</span>
    </span>
  )
}

export function SelectField({ value, onChange, options, placeholder = 'Select…', label, ariaLabel, size, className, allowEmpty, emptyLabel = 'Nepasirinkta', isDisabled, isRequired, required, invalid, isInvalid }) {
  const items = useMemo(() => {
    const list = (options || []).map((o) => (typeof o === 'string' ? { value: o, label: o } : o)).map((o) => ({ ...o, key: o.value === '' || o.value === null || o.value === undefined ? '__empty' : String(o.value) }))
    const hasEmpty = list.some((o) => o.key === '__empty')
    return allowEmpty && !hasEmpty ? [{ value: '', label: emptyLabel, key: '__empty' }, ...list] : list
  }, [options, allowEmpty, emptyLabel])
  const hasEmptyItem = items.some((o) => o.key === '__empty')
  const selected = value === '' || value === null || value === undefined ? (hasEmptyItem ? '__empty' : null) : String(value)
  const current = items.find((o) => o.key === selected)
  const title = current && (typeof current.label === 'string' || typeof current.label === 'number') ? String(current.label) : undefined
  const req = !!(isRequired || required)
  return (
    <NoBubble title={title}>
      <Select
        className={cx('cjs-select min-w-0', size === 'sm' && 'cjs-select-sm', className)}
        placeholder={placeholder}
        selectedKey={selected}
        isDisabled={isDisabled}
        isRequired={req}
        isInvalid={!!(invalid || isInvalid) || undefined}
        aria-label={ariaLabel || (typeof label === 'string' ? label : placeholder)}
        onSelectionChange={(key) => onChange && onChange(key === '__empty' || key === null ? '' : String(key))}
      >
        {label && (
          <Label>
            {label}
            {req && <RequiredMark />}
          </Label>
        )}
        <Select.Trigger>
          <Select.Value />
          <Select.Indicator />
        </Select.Trigger>
        <Select.Popover>
          <ListBox>
            {items.map((o) => (
              <ListBox.Item key={o.key} id={o.key} textValue={String(o.label)} isDisabled={!!o.disabled} className={o.disabled ? 'opacity-55' : undefined}>
                <OptionLabel option={o} />
                <ListBox.ItemIndicator />
              </ListBox.Item>
            ))}
          </ListBox>
        </Select.Popover>
      </Select>
    </NoBubble>
  )
}

export function MultiFilter({ label, options, value = [], onChange, size = 'sm', chips }) {
  const selected = useMemo(() => new Set(value.map(String)), [value])
  return (
    <NoBubble>
    <Dropdown>
      <Button size={size} variant={value.length ? 'secondary' : 'tertiary'} className="gap-1.5">
        {label}
        {value.length > 0 && <span className="bg-accent px-1.5 text-[11px] font-semibold text-accent-foreground">{value.length}</span>}
        <ChevronDown className="size-3.5 opacity-60" />
      </Button>
      <Dropdown.Popover className="min-w-[240px]">
        <Dropdown.Menu
          aria-label={label}
          selectionMode="multiple"
          selectedKeys={selected}
          onSelectionChange={(keys) => onChange(keys === 'all' ? options.map((o) => String(o.value)) : Array.from(keys).map(String))}
        >
          {options.map((o) => (
            <Dropdown.Item key={String(o.value)} id={String(o.value)} textValue={String(o.label)}>
              <Dropdown.ItemIndicator />
              {chips ? (
                <Label className="flex items-center">
                  <HexChip color={o.color} bg={o.bg} label={o.label} />
                </Label>
              ) : (
                <Label className="flex items-center gap-2">
                  {o.color && <span className="cjs-dot" style={{ background: o.color }} />}
                  {o.label}
                </Label>
              )}
            </Dropdown.Item>
          ))}
        </Dropdown.Menu>
      </Dropdown.Popover>
    </Dropdown>
    </NoBubble>
  )
}

export function Check({ checked, onChange, children, isDisabled, className, ariaLabel }) {
  return (
    <Checkbox isSelected={!!checked} onChange={onChange} isDisabled={isDisabled} className={className} aria-label={ariaLabel}>
      <Checkbox.Content>
        <Checkbox.Control>
          <Checkbox.Indicator />
        </Checkbox.Control>
        {children}
      </Checkbox.Content>
    </Checkbox>
  )
}

export function Toggle({ checked, onChange, children, isDisabled, size = 'sm' }) {
  return (
    <Switch isSelected={!!checked} onChange={onChange} isDisabled={isDisabled} size={size}>
      <Switch.Content>
        <Switch.Control>
          <Switch.Thumb />
        </Switch.Control>
        {children && <span className="text-[13px]">{children}</span>}
      </Switch.Content>
    </Switch>
  )
}

export function IconButton({ icon: Icon, label, onPress, variant = 'tertiary', size = 'sm', isDisabled, isPending, className, tooltip = true }) {
  const btn = (
    <Button isIconOnly aria-label={label} size={size} variant={variant} onPress={onPress} isDisabled={isDisabled} isPending={isPending} className={className}>
      {isPending ? <Spinner size="sm" color="current" /> : <Icon className="size-4" />}
    </Button>
  )
  if (!tooltip) return btn
  return (
    <Tooltip delay={300} closeDelay={0}>
      {btn}
      <Tooltip.Content>
        <p>{label}</p>
      </Tooltip.Content>
    </Tooltip>
  )
}

export function Btn({ isPending, children, icon: Icon, ...rest }) {
  return (
    <Button isPending={isPending} isDisabled={rest.isDisabled || isPending} {...rest}>
      {isPending ? <Spinner size="sm" color="current" /> : Icon ? <Icon className="size-4" /> : null}
      {children}
    </Button>
  )
}

const STATUS_TONES = {
  '#6b7280': 'default',
  '#b91c1c': 'danger',
  '#b45309': 'warning',
  '#d97706': 'warning',
  '#2563eb': 'accent',
  '#7c6500': 'warning',
  '#146c2e': 'success',
}

export function HexChip({ color, bg, label, size = 'sm', onClick, title, className }) {
  const tone = !bg && color ? STATUS_TONES[String(color).toLowerCase()] : null
  const chip = tone ? (
    <Chip color={tone} size={size} variant="soft" className={className}>
      <Chip.Label>{label}</Chip.Label>
    </Chip>
  ) : (
    <Chip size={size} variant="soft" className={className} style={{ background: bg || `color-mix(in srgb, ${color || '#6b7280'} 16%, #fff)`, color: color || '#6b7280' }}>
      <Chip.Label>{label}</Chip.Label>
    </Chip>
  )
  if (onClick) {
    return (
      <button type="button" className="inline-flex max-w-full cursor-pointer text-left hover:opacity-80" onClick={onClick} title={title}>
        {chip}
      </button>
    )
  }
  if (title) return <span className="inline-flex max-w-full" title={title}>{chip}</span>
  return chip
}

export function Tone({ tone = 'default', children, size = 'sm', variant = 'soft', className }) {
  return (
    <Chip color={tone} size={size} variant={variant} className={className}>
      <Chip.Label>{children}</Chip.Label>
    </Chip>
  )
}

const NOTICE_BG = { warning: 'bg-warning-soft', danger: 'bg-danger-soft', success: 'bg-success-soft', info: 'bg-accent-soft', accent: 'bg-accent-soft', default: 'bg-surface-secondary' }

export function Notice({ tone = 'warning', title, children, action, className }) {
  return (
    <div className={cx('cjs-note flex flex-wrap justify-between gap-x-3 gap-y-2 px-3 py-2 text-[12.5px] leading-snug text-foreground', title && children ? 'items-start' : 'items-center', NOTICE_BG[tone] || NOTICE_BG.default, className)}>
      <div className="min-w-0 flex-1">
        {title && <div className="font-medium">{title}</div>}
        {children && <div className={title ? 'mt-0.5' : undefined}>{children}</div>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}

export function Empty({ icon: Icon, title, description, action, compact }) {
  return (
    <div className={cx('flex flex-col items-center justify-center text-center', compact ? 'gap-1.5 py-6' : 'gap-2 py-14')}>
      {Icon && (
        <div className="mb-1 flex size-11 items-center justify-center bg-surface-secondary text-muted">
          <Icon className="size-5" />
        </div>
      )}
      <div className="text-[14px] font-semibold text-foreground">{title}</div>
      {description && <div className="max-w-md text-[13px] text-muted">{description}</div>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  )
}

export function Loading({ className, compact }) {
  return (
    <div className={cx('cjs-loading flex items-center justify-center text-muted', compact ? 'py-4' : 'py-12', className)} role="status" aria-live="polite">
      <Spinner size={compact ? 'sm' : 'md'} />
    </div>
  )
}

const SK_WIDTHS = [0, -14, 8, -22, -6, 12, -18, 4]

export function skw(i, base) {
  return `${Math.max(12, Math.min(100, base + SK_WIDTHS[i % SK_WIDTHS.length]))}%`
}

export function SkLine({ size = 13, w = '60%', lh = 1.45, className }) {
  return (
    <div className={cx('flex items-center', className)} style={{ height: size * lh }}>
      <div className="cjs-skeleton-line" style={{ width: w, height: Math.round(size * 0.72) }} />
    </div>
  )
}

export function SkBox({ w = 60, h = 20, className }) {
  return <div className={cx('cjs-skeleton-line shrink-0', className)} style={{ width: w, height: h }} />
}

export function SkChip({ w = 64, className }) {
  return <SkBox w={w} h={20} className={className} />
}

export function SkeletonRows({ rows = 6, children }) {
  return Array.from({ length: rows }).map((_, i) => (
    <tr key={i} aria-hidden="true">
      {children(i)}
    </tr>
  ))
}

export function SkeletonList({ rows = 6, divided = true, className, itemClassName, children }) {
  return (
    <ul className={cx(divided && 'divide-y divide-separator', className)} aria-busy="true">
      {Array.from({ length: rows }).map((_, i) => (
        <li key={i} aria-hidden="true" className={itemClassName}>
          {children(i)}
        </li>
      ))}
    </ul>
  )
}

const DIALOG_SIZES = new Set(['xs', 'sm', 'md', 'lg', 'xl', 'full'])

export function Dialog({ isOpen, onClose, title, description, children, footer, size = 'md', icon, isDismissable = true, fill, className, bodyClassName }) {
  const s = DIALOG_SIZES.has(size) ? size : size === 'cover' ? 'full' : 'md'
  const grow = !!fill || s === 'full'
  return (
    <Modal.Backdrop isOpen={isOpen} onOpenChange={(open) => !open && onClose && onClose()} isDismissable={isDismissable}>
      <Modal.Container size="md" scroll="inside" className={cx('cjs-dialog-container', `cjs-dialog-container--${s}`)}>
        <Modal.Dialog className={cx('cjs-dialog', `cjs-dialog--${s}`, grow && 'cjs-dialog--fill', className)}>
          <Modal.CloseTrigger />
          <Modal.Header>
            {icon && <Modal.Icon className="bg-accent-soft text-accent-soft-foreground">{icon}</Modal.Icon>}
            <Modal.Heading>{title}</Modal.Heading>
            {description && <p className="text-[13px] text-muted">{description}</p>}
          </Modal.Header>
          <Modal.Body className={cx('cjs-dialog-body', bodyClassName)}>{children}</Modal.Body>
          {footer && <Modal.Footer className="flex flex-wrap justify-end gap-2">{footer}</Modal.Footer>}
        </Modal.Dialog>
      </Modal.Container>
    </Modal.Backdrop>
  )
}

const ConfirmContext = createContext(null)

export function ConfirmProvider({ children }) {
  const [state, setState] = useState(null)
  const resolver = useRef(null)
  const confirm = useCallback((opts) => new Promise((resolve) => {
    resolver.current = resolve
    setState({ title: 'Are you sure?', confirmLabel: 'Continue', cancelLabel: 'Cancel', tone: 'accent', ...opts })
  }), [])
  const close = (result) => {
    setState(null)
    if (resolver.current) resolver.current(result)
    resolver.current = null
  }
  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <AlertDialog.Backdrop isOpen={!!state} onOpenChange={(open) => !open && close(false)}>
        <AlertDialog.Container>
          <AlertDialog.Dialog className="sm:max-w-[440px]">
            <AlertDialog.Header>
              <AlertDialog.Icon status={state?.tone === 'danger' ? 'danger' : state?.tone === 'warning' ? 'warning' : 'accent'} />
              <AlertDialog.Heading>{state?.title}</AlertDialog.Heading>
            </AlertDialog.Header>
            <AlertDialog.Body>
              {typeof state?.message === 'string' ? <p className="text-[13.5px] text-muted">{state?.message}</p> : state?.message}
            </AlertDialog.Body>
            <AlertDialog.Footer>
              <Button variant="tertiary" onPress={() => close(false)}>{state?.cancelLabel}</Button>
              <Button variant={state?.tone === 'danger' ? 'danger' : 'primary'} onPress={() => close(true)}>{state?.confirmLabel}</Button>
            </AlertDialog.Footer>
          </AlertDialog.Dialog>
        </AlertDialog.Container>
      </AlertDialog.Backdrop>
    </ConfirmContext.Provider>
  )
}

export function useConfirm() {
  return useContext(ConfirmContext)
}

function PageNumberInput({ page, pages, onChange }) {
  const [draft, setDraft] = useState(String(page))
  useEffect(() => setDraft(String(page)), [page])
  const commit = () => {
    const n = Number(draft)
    if (draft !== '' && Number.isInteger(n) && n >= 1 && n <= pages) {
      if (n !== page) onChange(n)
      else setDraft(String(page))
    } else {
      setDraft(String(page))
    }
  }
  return (
    <input
      type="text"
      inputMode="numeric"
      className="input cjs-pager-input tabular-nums"
      aria-label="Puslapis"
      value={draft}
      onFocus={(e) => e.currentTarget.select()}
      onChange={(e) => setDraft(e.target.value.replace(/[^0-9]/g, ''))}
      onBlur={commit}
      onKeyDown={(e) => {
        if (e.key === 'Enter') {
          e.preventDefault()
          e.currentTarget.blur()
        } else if (e.key === 'Escape') {
          e.preventDefault()
          setDraft(String(page))
          setTimeout(() => e.target.blur(), 0)
        }
      }}
    />
  )
}

export function Pager({ page, pages, total, onChange, label = 'results' }) {
  if (!total) return null
  const lbl = String(label || '')
  const one = lbl.endsWith('ies') ? `${lbl.slice(0, -3)}y` : lbl.replace(/s$/, '')
  const p = Math.max(1, Number(page) || 1)
  const max = Math.max(1, Number(pages) || 1)
  return (
    <div className="cjs-pager flex flex-wrap items-center justify-between gap-2 px-1 py-3 text-[13px] text-muted">
      <span>
        {total} {Number(total) === 1 ? one : lbl}
      </span>
      {max > 1 && (
        <div className="flex items-center gap-1.5">
          <IconButton icon={ChevronLeft} label="Previous page" isDisabled={p <= 1} onPress={() => onChange(p - 1)} tooltip={false} />
          <PageNumberInput page={p} pages={max} onChange={onChange} />
          <span aria-hidden="true">/</span>
          <input type="text" className="input cjs-pager-input tabular-nums" value={String(max)} disabled readOnly aria-label="Puslapių" tabIndex={-1} />
          <IconButton icon={ChevronRight} label="Next page" isDisabled={p >= max} onPress={() => onChange(p + 1)} tooltip={false} />
        </div>
      )}
    </div>
  )
}

export function TabBar({ value, onChange, items, className }) {
  return (
    <Tabs selectedKey={value} onSelectionChange={(k) => onChange(String(k))} className={cx('w-full', className)} variant="secondary">
      <Tabs.ListContainer className="cjs-scroll-x">
        <Tabs.List aria-label="Sections">
          {items.map((it) => (
            <Tabs.Tab key={it.id} id={it.id}>
              <span className="flex items-center gap-1.5 whitespace-nowrap">
                {it.icon && <it.icon className="size-4" />}
                {it.label}
                {it.count > 0 && <span className="bg-danger px-1.5 text-[10.5px] font-semibold leading-4 text-danger-foreground">{it.count}</span>}
              </span>
              <Tabs.Indicator />
            </Tabs.Tab>
          ))}
        </Tabs.List>
      </Tabs.ListContainer>
    </Tabs>
  )
}

export function CopyButton({ text, label = 'Copy', size = 'sm', variant = 'secondary' }) {
  const [done, setDone] = useState(false)
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
    } catch {
      const t = document.createElement('textarea')
      t.value = text
      ;(document.querySelector('[role="dialog"]') || document.body).appendChild(t)
      t.select()
      document.execCommand('copy')
      t.remove()
    }
    setDone(true)
    setTimeout(() => setDone(false), 1600)
  }
  return (
    <Button size={size} variant={variant} onPress={copy}>
      {done ? <CheckIcon className="size-4" /> : <Copy className="size-4" />}
      {done ? 'Copied' : label}
    </Button>
  )
}

export function Stat({ label, value, tone, hint }) {
  return (
    <div className="flex min-w-[110px] flex-col bg-surface-secondary/70 px-3 py-2">
      <span className="text-[11px] font-medium uppercase tracking-wide text-muted">{label}</span>
      <span className={cx('text-[17px] font-semibold tabular-nums', tone === 'danger' && 'text-danger', tone === 'success' && 'text-success-strong')}>{value}</span>
      {hint && <span className="text-[11px] text-muted">{hint}</span>}
    </div>
  )
}

export function KeyValue({ items, className }) {
  return (
    <dl className={cx('grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[13px]', className)}>
      {items.filter(Boolean).map(([k, v], i) => (
        <div key={i} className="contents">
          <dt className="text-muted">{k}</dt>
          <dd className="min-w-0 break-words text-foreground">{v}</dd>
        </div>
      ))}
    </dl>
  )
}

export function Section({ title, actions, children, className }) {
  return (
    <div className={cx('flex flex-col gap-2', className)}>
      {(title || actions) && (
        <div className="flex items-center justify-between gap-2">
          <h3 className="text-[12px] font-semibold uppercase tracking-wide text-muted">{title}</h3>
          {actions}
        </div>
      )}
      {children}
    </div>
  )
}

export function SegmentedFilter({ value, onChange, items }) {
  return (
    <div className="inline-flex flex-wrap gap-1 bg-surface-secondary p-1">
      {items.map((it) => (
        <Button key={it.value} size="sm" variant={value === it.value ? 'primary' : 'ghost'} className="h-7 px-3" onPress={() => onChange(it.value)}>
          {it.label}
        </Button>
      ))}
    </div>
  )
}

export function Expand({ open, children, className, lazy = false, appear = false, duration = 280 }) {
  const isOpen = !!open
  const [shown, setShown] = useState(appear ? false : isOpen)
  const [mounted, setMounted] = useState(isOpen || !lazy)
  const [settled, setSettled] = useState(isOpen && !appear)
  useEffect(() => {
    if (!isOpen) {
      setShown(false)
      setSettled(false)
      return undefined
    }
    setMounted(true)
    let inner = 0
    const outer = requestAnimationFrame(() => {
      inner = requestAnimationFrame(() => setShown(true))
    })
    return () => {
      cancelAnimationFrame(outer)
      cancelAnimationFrame(inner)
    }
  }, [isOpen])
  useEffect(() => {
    const t = setTimeout(() => {
      if (shown) setSettled(true)
      else if (lazy) setMounted(false)
    }, reducedMotion() ? 0 : duration + 40)
    return () => clearTimeout(t)
  }, [shown, lazy, duration])
  return (
    <div
      className={cx('cjs-expand', shown && 'is-open', shown && settled && 'is-settled', className)}
      style={{ '--cjs-expand-ms': `${duration}ms` }}
      aria-hidden={!isOpen}
      inert={!isOpen}
    >
      <div className="cjs-expand-inner">{mounted ? children : null}</div>
    </div>
  )
}

export const MoneyInput = forwardRef(function MoneyInput({ value, onChange, currency = '€', className, size, step = 'any', min, invalid, isDisabled, disabled, ...rest }, ref) {
  const off = !!(isDisabled || disabled)
  return (
    <InputGroup className={cx('cjs-money w-full', size === 'sm' && 'cjs-sm', className)} isDisabled={off} isInvalid={!!invalid || undefined}>
      <InputGroup.Prefix className="cjs-money-prefix">{currency}</InputGroup.Prefix>
      <InputGroup.Input
        ref={ref}
        type="number"
        inputMode="decimal"
        step={step}
        min={min}
        disabled={off}
        className="cjs-money-input tabular-nums"
        value={value ?? ''}
        aria-invalid={invalid ? true : undefined}
        onChange={(e) => onChange && onChange(e.target.value)}
        onWheel={(e) => e.currentTarget.blur()}
        {...rest}
      />
    </InputGroup>
  )
})

const HEX_RE = /^#[0-9a-f]{6}$/i

function normalHex(v) {
  if (typeof v !== 'string') return '#000000'
  const s = v.trim()
  if (HEX_RE.test(s)) return s.toLowerCase()
  if (/^#[0-9a-f]{3}$/i.test(s)) return `#${s[1]}${s[1]}${s[2]}${s[2]}${s[3]}${s[3]}`.toLowerCase()
  return '#000000'
}

export function ColorInput({ value, onChange, isDisabled, 'aria-label': ariaLabel, className }) {
  const [color, setColor] = useState(() => normalHex(value))
  useEffect(() => {
    setColor(normalHex(value))
  }, [value])
  const label = ariaLabel || 'Spalva'
  return (
    <NoBubble>
      <ColorPicker
        value={color}
        onChange={(c) => {
          let hex
          try {
            hex = c.toString('hex').toLowerCase()
          } catch {
            return
          }
          setColor(hex)
          if (onChange && hex !== normalHex(value)) onChange(hex)
        }}
      >
        <ColorPicker.Trigger className={cx('cjs-color-trigger', className)} isDisabled={isDisabled} aria-label={label}>
          <ColorSwatch size="sm" />
        </ColorPicker.Trigger>
        <ColorPicker.Popover className="cjs-color-popover">
          <div className="flex w-[232px] flex-col gap-3">
            <ColorArea aria-label={label} colorSpace="hsb" xChannel="saturation" yChannel="brightness" className="w-full">
              <ColorArea.Thumb />
            </ColorArea>
            <ColorSlider aria-label="Atspalvis" channel="hue" colorSpace="hsb" className="w-full">
              <ColorSlider.Track>
                <ColorSlider.Thumb />
              </ColorSlider.Track>
            </ColorSlider>
            <ColorField aria-label="HEX" className="w-full">
              <ColorField.Group fullWidth>
                <ColorField.Prefix>
                  <ColorSwatch size="xs" />
                </ColorField.Prefix>
                <ColorField.Input />
              </ColorField.Group>
            </ColorField>
          </div>
        </ColorPicker.Popover>
      </ColorPicker>
    </NoBubble>
  )
}

export function useSelection(ids) {
  const [selected, setSelected] = useState(() => new Set())
  const key = ids.map(String).join(',')
  useEffect(() => {
    setSelected((prev) => {
      const allowed = new Set(ids.map(String))
      const next = new Set([...prev].filter((id) => allowed.has(id)))
      return next.size === prev.size ? prev : next
    })
  }, [key])
  const count = selected.size
  const all = ids.length > 0 && count === ids.length
  return {
    selected,
    count,
    ids: [...selected],
    state: count === 0 ? 'none' : all ? 'all' : 'some',
    isSelected: (id) => selected.has(String(id)),
    toggle: (id) => setSelected((prev) => {
      const next = new Set(prev)
      const k = String(id)
      next.has(k) ? next.delete(k) : next.add(k)
      return next
    }),
    toggleAll: () => setSelected(all ? new Set() : new Set(ids.map(String))),
    clear: () => setSelected(new Set()),
  }
}

export function SelectAll({ selection, ariaLabel = 'Select all' }) {
  return (
    <Checkbox isSelected={selection.state === 'all'} isIndeterminate={selection.state === 'some'} onChange={selection.toggleAll} aria-label={ariaLabel}>
      <Checkbox.Content>
        <Checkbox.Control>
          <Checkbox.Indicator />
        </Checkbox.Control>
      </Checkbox.Content>
    </Checkbox>
  )
}

export function BulkBar({ count, onClear, children, label, clearLabel = 'Clear selection' }) {
  if (!count) return null
  return (
    <div className="cjs-bulkbar flex flex-wrap items-center gap-2 border border-border bg-surface px-3 py-2 text-[13px]">
      <span className="font-medium">{label ? label(count) : `${count} selected`}</span>
      <div className="flex flex-wrap items-center gap-2">{children}</div>
      <Button size="sm" variant="ghost" className="ml-auto" onPress={onClear}>{clearLabel}</Button>
    </div>
  )
}
