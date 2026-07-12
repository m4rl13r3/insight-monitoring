# Monitoring distribué

Insight peut fonctionner seul sur un serveur ou agréger les observations d’autant d’agents distants que nécessaire. Les agents ne se connectent jamais à MariaDB : ils récupèrent leur configuration depuis le hub, exécutent les sondes, puis envoient des lots signés à l’API Insight.

## Architecture

```mermaid
flowchart LR
    A1[Agent Paris] -->|HTTPS + HMAC| H[Hub Insight]
    A2[Agent Francfort] -->|HTTPS + HMAC| H
    AN[Agent N] -->|HTTPS + HMAC| H
    B[Blackbox Exporter optionnel] --> A1
    H --> R[(Observations brutes)]
    R --> C[Consensus]
    C --> P[(Sondes canoniques)]
    P --> S[Statistiques et incidents]
    H --> M[/metrics]
```

Le système reprend des principes éprouvés dans l’écosystème open source : séparation agent/hub d’OpenTelemetry, file persistante et rejeu idempotent de Prometheus Remote Write, sondes multi-cibles de Blackbox Exporter et contrôle de connectivité inspiré de Gatus. Insight conserve son propre protocole minimal afin de rester déployable sans Prometheus ni service externe.

Chaque agent possède :

- un identifiant stable, une région et une zone ;
- une file SQLite persistante qui survit aux redémarrages et aux coupures du hub ;
- des sondes HTTP et ICMP natives ;
- un adaptateur facultatif pour les sondes HTTP, ICMP, TCP, DNS et gRPC de Prometheus Blackbox Exporter ;
- une relance locale courte avant de confirmer un échec ;
- un contrôle de connectivité facultatif qui diffère les sondes si le réseau local est indisponible ;
- un envoi par lots avec rejeu octet pour octet, backoff exponentiel et jitter.

Le hub conserve séparément les observations brutes et le résultat canonique. Seul le consensus alimente `probes`, les statistiques horaires, les incidents et la page publique.

## Affectation et quorum

Les cibles sont distribuées par hachage rendezvous. L’affectation reste déterministe quand l’ordre des agents change et ne déplace qu’une partie des cibles lorsqu’un agent est ajouté ou retiré.

`INSIGHT_AGENT_DEFAULT_REPLICAS=3` affecte chaque cible à trois agents au maximum. La valeur `0` utilise tous les agents actifs. Une cible peut surcharger ce réglage avec `sites.probe_replication_factor`.

Les quorums de succès et d’échec valent par défaut `floor(n / 2) + 1`. Les colonnes `probe_success_quorum` et `probe_failure_quorum` permettent une politique différente par cible. La valeur `0` conserve la majorité automatique.

| Agents attendus | Observations fraîches | État canonique |
| --- | --- | --- |
| 1 | 1 en ligne | `online` |
| 1 | 1 hors ligne | `offline` |
| 2 | 2 en ligne | `online` |
| 2 | 1 en ligne, 1 hors ligne | `degraded` |
| 2 | 2 hors ligne | `offline` |
| 3 | 3 en ligne | `online` |
| 3 | 2 en ligne, 1 manquante | `online`, confiance 67 % |
| 3 | 2 en ligne, 1 hors ligne | `degraded` |
| 3 | 1 en ligne, 2 manquantes | `unknown` |
| 3 | 2 hors ligne | `offline` |

Une panne minoritaire explicite reste donc visible en `degraded`, même si la majorité répond. Une absence de données devient `unknown` et n’est pas comptée comme du temps d’arrêt dans les agrégations.

Un agent actif mais silencieux conserve ses affectations et compte comme manquant. Passez-le explicitement en pause ou révoquez-le pour redistribuer ses cibles.

## Configurer le hub

Pour une nouvelle installation, générez les secrets avec le script d’installation puis activez le mode hub dans `.env` :

```dotenv
INSIGHT_DISTRIBUTED_MODE=hub
INSIGHT_AGENT_MASTER_SECRET=une_valeur_aleatoire_de_64_caracteres_hexadecimaux
INSIGHT_AGENT_REQUIRE_HTTPS=1
INSIGHT_AGENT_DEFAULT_REPLICAS=3
```

Le secret peut aussi être généré manuellement :

```bash
openssl rand -hex 32
docker compose up -d --build
```

Le worker exécute alors `monitoring/distributed_consensus.php` à la place des sondes centrales. Les agrégations horaires et quotidiennes continuent normalement.

Pour une base Insight existante, le mode hub applique automatiquement les tables manquantes. La migration peut aussi être lancée explicitement :

```bash
docker compose exec -T db sh -lc 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < database/migrations/002-distributed-monitoring.sql
```

## Déployer un agent

Choisissez une clé unique en minuscules, puis dérivez son secret depuis le hub :

```bash
docker compose exec php php scripts/agent-key.php paris-1
```

Sur le serveur distant :

```bash
cp .env.agent.example .env.agent
```

Renseignez au minimum :

```dotenv
INSIGHT_HUB_URL=https://status.example.com
INSIGHT_AGENT_NODE_KEY=paris-1
INSIGHT_AGENT_SECRET=secret_genere_par_le_hub
INSIGHT_AGENT_DISPLAY_NAME=Paris 1
INSIGHT_AGENT_REGION=fr-par
INSIGHT_AGENT_ZONE=fr-par-1
```

Lancez ensuite l’agent :

```bash
docker compose --env-file .env.agent -f docker-compose.agent.yml up -d --build
```

Le volume `insight_agent_spool` contient la file locale. Ne le supprimez pas pendant une panne du hub, sous peine de perdre les observations qui n’ont pas encore été confirmées.

Pour tester un cycle sans Docker :

```bash
INSIGHT_HUB_URL=https://status.example.com \
INSIGHT_AGENT_NODE_KEY=paris-1 \
INSIGHT_AGENT_SECRET=secret_genere_par_le_hub \
INSIGHT_AGENT_SPOOL_PATH=./data/agent.sqlite \
python3 monitoring/agent/agent.py --once
```

## Blackbox Exporter

L’agent natif suffit pour HTTP, ICMP et TCP. Blackbox Exporter ajoute notamment DNS, gRPC, des contrôles TLS plus fins et des scénarios HTTP configurables.

Dans `.env.agent` :

```dotenv
INSIGHT_AGENT_BLACKBOX_URL=http://blackbox:9115
INSIGHT_AGENT_BLACKBOX_HTTP_MODULE=http_2xx
INSIGHT_AGENT_BLACKBOX_ICMP_MODULE=icmp
INSIGHT_AGENT_BLACKBOX_TCP_MODULE=tcp_connect
INSIGHT_AGENT_BLACKBOX_DNS_MODULE=dns
INSIGHT_AGENT_BLACKBOX_GRPC_MODULE=grpc
INSIGHT_AGENT_BLACKBOX_FALLBACK_NATIVE=1
```

Puis lancez le profil fourni :

```bash
docker compose --env-file .env.agent -f docker-compose.agent.yml --profile blackbox up -d --build
```

Si Blackbox est indisponible, le fallback natif reste actif par défaut pour HTTP, ICMP et TCP. DNS et gRPC exigent Blackbox. La configuration fournie dans `docker/agent/blackbox.yml` couvre les cinq protocoles. Son module DNS interroge `example.com` en type A ; adaptez `query_name`, le transport et les validations à votre usage.

Chaque échec est retenté une fois après 500 ms par défaut. Ajustez `INSIGHT_AGENT_PROBE_RETRIES` et `INSIGHT_AGENT_PROBE_RETRY_DELAY_MS` sur les agents sans dépasser l’intervalle de la cible.

## Connectivité locale

`INSIGHT_AGENT_CONNECTIVITY_TARGET` accepte `hôte:port` ou une URL. Utilisez une passerelle, un résolveur DNS ou un service que vous contrôlez :

```dotenv
INSIGHT_AGENT_CONNECTIVITY_TARGET=dns.internal.example:53
```

Si cette cible ne répond plus, l’agent signale sa connectivité locale comme hors ligne et diffère ses sondes. Il n’envoie pas une série de faux échecs pour toutes les cibles.

Laissez la variable vide pour exécuter les sondes sans ce garde-fou.

## Exploiter les nœuds

Lister les agents et leurs affectations :

```bash
docker compose exec php php scripts/node-admin.php list
```

Modifier leur état :

```bash
docker compose exec php php scripts/node-admin.php pause paris-1
docker compose exec php php scripts/node-admin.php activate paris-1
docker compose exec php php scripts/node-admin.php revoke paris-1
```

`pause` et `revoke` retirent l’agent des affectations au prochain calcul. `revoke` bloque aussi ses futures requêtes jusqu’à une réactivation explicite.

Personnaliser une cible :

```sql
UPDATE sites
SET probe_replication_factor = 5,
    probe_success_quorum = 3,
    probe_failure_quorum = 3
WHERE id = 1;
```

L’affectation est rafraîchie lors de la prochaine configuration d’agent ou du prochain passage du worker.

## Incidents

Un seul lot négatif n’ouvre pas immédiatement un incident. Par défaut, Insight exige deux fenêtres de consensus hors ligne et deux fenêtres en ligne pour confirmer la récupération :

```dotenv
INSIGHT_CONSENSUS_FAILURE_WINDOWS=2
INSIGHT_CONSENSUS_RECOVERY_WINDOWS=2
INSIGHT_DISTRIBUTED_INCIDENTS=1
```

Le statut `degraded` signale un désaccord régional sans ouvrir automatiquement un incident global. Les valeurs `unknown` ne ferment ni n’ouvrent un incident.

## Prometheus et VictoriaMetrics

L’endpoint `/metrics` est désactivé par défaut. Activez-le et protégez-le avec un jeton :

```dotenv
INSIGHT_METRICS_ENABLED=1
INSIGHT_METRICS_TOKEN=un_jeton_aleatoire
```

Configuration Prometheus minimale :

```yaml
scrape_configs:
  - job_name: insight
    metrics_path: /metrics
    authorization:
      credentials: un_jeton_aleatoire
    static_configs:
      - targets: [status.example.com]
```

Les séries exposées couvrent la présence et le décalage d’horloge des agents, la connectivité locale, les observations par cible et par région, le consensus, sa confiance et le nombre de réponses attendues, fraîches ou manquantes.

## Sécurité

Le hub dérive un secret distinct pour chaque clé de nœud avec HMAC-SHA256. Il ne stocke pas ces secrets dans la base. Chaque requête comprend un horodatage, un nonce unique, l’empreinte du corps et une signature. Les nonces déjà vus sont rejetés.

- Utilisez HTTPS et `INSIGHT_AGENT_REQUIRE_HTTPS=1` dès que le hub est exposé.
- Gardez `INSIGHT_AGENT_MASTER_SECRET` uniquement sur le hub.
- Donnez à chaque agent une clé unique et ne réutilisez pas son secret.
- Désactivez `INSIGHT_AGENT_AUTO_REGISTER` après l’enrôlement si aucun nouveau nœud n’est attendu.
- Synchronisez les horloges avec NTP ; la fenêtre HMAC vaut 300 secondes par défaut.
- Une rotation du secret maître invalide tous les secrets agents. Mettez à jour chaque `.env.agent` sans supprimer son volume SQLite.

## Rétention et capacité

Les observations brutes et les lots sont conservés sept jours par défaut. Les snapshots de consensus sont conservés 90 jours. Les sondes canoniques et les agrégations suivent les politiques historiques d’Insight.

Pour N agents et M cibles, le volume brut est approximativement `N × M × 1440` observations par jour à une minute lorsque toutes les cibles utilisent tous les agents. Gardez trois répliques pour le cas général et augmentez-les seulement pour les services critiques ou l’analyse géographique.

Variables utiles :

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `INSIGHT_AGENT_DEFAULT_REPLICAS` | `3` | Agents affectés par cible, `0` pour tous |
| `INSIGHT_AGENT_NODE_TTL_SEC` | `180` | Délai avant qu’un agent soit affiché comme silencieux |
| `INSIGHT_CONSENSUS_FRESHNESS_SEC` | `180` | Fraîcheur minimale d’une observation |
| `INSIGHT_CONSENSUS_BUCKET_SEC` | `60` | Taille d’une fenêtre canonique |
| `INSIGHT_AGENT_BATCH_SIZE` | `200` | Observations maximales par lot |
| `INSIGHT_AGENT_RAW_RETENTION_DAYS` | `7` | Rétention des observations brutes |
| `INSIGHT_AGENT_BATCH_RETENTION_DAYS` | `7` | Rétention des reçus de lots |
| `INSIGHT_CONSENSUS_RETENTION_DAYS` | `90` | Rétention des snapshots agrégés |

## Références techniques

- [Prometheus Remote Write](https://prometheus.io/docs/specs/prw/remote_write_spec/)
- [Prometheus Multi-target Exporter Pattern](https://prometheus.io/docs/guides/multi-target-exporter/)
- [Prometheus Blackbox Exporter](https://github.com/prometheus/blackbox_exporter)
- [OpenTelemetry Agent to Gateway](https://opentelemetry.io/docs/collector/deploy/other/agent-to-gateway/)
- [Gatus](https://github.com/TwiN/gatus)
- [VictoriaMetrics deduplication](https://docs.victoriametrics.com/victoriametrics/cluster-victoriametrics/)
