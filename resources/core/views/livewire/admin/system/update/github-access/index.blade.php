<div>
    <x-slot name="title">{{ __('GitHub Access') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('GitHub Access')"
            :subtitle="__('Tokens Belimbing uses to pull updates. Open-source modules are public and need none; private repositories each need a read-only token for their GitHub owner. A fine-grained token is scoped to one owner, so set one per private owner below.')"
        >
            <x-slot name="help">
                <p class="font-medium text-ink">{{ __('How to create a token') }}</p>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li>{{ __('GitHub → Settings → Developer settings → Fine-grained tokens → Generate new token.') }}</li>
                    <li>{{ __('Resource owner: set it to the owner on the card you are configuring (your account or the org).') }}</li>
                    <li>{{ __('Repository access + Permissions: the relevant repositories, Contents → Read-only.') }}</li>
                    <li>{{ __('Generate, copy, paste into that owner, and Save. Public owners need no token.') }}</li>
                </ol>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @forelse ($owners as $owner)
            @php($name = $owner['owner'])
            <x-ui.card wire:key="owner-{{ $name }}">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <h2 class="text-base font-medium text-ink">{{ $name }}</h2>
                        <p class="mt-0.5 truncate text-xs text-muted">{{ implode(', ', $owner['repos']) }}</p>
                    </div>
                    <x-ui.badge :variant="$owner['has_token'] ? 'success' : 'warning'">
                        {{ $owner['has_token'] ? __('Token set') : __('No token') }}
                    </x-ui.badge>
                </div>

                <div class="mt-4 space-y-3">
                    <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noreferrer"
                       class="inline-flex items-center gap-1 text-xs text-accent hover:underline">
                        <x-icon name="heroicon-o-arrow-top-right-on-square" class="h-3.5 w-3.5" />
                        {{ __('Create a fine-grained token for :owner — Resource owner: :owner, Contents: Read-only', ['owner' => $name]) }}
                    </a>

                    <x-ui.secret-input
                        id="github-token-{{ $name }}"
                        wire:model="tokens.{{ $name }}"
                        :label="__('Token for :owner', ['owner' => $name])"
                        :has-value="$owner['has_token']"
                        :error="$errors->first('tokens.'.$name)"
                    />

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="button" variant="primary" wire:click="save('{{ $name }}')" wire:loading.attr="disabled" wire:target="save('{{ $name }}')">
                            {{ __('Save token') }}
                        </x-ui.button>
                        <x-ui.button type="button" variant="outline" wire:click="test('{{ $name }}')" wire:loading.attr="disabled" wire:target="test('{{ $name }}')">
                            <span wire:loading.remove wire:target="test('{{ $name }}')">{{ __('Test connection') }}</span>
                            <span wire:loading wire:target="test('{{ $name }}')">{{ __('Testing…') }}</span>
                        </x-ui.button>
                        @if ($owner['has_token'])
                            <x-ui.button type="button" variant="ghost" wire:click="clearToken('{{ $name }}')" wire:confirm="{{ __('Remove the stored token for :owner?', ['owner' => $name]) }}">
                                {{ __('Clear') }}
                            </x-ui.button>
                        @endif
                    </div>

                    @if (! empty($testResults[$name] ?? []))
                        <ul class="space-y-1.5 pt-1">
                            @foreach ($testResults[$name] as $result)
                                <li class="flex items-center gap-3 text-sm">
                                    <x-ui.badge :variant="$result['ok'] ? 'success' : 'warning'">
                                        {{ $result['ok'] ? __('OK') : __('Fail') }}
                                    </x-ui.badge>
                                    <span class="font-mono text-ink">{{ $result['repo'] }}</span>
                                    <span class="text-muted">{{ $result['message'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </x-ui.card>
        @empty
            <x-ui.card>
                <p class="text-sm text-muted">{{ __('No GitHub-hosted Distribution Bundles found in this deployment.') }}</p>
            </x-ui.card>
        @endforelse

        <p class="text-xs text-muted">
            {{ __('Tokens are stored encrypted (integrations.github.token.{owner}) and never shown back.') }}
            <a href="{{ route('admin.system.integration-parameters.index') }}" class="text-accent hover:underline" wire:navigate>
                {{ __('Manage in Integration Parameters') }}
            </a>
        </p>
    </div>
</div>
