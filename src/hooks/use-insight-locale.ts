import { useEffect, useState } from "react"

export type InsightLocaleOption = {
  code: string
  label: string
}

type InsightI18nEngine = {
  getLocale: () => string
  getLocales: () => InsightLocaleOption[]
  ready: Promise<string>
  setLocale: (locale: string) => boolean
  t: (key: string, variables?: Record<string, string | number>) => string
}

declare global {
  interface Window {
    InsightI18n?: InsightI18nEngine
  }
}

export function useInsightLocale() {
  const engine = window.InsightI18n
  const [locale, setLocaleState] = useState(() => engine?.getLocale() || "fr")

  useEffect(() => {
    const handleLocaleChange = (event: Event) => {
      const detail = (event as CustomEvent<{ locale?: string }>).detail
      setLocaleState(detail?.locale || engine?.getLocale() || "fr")
    }
    window.addEventListener("insight:locale-changed", handleLocaleChange)
    return () => window.removeEventListener("insight:locale-changed", handleLocaleChange)
  }, [engine])

  return {
    locale,
    locales: engine?.getLocales() || [],
    setLocale: (nextLocale: string) => engine?.setLocale(nextLocale),
    t: (key: string, variables?: Record<string, string | number>) => engine?.t(key, variables) || key,
  }
}
