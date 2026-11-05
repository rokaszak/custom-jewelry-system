import { useBootstrap } from '../../lib/hooks'
import { Field, Loading, Notice, Section, cx } from '../../components/ui'
import { OrderActionsProvider, LOCK_TEXT } from './orderActions'
import { DatesEditor, HoursEditor, FlagsEditor, StatusSelect, TypeSelect, CastingNotes } from './OrderFields'
import OrderPanel, { OrderBadges, useOrderPanel } from './OrderPanel'
import { WooEditGuard } from './WooGuards'

function ProductionCard({ order, statuses, types }) {
  return (
    <div className="flex flex-col gap-3">
    {order.locked && <Notice tone="info">{LOCK_TEXT}</Notice>}
    <div inert={order.locked || undefined} className={cx('grid gap-4 @xl:grid-cols-2 @3xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.3fr)]', order.locked && 'opacity-70')}>
      <Section title="Terminai" className="min-w-0">
        <DatesEditor order={order} />
      </Section>
      <Section title="Gamyba" className="min-w-0">
        <div className="flex flex-col gap-2.5">
          <Field label="Statusas">
            <StatusSelect order={order} statuses={statuses} />
          </Field>
          <Field label="Užsakymo tipas">
            <TypeSelect order={order} types={types} />
          </Field>
          <Field label="Darbas (h): padaryta / paskirta">
            <HoursEditor order={order} />
          </Field>
          <div className="flex flex-col gap-1.5">
            <span className="text-[12px] font-medium text-foreground/80">Užsakyta</span>
            <FlagsEditor order={order} vertical />
          </div>
        </div>
      </Section>
      <Section title="Liejimas" className="min-w-0 @xl:col-span-2 @3xl:col-span-1">
        <CastingNotes order={order} rows={5} />
        <OrderBadges order={order} />
      </Section>
    </div>
    </div>
  )
}

export default function OrderScreen({ orderId }) {
  return (
    <>
      <WooEditGuard orderId={orderId} />
      <OrderScreenBody orderId={orderId} />
    </>
  )
}

function OrderScreenBody({ orderId }) {
  const boot = useBootstrap()
  const q = useOrderPanel(orderId)
  if (!orderId) {
    return <div className="p-4 text-[13px] text-muted">Save the order first to manage production and inventory.</div>
  }
  if (q.isLoading || boot.isLoading) return <Loading />
  if (q.isError) return <Notice tone="danger" className="m-3">{q.error.message}</Notice>
  return (
    <OrderActionsProvider onCompleted={() => window.location.reload()}>
      <div className="@container flex flex-col gap-5 p-3 sm:p-4">
        <ProductionCard order={q.data} statuses={boot.data?.statuses || []} types={boot.data?.order_types || []} />
        <div className="border-t border-separator pt-4">
          <OrderPanel orderId={orderId} embedded />
        </div>
      </div>
    </OrderActionsProvider>
  )
}
