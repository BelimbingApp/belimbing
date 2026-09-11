# extension-sqlsrv-runtime-prerequisite

Status: Ready for review
Last Updated: 2026-09-12
Sources: app/Base/Foundation/Services/ExtensionInstaller.php; app/Extensions/SbGroup/Admin/composer.json
Agents: root/gpt-5

## Problem Essence

An Extension can require host-level PHP support that Composer cannot install. SBGroup's AX integration needs Microsoft's ODBC driver and the `sqlsrv` PDO driver in a separate system PHP CLI, while the web application uses FrankenPHP, which cannot load that driver.

## Desired Outcome

Extension installation must reject an unmet declared host prerequisite before migrations run, tell the operator how to provision it using platform-owned code, and never execute an Extension-provided privileged hook. A feature-specific preflight uses the mbstring CLI profile only when SBG's market-spot importer is configured to run.

## Design Decisions

An Extension-provided setup script would be convenient but turns a repository clone into privileged code execution. A generic package-manager abstraction would imply support for host combinations BLB has not tested. The selected design accepts only reviewed profile names in Module manifests, verifies them through the intended CLI, and points to one Ubuntu/Debian platform script for the SQL Server profile.

## Public Contract

Modules may declare `extra.blb.runtime-requirements` as a list of platform profile names. `php-cli-sqlsrv` requires a CLI selected by `BLB_SYSTEM_PHP_BINARY` (or `php`) to load `sqlsrv`, `pdo_sqlsrv`, and expose `sqlsrv` through `PDO::getAvailableDrivers()`. `php-cli-mbstring` requires the same CLI to load `mbstring`. Operators provision the profiles with `scripts/setup-steps/16-sqlsrv-cli.sh` and `scripts/setup-steps/17-mbstring-cli.sh`.

## Phases

### Platform enforcement

- [x] Parse Module runtime-requirement profile names and reject unsupported profiles. {root/gpt-5}
- [x] Preflight catalog and repository-installed Extensions before migrations, removing a checkout with unmet requirements. {root/gpt-5}
- [x] Ship a platform-owned Ubuntu/Debian SQL Server CLI provisioning script and focused verifier tests. {root/gpt-5}
- [x] Add the mbstring CLI profile, verifier coverage, and Ubuntu/Debian provisioning script. {root/gpt-5}

### SBGroup declaration

- [x] Declare the AX-owning Module's SQL Server CLI prerequisite and consume the shared CLI setting. {root/gpt-5}
- [x] Update the operator guide to use the provisioning command. {root/gpt-5}
- [x] Preflight mbstring only when the configured IBP market-spot importer runs, with an actionable failure message. {root/gpt-5}
