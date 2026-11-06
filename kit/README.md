# unraid-plugin-kit

The shared build, test and About tab of these Unraid plugins. A plugin repository holds a copy in `kit/`, made by `bin/kit-sync`, so it builds by itself. Nothing on a server depends on this repository or on another plugin: what a plugin needs at run time goes into its package.

| Path | What |
|---|---|
| `bin/build.sh` | Package, `.txz` checks (root 755, owner 0/0), md5 into the `.plg`, `CHANGELOG.md`, generated About page |
| `bin/lint.sh` | shellcheck, `php -l`, `node --check`, page headers, the `.plg`'s inline blocks |
| `bin/check-public.sh` | Readiness for a public push: private names, identity, tracked tooling, required files |
| `bin/kit-sync` | Copies the kit into a plugin repository and records the commit in `kit/KIT-VERSION` |
| `bin/changes-from-plg.sh` | The `.plg`'s `<CHANGES>` as plain text, the one source of the release notes |
| `runtime/` | Goes into the package: About tab, install message, changes parser |
| `render/` | Pages rendered with Unraid's real stylesheets in headless Chrome, on a workstation |
| `tests/kit-test.sh` | Builds a throwaway plugin and checks the result |
| `docs/STANDARD.md` | What a plugin of this family has to do |

A plugin needs `plugin.conf`, `<name>.plg`, `src/`, and a `build.sh` that runs `kit/bin/build.sh`.

The copy in a plugin's `kit/` holds everything that goes into its package, and the tools that build it, so a plugin repository is complete source by itself.

## Licence

GPL-2.0, see [LICENSE](LICENSE). Copyright (C) 2026 the plugin kit project.
