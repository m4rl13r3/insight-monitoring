# Third-party notices

## Font Awesome Free

Insight distributes Font Awesome Free 7.3.0 for its interface icons.

- Copyright 2026 Fonticons, Inc.
- Icons: Creative Commons Attribution 4.0 International.
- Fonts: SIL Open Font License 1.1.
- CSS code: MIT License.

The full text is distributed in `licenses/FONT-AWESOME-FREE.txt` and remains available at https://fontawesome.com/license/free.

## Prometheus Blackbox Exporter

The optional Docker profile references Prometheus Blackbox Exporter 0.28.0, distributed under the Apache 2.0 License. Its source code is not included in the Insight package; the image is pulled from `quay.io/prometheus/blackbox-exporter` when deploying the profile.

Project and license: https://github.com/prometheus/blackbox_exporter

## Direct JavaScript dependencies

| Package | Version | License |
| --- | --- | --- |
| `@tailwindcss/vite` | 4.3.2 | MIT |
| `@types/node` | 26.1.1 | MIT |
| `@types/react` | 19.2.17 | MIT |
| `@types/react-dom` | 19.2.3 | MIT |
| `@vitejs/plugin-react` | 6.0.3 | MIT |
| `chart.js` | 4.5.1 | MIT |
| `class-variance-authority` | 0.7.1 | Apache-2.0 |
| `clsx` | 2.1.1 | MIT |
| `radix-ui` | 1.6.2 | MIT |
| `react` | 19.2.7 | MIT |
| `react-dom` | 19.2.7 | MIT |
| `shadcn` | 4.13.0 | MIT |
| `tailwind-merge` | 3.6.0 | MIT |
| `tailwindcss` | 4.3.2 | MIT |
| `tw-animate-css` | 1.4.0 | MIT |
| `typescript` | 7.0.2 | Apache-2.0 |
| `vite` | 8.1.4 | MIT |

The corresponding license texts are stored in `licenses/npm`. Transitive dependencies and their license metadata remain reproducibly described in `package-lock.json`.

## Python MariaDB connector

Insight installs `PyMySQL` 1.2.0 from PyPI in the application image. It is distributed under the MIT License, and its license text is stored in `licenses/python/PyMySQL.txt`.

## Python notifications

| Package | Version | License | Purpose |
| --- | --- | --- | --- |
| `Apprise` | 1.12.0 | BSD-2-Clause | Gateway to more than 138 notification services |
| `PyNaCl` | 1.6.2 | Apache-2.0 | SecretBox encryption compatible with libsodium |
| `python-liquid` | 2.3.0 | MIT | Rendering custom alert messages |

The full texts are stored in `licenses/python/Apprise.txt`, `licenses/python/PyNaCl.txt`, and `licenses/python/python-liquid.txt`.
