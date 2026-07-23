<?php

namespace App\Modules\Core\AI\Services;

use App\Base\Settings\Contracts\SettingsService;

/**
 * Resolves Lara's interactive chat tool set.
 *
 * The default coding-agent surface is fixed in code. Operators may only add
 * or remove extra tools around that default, keeping the harness stable while
 * making opt-in capabilities visible and reversible.
 */
class LaraInteractiveToolSet
{
    private const EXTRA_TOOLS_SETTING = 'ai.lara.interactive_extra_tool_names';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AgentToolRegistry $toolRegistry,
    ) {}

    /**
     * @return list<string>
     */
    public function defaultToolNames(): array
    {
        return ChatTurnRunner::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES;
    }

    /**
     * @return list<string>
     */
    public function extraToolNames(): array
    {
        $stored = $this->settings->get(self::EXTRA_TOOLS_SETTING);

        if (! is_array($stored)) {
            return [];
        }

        $defaultNames = $this->defaultToolNames();
        $extraNames = [];

        foreach ($stored as $toolName) {
            if (! is_string($toolName)
                || in_array($toolName, $defaultNames, true)
                || ! $this->toolRegistry->isRegistered($toolName)
                || in_array($toolName, $extraNames, true)) {
                continue;
            }

            $extraNames[] = $toolName;
        }

        return $extraNames;
    }

    /**
     * @return list<string>
     */
    public function enabledToolNames(): array
    {
        return [...$this->defaultToolNames(), ...$this->extraToolNames()];
    }

    /**
     * @return list<string>
     */
    public function candidateExtraToolNames(): array
    {
        $defaultNames = $this->defaultToolNames();
        $candidateNames = array_values(array_filter(
            $this->toolRegistry->registeredToolNames(),
            fn (string $toolName): bool => ! in_array($toolName, $defaultNames, true),
        ));

        sort($candidateNames);

        return $candidateNames;
    }

    public function setExtraToolEnabled(string $toolName, bool $enabled): void
    {
        if (in_array($toolName, $this->defaultToolNames(), true) || ! $this->toolRegistry->isRegistered($toolName)) {
            return;
        }

        $extraNames = $this->extraToolNames();

        if ($enabled && ! in_array($toolName, $extraNames, true)) {
            $extraNames[] = $toolName;
        }

        if (! $enabled) {
            $extraNames = array_values(array_filter(
                $extraNames,
                fn (string $enabledToolName): bool => $enabledToolName !== $toolName,
            ));
        }

        $this->settings->set(self::EXTRA_TOOLS_SETTING, $extraNames);
    }
}
