# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.2.0 — 2026-10-26

First release (FE-36). Cron expressions, tasks run in their own container scope, overlap locks
in the PSR-16 cache, failures logged, and the `schedule:run` and `schedule:list` commands.
