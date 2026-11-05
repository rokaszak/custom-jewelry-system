import { adminViewUrl } from '../lib/nav'
import { boot } from '../lib/api'
import { cx } from './ui'

export function orderListUrl(id) {
  return adminViewUrl('orders', { order: id })
}

export function wooOrderUrl(id) {
  if (!id) return ''
  const base = (boot && boot.admin) || '/wp-admin/'
  const url = new URL(base + 'admin.php', window.location.origin)
  url.searchParams.set('page', 'wc-orders')
  url.searchParams.set('action', 'edit')
  url.searchParams.set('id', String(id))
  return url.toString()
}

export function WooLink({ href, id, children, className, title, ariaLabel }) {
  const url = href || wooOrderUrl(id)
  if (!url) return children ? <span className={className}>{children}</span> : null
  return (
    <a href={url} target="_blank" rel="noopener noreferrer" className={className} title={title} aria-label={ariaLabel} onClick={(e) => e.stopPropagation()}>
      {children}
    </a>
  )
}

export function OrderLink({ id, number, customer, className, newTab, inList = true }) {
  if (!id) {
    return <span className={cx('text-[12.5px] text-muted', className)}>No order</span>
  }
  return (
    <a
      href={inList ? orderListUrl(id) : wooOrderUrl(id)}
      target={newTab || !inList ? '_blank' : undefined}
      rel={newTab || !inList ? 'noopener noreferrer' : undefined}
      className={cx('inline-flex items-center gap-1 text-[12.5px] font-medium text-accent hover:underline', className)}
      onClick={(e) => e.stopPropagation()}
    >
      #{number || id}
      {customer && <span className="font-normal text-muted">{customer}</span>}
    </a>
  )
}
