CREATE TABLE IF NOT EXISTS auth_meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS auth_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    active INTEGER NOT NULL DEFAULT 1,
    totp_secret_ciphertext TEXT NULL,
    totp_enabled INTEGER NOT NULL DEFAULT 0,
    totp_last_counter INTEGER NOT NULL DEFAULT 0,
    recovery_codes_json TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_hash TEXT NOT NULL,
    attempted_at INTEGER NOT NULL,
    success INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_auth_login_attempts_key_time
    ON auth_login_attempts (key_hash, attempted_at);

CREATE TABLE IF NOT EXISTS auth_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    event TEXT NOT NULL,
    ip_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_auth_audit_log_created_at
    ON auth_audit_log (created_at);

CREATE TABLE IF NOT EXISTS auth_api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    token_prefix TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL UNIQUE,
    scopes_json TEXT NOT NULL,
    expires_at INTEGER NULL,
    last_used_at INTEGER NULL,
    last_ip_hash TEXT NULL,
    revoked_at INTEGER NULL,
    created_by_user_id INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_user_id) REFERENCES auth_users (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_auth_api_tokens_active
    ON auth_api_tokens (revoked_at, expires_at);

CREATE TABLE IF NOT EXISTS auth_oauth_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    client_id TEXT NOT NULL UNIQUE,
    client_secret_hash TEXT NOT NULL,
    redirect_uris_json TEXT NOT NULL,
    scopes_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    last_used_at INTEGER NULL,
    created_by_user_id INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_user_id) REFERENCES auth_users (id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS auth_oauth_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code_hash TEXT NOT NULL UNIQUE,
    client_id TEXT NOT NULL,
    redirect_uri TEXT NOT NULL,
    scopes_json TEXT NOT NULL,
    code_challenge TEXT NOT NULL,
    nonce TEXT NOT NULL,
    subject TEXT NOT NULL,
    username TEXT NOT NULL,
    email TEXT NULL,
    role TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    used_at INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES auth_oauth_clients (client_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_auth_oauth_codes_expiry
    ON auth_oauth_codes (expires_at, used_at);

CREATE TABLE IF NOT EXISTS auth_oauth_access_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_prefix TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL UNIQUE,
    client_id TEXT NOT NULL,
    scopes_json TEXT NOT NULL,
    subject TEXT NOT NULL,
    username TEXT NOT NULL,
    email TEXT NULL,
    role TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    last_used_at INTEGER NULL,
    revoked_at INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES auth_oauth_clients (client_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_auth_oauth_access_tokens_active
    ON auth_oauth_access_tokens (revoked_at, expires_at);

CREATE TABLE IF NOT EXISTS auth_sso_identities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    issuer TEXT NOT NULL,
    subject TEXT NOT NULL,
    username TEXT NOT NULL,
    email TEXT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TEXT NULL,
    UNIQUE (issuer, subject)
);

CREATE INDEX IF NOT EXISTS idx_auth_sso_identities_active
    ON auth_sso_identities (active, issuer);
