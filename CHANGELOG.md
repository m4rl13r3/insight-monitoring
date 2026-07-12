# Changelog

Toutes les modifications notables d’Insight sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet utilise [Semantic Versioning](https://semver.org/lang/fr/).

## [0.1.0] - Non publiée

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

### Sécurité

- Secrets exclusivement fournis par variables d’environnement.
- API d’administration protégée par session et jeton CSRF.
- Agents distribués signés, protégés contre le rejeu et limités à HTTPS par défaut.
- Conteneurs applicatifs exécutés sans privilèges root.
- Image agent autonome avec ses dépendances Python verrouillées.
- Secrets des canaux chiffrés avec libsodium SecretBox et masqués dans l’API d’administration.
- Authorization Code avec PKCE S256, URI de retour exactes, codes à usage unique et ID Tokens RS256.
- Secrets API et OAuth affichés une fois puis uniquement conservés sous forme de hachage.
