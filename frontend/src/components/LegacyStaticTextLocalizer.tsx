import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { textTranslationKey } from '../lib/i18n'

const translatedAttributes = ['aria-label', 'placeholder', 'title'] as const

/**
 * The older feature screens intentionally keep their existing JSX markup. This
 * observer localizes only exact, catalogued UI strings in that legacy markup;
 * arbitrary business values and API payloads are never sent through i18n.
 */
export function LegacyStaticTextLocalizer() {
  const { t, i18n } = useTranslation()

  useEffect(() => {
    const originalText = new WeakMap<Text, string>()
    const lastTranslatedText = new WeakMap<Text, string>()
    const originalAttributes = new WeakMap<Element, Map<string, string>>()
    const lastTranslatedAttributes = new WeakMap<Element, Map<string, string>>()

    const translate = (value: string) => t(textTranslationKey(value), { defaultValue: value })

    const translateTextNode = (node: Text) => {
      const value = node.nodeValue || ''
      const previous = lastTranslatedText.get(node)
      const source = previous !== undefined && previous === value ? originalText.get(node) || value : value
      originalText.set(node, source)
      const next = translate(source)
      if (next !== value) node.nodeValue = next
      lastTranslatedText.set(node, next)
    }

    const translateElement = (element: Element) => {
      const originals = originalAttributes.get(element) || new Map<string, string>()
      const translated = lastTranslatedAttributes.get(element) || new Map<string, string>()
      for (const attribute of translatedAttributes) {
        const value = element.getAttribute(attribute)
        if (value === null) continue
        const previous = translated.get(attribute)
        const source = previous !== undefined && previous === value ? originals.get(attribute) || value : value
        originals.set(attribute, source)
        const next = translate(source)
        if (next !== value) element.setAttribute(attribute, next)
        translated.set(attribute, next)
      }
      originalAttributes.set(element, originals)
      lastTranslatedAttributes.set(element, translated)
    }

    const scan = (root: Node) => {
      if (root.nodeType === Node.TEXT_NODE) {
        translateTextNode(root as Text)
        return
      }
      if (root.nodeType !== Node.ELEMENT_NODE) return
      const element = root as Element
      if (['SCRIPT', 'STYLE', 'PRE', 'TEXTAREA'].includes(element.tagName)) return
      translateElement(element)
      const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT)
      let current: Node | null = walker.nextNode()
      while (current) {
        if (current.nodeType === Node.TEXT_NODE) translateTextNode(current as Text)
        else if (current.nodeType === Node.ELEMENT_NODE) {
          const child = current as Element
          if (!['SCRIPT', 'STYLE', 'PRE', 'TEXTAREA'].includes(child.tagName)) translateElement(child)
        }
        current = walker.nextNode()
      }
    }

    scan(document.body)
    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        if (mutation.type === 'childList') mutation.addedNodes.forEach(scan)
        if (mutation.type === 'attributes') translateElement(mutation.target as Element)
      }
    })
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: [...translatedAttributes] })
    return () => observer.disconnect()
  }, [i18n.language, t])

  return null
}
