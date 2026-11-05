import { Button } from '@heroui/react'
import { Plus, ArrowDownToLine, ListUl, ClockArrowRotateLeft, Layers, BarsDescendingAlignLeft } from '@gravity-ui/icons'
import { useState } from 'react'
import { useUrlParam } from '../../lib/hooks'
import { Page, TabBar } from '../../components/ui'
import ItemDialog from '../../components/ItemDialog'
import ItemsList from './ItemsList'
import ItemDetail, { ItemDetailModal } from './ItemDetail'
import ReceiveForm from './ReceiveForm'
import CountForm from './CountForm'
import HistoryTab from './HistoryTab'
import CategoriesEditor from './CategoriesEditor'

const TABS = [
  { id: 'items', label: 'Items', icon: ListUl },
  { id: 'receive', label: 'Receive stock', icon: ArrowDownToLine },
  { id: 'count', label: 'Stock count', icon: BarsDescendingAlignLeft },
  { id: 'history', label: 'History', icon: ClockArrowRotateLeft },
  { id: 'categories', label: 'Categories & fields', icon: Layers },
]

export default function InventoryPage() {
  const [tab, setTab] = useUrlParam('tab', 'items')
  const [itemId, setItemId] = useUrlParam('item', '')
  const [cat] = useUrlParam('cat', '')
  const [creating, setCreating] = useState(false)
  const [openId, setOpenId] = useState(null)

  if (itemId) {
    return <ItemDetail itemId={Number(itemId)} onBack={() => setItemId('')} />
  }

  return (
    <Page
      title="Everything you stock: stones, metals, packaging, sizing kits"
      actions={
        <Button variant="primary" onPress={() => setCreating(true)}>
          <Plus className="size-4" />
          New item
        </Button>
      }
    >
      <div className="mb-4">
        <TabBar value={tab} onChange={(t) => setTab(t)} items={TABS} />
      </div>
      {tab === 'items' && <ItemsList onOpen={(id) => setOpenId(id)} />}
      {tab === 'receive' && <ReceiveForm onDone={() => setTab('history')} />}
      {tab === 'count' && <CountForm onDone={() => setTab('history')} />}
      {tab === 'history' && <HistoryTab onOpenItem={(id) => setOpenId(id)} />}
      {tab === 'categories' && <CategoriesEditor />}
      <ItemDialog isOpen={creating} defaultCategoryId={tab === 'items' && cat ? Number(cat) : undefined} onClose={() => setCreating(false)} onSaved={(it) => it && setOpenId(it.id)} />
      <ItemDetailModal itemId={openId} isOpen={!!openId} onClose={() => setOpenId(null)} />
    </Page>
  )
}
