# Mise en production

Ce guide part d’un serveur Linux avec Docker Engine, Docker Compose, OpenSSL et un nom de domaine. Insight doit être placé derrière un proxy HTTPS ; le port HTTP du conteneur reste lié à l’interface locale.

## Configuration

```bash
git clone https://github.com/m4rl13r3/insight-monitoring.git insight
cd insight
./scripts/install.sh
```

Modifiez ensuite `.env` :

```dotenv
INSIGHT_APP_ENV=production
INSIGHT_DEV_AUTH_BYPASS=0
INSIGHT_PUBLIC_URL=https://status.votre-domaine.fr
INSIGHT_CONTACT_EMAIL=technique@votre-domaine.fr
INSIGHT_HTTP_BIND=127.0.0.1
INSIGHT_ALLOWED_ORIGINS=https://status.votre-domaine.fr
INSIGHT_API_ALLOWED_ORIGINS=https://status.votre-domaine.fr
INSIGHT_AUTH_COOKIE_SECURE=1
```

Conservez `.env` avec les droits `600` et sauvegardez-le dans un coffre à secrets distinct. Il contient les mots de passe MariaDB, la clé de chiffrement des alertes et, en mode distribué, le secret maître des agents.

## HTTPS

Avec Caddy installé sur l’hôte :

```caddyfile
status.votre-domaine.fr {
    reverse_proxy 127.0.0.1:8080
}
```

Rechargez Caddy puis vérifiez que `https://status.votre-domaine.fr/` et `https://status.votre-domaine.fr/api/public_runtime_state.php` répondent. N’exposez pas directement le port `8080` dans le pare-feu.

## Première mise en service

1. Ouvrez `/admin/` depuis un réseau de confiance et créez le premier compte administrateur.
2. Supprimez les éventuelles cibles de smoke test puis créez les vrais moniteurs HTTP, ICMP ou TCP.
3. Laissez le worker effectuer au moins un cycle et vérifiez que chaque cible publie un état.
4. Créez un canal dans **Alertes**, effectuez un envoi de test concluant, puis réglez `INSIGHT_DISABLE_NOTIFICATIONS=0`.
5. Redémarrez les services concernés avec `docker compose up -d`.
6. Lancez `./scripts/production-check.sh --strict`. La mise en production est validée uniquement lorsque la commande se termine sans erreur.

Le contrôle strict refuse notamment les domaines de démonstration, une instance sans administrateur, un worker inactif, les notifications non testées, les origines non HTTPS et le contournement d’authentification de développement.

## Agents distribués

En mode `hub`, enrôlez d’abord chaque agent avec une clé propre générée par `scripts/agent-key.php`. Vérifiez leur présence dans **Réseau**, puis réglez :

```dotenv
INSIGHT_AGENT_REQUIRE_HTTPS=1
INSIGHT_AGENT_AUTO_REGISTER=0
```

Le contrôle de production exige ces valeurs et un secret maître d’au moins 32 caractères. Les agents doivent pointer vers l’URL HTTPS publique du hub.

## Sauvegardes

Testez d’abord une sauvegarde et une restauration selon le README. Pour une sauvegarde quotidienne conservée 30 jours :

```cron
17 2 * * * cd /opt/insight && INSIGHT_BACKUP_RETENTION_DAYS=30 ./scripts/backup-scheduled.sh >> /var/log/insight-backup.log 2>&1
```

Une copie distante peut être envoyée vers une destination rclone :

```dotenv
INSIGHT_BACKUP_RCLONE_DEST=s3-insight:production
```

Testez régulièrement la restauration sur une instance isolée. La présence d’une archive ne garantit pas à elle seule qu’elle est exploitable.

## Mises à jour

```bash
git pull --ff-only
docker compose build --pull
docker compose up -d
./scripts/production-check.sh --strict
```

Créez une sauvegarde avant chaque mise à jour et consultez `CHANGELOG.md` pour les changements de schéma ou de configuration.
