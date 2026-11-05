import {
  mdiArchiveOutline,
  mdiBeakerOutline,
  mdiBottleTonicOutline,
  mdiBrush,
  mdiCertificateOutline,
  mdiCircleOutline,
  mdiCrown,
  mdiCubeOutline,
  mdiDiamondOutline,
  mdiDiamondStone,
  mdiFileDocumentOutline,
  mdiFire,
  mdiGiftOutline,
  mdiGold,
  mdiHammer,
  mdiHexagonOutline,
  mdiKeyVariant,
  mdiLinkVariant,
  mdiNecklace,
  mdiPackageVariantClosed,
  mdiPalette,
  mdiPrinter3d,
  mdiRing,
  mdiRuler,
  mdiScaleBalance,
  mdiScrewdriver,
  mdiSeal,
  mdiShimmer,
  mdiStarFourPoints,
  mdiTagOutline,
  mdiTapeMeasure,
  mdiToolboxOutline,
  mdiTreasureChestOutline,
  mdiTruckOutline,
  mdiWatch,
  mdiWaterOutline,
  mdiWeightGram,
  mdiWrenchOutline,
} from '@mdi/js'
import { useCatalog } from '../lib/hooks'
import { cx } from './ui'

export const ICONS = [
  { key: 'diamond-stone', label: 'Diamond', path: mdiDiamondStone },
  { key: 'gold', label: 'Gold ingot', path: mdiGold },
  { key: 'gift', label: 'Gift box', path: mdiGiftOutline },
  { key: 'ring', label: 'Ring', path: mdiRing },
  { key: 'diamond-outline', label: 'Diamond outline', path: mdiDiamondOutline },
  { key: 'necklace', label: 'Necklace', path: mdiNecklace },
  { key: 'crown', label: 'Crown', path: mdiCrown },
  { key: 'watch', label: 'Watch', path: mdiWatch },
  { key: 'link', label: 'Chain', path: mdiLinkVariant },
  { key: 'sparkle', label: 'Sparkle', path: mdiStarFourPoints },
  { key: 'shimmer', label: 'Shimmer', path: mdiShimmer },
  { key: 'hexagon', label: 'Hexagon', path: mdiHexagonOutline },
  { key: 'circle', label: 'Circle', path: mdiCircleOutline },
  { key: 'package', label: 'Package', path: mdiPackageVariantClosed },
  { key: 'cube', label: 'Cube', path: mdiCubeOutline },
  { key: 'chest', label: 'Chest', path: mdiTreasureChestOutline },
  { key: 'archive', label: 'Archive', path: mdiArchiveOutline },
  { key: 'truck', label: 'Delivery', path: mdiTruckOutline },
  { key: 'tag', label: 'Tag', path: mdiTagOutline },
  { key: 'certificate', label: 'Certificate', path: mdiCertificateOutline },
  { key: 'document', label: 'Document', path: mdiFileDocumentOutline },
  { key: 'seal', label: 'Seal', path: mdiSeal },
  { key: 'key', label: 'Key', path: mdiKeyVariant },
  { key: 'hammer', label: 'Hammer', path: mdiHammer },
  { key: 'wrench', label: 'Wrench', path: mdiWrenchOutline },
  { key: 'screwdriver', label: 'Screwdriver', path: mdiScrewdriver },
  { key: 'toolbox', label: 'Toolbox', path: mdiToolboxOutline },
  { key: 'ruler', label: 'Ruler', path: mdiRuler },
  { key: 'tape-measure', label: 'Tape measure', path: mdiTapeMeasure },
  { key: 'scale', label: 'Scale', path: mdiScaleBalance },
  { key: 'weight', label: 'Weight', path: mdiWeightGram },
  { key: 'fire', label: 'Fire', path: mdiFire },
  { key: 'water', label: 'Liquid', path: mdiWaterOutline },
  { key: 'beaker', label: 'Chemicals', path: mdiBeakerOutline },
  { key: 'bottle', label: 'Bottle', path: mdiBottleTonicOutline },
  { key: 'printer-3d', label: '3D print', path: mdiPrinter3d },
  { key: 'brush', label: 'Brush', path: mdiBrush },
  { key: 'palette', label: 'Colours', path: mdiPalette },
]

const BY_KEY = Object.fromEntries(ICONS.map((i) => [i.key, i]))

export function Icon({ name, className, title }) {
  const icon = BY_KEY[name]
  if (!icon) return null
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={cx('inline-block shrink-0', className || 'size-4')} aria-hidden={title ? undefined : true} role={title ? 'img' : undefined}>
      {title && <title>{title}</title>}
      <path d={icon.path} />
    </svg>
  )
}

export function useCategoryIcon(categoryId) {
  const { categoryById } = useCatalog()
  return categoryById[categoryId]?.icon || ''
}

export function CategoryIcon({ categoryId, icon, className }) {
  const fromCatalog = useCategoryIcon(categoryId)
  const name = icon !== undefined ? icon : fromCatalog
  if (!name) return null
  return <Icon name={name} className={className} />
}

export function categoryOptions(categories) {
  return (categories || []).map((c) => ({ value: String(c.id), label: c.name, icon: c.icon ? <Icon name={c.icon} /> : null }))
}

export function IconPicker({ value, onChange }) {
  const cell = 'flex size-9 items-center justify-center border text-[11px] transition-colors'
  return (
    <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Icon">
      <button type="button" role="radio" aria-checked={!value} title="No icon" className={cx(cell, !value ? 'border-accent bg-accent-soft text-accent-soft-foreground' : 'border-separator text-muted hover:bg-surface-secondary')} onClick={() => onChange('')}>
        None
      </button>
      {ICONS.map((i) => (
        <button key={i.key} type="button" role="radio" aria-checked={value === i.key} title={i.label} aria-label={i.label} className={cx(cell, value === i.key ? 'border-accent bg-accent-soft text-accent-soft-foreground' : 'border-separator text-foreground hover:bg-surface-secondary')} onClick={() => onChange(i.key)}>
          <Icon name={i.key} className="size-5" />
        </button>
      ))}
    </div>
  )
}
