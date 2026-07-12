import { copyFile, mkdir } from "node:fs/promises"
import { dirname, resolve } from "node:path"
import { fileURLToPath } from "node:url"

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..")
const assets = resolve(root, "public/assets")

await mkdir(assets, { recursive: true })
await copyFile(
  resolve(root, "node_modules/chart.js/dist/chart.umd.js"),
  resolve(assets, "chart.umd.js"),
)
