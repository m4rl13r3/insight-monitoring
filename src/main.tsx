import { StrictMode } from "react"
import { createRoot } from "react-dom/client"

import { ModeToggle } from "@/components/mode-toggle"
import { LocaleToggle } from "@/components/locale-toggle"
import { ThemeProvider } from "@/components/theme-provider"
import { mountAdminSelects } from "@/components/admin-selects"
import "@/index.css"

const root = document.getElementById("insight-controls-root")

function renderControls() {
  if (root) {
    createRoot(root).render(
      <StrictMode>
        <ThemeProvider defaultTheme="system" storageKey="insight-ui-theme">
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
