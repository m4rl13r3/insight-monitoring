import { StrictMode } from "react"
import { createRoot } from "react-dom/client"

import { ModeToggle } from "@/components/mode-toggle"
import { LocaleToggle } from "@/components/locale-toggle"
import { ThemeProvider } from "@/components/theme-provider"
import { mountAdminSelects } from "@/components/admin-selects"
import "@/index.css"

const root = document.getElementById("insight-controls-root")

function configuredTheme() {
  const value = String(window.INSIGHT_CONFIG?.statusPageTheme || "system")
  return value === "light" || value === "dark" ? value : "system"
}

function configuredThemeStorageKey() {
  return String(window.INSIGHT_CONFIG?.statusPageThemeStorageKey || "insight-ui-theme")
}

function renderControls() {
  if (root) {
    createRoot(root).render(
      <StrictMode>
        <ThemeProvider defaultTheme={configuredTheme()} storageKey={configuredThemeStorageKey()}>
          <LocaleToggle />
          <ModeToggle />
        </ThemeProvider>
      </StrictMode>,
    )
  }
  mountAdminSelects()
}

if (window.InsightI18n?.ready) {
  window.InsightI18n.ready.finally(renderControls)
} else {
  renderControls()
}
