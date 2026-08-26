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

        <x-ui.session-flash />

        @forelse ($owners as $owner)
            @php($name = $owner['owner'])
            <x-ui.card wire:key="owner-{{ $name }}">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <h2 class="text-base font-medium text-ink">{{ $name }}</h2>
                        <p class="mt-0.5 truncate text-xs text-muted">{{ implode(', ', array_column($owner['repos'], 'repo')) }}</p>
                    </div>
                    @if ($owner['all_public'])
                        <x-ui.badge variant="success">{{ __('Public — no token required') }}</x-ui.badge>
                    @else
                        <x-ui.badge :variant="$owner['has_token'] ? 'success' : 'warning'">
                            {{ $owner['has_token'] ? __('Token set') : __('No token') }}
                        </x-ui.badge>
                    @endif
                </div>

                @if (! $owner['all_public'] && collect($owner['repos'])->contains('visibility', 'public'))
                    {{-- Mixed owner: some repos are public, but the token still applies
                         to the ones that are not — name which are which rather than
                         implying the whole owner needs credentials. A repo GitHub could
                         not be reached about (rate limit, outage) is named separately —
                         it is not confirmed to need a token, only unconfirmed either way. --}}
                    @php($publicRepos = collect($owner['repos'])->where('visibility', 'public')->pluck('repo')->all())
                    @php($privateRepos = collect($owner['repos'])->where('visibility', 'private')->pluck('repo')->all())
                    @php($unknownRepos = collect($owner['repos'])->where('visibility', 'unknown')->pluck('repo')->all())
                    <p class="mt-2 text-xs text-muted">
                        {{ __('Public, no token needed: :repos.', ['repos' => implode(', ', $publicRepos)]) }}
                        @if ($privateRepos !== [])
                            {{ __('Needs a token: :repos.', ['repos' => implode(', ', $privateRepos)]) }}
                        @endif
                        @if ($unknownRepos !== [])
                            {{ __('Could not confirm right now (GitHub unreachable or rate-limited): :repos.', ['repos' => implode(', ', $unknownRepos)]) }}
                        @endif
                    </p>
                @endif

                <div class="mt-4 space-y-3">
                    @if ($owner['all_public'])
                        <p class="text-xs text-muted">{{ __('Every repository under :owner resolved anonymously. A token is optional here — add one only to raise your GitHub API rate limit.', ['owner' => $name]) }}</p>
                    @endif

                    <x-ui.link kind="external" href="https://github.com/settings/personal-access-tokens/new" class="text-xs">
                        {{ __('Create a fine-grained token for :owner — Resource owner: :owner, Contents: Read-only', ['owner' => $name]) }}
                    </x-ui.link>

                    <x-ui.secret-input
                        id="github-token-{{ $name }}"
                        wire:model="tokens.{{ $name }}"
                        :label="$owner['all_public'] ? __('Optional token for :owner', ['owner' => $name]) : __('Token for :owner', ['owner' => $name])"
                        :has-value="$owner['has_token']"
                        :show-reveal-button="true"
                        :reveal-subject="__('token')"
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
                <p class="text-sm text-muted">{{ __('No GitHub-hosted software sources found in this deployment.') }}</p>
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
