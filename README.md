# Insight

Insight est une page de statut open source auto-hébergée avec supervision HTTP, ICMP et TCP, historique de disponibilité, incidents, maintenances planifiées, suivi TLS et dashboard protégé. Le moteur de supervision est écrit en Python. Insight peut aussi agréger des sondes provenant d’un, deux, trois ou autant de serveurs distants que nécessaire.

L’interface publique est disponible en français et en anglais, détecte la langue du navigateur et ne nécessite aucun service privé. Le déploiement de référence utilise Docker Compose avec Nginx, PHP-FPM, un worker et MariaDB.

L’application est rendue en PHP et JavaScript. React gère uniquement les contrôles de langue et de thème. Le build Vite utilise les conventions shadcn et Tailwind comme compilateur CSS ; le navigateur ne charge ensuite que les ressources statiques locales produites dans `public/assets`.

Les icônes Font Awesome Free utilisées par l’interface sont embarquées dans `public/assets` sous forme de fontes WOFF2 locales. Leur notice et leur licence complète sont conservées dans `THIRD_PARTY_NOTICES.md` et `licenses/FONT-AWESOME-FREE.txt`.

## Démarrage rapide

Prérequis : Docker avec le module Compose et OpenSSL.

```bash
./scripts/install.sh
```

Le script crée `.env`, génère les mots de passe MariaDB et le secret maître des agents, construit les images puis démarre Insight. Compose refuse les mots de passe vides.

Pour une configuration manuelle, copiez `.env.example`, renseignez `INSIGHT_DB_PASSWORD`, `INSIGHT_DB_ROOT_PASSWORD` et `INSIGHT_NOTIFICATION_ENCRYPTION_KEY` avec le résultat de `openssl rand -hex 32`, puis exécutez `docker compose up -d --build`.

Insight est ensuite disponible sur `http://localhost:8080`.

Pour une instance publique, ne conservez pas les valeurs locales. Le guide [Mise en production](docs/production.md) couvre HTTPS, le premier compte, les vraies sondes, le test des alertes, les agents distants et le contrôle final avec `./scripts/production-check.sh --strict`.

Ouvrez `http://localhost:8080/admin/` pour créer le premier compte administrateur. Comme Uptime Kuma, Insight utilise un compte propre à l’instance : aucun fournisseur d’identité ni serveur externe n’est requis. Un fournisseur OpenID Connect externe peut ensuite être activé sans supprimer cet accès local de secours. Les comptes, sessions, jetons et clés d’identité sont conservés dans le volume privé `insight_auth`, séparé des données de supervision MariaDB.

Ajoutez un premier site :

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py actions add --site-url https://example.com --probe-type http
```

Pour surveiller uniquement la disponibilité d’un serveur, utilisez ICMP ou un port TCP :

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py actions add --site-url server.example.com --probe-type icmp
docker compose exec worker python3 monitoring/python_monitoring/cli.py actions add --site-url server.example.com:22 --probe-type tcp
```

Insight enregistre alors seulement l’état en ligne ou hors ligne, la latence et l’heure du dernier contrôle. Aucun agent de métriques système n’est requis.

Les mêmes actions sont disponibles dans le dashboard : **Moniteurs → Nouvelle sonde** pour HTTP/HTTPS et **Serveurs → Ajouter un serveur** pour ICMP ou TCP. Chaque sonde peut ensuite être modifiée ou supprimée. En mode développement sans MariaDB, les cibles créées sont conservées localement dans le dossier `data/`, déjà exclu du paquet publié.

Les alertes se configurent dans **Alertes**. Insight fournit directement SMTP, webhook HTTP et Free Mobile, puis s’appuie sur Apprise pour Discord, Telegram, Slack, Teams, ntfy, Gotify, PagerDuty, Opsgenie, Matrix, Signal et plus de 138 services. Chaque canal choisit les événements reçus et possède une action de test. Les titres et messages sont modifiables avec des variables Liquid.

Affichez les sites configurés :

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py actions list
```

Le schéma SQL est importé automatiquement au premier démarrage de MariaDB. Pour réinitialiser complètement une instance de développement :

```bash
docker compose down -v
docker compose up -d --build
```

Cette commande supprime toutes les données locales.

## Services Docker

- `web` sert les fichiers publics avec Nginx et transmet les scripts PHP à PHP-FPM.
- `php` exécute la page publique, les API, l’authentification locale SQLite et le dashboard.
- `worker` lance les sondes selon `INSIGHT_MONITOR_INTERVAL_SEC`, puis les agrégations horaires et quotidiennes.
- `db` stocke les sites, sondes, statistiques, incidents et maintenances dans MariaDB.

## Sauvegarde et restauration

Créez une archive cohérente de MariaDB, des comptes locaux, des clients API et de la clé privée OIDC :

```bash
./scripts/backup.sh
```

L’archive et son empreinte SHA-256 sont écrites dans `backups/`, dossier exclu de Git. Le fichier `.env` n’est jamais inclus : conservez-le séparément dans un coffre à secrets, car il contient notamment la clé qui protège les canaux d’alerte.

Pour restaurer une archive, placez d’abord le bon `.env`, démarrez la pile, puis confirmez explicitement l’opération :

```bash
INSIGHT_RESTORE_CONFIRM=1 ./scripts/restore.sh backups/insight-AAAAmmjjTHHMMSSZ.tar.gz
```

Le script vérifie l’empreinte lorsqu’elle est disponible, crée une sauvegarde de sécurité, suspend le worker et le web, restaure les deux bases, contrôle leur intégrité puis redémarre la pile. Les sessions de dashboard sont invalidées après restauration. Pour un fichier Compose ou un nom de projet particulier, utilisez `INSIGHT_COMPOSE_ENV_FILE` et `INSIGHT_COMPOSE_PROJECT_NAME`.

Pour automatiser la sauvegarde, utilisez `scripts/backup-scheduled.sh`. La durée de conservation locale se règle avec `INSIGHT_BACKUP_RETENTION_DAYS` et une copie distante optionnelle avec `INSIGHT_BACKUP_RCLONE_DEST`.

## Monitoring distribué

Le mode `standalone` par défaut exécute les sondes depuis le worker. Le mode `hub` reçoit les observations d’agents indépendants, calcule un quorum par cible et publie uniquement le consensus. Chaque agent possède une file SQLite persistante et peut utiliser les sondes natives ou Prometheus Blackbox Exporter.

Configuration minimale du hub :

```dotenv
INSIGHT_DISTRIBUTED_MODE=hub
INSIGHT_AGENT_MASTER_SECRET=remplacez_par_le_resultat_de_openssl_rand_hex_32
INSIGHT_AGENT_REQUIRE_HTTPS=1
```

Générez ensuite un secret par agent et déployez l’image dédiée :

```bash
docker compose exec php php scripts/agent-key.php paris-1
cp .env.agent.example .env.agent
docker compose --env-file .env.agent -f docker-compose.agent.yml up -d --build
```

Le dashboard affiche les agents, régions, affectations, réponses manquantes et la confiance du consensus. Le guide [Monitoring distribué](docs/distributed-monitoring.md) détaille les scénarios 1, 2, 3 et N serveurs, les quorums, Blackbox Exporter, Prometheus et la rotation des secrets.

## Configuration

Copiez `.env.example` vers `.env`, puis remplacez au minimum les mots de passe de base de données. Ne publiez jamais le fichier `.env`.

Variables principales :

| Variable | Valeur par défaut | Rôle |
| --- | --- | --- |
| `INSIGHT_APP_NAME` | `Insight` | Nom affiché publiquement |
| `INSIGHT_PUBLIC_URL` | `http://localhost:8080` | URL canonique de l’instance |
| `INSIGHT_CONTACT_EMAIL` | `contact@example.com` | Contact affiché sur la page |
| `INSIGHT_TIMEZONE` | `Europe/Paris` | Fuseau du service |
| `INSIGHT_DEFAULT_LOCALE` | `auto` | Langue initiale ou détection du navigateur |
| `INSIGHT_SUPPORTED_LOCALES` | `fr,en` | Catalogues proposés, séparés par des virgules |
| `INSIGHT_APP_ENV` | `production` | Environnement actif |
| `INSIGHT_DEV_AUTH_BYPASS` | `0` | Contournement local de l’authentification en développement |
| `INSIGHT_AUTH_DB_PATH` | `/var/lib/insight-auth/auth.sqlite` | Base privée des comptes locaux |
| `INSIGHT_AUTH_SESSION_TTL_SEC` | `43200` | Durée d’inactivité d’une session standard |
| `INSIGHT_AUTH_REMEMBER_TTL_SEC` | `2592000` | Durée d’une session conservée |
| `INSIGHT_AUTH_MAX_ATTEMPTS` | `5` | Échecs admis dans la fenêtre de connexion |
| `INSIGHT_AUTH_WINDOW_SEC` | `900` | Fenêtre de limitation des connexions |
| `INSIGHT_AUTH_COOKIE_SECURE` | `auto` | Cookie sécurisé détecté depuis HTTPS |
| `INSIGHT_AUTH_COOKIE_SAMESITE` | `Lax` | Compatible avec le retour OIDC, `Strict` sans SSO externe |
| `INSIGHT_API_ALLOWED_ORIGINS` | URL locale | Origines autorisées pour l’API headless |
| `INSIGHT_SSO_ENABLED` | `0` | Active la connexion du dashboard par OIDC externe |
| `INSIGHT_SSO_ISSUER_URL` | vide | Issuer exact du fournisseur d’identité |
| `INSIGHT_SSO_ALLOWED_ENDPOINT_HOSTS` | hôte de l’issuer | Hôtes OIDC supplémentaires explicitement autorisés |
| `INSIGHT_SSO_CLIENT_ID` | vide | Identifiant du client OIDC Insight |
| `INSIGHT_SSO_ALLOWED_EMAILS` | vide | E-mails autorisés, avec claim vérifié par défaut |
| `INSIGHT_SSO_ALLOWED_GROUPS` | vide | Groupes autorisés à ouvrir le dashboard |
| `INSIGHT_DB_*` | voir `.env.example` | Connexion MariaDB |
| `INSIGHT_MONITOR_INTERVAL_SEC` | `60` | Fréquence du worker en secondes |
| `INSIGHT_DISTRIBUTED_MODE` | `standalone` | Sondes locales ou consensus du hub |
| `INSIGHT_AGGREGATION_REPROCESS_HOURS` | `2` | Fenêtre recalculée à chaque passage, étendue automatiquement après une interruption |
| `INSIGHT_PROBE_RETENTION_DAYS` | `30` | Conservation des vérifications brutes après agrégation |
| `INSIGHT_HOURLY_RETENTION_DAYS` | `365` | Conservation des statistiques horaires |
| `INSIGHT_DAILY_RETENTION_DAYS` | `730` | Conservation des statistiques journalières |
| `INSIGHT_TLS_RETENTION_DAYS` | `365` | Conservation des contrôles TLS |
| `INSIGHT_HTTP_BIND` | `0.0.0.0` | Adresse publiée par Docker, `127.0.0.1` derrière un proxy HTTPS local |
| `INSIGHT_AGENT_MASTER_SECRET` | vide | Secret maître des agents distants |
| `INSIGHT_AGENT_REQUIRE_HTTPS` | `1` | Refuse les agents distribués hors HTTPS |
| `INSIGHT_AGENT_DEFAULT_REPLICAS` | `3` | Nombre d’agents affectés par cible, `0` pour tous |
| `INSIGHT_DISABLE_NOTIFICATIONS` | `1` | Coupe les envois automatiques, mais pas les tests manuels |
| `INSIGHT_NOTIFICATION_ENCRYPTION_KEY` | générée à l’installation | Chiffre les secrets des canaux avec SecretBox |
| `INSIGHT_ALLOWED_ORIGINS` | URL locale | Origines CORS séparées par des virgules |

Les notifications sont désactivées par défaut avec `INSIGHT_DISABLE_NOTIFICATIONS=1`. Configurez et testez les canaux dans le dashboard, puis passez cette variable à `0`. Les configurations sont chiffrées avant leur écriture en base et leurs secrets ne sont jamais renvoyés à l’interface. L’ancien réglage SMTP/SMS par variables d’environnement reste utilisé uniquement lorsqu’aucun canal moderne n’est configuré. Le guide [Alertes et notifications](docs/notifications.md) décrit les services Apprise, les modèles Liquid, les webhooks et les sauvegardes.

## Internationalisation

Les catalogues sont stockés dans `public/locales`. Le français sert de langue de repli. La langue choisie est enregistrée dans le navigateur et peut aussi être imposée avec `?lang=fr` ou `?lang=en`.

Pour ajouter une langue, dupliquez un catalogue existant, traduisez toutes ses clés, ajoutez son code à `INSIGHT_SUPPORTED_LOCALES`, puis vérifiez la page sur ordinateur et mobile. Les dates, nombres, durées et fuseaux utilisent automatiquement la locale active avec `Intl`.

## API publique

- `GET /` : page publique Insight.
- `GET /hourly_stats_report.php?contract=v2` : disponibilité et services.
- `GET /api/public_runtime_state.php` : état du moteur actif.
- `GET /api/distributed_state.php` : résumé public du réseau d’agents et du consensus.
- `GET /metrics` : métriques Prometheus, désactivées par défaut.
- `GET /hourly_stats_report.php?contract=v2&mode=incidents` : incidents en JSON.
- `GET /hourly_stats_report.php?contract=v2&mode=incidents&format=rss` : incidents en RSS.
- `GET /admin/` : dashboard local protégé ou création du premier compte.

## API headless et SSO

Le menu **Accès** active une API d’administration indépendante du dashboard. Les jetons sont limités par permissions, expirables et révocables ; leur valeur n’est affichée qu’une fois. Les routes versionnées couvrent l’état global, les moniteurs, les incidents et les alertes sous `/api/v1/`.

Insight peut aussi authentifier un autre dashboard en tant que fournisseur OpenID Connect, ou déléguer sa propre connexion à un fournisseur OIDC externe. Ces deux directions utilisent Authorization Code, PKCE S256, des URI de retour exactes et des jetons courts. Consultez le [guide API et SSO](docs/api-and-sso.md) ou ouvrez **Accès → Guide d’intégration** dans l’instance.

Lorsque la base locale ne contient aucun site, l’interface affiche un aperçu avec `example.com`, `status.example.com` et `api.example.com` sur `localhost`. Le détail contient aussi quatre incidents fictifs couvrant les états en cours, résolu, assisté et à confiance faible. Ajoutez `?incidents=off` pour masquer ce jeu de test.

## Commandes du worker

```bash
docker compose exec worker php monitoring/monitoring.php
docker compose exec worker php monitoring/hourly.php
docker compose exec worker php monitoring/daily.php
docker compose exec worker php monitoring/retention.php
```

Les agrégations mémorisent leur dernier passage réussi et recalculent au minimum les deux dernières heures. La purge quotidienne travaille par lots et refuse de supprimer les sondes brutes ou les heures tant que les agrégations correspondantes ne sont pas à jour. Les périodes sans observation sont conservées comme temps inconnu et ne sont pas comptées comme une disponibilité réussie. Les temps de réponse journaliers sont pondérés par le nombre réel d’échantillons.

Le CLI Python permet aussi d’ajouter, modifier, supprimer ou tester manuellement une sonde :

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py --help
```

## Installation sans Docker

Utilisez PHP 8.2 ou plus récent avec `mysqli`, `pdo_sqlite`, `curl`, `mbstring`, `sodium` et `xml`, Python 3.10 ou plus récent, Node.js 22, MariaDB ou MySQL, et Nginx ou Apache. Importez `database/schema.sql`, installez les dépendances puis compilez l’interface avec `npm ci && npm run build`, installez `monitoring/python_monitoring/requirements.txt`, exposez uniquement le dossier `public/`, puis exécutez régulièrement `monitoring/monitoring.php`, `monitoring/hourly.php` et `monitoring/daily.php`. Le schéma `database/auth-schema.sql` est appliqué automatiquement au premier accès à `/admin/`.

## Développement

Pour ouvrir toute l’administration sans créer de compte, lancez le serveur de développement dédié :

```bash
./scripts/dev-server.sh
```

Le contournement n’est actif que lorsque `INSIGHT_APP_ENV=development` et `INSIGHT_DEV_AUTH_BYPASS=1` sont définis ensemble. Il reste désactivé par défaut dans Docker et ne doit jamais être utilisé sur une instance exposée.

```bash
npm ci
npm run build
npm run check
find . -name '*.php' -print0 | xargs -0 -n1 php -l
python3 -m py_compile monitoring/python_monitoring/*.py
php tests/admin_probes.php
php tests/admin_notifications.php
php tests/admin_auth.php
php tests/admin_access.php
php tests/public_api.php
php tests/distributed_consensus.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
```

Avec Docker démarré, `./scripts/smoke-test.sh` contrôle le schéma et le CRUD MariaDB sur une installation complète, puis exécute réellement une sonde HTTP, une sonde ICMP et une sonde TCP.

Pour produire l’archive publique depuis un commit contrôlé :

```bash
./scripts/package-release.sh
```

La commande relance tous les contrôles, exporte uniquement les fichiers suivis sans dossier `.git`, recherche les données d’exécution et anciennes dépendances privées, puis écrit l’archive et son empreinte dans `dist/`.

Le CLI shadcn peut ajouter un composant dans le dépôt avec `npx shadcn@latest add nom-du-composant`. Les composants compilés ne dépendent pas d’un service distant à l’exécution.

Consultez `CONTRIBUTING.md` pour proposer une modification et `SECURITY.md` pour signaler une vulnérabilité.

## Licence

Insight est distribué sous licence MIT.
