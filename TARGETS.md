# Server Target

The installer writes modpack files into a server's file tree through a
pluggable **server file target**. The active target is chosen once per
request from the `MODPACK_INSTALLER_SERVER_TARGET` environment variable:

| Value   | Target                          | Intended use            |
|---------|---------------------------------|-------------------------|
| `local` | Local filesystem                | Development and tests   |
| `wings` | Pterodactyl Wings via the node  | Production              |

Defaults to `local`. An unset, empty, or unknown value is a hard
configuration error (`422 Bad Request`), never a silent fallback.

## local mode (development / tests)

Files are written to `MODPACK_INSTALLER_SERVER_ROOT` (falls back to
`VOLUMES_ROOT` under `/var/lib/pterodactyl/volumes`). The server's files live
in `<root>/<server-uuid>/`. This mode requires the Panel process to be able to
read and write those directories directly, which is why it is **not** used in
production.

## wings mode (production)

The Panel never touches the server filesystems itself. For each request the
installer resolves the authenticated server's **node** from the Panel database
and talks directly to the node's Wings daemon:

- Connection address and daemon key come exclusively from the
  `Pterodactyl\Models\Node` model (`getConnectionAddress()` /
  `getDecryptedKey()`), the same source the Panel's own daemon repositories
  use. No URL, host, port, or token is ever accepted from a client request.
- Every call is authenticated with the node's daemon key (`Authorization:
  Bearer ...`), scoped to the server UUID. There is no fallback to the local
  filesystem: if the node is unreachable or returns an error, the request
  fails with `503 Service Unavailable` and a generic message.
- SSL certificate verification (`verify_ssl`) is enabled when the Panel is in
  the `production` environment and disabled otherwise.
- Timeouts: 30s request timeout, 10s connection timeout.

## Backup and rollback

Both targets support the full backup/rollback lifecycle. In `wings` mode the
file bytes staged by the backup manager are kept in temporary storage on the
Panel web server and then pushed back through Wings during a rollback, so a
preexisting file's original content is always restorable.

## Security notes

- Any server can use the installer against its **own** node. There is no
  mechanism (and no capability) to write to a different server's files.
- Invalid or unsafe relative paths (absolute paths, `..`, NUL bytes, drive
  letters, reserved names) are rejected before any request reaches Wings.
- Error messages returned to the client never include the node address, the
  daemon key, or the server UUID.

## Verifying the active mode

From the Panel web root:

```bash
php -r 'var_dump(env("MODPACK_INSTALLER_SERVER_TARGET", "local"));'
```

## Troubleshooting

- **`The server node could not complete the operation`** (`503`): Wings
  returned a non-success status (e.g. `403` when the daemon key is invalid or
  a file is denylisted, `429` during rate limiting, or a `5xx` on the node).
  Check `wings.service` and the node's address in the admin panel.
- **`Unable to reach the server node`** (`503`): The node did not respond
  within the timeout (offline, wrong address, TLS mismatch). The Panel's
  "daemon connectable" indicator is a good first check.

## Limitations

- The file tree is currently read and written only through the installer's
  operations (`exists` / `isDirectory` / `read` / `write` / `delete` /
  `ensureDirectory`); there is no full recursive directory listing surface.
  Because of this, uninstall removes exactly the files an install record owns
  and never prunes directories: now-empty folders may remain after uninstall.
- Concurrent install/update/uninstall operations for the **same** server are
  serialized by a per-server filesystem lock (see ARCHITECTURE.md): while one
  is running, another for that server fails fast with `503 Service
  Unavailable`. Stale locks are reclaimed automatically after their timeout.
  Different servers never contend with each other.
- The downloader's live success/redirect path is validated at deployment time
  rather than in the hermetic test suite (the downloader never routes to
  private hosts, including the loopback interface, by design).