import './styles.css'
import { StrictMode, Suspense, lazy, startTransition, useEffect, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { I18nProvider } from '@heroui/react'
import ToastHost from './components/ToastHost'
import { UNSAFE_PortalProvider } from 'react-aria'
import { ConfirmProvider, Loading } from './components/ui'
import { StockModalProvider } from './components/StockModal'
import { currentView, interceptMenu, onViewChange } from './lib/nav'

const VIEWS = {
  orders: lazy(() => import('./pages/orders/OrdersPage')),
  inventory: lazy(() => import('./pages/inventory/InventoryPage')),
  required: lazy(() => import('./pages/required/RequiredPage')),
  'stock-orders': lazy(() => import('./pages/stock-orders/StockOrdersPage')),
  discrepancies: lazy(() => import('./pages/discrepancies/DiscrepanciesPage')),
  calendar: lazy(() => import('./pages/calendar/CalendarPage')),
  timeline: lazy(() => import('./pages/timeline/TimelinePage')),
  settings: lazy(() => import('./pages/settings/SettingsPage')),
}

const OrderScreen = lazy(() => import('./pages/orders/OrderScreen'))
const WooListGuard = lazy(() => import('./pages/orders/WooGuards').then((m) => ({ default: m.WooListGuard })))

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 20_000, retry: 1, refetchOnWindowFocus: true },
    mutations: { retry: 0 },
  },
})

let portal = null
function portalContainer() {
  if (!portal) {
    portal = document.createElement('div')
    portal.className = 'cjs-app cjs-scope light cjs-portal'
    portal.setAttribute('data-theme', 'light')
    document.body.appendChild(portal)
  }
  return portal
}

function Providers({ children }) {
  return (
    <QueryClientProvider client={queryClient}>
      <I18nProvider locale="en-GB">
        <UNSAFE_PortalProvider getContainer={portalContainer}>
          <ConfirmProvider>
            <StockModalProvider>
              {children}
              <ToastHost container={portalContainer} />
            </StockModalProvider>
          </ConfirmProvider>
        </UNSAFE_PortalProvider>
      </I18nProvider>
    </QueryClientProvider>
  )
}

function App() {
  const [view, setView] = useState(currentView())
  useEffect(() => onViewChange((v) => startTransition(() => setView(v))), [])
  const View = VIEWS[view] || VIEWS.orders
  return (
    <Suspense fallback={<Loading />}>
      <View key={view} />
    </Suspense>
  )
}

const root = document.getElementById('cjs-root')
if (root) {
  interceptMenu()
  createRoot(root).render(
    <StrictMode>
      <Providers>
        <App />
      </Providers>
    </StrictMode>,
  )
}

const orderRoot = document.getElementById('cjs-order-root')
if (orderRoot) {
  const orderId = Number(orderRoot.getAttribute('data-order-id')) || 0
  createRoot(orderRoot).render(
    <StrictMode>
      <Providers>
        <Suspense fallback={<Loading />}>
          <OrderScreen orderId={orderId} />
        </Suspense>
      </Providers>
    </StrictMode>,
  )
}

if (!root && !orderRoot && window.CJS_BOOT && window.CJS_BOOT.view === 'woo-orders') {
  const listRoot = document.createElement('div')
  listRoot.className = 'cjs-app cjs-scope light'
  listRoot.setAttribute('data-theme', 'light')
  document.body.appendChild(listRoot)
  createRoot(listRoot).render(
    <StrictMode>
      <Providers>
        <Suspense fallback={null}>
          <WooListGuard />
        </Suspense>
      </Providers>
    </StrictMode>,
  )
}
