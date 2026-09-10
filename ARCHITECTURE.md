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
