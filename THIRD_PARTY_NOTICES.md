# Third-party notices

## Font Awesome Free

Insight distribue Font Awesome Free 7.3.0 pour ses icônes d’interface.

- Copyright 2026 Fonticons, Inc.
- Icônes : Creative Commons Attribution 4.0 International.
- Fontes : SIL Open Font License 1.1.
- Code CSS : licence MIT.

Le texte complet est distribué dans `licenses/FONT-AWESOME-FREE.txt` et reste également disponible sur https://fontawesome.com/license/free.

## Prometheus Blackbox Exporter

Le profil Docker facultatif référence Prometheus Blackbox Exporter 0.28.0, distribué sous licence Apache 2.0. Son code n’est pas inclus dans le paquet Insight et l’image est récupérée depuis `quay.io/prometheus/blackbox-exporter` lors du déploiement du profil.

Projet et licence : https://github.com/prometheus/blackbox_exporter

## Dépendances JavaScript directes

| Paquet | Version | Licence |
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

Les textes correspondants sont conservés dans `licenses/npm`. Les dépendances transitives et leurs métadonnées de licence restent décrites de manière reproductible dans `package-lock.json`.

## Connecteur MariaDB Python

Insight installe `PyMySQL` 1.2.0, distribué sous licence MIT, depuis PyPI dans l’image applicative. Son texte de licence est conservé dans `licenses/python/PyMySQL.txt`.

## Notifications Python

| Paquet | Version | Licence | Rôle |
| --- | --- | --- | --- |
| `Apprise` | 1.12.0 | BSD-2-Clause | Passerelle vers plus de 138 services de notification |
| `PyNaCl` | 1.6.2 | Apache-2.0 | Chiffrement SecretBox compatible avec libsodium |
| `python-liquid` | 2.3.0 | MIT | Rendu des messages d’alerte personnalisés |

Les textes complets sont conservés dans `licenses/python/Apprise.txt`, `licenses/python/PyNaCl.txt` et `licenses/python/python-liquid.txt`.
