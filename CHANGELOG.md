# Changelog

Toutes les modifications notables d’Insight sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet utilise [Semantic Versioning](https://semver.org/lang/fr/).

## [0.1.2] - 2026-07-13

### Ajouté

- Mise à jour manuelle ou planifiée depuis les tags Git stables, sans recréer la configuration ni les volumes.
- Sauvegarde préalable, migrations SQL idempotentes, contrôle de santé et retour automatique au code précédent.
- Timer systemd utilisateur optionnel et guide d’exploitation dédié.

## [0.1.1] - 2026-07-13

### Ajouté

- Watermarks d’agrégation incrémentale et rétention configurable des sondes, statistiques et contrôles TLS.
- Conservation explicite du temps sans observation dans les agrégats horaires et journaliers.
- Test MariaDB de précision, purge et suppression en cascade des données de monitoring.

### Modifié

- Les agrégations recalculent une fenêtre récente ou la période écoulée depuis le dernier passage réussi au lieu de rescanner tout l’historique.
- La purge travaille par lots et reste bloquée si les agrégations sources ne sont pas à jour.

## [0.1.0] - 2026-07-13

### Ajouté

- Page de statut publique bilingue avec thèmes clair, sombre et système.
- Sondes HTTP, ICMP et TCP avec statistiques horaires et quotidiennes.
- Incidents automatiques, maintenances planifiées et suivi TLS.
- Administration locale avec authentification, création, modification et suppression des sondes.
- Alertes multicanales chiffrées avec SMTP, webhooks, Free Mobile et plus de 138 services Apprise.
- Modèles de notification Liquid personnalisables et historique des livraisons.
- Mode distribué hub/agent avec observations signées et consensus configurable.
- Déploiement Docker Compose, smoke test et CI de contrôle.
- Sauvegarde et restauration contrôlées de MariaDB et de l’identité locale.
- Export de release reproductible sans historique Git ni données d’exécution.
- API headless versionnée avec jetons à permissions, expiration et révocation.
- Fournisseur OpenID Connect pour authentifier des dashboards tiers.
- Connexion du dashboard par SSO OIDC externe avec politiques par e-mails et groupes.
- Écran Accès et guide d’intégration API, OAuth 2.0 et SSO.
- Contrôle strict de mise en production et sauvegardes planifiées avec rétention et copie rclone optionnelle.

### Modifié

- Le moteur Python est désormais l’unique moteur de supervision local.
- Le consensus distribué publie directement son état dans l’API publique.
- Les tâches monitor, horaire et journalière conservent indépendamment leur dernier état.

### Supprimé

- Ancien moteur de secours PHP et ses variables de configuration.

### Sécurité

- Secrets exclusivement fournis par variables d’environnement.
- API d’administration protégée par session et jeton CSRF.
- Agents distribués signés, protégés contre le rejeu et limités à HTTPS par défaut.
- Conteneurs applicatifs exécutés sans privilèges root.
- Image agent autonome avec ses dépendances Python verrouillées.
- Secrets des canaux chiffrés avec libsodium SecretBox et masqués dans l’API d’administration.
- Authorization Code avec PKCE S256, URI de retour exactes, codes à usage unique et ID Tokens RS256.
- Secrets API et OAuth affichés une fois puis uniquement conservés sous forme de hachage.
