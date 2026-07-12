import { useEffect, useState } from "react"
import { createRoot } from "react-dom/client"

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

type NativeOption = {
  disabled: boolean
  label: string
  value: string
}

function readOptions(select: HTMLSelectElement): NativeOption[] {
  return Array.from(select.options)
    .filter((option) => option.value !== "")
    .map((option) => ({
      disabled: option.disabled,
      label: option.textContent?.trim() || option.label || option.value,
      value: option.value,
    }))
}

function readLabel(select: HTMLSelectElement): string {
  const explicit = select.getAttribute("aria-label")
  if (explicit) {
    return explicit
  }
  const label = select.closest("label")
  const heading = label?.querySelector(":scope > span:first-child")
  return heading?.textContent?.trim() || select.name || "Select"
}

function AdminSelect({ nativeSelect }: { nativeSelect: HTMLSelectElement }) {
  const [options, setOptions] = useState(() => readOptions(nativeSelect))
  const [value, setValue] = useState(() => nativeSelect.value)
  const [disabled, setDisabled] = useState(() => nativeSelect.disabled)
  const [label, setLabel] = useState(() => readLabel(nativeSelect))

  useEffect(() => {
    const sync = () => {
      const nextOptions = readOptions(nativeSelect)
      setOptions((current) => {
        const currentSignature = JSON.stringify(current)
        const nextSignature = JSON.stringify(nextOptions)
        return currentSignature === nextSignature ? current : nextOptions
      })
      setValue((current) => current === nativeSelect.value ? current : nativeSelect.value)
      setDisabled(nativeSelect.disabled)
      setLabel(readLabel(nativeSelect))
    }
    const observer = new MutationObserver(sync)
    observer.observe(nativeSelect, {
      attributes: true,
      characterData: true,
      childList: true,
      subtree: true,
    })
    const form = nativeSelect.form
    const handleReset = () => window.setTimeout(sync, 0)
    const interval = window.setInterval(sync, 180)
    nativeSelect.addEventListener("change", sync)
    nativeSelect.addEventListener("input", sync)
    form?.addEventListener("reset", handleReset)
    window.addEventListener("insight:locale-changed", sync)
    return () => {
      observer.disconnect()
      window.clearInterval(interval)
      nativeSelect.removeEventListener("change", sync)
      nativeSelect.removeEventListener("input", sync)
      form?.removeEventListener("reset", handleReset)
      window.removeEventListener("insight:locale-changed", sync)
    }
  }, [nativeSelect])

  function updateValue(nextValue: string) {
    nativeSelect.value = nextValue
    setValue(nextValue)
    nativeSelect.dispatchEvent(new Event("input", { bubbles: true }))
    nativeSelect.dispatchEvent(new Event("change", { bubbles: true }))
  }

  return (
    <Select value={value || undefined} onValueChange={updateValue} disabled={disabled}>
      <SelectTrigger
        aria-label={label}
        className="insight-admin-select-trigger h-10 w-full min-w-0 border-0 bg-transparent px-0 py-0 text-foreground shadow-none focus-visible:ring-0 dark:bg-transparent dark:hover:bg-transparent"
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent
        position="popper"
        align="start"
        portalContainer={nativeSelect.closest("dialog")}
        className="insight-admin-select-content z-[220] w-[var(--radix-select-trigger-width)] min-w-[var(--radix-select-trigger-width)]"
      >
        {options.map((option) => (
          <SelectItem
            key={option.value}
            value={option.value}
            disabled={option.disabled}
            className="insight-admin-select-item"
          >
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

const mountedSelects = new WeakSet<HTMLSelectElement>()

function mountSelect(select: HTMLSelectElement) {
  if (
    mountedSelects.has(select)
    || select.multiple
    || select.size > 1
    || select.closest(".insight-admin-select-root")
  ) {
    return
  }
  mountedSelects.add(select)
  select.classList.add("insight-native-select")
  select.setAttribute("aria-hidden", "true")
  select.tabIndex = -1
  const host = document.createElement("span")
  host.className = "insight-admin-select-root"
  select.insertAdjacentElement("afterend", host)
  createRoot(host).render(<AdminSelect nativeSelect={select} />)
}

export function mountAdminSelects() {
  if (!document.body.classList.contains("admin-page")) {
    return
  }
  document.querySelectorAll<HTMLSelectElement>("select").forEach(mountSelect)
  const observer = new MutationObserver((records) => {
    records.forEach((record) => {
      record.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) {
          return
        }
        if (node instanceof HTMLSelectElement) {
          mountSelect(node)
        }
        node.querySelectorAll<HTMLSelectElement>("select").forEach(mountSelect)
      })
    })
  })
  observer.observe(document.body, { childList: true, subtree: true })
}
