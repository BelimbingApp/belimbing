<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class OllamaHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Install Ollama from ollama.com/download for your operating system.'),
            __('Start the Ollama server by running: ollama serve'),
            __('Pull a model to use, for example: ollama pull llama3.2'),
            __('The server listens on :url by default — BLB is preconfigured for this address.', ['url' => 'http://localhost:11434']),
            __('Click "Update Models" in BLB to discover all locally available models.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Run "ollama list" in your terminal to confirm at least one model is downloaded.'),
            __('If Ollama is already installed, make sure it is running: open a terminal and run "ollama serve".'),
            __('On macOS, Ollama may run as a menu-bar app — look for the llama icon and ensure it shows "Running".'),
            __('If you changed the Ollama port, update the Base URL in the provider settings to match (e.g. http://localhost:11435/v1).'),
            __('Firewall or VPN software may block localhost connections — temporarily disable them to test.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return 'https://ollama.com/';
    }

    public function connectionErrorAdvice(): string
    {
        return __('Ollama does not appear to be running. Start it with "ollama serve" in a terminal, or open the Ollama app.');
    }
}
