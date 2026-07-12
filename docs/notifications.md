# Alertes et notifications

Insight envoie les changements d’état du moteur Python ou du consensus distribué vers les canaux configurés. Les canaux, abonnements, modèles et livraisons sont conservés dans MariaDB. Les secrets sont chiffrés avant écriture avec libsodium SecretBox.

## Mise en service

Le script `./scripts/install.sh` génère automatiquement la clé. Pour une installation manuelle :

```bash
openssl rand -hex 32
```

Placez le résultat dans `INSIGHT_NOTIFICATION_ENCRYPTION_KEY`, démarrez Insight, ouvrez **Administration → Alertes**, créez un canal puis utilisez son bouton de test. Quand les tests sont concluants :

```dotenv
INSIGHT_DISABLE_NOTIFICATIONS=0
```

Une modification de `.env` nécessite le redémarrage des services `php` et `worker`.

## Canaux

Insight gère trois transports directement :

- SMTP avec SSL, STARTTLS ou connexion sans chiffrement pour un relais local.
- Webhook HTTP en `POST`, `PUT` ou `PATCH`, avec en-têtes et corps JSON facultatifs.
- API SMS Free Mobile.

Tous les autres services passent par [Apprise](https://github.com/caronc/apprise). Le catalogue du dashboard propose les destinations courantes, tandis que l’entrée **Apprise · 138+ services** accepte n’importe quel schéma pris en charge. Collez une URL par ligne ; la [documentation des services Apprise](https://appriseit.com/services/) fournit le format propre à chaque fournisseur.

Les URL Apprise contiennent souvent un jeton. Insight les traite comme des secrets : elles restent vides lors d’une modification et une valeur vide conserve la configuration actuelle.

## Événements

Chaque canal peut s’abonner indépendamment à :

- `monitor_down` : une ou plusieurs cibles deviennent indisponibles.
- `monitor_up` : les cibles répondent de nouveau.
- `incident_open` : Insight ouvre un incident.
- `incident_resolved` : Insight clôt un incident.

Les changements simultanés d’un même domaine sont regroupés pour éviter une rafale de messages.

## Messages Liquid

Le titre et le corps de chaque événement sont modifiables dans le dashboard. Le moteur utilise `python-liquid`, avec les variables sûres suivantes :

| Variable | Contenu |
| --- | --- |
| `app_name` | Nom public de l’instance |
| `public_url` | URL de la page de statut |
| `event` | Clé de l’événement |
| `domain` | Domaine regroupant les cibles |
| `sites` | Liste des cibles concernées |
| `site_url` | Première cible du groupe |
| `count` | Nombre de cibles concernées |
| `status` | Nouvel état |
| `message` | Contexte fourni par le moteur |
| `timestamp` | Horodatage de l’envoi |
| `channel_name` | Nom du canal destinataire |

Exemple :

```liquid
[{{ app_name }}] {{ domain }} est hors ligne

{{ count }} service{% if count > 1 %}s sont{% else %} est{% endif %} indisponible{% if count > 1 %}s{% endif %} : {{ sites }}.
```

Le dashboard valide la syntaxe avant l’enregistrement. Pour un webhook, un corps personnalisé peut également utiliser `title`, `body` et les variables de contexte ; le résultat doit être un document JSON.

## Sauvegarde

`./scripts/backup.sh` sauvegarde MariaDB et l’identité locale. Conservez en plus le fichier `.env`, ou au minimum `INSIGHT_NOTIFICATION_ENCRYPTION_KEY`, dans un coffre séparé. La clé n’est pas stockée dans la base et Insight ne possède aucun mécanisme de récupération. La remplacer sans déchiffrer puis rechiffrer les canaux rend les configurations existantes inutilisables.

Le journal de livraison conserve pendant 90 jours le canal, l’événement, le titre rendu et l’erreur éventuelle. Il ne conserve ni mot de passe, ni jeton, ni corps complet du message.
