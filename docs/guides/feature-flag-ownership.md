# Check feature flag ownership

Run `vendor/bin/phpstan analyse -c phpstan-feature-flags.neon --memory-limit=2G`
from the platform root. The quality workflow runs this rule across all four
application roots present in the checkout, including installed disabled Domains.
Unmounted Domain repositories are not analysed by a platform-only checkout.

The reusable Domain CI workflow runs the same pass in its SQLite lane after
Pint and before Domain Pest, against the materialized platform and pinned
Domains. A caller must advance its immutable workflow reference to adopt this
step; an older workflow pin does not inherit the new check automatically.

The rule checks calls to `enabled()` on values known by PHPStan to be
`App\Base\FeatureFlags\Services\FeatureFlags`. A caller's owning Module must
declare the flag in `extra.blb.feature-flags` or directly name its declaring
Module in `requires-modules`. An optional or transitive dependency does not grant
this permission. Descriptor parsing uses the platform manifest reader; ownership
uses the four-root physical Module boundaries, not namespace spelling.

The diagnostic identifies the consumer and flag. Correct the flag name or declare
the actual dependency. The `allowlist` argument in `phpstan-feature-flags.neon`
supports narrowly reviewed exceptions as Module ID → flag → nonblank reason.
There are no default exceptions. A missing owner manifest or a name with no
statically known string value is refused.

This is static ownership checking, not runtime authorization or a proof about
calls made through unknown receiver types, reflection, or dynamic dispatch.
Keep feature flag consumers typed. The ordinary runtime registry still refuses
undeclared flags and resolves tenant overrides independently of this check.
