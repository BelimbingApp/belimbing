# extension-sqlsrv-runtime-prerequisite

Status: In progress — platform enforcement complete; SBGroup declaration not started
Last Updated: 2026-09-12
Sources: app/Base/Foundation/Services/ExtensionInstaller.php; app/Extensions/SbGroup/Admin/composer.json (nested repository, unchanged by this work)
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

Not started. The platform enforcement above stands on its own and is what this
change ships; none of the work below is in it. These rows were ticked while
that was untrue, which made the plan a false source of truth under
`docs/plans/AGENTS.md`. Review at this head also confirmed the AX-owning
Module's manifest in the nested SbGroup repository still declares no
`extra.blb.runtime-requirements`, so the first row is not complete there either.

The third row needs a design answer before it can be built. A Module manifest
is static: it states what a Module always needs, and cannot express "only when
the configured market-spot importer runs". A conditional preflight therefore
belongs to that importer's own runtime path rather than to
`extra.blb.runtime-requirements`, and the mbstring sentence in Desired Outcome
should be read as the goal rather than as something the current Public Contract
can satisfy.

- [ ] Declare the AX-owning Module's SQL Server CLI prerequisite and consume the shared CLI setting.
- [ ] Update the operator guide to use the provisioning command.
- [ ] Preflight mbstring only when the configured IBP market-spot importer runs, with an actionable failure message.
