import { readdir, readFile } from "node:fs/promises"
import { extname, join } from "node:path"
import { fileURLToPath } from "node:url"

const root = new URL("../", import.meta.url)
const localeDir = fileURLToPath(new URL("public/locales/", root))
const sourceRoots = [fileURLToPath(new URL("public/", root)), fileURLToPath(new URL("src/", root))]
const sourceExtensions = new Set([".html", ".js", ".php", ".ts", ".tsx"])

async function collectFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true })
  const files = []
  for (const entry of entries) {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) {
      if (entry.name !== "assets") {
        files.push(...await collectFiles(path))
      }
    } else if (sourceExtensions.has(extname(entry.name))) {
      files.push(path)
    }
  }
  return files
}

const localeFiles = (await readdir(localeDir)).filter((name) => name.endsWith(".json")).sort()
const catalogs = new Map()
for (const file of localeFiles) {
  const locale = file.replace(/\.json$/, "")
  const catalog = JSON.parse(await readFile(join(localeDir, file), "utf8"))
  catalogs.set(locale, new Set(Object.keys(catalog).filter((key) => key !== "_meta")))
}

const fallbackKeys = catalogs.get("fr")
if (!fallbackKeys) {
  throw new Error("The French fallback catalogue is missing.")
}

for (const [locale, keys] of catalogs) {
  const missing = [...fallbackKeys].filter((key) => !keys.has(key))
  const extra = [...keys].filter((key) => !fallbackKeys.has(key))
  if (missing.length || extra.length) {
    throw new Error(`${locale}: missing keys [${missing.join(", ")}], extra keys [${extra.join(", ")}]`)
  }
}

const referencedKeys = new Set()
for (const sourceRoot of sourceRoots) {
  for (const file of await collectFiles(sourceRoot)) {
    const content = await readFile(file, "utf8")
    const patterns = [
      /\binsightT\(\s*["']([^"']+)["']/g,
      /\bt\(\s*["']([^"']+)["']/g,
      /data-i18n(?:-aria-label|-title|-placeholder|-description)?=["']([^"']+)["']/g,
    ]
    for (const pattern of patterns) {
      for (const match of content.matchAll(pattern)) {
        if (!match[1].includes("<?")) {
          referencedKeys.add(match[1])
        }
      }
    }
  }
}

const missingReferences = [...referencedKeys].filter((key) => {
  return !fallbackKeys.has(key) && !(fallbackKeys.has(`${key}.one`) && fallbackKeys.has(`${key}.other`))
})
if (missingReferences.length) {
  throw new Error(`Missing i18n keys: ${missingReferences.sort().join(", ")}`)
}

console.log(`${catalogs.size} catalogues, ${fallbackKeys.size} keys, and ${referencedKeys.size} references validated.`)
