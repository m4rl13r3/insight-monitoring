# API and SSO

Insight separates three use cases: the token-based headless API, Insight as an OpenID Connect provider for another dashboard, and Insight as a client of an external SSO provider. All three features are disabled by default.

## Headless API

From **Administration -> Access**, enable the API and create a token. Its value is displayed only once, and only its SHA-256 hash is stored in the private SQLite database.

Available permissions:

| Permission | Access |
| --- | --- |
| `status:read` | Global status and engine state |
| `monitors:read` | Monitor list |
| `monitors:write` | Monitor creation, editing, and deletion |
| `incidents:read` | Incident list |
| `notifications:read` | Channels and messages, with masked secrets |
| `notifications:write` | Channel and message management and testing |

Example:

```bash
curl \
  -H "Authorization: Bearer insight_pat_..." \
  https://status.example.com/api/v1/status.php
```

Routes are documented at `/api/v1/openapi.php` while the API is enabled. For browser access from another domain, add only the required origins to `INSIGHT_API_ALLOWED_ORIGINS`.

## Insight as an OpenID Connect provider

Enable **Connected dashboards**, then create an application with its exact redirect URIs. Insight provides:

- discovery: `/.well-known/openid-configuration`;
- authorization: `/admin/oauth/authorize.php`;
- code exchange: `/api/oauth/token.php`;
- profile: `/api/oauth/userinfo.php`;
- public keys: `/api/oauth/jwks.php`.

Only the Authorization Code flow is accepted. PKCE `S256`, `state`, and `nonce` are mandatory. Redirect URIs are compared exactly. Codes expire after five minutes and can be consumed only once. Access tokens expire after one hour and RS256 ID Tokens after five minutes.

The client dashboard must use discovery, keep its `client_secret` server-side, validate `state`, `nonce`, `iss`, `aud`, `exp`, and the ID Token signature, then create its own secure session.

## Insight as an SSO client

First create the fallback local administrator, then register a confidential OIDC client with the identity provider. The redirect URI is:

```text
https://status.example.com/admin/sso/callback.php
```

Minimal configuration:

```dotenv
INSIGHT_SSO_ENABLED=1
INSIGHT_SSO_PROVIDER_NAME=Company
INSIGHT_SSO_ISSUER_URL=https://id.example.com
INSIGHT_SSO_ALLOWED_ENDPOINT_HOSTS=
INSIGHT_SSO_CLIENT_ID=insight
INSIGHT_SSO_CLIENT_SECRET=
INSIGHT_SSO_ALLOWED_GROUPS=ops,status-admins
```

Access control:

- `INSIGHT_SSO_ALLOWED_EMAILS` accepts comma-separated exact email addresses;
- `INSIGHT_SSO_REQUIRE_VERIFIED_EMAIL=1` requires the `email_verified=true` claim for this list by default;
- `INSIGHT_SSO_ALLOWED_GROUPS` accepts members of at least one group;
- `INSIGHT_SSO_ADMIN_GROUPS` additionally requires membership in an administrator group;
- `INSIGHT_SSO_ALLOW_ALL=1` fully delegates admission to the provider, which is discouraged without strict application assignment at the IdP.

Insight refuses to enable SSO without an admission policy. It validates discovery, TLS, the RS256 signature, issuer, audience, nonce, and dates before storing the identity in the private database. `INSIGHT_SSO_AUTO_LOGIN=1` starts SSO automatically. `INSIGHT_SSO_HIDE_LOCAL_LOGIN=1` hides the local form, which remains available as fallback access at `/admin/login.php?local=1`.

Endpoints advertised through discovery must use the same host as the issuer. For providers that intentionally separate endpoints, add only the required official hosts to `INSIGHT_SSO_ALLOWED_ENDPOINT_HOSTS`.

## Operations

- Use HTTPS and an exact canonical value for `INSIGHT_PUBLIC_URL`.
- Include the `insight_auth` volume in backups: it contains accounts, token hashes, clients, and the OIDC signing private key.
- Never copy a token or client secret into a URL, log, or public JavaScript.
- Grant minimum permissions and short expirations, then immediately revoke removed access.
- The current version supports one active OIDC key. Rotation invalidates outstanding ID Tokens; schedule it with a short token window and immediate client-side JWKS refresh.
