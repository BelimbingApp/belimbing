<?php

namespace App\Base\FeatureFlags;

use App\Base\FeatureFlags\Services\FeatureFlags;
use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/** @implements Rule<MethodCall> */
final class FeatureFlagReadRule implements Rule
{
    /** @var list<ModuleManifest>|null */
    private ?array $manifests = null;

    /** @param array<string, array<string, string>> $allowlist Module ID => flag => reason. */
    public function __construct(private string $projectRoot, private array $allowlist = [])
    {
        foreach ($allowlist as $flags) {
            foreach ($flags as $reason) {
                if (trim($reason) === '') {
                    throw new \InvalidArgumentException('Feature flag exceptions require a reason.');
                }
            }
        }
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || $node->name->toString() !== 'enabled'
            || ! (new ObjectType(FeatureFlags::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        $owner = $this->owner($scope->getFile());
        $values = isset($node->getArgs()[0]) ? $scope->getType($node->getArgs()[0]->value)->getConstantStrings() : [];
        if ($owner === null || $values === []) {
            return [RuleErrorBuilder::message('Feature flag reads require an owning module manifest and a statically known flag name.')
                ->identifier('blb.featureFlagOwnership')->build()];
        }

        $allowed = $owner->featureFlags;
        foreach ($this->manifests() as $manifest) {
            if (array_key_exists($manifest->module, $owner->requiresModules)) {
                $allowed += $manifest->featureFlags;
            }
        }
        $errors = [];
        foreach ($values as $value) {
            $flag = $value->getValue();
            if (! array_key_exists($flag, $allowed) && ! isset($this->allowlist[$owner->module][$flag])) {
                $errors[] = RuleErrorBuilder::message("Module [{$owner->module}] reads flag [{$flag}] without declaring it or directly requiring its declaring module.")
                    ->identifier('blb.featureFlagOwnership')->build();
            }
        }

        return $errors;
    }

    private function owner(string $file): ?ModuleManifest
    {
        foreach (ApplicationTopology::relativeRoots() as $root) {
            $prefix = $this->projectRoot.'/'.$root.'/';
            if (! str_starts_with($file, $prefix)) {
                continue;
            }
            $parts = explode('/', substr($file, strlen($prefix)));
            $depth = in_array($root, [ApplicationTopology::BASE, ApplicationTopology::CORE], true) ? 1 : 2;
            $directory = $prefix.implode('/', array_slice($parts, 0, $depth));
            foreach ($this->manifests() as $manifest) {
                if ($manifest->path === $directory) {
                    return $manifest;
                }
            }
        }

        return null;
    }

    /** @return list<ModuleManifest> */
    private function manifests(): array
    {
        return $this->manifests ??= (new ModuleManifestReader(array_map(
            fn (string $root): string => $this->projectRoot.'/'.$root,
            ApplicationTopology::relativeRoots(),
        )))->allIncludingDisabledDomains();
    }
}
