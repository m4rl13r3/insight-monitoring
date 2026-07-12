# Contribuer à Insight

Merci de contribuer à Insight.

## Préparer l’environnement

```bash
cp .env.example .env
docker compose up -d --build
```

Travaillez sur une branche dédiée et gardez les changements centrés sur un problème précis. N’ajoutez jamais de secrets, de journaux, de captures de débogage ni de données provenant d’une instance réelle.

## Vérifications attendues

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
find public -name '*.js' -not -path 'public/assets/*' -print0 | xargs -0 -n1 node --check
python3 -m py_compile monitoring/python_monitoring/*.py monitoring/agent/agent.py
php tests/admin_probes.php
php tests/admin_notifications.php
php tests/admin_auth.php
php tests/admin_access.php
php tests/admin_sso.php
php tests/public_api.php
php tests/distributed_consensus.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
```

Pour les changements de comportement, vérifiez aussi la page publique, le contrat JSON v2, l’état du moteur et le flux RSS dans une installation fraîche.

Les changements de déploiement doivent également passer `./scripts/smoke-test.sh` après un `docker compose up -d --build` sur des volumes vierges. Ce test inclut `tests/mariadb_integration.php`.

Les changements distribués doivent couvrir au minimum un agent unique, une paire en désaccord, une majorité sur trois agents, les réponses manquantes et le rejeu d’un lot après redémarrage.

Toute nouvelle chaîne visible doit passer par le moteur i18n. Maintenez les catalogues `public/locales/fr.json` et `public/locales/en.json` avec les mêmes clés.

## Proposition de modification

Décrivez le problème, la solution retenue, les effets visibles et les vérifications réalisées. Ajoutez une migration compatible lorsqu’un changement de schéma est nécessaire et maintenez `database/schema.sql` à jour pour les nouvelles installations.
