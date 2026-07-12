# Mises à jour

Insight se met à jour depuis les versions stables `vX.Y.Z` du dépôt Git configuré. Le script s’exécute sur l’hôte avec le même utilisateur que l’installation Docker. Aucun conteneur ne reçoit le socket Docker et aucun service tiers n’est requis.

Le gestionnaire est disponible à partir d’Insight 0.1.2. Une instance plus ancienne doit être amenée une première fois sur cette version avec la procédure manuelle de sa release ; les versions suivantes utiliseront ensuite `update.sh`.

Une mise à jour conserve `.env`, les volumes MariaDB et d’identité, ainsi que les sauvegardes. Elle vérifie le tag distant, refuse un historique divergent, crée une sauvegarde, construit les nouvelles images, arrête brièvement le worker, applique chaque migration une seule fois, redémarre la pile et contrôle MariaDB ainsi que les endpoints web.

## Mise à jour manuelle

Rechercher une version sans rien modifier :

```bash
./scripts/update.sh --check
```

Installer la dernière version stable :

```bash
./scripts/update.sh --apply
```

Installer une version stable précise :

```bash
./scripts/update.sh --apply --target v0.2.0
```

Le dépôt passe volontairement sur le commit détaché du tag publié. Les fichiers suivis doivent être propres. Les adaptations locales doivent vivre dans `.env`, les volumes ou un fork, pas dans les fichiers versionnés de l’instance.

## Activation automatique

Sur un serveur Linux avec systemd, exécutez une fois :

```bash
./scripts/install-auto-update.sh
```

L’installateur active `INSIGHT_AUTO_UPDATE=1` dans `.env` et crée un timer utilisateur quotidien avec un décalage aléatoire. Le service utilise l’utilisateur courant, qui doit déjà pouvoir exécuter Docker.

Contrôler le timer et son dernier journal :

```bash
systemctl --user list-timers insight-update.timer
journalctl --user -u insight-update.service -n 100 --no-pager
```

Pour que le timer utilisateur continue après la déconnexion, l’administrateur du serveur peut activer le maintien de session :

```bash
sudo loginctl enable-linger "$USER"
```

Désactiver proprement l’automatisation :

```bash
./scripts/install-auto-update.sh --remove
```

Sans systemd, utilisez la commande suivante dans le planificateur de l’hôte après avoir réglé `INSIGHT_AUTO_UPDATE=1` :

```cron
17 4 * * * cd /opt/insight && ./scripts/update.sh --auto >> /var/log/insight-update.log 2>&1
```

## Sécurité des versions

Le canal stable ignore les préversions et n’accepte que les tags annotés dont le numéro correspond à `package.json`. Le remote par défaut est `origin` et se règle avec `INSIGHT_UPDATE_REMOTE`.

Pour exiger en plus une signature Git vérifiable localement :

```dotenv
INSIGHT_UPDATE_REQUIRE_SIGNED_TAGS=1
```

Cette option nécessite que les clés de signature des mainteneurs soient déjà approuvées sur le serveur. Elle ne doit être activée qu’après publication de tags signés.

## Échec et retour arrière

Si la construction, une migration, le démarrage ou le contrôle de santé échoue, le script reconstruit automatiquement le commit précédent. Il ne restaure jamais automatiquement la base : une restauration pourrait supprimer les observations reçues entre-temps. L’archive créée avant l’opération reste dans `backups/` pour une intervention manuelle.

Pour revenir volontairement au code précédant la dernière mise à jour :

```bash
./scripts/update.sh --rollback
```

Les migrations publiées doivent rester additives et compatibles avec la version précédente. Un fichier de migration déjà appliqué ne doit jamais être modifié : son empreinte est contrôlée dans `insight_schema_migrations`.

Les réglages associés sont :

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `INSIGHT_AUTO_UPDATE` | `0` | Autorise l’exécution planifiée avec `--auto` |
| `INSIGHT_UPDATE_REMOTE` | `origin` | Remote Git contenant les versions officielles |
| `INSIGHT_UPDATE_BACKUP` | `1` | Crée une archive avant le déploiement |
| `INSIGHT_UPDATE_REQUIRE_SIGNED_TAGS` | `0` | Exige une signature Git valide sur le tag |
| `INSIGHT_UPDATE_HEALTH_TIMEOUT_SEC` | `180` | Délai maximal de remise en service Docker |
