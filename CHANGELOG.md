# Changelog

## 0.46.22 — 2026-09-17

### Features
- added test testing tools
- added admin panel config customization
- added some icons and responsiveness
- made quite a lot visual improvements
- added curseforge modpack download through manifest file
- curseforge support added
- installation handling has been improved with progress banner
- installation and recovery feature added
- added some new visual implementations
- icon-only toolbar and pagination controls with chevron buttons and filter badge
- updated installer toolbar and added compact icon ui models
- icon fallback on card banners, pill metadata and green install-complete button in modal
- modrinth-style catalog cards with banners, tags, and stats
- multi-value catalog filters, provider capabilities and redesigned dashboard
- installed-modpack lifecycle with CurseForge manual-download guidance
- CurseForge catalog browsing and exact-version selection
- catalog version picker and pinned-source install modal
- add provider-backed modpack catalog browser
- add Wings production server target
- add modrinth and curseforge providers
- add modrinth and curseforge providers
- polish Addon Manager theme integration
- harden blueprint theme compatibility
- complete Addon Manager dashboard
- add modpack metadata UI
- add server target resolver
- add server file target factory
- server indentity added
- Refactor DeploymentExecutor
- refactor backup manager to use server file target
- add local filesystem server target
- add server file target contract
- proper archive path added
- package layout added
- add installation preview
- add deterministic deployment rollback
- add orchestrator rollback
- harden deployment executor paths
- add secure backup manager
- add deployment policy
- add deployment planner
- add installation workspace
- add SSRF-safe download manager
- add safe archive extraction
- add secure archive validator
- add modpack metadata provider engine

### Fixes
- made some devoloemt related changes
- searchbar proper filter handling
- redefined the deployment logic for wrapper directory removal and minecraft version not found error for undefined version name modpack versions
- curseforge download downloading less files that the original mods list
- installation failing  over IPV6 and some modpacks getting downloaded in headless mode
- download stuck at 126mb default php rule fixed
- devloper variable were visible removed them
- made some structural changes to the project
- made some visual improvements in toolbar section
- build toubleshooting issue fised
- added responsive behaviour for all the components
- filter button and grid toggel alignment fixed
- restructured frontend
- the search bar and installed modpack button allignment change
- modal sizing, drop version filters and source row, full-width install button
- grey out install button after successful install
- panel white screen issue bug fixed
- security related bugs fixed
- add some ignore rules
- installer preview unable to load fixed
- provider path was wrong fixed it
- rollback test bug fixed

### Other
- refine: compact square cards, pill tags, provider badge, unified white actions
- refactor: remove install preview and layout/policy options
- refactor: use server file target for rollback
- refactor: use server file target in deployment planner
