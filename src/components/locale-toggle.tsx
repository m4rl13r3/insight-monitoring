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

export function LocaleToggle() {
  const { locale, locales, setLocale, t } = useInsightLocale()
  const changeLabel = t("language.change")

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          aria-label={changeLabel}
          className="insight-locale-trigger"
          size="icon"
          title={changeLabel}
          variant="outline"
        >
          <i aria-hidden="true" className="fa-solid fa-language" />
          <span className="sr-only">{changeLabel}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="insight-theme-menu">
        <DropdownMenuLabel>{t("language.title")}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {locales.map(({ code, label }) => (
          <DropdownMenuItem key={code} onSelect={() => setLocale(code)}>
            <span>{label}</span>
            {locale === code ? <i aria-hidden="true" className="fa-solid fa-check ml-auto" /> : null}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
