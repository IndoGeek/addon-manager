# Architecture

## Overview

Modpack Installer is designed as a modular Blueprint extension for
Pterodactyl.

The system is divided into several logical layers:

    Pterodactyl
         │
         ▼
    Blueprint
         │
         ▼
    Modpack Installer UI
         │
         ▼
    Application/API Layer
         │
         ▼
    Installation Engine
         │
         ├── Downloader
         ├── Archive Handler
         ├── File Deployer
         └── Server Integration
         │
         ▼
    Modpack Providers
         ├── Modrinth
         ├── CurseForge
         └── Future Providers

## Design Goals

### Pterodactyl compatibility

The extension should integrate with Pterodactyl rather than replacing
or modifying the panel unnecessarily.

### Blueprint compatibility

Use Blueprint's supported extension mechanisms wherever possible.

### Theme compatibility

The extension must not assume a specific third-party panel theme.

### Extension compatibility

The extension must avoid modifying shared global UI structures in ways
that could break other Blueprint extensions.

### Provider independence

The installation engine must not depend directly on a particular
modpack provider.

### Security

Downloaded archives and files must be treated as untrusted input.
Installation must validate paths, archives and provider responses.

#### Threat model and hardening

Every input boundary treats its data as untrusted and is validated:

- **Request input.** Modpack sources, policies and layouts are scalar-typed
  and length-capped in the controller before they reach a provider or the
  installation engine. Rejections are `422 Bad Request` with static messages.
- **Provider payloads.** Structured (JSON) provider responses are type-checked
  at parse time; malformed lists or missing required fields become controlled
  errors, never crashes. The CurseForge API key is transmitted only inside the
  request header and never appears in URLs, logs, or error messages.
- **Downloads.** The downloader refuses non-HTTP(S) schemes, URLs containing
  credentials, and hosts whose DNS records point at private or reserved
  address space (loopback, link-local, ULA, multicast, CGNAT `100.64/10`,
  documentation and benchmark ranges, NAT64 `64:ff9b::/96`, IPv4-mapped IPv6).
  Addresses are resolved first and then pinned with `CURLOPT_RESOLVE`, so a
  hostname can never be re-resolved by libcurl to a different (internal)
  address. Redirects are followed only hop-by-hop with per-hop re-validation
  (scheme, host, credentials, address), capped at 5 hops, and every hop body
  is streamed against a byte budget. Connection and total timeouts bound the
  request. Download size is capped (`MODPACK_INSTALLER_MAX_DOWNLOAD_MB`,
  default 2 GiB) and enforced during the transfer, so no oversized file is
  ever written to disk.
- **Archives.** A validation pass runs before extraction and rejects path
  traversal, absolute paths, Windows drive letters, NULs, backslashes,
  duplicate or conflicting entries, symbolic links, special device types, and
  abuse that exceeds the configured size/entry budget. Extraction is
  per-entry and bounded at runtime, and a failed extraction is fully cleaned
  up. The validator's limits are the extractor's limits.
- **Workspaces.** Extracted content stays inside a per-install workspace under
  the shared temporary root. The package-root resolver asserts the selected
  override directory remains inside that workspace, and the deployment
  planner re-checks that every planned source file is contained in it.
- **Deployment.** Every relative path is re-validated against absolute paths,
  traversal, NULs, and drive letters immediately before backup or write.
  Writes go through the server file target (`local` filesystem or Wings),
  never through direct filesystem access on the Panel. Overwritten files are
  backed up and restored on failure; rollback is best-effort and its
  failures are reported, never swallowed silently.
- **Concurrency.** A per-server filesystem lock serializes installs. A second
  install for the same server fails fast (`503 Service Unavailable`),
  stale locks are reclaimed after a timeout, and a lock can only be released
  by the request that holds it (token check).
- **Error hygiene.** Client-facing messages are static and never embed hosts,
  URLs, paths, tokens, the daemon key, the server UUID, or the CurseForge key.
  Unexpected internal errors are reported to the panel logs and surfaced as a
  generic `500` message.

#### Known limitations

- The downloader's success/redirect path uses real public hosts and is
  therefore verified during deployment rather than in the hermetic suite.
- Panel log lines from `report()` are only as sanitized as their inputs;
  provider HTTP failure details are mapped to static messages before
  bubbling up. (CurseForge authorization headers are never logged.)
