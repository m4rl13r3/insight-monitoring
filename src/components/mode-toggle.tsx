import { useTheme, type Theme } from "@/components/theme-provider"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { useInsightLocale } from "@/hooks/use-insight-locale"

const themes: Array<{ icon: string; labelKey: string; value: Theme }> = [
  { icon: "fa-sun", labelKey: "theme.light", value: "light" },
  { icon: "fa-moon", labelKey: "theme.dark", value: "dark" },
  { icon: "fa-desktop", labelKey: "theme.system", value: "system" },
]

export function ModeToggle() {
  const { resolvedTheme, setTheme, theme } = useTheme()
  const { t } = useInsightLocale()
  const activeIcon = resolvedTheme === "dark" ? "fa-moon" : "fa-sun"
  const changeLabel = t("theme.change")

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          aria-label={changeLabel}
          className="insight-theme-trigger"
          size="icon"
          title={changeLabel}
          variant="outline"
        >
          <i aria-hidden="true" className={`fa-solid ${activeIcon}`} />
          <span className="sr-only">{changeLabel}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="insight-theme-menu">
        <DropdownMenuLabel>{t("theme.appearance")}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {themes.map(({ icon, labelKey, value }) => (
          <DropdownMenuItem key={value} onSelect={() => setTheme(value)}>
            <i aria-hidden="true" className={`fa-solid ${icon}`} />
            <span>{t(labelKey)}</span>
            {theme === value ? <i aria-hidden="true" className="fa-solid fa-check ml-auto" /> : null}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
