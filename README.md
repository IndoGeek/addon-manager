# Modpack Installer

A modular modpack installation extension for Pterodactyl powered by
Blueprint.

## Goals

- One-click modpack installation
- Multiple modpack providers
- Pterodactyl compatibility
- Blueprint compatibility
- Panel theme compatibility
- Safe and reliable installations
- Extensible provider architecture

## Status

🚧 Early development

## Development

This project is developed as a Blueprint extension.

The source repository is maintained separately from the Pterodactyl
installation. Blueprint's `.blueprint/dev` directory is used only as the
development deployment target.

## Providers

The installer ships with a mock provider for development plus Modrinth and
CurseForge providers. See [PROVIDERS.md](PROVIDERS.md) for source formats,
configuration, and current package limitations.

## Server Target

Installer files are written to a server's file tree through a pluggable
"server file target". In development (and in the test suite) this is the
local filesystem; in production the installer can talk directly to the
Pterodactyl Wings daemon for the server's node.

See [TARGETS.md](TARGETS.md) for both modes, configuration, security
considerations, and current limitations.

## License

TBD
