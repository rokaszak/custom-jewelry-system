import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import postcss from 'postcss'

const SCOPE = '.cjs-app.cjs-scope'

function flattenLayers(root) {
  const order = []
  const buckets = new Map()
  const unlayered = []
  const addName = (name) => {
    if (!buckets.has(name)) {
      buckets.set(name, [])
      order.push(name)
    }
  }
  const inline = (node) => {
    const out = []
    node.each((child) => {
      if (child.type === 'atrule' && child.name === 'layer') {
        if (child.nodes) {
          out.push(...inline(child))
        }
      } else {
        out.push(child)
      }
    })
    return out
  }
  root.each((node) => {
    if (node.type === 'atrule' && node.name === 'layer') {
      if (!node.nodes) {
        node.params.split(',').map((s) => s.trim()).filter(Boolean).forEach(addName)
        return
      }
      const name = node.params.trim() || '__anon'
      addName(name)
      buckets.get(name).push(...inline(node))
    } else {
      unlayered.push(node)
    }
  })
  const rebuilt = []
  for (const name of order) {
    for (const n of buckets.get(name)) rebuilt.push(n)
  }
  rebuilt.push(...unlayered)
  root.removeAll()
  for (const n of rebuilt) {
    root.append(n.clone ? n.clone() : n)
  }
}

function scopeSelector(sel) {
  const s = sel.trim()
  if (!s) return null
  if (s.startsWith(':host')) return null
  if (s.startsWith('#') || /^body[.\[#:]/.test(s) || s.startsWith('.cjs-global')) return s
  if (s.startsWith(SCOPE)) return s
  if (s.startsWith('*') || s.startsWith('::')) return `:where(${SCOPE}) ${s}`
  const replaced = s.replace(/^(:root|html|body)(?=$|[\s.:\[>~+,])/, SCOPE)
  if (replaced !== s) return replaced
  return `${SCOPE} ${s}`
}

function scopeRules(root) {
  root.walkRules((rule) => {
    let p = rule.parent
    while (p) {
      if (p.type === 'atrule' && /keyframes$/i.test(p.name)) return
      p = p.parent
    }
    const next = []
    for (const sel of rule.selectors) {
      const scoped = scopeSelector(sel)
      if (scoped && !next.includes(scoped)) next.push(scoped)
    }
    if (next.length === 0) {
      rule.remove()
    } else {
      rule.selectors = next
    }
  })
}

function cjsScopeCss() {
  return {
    name: 'cjs-scope-css',
    enforce: 'post',
    generateBundle(_, bundle) {
      for (const file of Object.values(bundle)) {
        if (file.type === 'asset' && file.fileName.endsWith('.css')) {
          const root = postcss.parse(String(file.source))
          flattenLayers(root)
          scopeRules(root)
          file.source = root.toString()
        }
      }
    },
  }
}

export default defineConfig({
  base: './',
  plugins: [react(), tailwindcss(), cjsScopeCss()],
  build: {
    outDir: 'build',
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2020',
    chunkSizeWarningLimit: 1500,
    manifest: 'manifest.json',
    rollupOptions: {
      input: 'src/main.jsx',
      output: {
        entryFileNames: 'app-[hash].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (info) => (info.names && info.names.some((n) => n.endsWith('.css')) ? 'app-[hash].css' : 'assets/[name]-[hash][extname]'),
      },
    },
  },
})
