# Updates

Insight updates from stable `vX.Y.Z` releases in the configured Git repository. The script runs on the host under the same user as the Docker installation. No container receives the Docker socket, and no third-party service is required.

The update manager is available starting with Insight 0.1.2. An older instance must first be moved to this version using that release's manual procedure; subsequent versions can use `update.sh`.

An update preserves `.env`, MariaDB and identity volumes, and backups. It validates the remote tag, rejects divergent history, creates a backup, builds the new images, briefly stops the worker, applies each migration once, restarts the stack, and validates MariaDB and the web endpoints.

## Manual update

Check for a release without changing anything:

```bash
./scripts/update.sh --check
```

Install the latest stable release:

```bash
./scripts/update.sh --apply
```

Install a specific stable release:

```bash
./scripts/update.sh --apply --target v0.2.0
```

The repository intentionally switches to the detached commit of the published tag. Tracked files must be clean. Local customization belongs in `.env`, volumes, or a fork, not in the instance's versioned files.

## Automatic updates

On a Linux server with systemd, run once:

```bash
./scripts/install-auto-update.sh
```

The installer sets `INSIGHT_AUTO_UPDATE=1` in `.env` and creates a daily user timer with a randomized delay. The service runs under the current user, who must already be allowed to use Docker.

Inspect the timer and its latest log:

```bash
systemctl --user list-timers insight-update.timer
journalctl --user -u insight-update.service -n 100 --no-pager
```

To keep the user timer running after logout, the server administrator can enable lingering:

```bash
sudo loginctl enable-linger "$USER"
```

Disable automation cleanly:

```bash
./scripts/install-auto-update.sh --remove
```

Without systemd, use the following command in the host scheduler after setting `INSIGHT_AUTO_UPDATE=1`:

```cron
17 4 * * * cd /opt/insight && ./scripts/update.sh --auto >> /var/log/insight-update.log 2>&1
```

## Release security

The stable channel ignores prereleases and accepts only annotated tags whose version matches `package.json`. The default remote is `origin` and can be changed with `INSIGHT_UPDATE_REMOTE`.

To additionally require a locally verifiable Git signature:

```dotenv
INSIGHT_UPDATE_REQUIRE_SIGNED_TAGS=1
```

This option requires the maintainers' signing keys to already be trusted on the server. Enable it only after signed tags are published.

## Failure and rollback

If the build, a migration, startup, or a health check fails, the script automatically rebuilds the previous commit. It never restores the database automatically because doing so could discard observations received in the meantime. The archive created before the operation remains in `backups/` for manual recovery.

To intentionally return to the code preceding the latest update:

```bash
./scripts/update.sh --rollback
```

Published migrations must remain additive and compatible with the previous version. Never edit an applied migration file: its checksum is validated in `insight_schema_migrations`.

Related settings:

| Variable | Default | Purpose |
| --- | --- | --- |
| `INSIGHT_AUTO_UPDATE` | `0` | Allows scheduled execution with `--auto` |
| `INSIGHT_UPDATE_REMOTE` | `origin` | Git remote containing official releases |
| `INSIGHT_UPDATE_BACKUP` | `1` | Creates an archive before deployment |
| `INSIGHT_UPDATE_REQUIRE_SIGNED_TAGS` | `0` | Requires a valid Git signature on the tag |
| `INSIGHT_UPDATE_HEALTH_TIMEOUT_SEC` | `180` | Maximum Docker service recovery time |
