<div class="space-y-section-gap">
    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Field-Level Edit-in-Place')"
                component="<code>x-ui.edit-in-place.text</code>, <code>x-ui.edit-in-place.select</code>, <code>x-ui.edit-in-place.textarea</code>"
            >
                {{ __('Use this pattern on detail pages when the user should review facts first, then update one field without navigating to a separate edit form.') }}
            </x-ui.catalog-section>

            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Read First') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Values render as normal detail text. The pencil appears on hover or focus as an affordance, not as visual clutter.') }}</p>
                </div>
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('One Field at a Time') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Clicking a value swaps only that value into an input, select, or textarea. The rest of the page stays readable.') }}</p>
                </div>
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Keyboard Contract') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Enter saves short text, blur commits, and Escape restores the last saved value.') }}</p>
                </div>
            </div>
        </div>
    </x-ui.card>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Detail Facts Example')"
                    component="<code>x-ui.edit-in-place.*</code>"
                >
                    {{ __('This mirrors Company, Address, and Inventory detail pages: compact facts, direct field edits, and page-owned Livewire save methods. Edit-in-place help opens a quiet line below the label so the value row remains readable.') }}
                </x-ui.catalog-section>

                <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.edit-in-place.text
                        :label="__('Title')"
                        :value="$editInPlaceTitle"
                        field="editInPlaceTitle"
                        save-method="saveReferenceField"
                        :help="__('Use help beside the label for edit-in-place fields. It keeps the value line clean while preserving local context.')"
                    />

                    <x-ui.edit-in-place.select
                        :label="__('Review Status')"
                        :value="$editInPlaceStatus"
                        field="editInPlaceStatus"
                        save-method="saveReferenceField"
                        :help="__('Status controls describe workflow state. Keep the help short because the badge should remain the visual focus.')"
                    >
                        <x-slot name="read">
                            <x-ui.badge :variant="match($editInPlaceStatus) {
                                'approved' => 'success',
                                'blocked' => 'danger',
                                'review' => 'warning',
                                default => 'default',
                            }">
                                {{ __(Illuminate\Support\Str::headline($editInPlaceStatus)) }}
                            </x-ui.badge>
                        </x-slot>

                        @foreach ($statusOptions as $option)
                            <option value="{{ $option['value'] }}">{{ __($option['label']) }}</option>
                        @endforeach
                        <option value="blocked">{{ __('Blocked') }}</option>
                    </x-ui.edit-in-place.select>
                </dl>

                <dl class="border-t border-border-default pt-4">
                    <x-ui.edit-in-place.textarea
                        :label="__('Operator Notes')"
                        :value="$editInPlaceNotes"
                        field="editInPlaceNotes"
                        save-method="saveReferenceField"
                        :empty="__('No notes captured yet.')"
                        :help="__('Private notes can be longer, so help stays on the label instead of adding another line below the value.')"
                        rows="5"
                    />
                </dl>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-3">
                <x-ui.catalog-section
                    :title="__('When to Use')"
                    component="<code>detail pages</code>"
                />

                <div class="space-y-3 text-sm text-muted">
                    <p>{{ __('Use field-level edit-in-place for low-risk factual attributes where the saved value is obvious from the field itself.') }}</p>
                    <p>{{ __('Prefer a full form when edits are multi-step, destructive, require confirmation, or depend on several fields changing together.') }}</p>
                    <p>{{ __('Keep authorization, validation, persistence, and data conversion in the Livewire page. The UI component owns interaction behavior only.') }}</p>
                </div>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Disclosure Chevron (Expand / Collapse)')"
                component="<code>x-ui.disclosure</code> (<code>variant</code>: <code>section</code> | <code>card-header</code>)"
            >
                {{ __('Use this for compact “optional detail” sections inside cards (e.g., Change Password, Effective Permissions). The chevron is purely an affordance; keep the trigger text as the accessible name and animate only opacity/transform.') }}
            </x-ui.catalog-section>

            <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                <x-ui.disclosure :title="__('Advanced Options')">
                    <p class="text-sm text-ink">{{ __('This content is hidden by default and revealed on demand.') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('Keep disclosure sections short and avoid placing destructive actions inside unless you add confirmation.') }}</p>
                </x-ui.disclosure>
            </div>

            <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                <x-ui.disclosure
                    :title="__('Card Header Disclosure')"
                    variant="card-header"
                    :default-open="true"
                    panel-id="ui-ref-card-header-disclosure"
                    content-class="mt-3 space-y-2"
                >
                    <x-slot name="hint">
                        <p class="text-xs text-muted">{{ __('Use the card-header variant when the disclosure is the primary card title, and you need a richer trigger (focus ring, larger type).') }}</p>
                    </x-slot>

                    <p class="text-sm text-ink">{{ __('This panel starts open and uses the shared disclosure transitions.') }}</p>
                    <p class="text-xs text-muted">{{ __('Provide a stable panel id when you want aria-controls to point at the revealed content.') }}</p>
                </x-ui.disclosure>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Destructive-Action Acknowledgment')"
                component="<code>x-ui.acknowledge-input</code>"
            >
                {{ __('Use this pattern for permanent, irreversible actions (dropping tables, uninstalling a domain, deleting an account). The user types a phrase that states the consequence; the danger button does not exist on the page until the phrase matches and a target is selected. No browser confirm dialog on top — the typed phrase is the confirmation.') }}
            </x-ui.catalog-section>

            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Phrase States the Consequence') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('THIS CANNOT BE UNDONE, or a command phrase like "uninstall commerce" — never a magic word. Expose the phrase as a class constant on the Livewire component.') }}</p>
                </div>
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Buttons Appear Only When Armed') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Bind with wire:model.live and render the danger button only when the phrase matches and the action has selected targets. The button label states scope and permanence: "Permanently drop 2 table(s)".') }}</p>
                </div>
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Server Re-Checks') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('The action method re-validates the phrase and adds an error on mismatch — hiding the button is UX, not security. Live examples: Database Residue, Domains uninstall, Profile delete account.') }}</p>
                </div>
            </div>

            <div class="max-w-md rounded-2xl border border-status-danger-border bg-status-danger-subtle p-4">
                <x-ui.acknowledge-input
                    id="ui-ref-acknowledge-demo"
                    :phrase="'THIS CANNOT BE UNDONE'"
                    :label="__('Acknowledgment')"
                    :help="__('Demo only — nothing is wired to this input.')"
                />
            </div>
        </div>
    </x-ui.card>
</div>
