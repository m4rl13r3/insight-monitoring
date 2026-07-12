# Sécurité

## Versions prises en charge

Les correctifs de sécurité ciblent la dernière version publiée d’Insight.

## Signaler une vulnérabilité

N’ouvrez pas de ticket public pour une vulnérabilité exploitable. Utilisez le signalement privé **Security → Advisories → Report a vulnerability** du dépôt. Le mainteneur doit activer cette fonctionnalité avant de rendre le dépôt public.

Indiquez la version concernée, les conditions de reproduction, l’impact estimé et, si possible, une proposition de correction. Évitez d’inclure des secrets, des données personnelles ou des données de production dans le rapport.

## Bonnes pratiques de déploiement

- Remplacez tous les mots de passe fournis en exemple avant le premier déploiement public.
- Conservez `.env` hors du contrôle de version et limitez ses permissions.
- Exposez uniquement le service `web`, jamais MariaDB ni PHP-FPM directement.
- Utilisez HTTPS devant Nginx et limitez `INSIGHT_ALLOWED_ORIGINS` à vos domaines.
- Créez le premier compte depuis un réseau de confiance, utilisez un mot de passe unique et sauvegardez le volume privé `insight_auth`.
- Ne placez jamais `INSIGHT_AUTH_DB_PATH` sous le dossier public et laissez `INSIGHT_AUTH_COOKIE_SECURE=auto` ou activez-le explicitement derrière HTTPS.
- Laissez `INSIGHT_AUTH_COOKIE_SAMESITE=Lax` avec un SSO externe : le callback OIDC est une navigation intersite. Utilisez `Strict` uniquement si ce mode est désactivé.
- Ne définissez jamais `INSIGHT_DEV_AUTH_BYPASS=1` hors d’un environnement local éphémère. Ce réglage supprime entièrement la protection du dashboard.
- Activez l’API headless uniquement si elle est utilisée, limitez `INSIGHT_API_ALLOWED_ORIGINS`, donnez les permissions minimales et révoquez les jetons inutiles.
- Conservez les jetons API et secrets OAuth côté serveur. Ne les placez jamais dans une URL, un bundle JavaScript, une capture ou un log.
- Pour le SSO, gardez une politique locale par e-mails vérifiés ou groupes et conservez le compte local comme accès de secours. `INSIGHT_SSO_ALLOW_ALL=1` suppose une affectation stricte de l’application chez le fournisseur.
- Sauvegardez le volume `insight_auth` avec sa clé privée OIDC. Une perte ou rotation non planifiée invalide les ID Tokens en circulation.
- Pour les agents distribués, générez un `INSIGHT_AGENT_MASTER_SECRET` aléatoire, imposez HTTPS et ne transmettez à chaque serveur que son secret dérivé.
- Donnez une clé unique à chaque agent, révoquez immédiatement un nœud retiré et désactivez l’enregistrement automatique après l’enrôlement si votre parc est fixe.
- Protégez `/metrics` avec `INSIGHT_METRICS_TOKEN` ou laissez l’endpoint désactivé.
- Ne placez pas de jeton, mot de passe ni donnée sensible dans les URL surveillées : les cibles apparaissent dans le dashboard, les métriques et les observations.
- Laissez les notifications et l’ingestion désactivées tant qu’elles ne sont pas configurées.
- Générez `INSIGHT_NOTIFICATION_ENCRYPTION_KEY` avec `openssl rand -hex 32`, sauvegardez-la avec la base et ne la placez jamais dans MariaDB. Sans cette clé, les secrets des canaux sont irrécupérables.
- Ne changez pas la clé de chiffrement sans procédure de migration. Une simple substitution rend toutes les configurations de notification existantes illisibles.
- Traitez les URL Apprise, URL de webhook et en-têtes d’autorisation comme des secrets. Insight les masque dans l’API, mais leur destination peut toujours recevoir le contenu des alertes.
