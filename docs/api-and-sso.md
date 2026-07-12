# API et SSO

Insight sépare trois usages : l’API headless par jetons, Insight comme fournisseur OpenID Connect pour un autre dashboard, et Insight comme client d’un fournisseur SSO externe. Les trois fonctions sont désactivées par défaut.

## API headless

Depuis **Administration → Accès**, activez l’API puis créez un jeton. Sa valeur n’est affichée qu’une fois et seul son hachage SHA-256 est conservé dans la base SQLite privée.

Permissions disponibles :

| Permission | Accès |
| --- | --- |
| `status:read` | État global et moteur |
| `monitors:read` | Liste des moniteurs |
| `monitors:write` | Création, modification et suppression des moniteurs |
| `incidents:read` | Liste des incidents |
| `notifications:read` | Canaux et messages, avec secrets masqués |
| `notifications:write` | Gestion et test des canaux et messages |

Exemple :

```bash
curl \
  -H "Authorization: Bearer insight_pat_..." \
  https://status.example.com/api/v1/status.php
```

Les routes sont documentées dans `/api/v1/openapi.php` lorsque l’API est active. Pour un accès depuis un navigateur sur un autre domaine, ajoutez uniquement les origines nécessaires à `INSIGHT_API_ALLOWED_ORIGINS`.

## Insight comme fournisseur OpenID Connect

Activez **Dashboards connectés**, puis créez une application avec ses URI de retour exactes. Insight fournit :

- découverte : `/.well-known/openid-configuration` ;
- autorisation : `/admin/oauth/authorize.php` ;
- échange de code : `/api/oauth/token.php` ;
- profil : `/api/oauth/userinfo.php` ;
- clés publiques : `/api/oauth/jwks.php`.

Seul le flow Authorization Code est accepté. PKCE `S256`, `state` et `nonce` sont obligatoires. Les URI de retour sont comparées à l’identique. Les codes expirent après cinq minutes et ne peuvent être consommés qu’une fois. Les jetons d’accès expirent après une heure et les ID Tokens RS256 après cinq minutes.

Le dashboard client doit utiliser la découverte, conserver son `client_secret` côté serveur, vérifier `state`, `nonce`, `iss`, `aud`, `exp` et la signature de l’ID Token, puis créer sa propre session sécurisée.

## Insight comme client SSO

Créez d’abord l’administrateur local de secours, puis un client OIDC confidentiel chez le fournisseur d’identité. L’URI de retour est :

```text
https://status.example.com/admin/sso/callback.php
```

Configuration minimale :

```dotenv
INSIGHT_SSO_ENABLED=1
INSIGHT_SSO_PROVIDER_NAME=Entreprise
INSIGHT_SSO_ISSUER_URL=https://id.example.com
INSIGHT_SSO_ALLOWED_ENDPOINT_HOSTS=
INSIGHT_SSO_CLIENT_ID=insight
INSIGHT_SSO_CLIENT_SECRET=
INSIGHT_SSO_ALLOWED_GROUPS=ops,status-admins
```

Contrôle d’accès :

- `INSIGHT_SSO_ALLOWED_EMAILS` accepte des e-mails exacts séparés par des virgules ;
- `INSIGHT_SSO_REQUIRE_VERIFIED_EMAIL=1` exige par défaut le claim `email_verified=true` pour cette liste ;
- `INSIGHT_SSO_ALLOWED_GROUPS` accepte les membres d’au moins un groupe ;
- `INSIGHT_SSO_ADMIN_GROUPS` impose en plus l’appartenance à un groupe administrateur ;
- `INSIGHT_SSO_ALLOW_ALL=1` délègue entièrement l’admission au fournisseur, ce qui est déconseillé sans affectation stricte de l’application côté IdP.

Insight refuse d’activer le SSO sans politique d’admission. Il vérifie la découverte, TLS, la signature RS256, l’issuer, l’audience, le nonce et les dates avant d’enregistrer l’identité dans la base privée. `INSIGHT_SSO_AUTO_LOGIN=1` lance automatiquement le SSO. `INSIGHT_SSO_HIDE_LOCAL_LOGIN=1` masque le formulaire local, qui reste accessible comme accès de secours sur `/admin/login.php?local=1`.

Les endpoints annoncés par la découverte doivent utiliser le même hôte que l’issuer. Pour un fournisseur qui sépare volontairement ses endpoints, ajoutez uniquement les hôtes officiels nécessaires à `INSIGHT_SSO_ALLOWED_ENDPOINT_HOSTS`.

## Exploitation

- Utilisez HTTPS et une valeur canonique exacte pour `INSIGHT_PUBLIC_URL`.
- Conservez le volume `insight_auth` avec les sauvegardes : il contient les comptes, les hachages de jetons, les clients et la clé privée de signature OIDC.
- Ne copiez jamais un jeton ou un secret client dans une URL, un log ou du JavaScript public.
- Donnez les permissions minimales et une expiration courte, puis révoquez immédiatement les accès retirés.
- La version actuelle gère une seule clé OIDC active. Une rotation invalide les ID Tokens en circulation ; planifiez-la avec une courte fenêtre de jetons et un rafraîchissement immédiat du JWKS côté clients.
