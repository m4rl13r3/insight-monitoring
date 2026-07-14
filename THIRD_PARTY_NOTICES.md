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
| `dnspython` | 2.8.0 | ISC | DNS record resolution |

The full texts are stored in `licenses/python/Apprise.txt`, `licenses/python/PyNaCl.txt`, `licenses/python/python-liquid.txt`, and `licenses/python/dnspython.txt`.

## Python probe and configuration runtimes

| Package | Version | License | Purpose |
| --- | --- | --- | --- |
| `playwright` | 1.61.0 | Apache-2.0 | Declarative Chromium probes |
| `websocket-client` | 1.9.0 | Apache-2.0 | WebSocket probes |
| `paho-mqtt` | 2.1.0 | EDL-1.0 | MQTT probes |
| `psycopg` and `psycopg-binary` | 3.3.4 | LGPL-3.0-only | PostgreSQL probes |
| `docker` | 7.2.0 | Apache-2.0 | Docker Engine probes |
| `grpcio` and `grpcio-health-checking` | 1.82.1 | Apache-2.0 | gRPC Health Checking probes |
| `redis` | 8.0.1 | MIT | Redis PING probes |
| `pika` | 1.4.1 | BSD-3-Clause | RabbitMQ AMQP probes |
| `PyYAML` | 6.0.3 | MIT | Declarative configuration files |

The Apache 2.0 text is stored in `licenses/python/PyNaCl.txt`. The additional texts are stored in `licenses/python/paho-mqtt.txt`, `licenses/python/psycopg.txt`, `licenses/python/PyYAML.txt`, `licenses/python/redis.txt`, and `licenses/python/pika.txt`.
