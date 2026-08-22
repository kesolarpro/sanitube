<script setup lang="ts">
import { computed, reactive } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import ProbeReport from '@/Components/Settings/ProbeReport.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { ProbeResult, SettingsOverview } from '@/Types/settings';

/**
 * What this installation is configured with.
 *
 * Every credential is one of two words. There is no reveal button, no "show
 * last four", no copy action — the payload does not contain the value, so
 * there is nothing for a control to reveal, and building one would only invite
 * somebody to make the payload carry it.
 *
 * Debug mode and the configuration cache are given their own callouts. Both
 * are settings whose being wrong is invisible from every other screen: debug
 * on in production hands stack traces to whoever triggers an error, and a
 * built config cache means an edited .env has changed nothing at all.
 *
 * **The editable fields start empty and stay empty.** A secret's input is
 * never populated, because the payload has never carried a value to populate
 * it with — and the form is submitted with the fields as typed, so leaving one
 * alone is how you leave the value alone. There is no reveal, no mask and no
 * "clear" control: emptying a value stays a .env edit, deliberately, so that
 * saving a rate limit can never silently unset a provider key.
 */
const props = defineProps<{ settings: SettingsOverview; writable: string[] }>();

const page = usePage<SharedProps>();

const draft = reactive<Record<string, string>>({});
const saving = computed(() => false);

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.settings ?? null;
});

function isWritable(variable: string): boolean {
    return props.writable.includes(variable);
}

function save(): void {
    // Only what was actually typed into. Sending every field as an empty
    // string would mean nothing, since a blank field changes nothing — but it
    // would also make the request describe an intention nobody had.
    const changes: Record<string, string> = {};

    for (const [variable, value] of Object.entries(draft)) {
        if (value.trim() !== '') {
            changes[variable] = value;
        }
    }

    router.patch('/settings', changes, {
        preserveScroll: true,
        onSuccess: () => {
            for (const key of Object.keys(draft)) {
                delete draft[key];
            }
        },
    });
}

/**
 * "Does this actually work?", asked without SSH.
 *
 * The target is never composed here: it is the word the payload gave this
 * section, sent back unchanged. A settings screen that let the browser name
 * what to reach would be a settings screen that reaches whatever anybody asks
 * it to.
 */
const probing = reactive<Record<string, boolean>>({});
const probed = reactive<Record<string, ProbeResult>>({});

/** The last answer for a section, or null when it has not been asked. */
function probeOf(key: string): ProbeResult | null {
    return probed[key] ?? null;
}

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function test(section: { key: string; probe: string | null }): Promise<void> {
    const target = section.probe;

    if (target === null || probing[section.key] === true) {
        return;
    }

    probing[section.key] = true;
    delete probed[section.key];

    try {
        const response = await fetch('/settings/test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ target }),
        });

        if (!response.ok) {
            throw new Error('refused');
        }

        probed[section.key] = (await response.json()) as ProbeResult;
    } catch {
        // Deliberately without detail. A request that never came back carries
        // the address it could not reach, and this page has never let one out.
        probed[section.key] = { status: 'UNREACHABLE', checks: [] };
    } finally {
        probing[section.key] = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.settings.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.settings.description') }}</p>
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.settings.not_saved')">
            {{ trans(`ui.settings.failure.${refusal}`) }}
        </AppAlert>

        <AppAlert tone="info" :title="trans('ui.settings.editing')">
            {{ trans('ui.settings.editing_note') }}
        </AppAlert>

        <AppAlert
            v-if="settings.application.debug"
            tone="danger"
            :title="trans('ui.settings.debug_is_on')"
        >
            {{ trans('ui.settings.debug_is_on_note') }}
        </AppAlert>

        <AppCard>
            <template #header>{{ trans('ui.settings.application') }}</template>

            <dl class="grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.settings.environment') }}</dt>
                    <dd class="text-small text-foreground">{{ settings.application.environment }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.settings.locale') }}</dt>
                    <dd class="text-small text-foreground">{{ settings.application.locale }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.settings.locales') }}</dt>
                    <dd class="text-small text-foreground">{{ settings.application.locales.join(', ') }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.settings.config_cached') }}</dt>
                    <dd class="text-small text-foreground">
                        {{
                            settings.application.config_cached
                                ? trans('ui.settings.config_cached_yes')
                                : trans('ui.settings.config_cached_no')
                        }}
                    </dd>
                </div>
            </dl>

            <p v-if="settings.application.config_cached" class="mt-3 text-caption text-muted">
                {{ trans('ui.settings.config_cached_note') }}
            </p>
        </AppCard>

        <AppCard v-for="section in settings.sections" :key="section.key">
            <template #header>{{ trans(`ui.settings.section.${section.key}`) }}</template>

            <dl class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.settings.selected') }}</dt>
                    <dd class="text-small text-foreground">{{ section.selected }}</dd>
                </div>
                <div v-if="section.options.length > 0">
                    <dt class="text-caption text-muted">{{ trans('ui.settings.options') }}</dt>
                    <dd class="text-small text-foreground">{{ section.options.join(', ') }}</dd>
                </div>
            </dl>

            <!-- A named provider with no adapter. Not a missing credential,
                 and not the same thing to fix. -->
            <AppAlert
                v-if="!section.known"
                tone="danger"
                class="mt-3"
                :title="trans('ui.settings.unknown_provider')"
            >
                {{ trans('ui.settings.unknown_provider_note') }}
            </AppAlert>

            <div v-if="section.settings.length > 0" class="mt-4 border-t border-border pt-3">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div v-for="setting in section.settings" :key="setting.variable">
                        <dt class="text-caption text-muted">
                            <label :for="setting.variable"><CodeValue :value="setting.variable" /></label>
                        </dt>
                        <dd class="text-small text-foreground">
                            <template v-if="isWritable(setting.variable)">
                                <TextInput
                                    :id="setting.variable"
                                    v-model="draft[setting.variable]"
                                    :placeholder="setting.value"
                                />
                            </template>
                            <template v-else>{{ setting.value }}</template>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="mt-4 border-t border-border pt-3">
                <p v-if="section.secrets.length === 0" class="text-small text-muted">
                    {{ trans('ui.settings.no_secrets') }}
                </p>

                <ul v-else class="space-y-2">
                    <li
                        v-for="secret in section.secrets"
                        :key="secret.variable"
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <label :for="secret.variable"><CodeValue :value="secret.variable" /></label>
                        <!-- Two words, and no third option. There is nothing
                             to reveal because nothing was sent. -->
                        <span :class="secret.configured ? 'text-small text-success' : 'text-small text-warning'">
                            {{
                                secret.configured
                                    ? trans('ui.settings.configured')
                                    : trans('ui.settings.not_configured')
                            }}
                        </span>
                        <!-- Always empty, on every load. The field is where a
                             replacement is typed, never where the current
                             value is shown. -->
                        <TextInput
                            v-if="isWritable(secret.variable)"
                            :id="secret.variable"
                            v-model="draft[secret.variable]"
                            type="password"
                            autocomplete="new-password"
                            class="w-full sm:w-64"
                            :placeholder="trans('ui.settings.replace_placeholder')"
                        />
                    </li>
                </ul>

                <p class="mt-3 text-caption text-muted">{{ trans('ui.settings.never_shown') }}</p>
            </div>

            <!-- CFG-001. The one control on this page that reaches anything.
                 It sends back the word the server put in `probe` and nothing
                 else, so there is no address for a caller to choose. -->
            <div v-if="section.probe !== null" class="mt-4 border-t border-border pt-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-caption text-muted">{{ trans('ui.settings.probe_note') }}</p>
                    <AppButton :loading="probing[section.key] === true" @click="test(section)">
                        {{
                            probing[section.key] === true
                                ? trans('ui.settings.testing')
                                : trans('ui.settings.test_connection')
                        }}
                    </AppButton>
                </div>

                <ProbeReport :result="probeOf(section.key)" />
            </div>
        </AppCard>

        <div class="flex items-center justify-end gap-3">
            <p class="text-caption text-muted">{{ trans('ui.settings.blank_means_unchanged') }}</p>
            <AppButton :loading="saving" @click="save">{{ trans('ui.settings.save') }}</AppButton>
        </div>
    </div>
</template>
