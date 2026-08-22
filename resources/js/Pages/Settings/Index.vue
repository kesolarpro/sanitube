<script setup lang="ts">
import { computed, reactive } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import ProbeReport from '@/Components/Settings/ProbeReport.vue';
import StandingsTable from '@/Components/Settings/StandingsTable.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { trans } from '@/Support/i18n';
import { csrfToken, refusalFrom } from '@/Support/request';
import type { SharedProps } from '@/Types/inertia';
import type { ProbeResult, ProviderStanding, SettingsOverview } from '@/Types/settings';

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
const props = defineProps<{ settings: SettingsOverview; writable: string[]; standings: ProviderStanding[] }>();

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

/**
 * What the server said about one field.
 *
 * CFG-004. Every writable setting on this page is validated — a provider name
 * outside the vocabulary, a backup path inside the web root, a byte ceiling
 * past what the host could carry — and until now none of those refusals had
 * anywhere to appear. The form simply came back unchanged, which reads as "it
 * saved" and is the worst possible answer to "it refused".
 */
function fieldError(variable: string): string | null {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.[variable] ?? null;
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

/**
 * What pressing the button on this section actually does.
 *
 * Storage writes and deletes its own object; mail *sends* something, to the
 * signed-in operator and nowhere else. Saying so beside the button matters
 * more here than anywhere: a test that mails a stranger is a different act
 * from one that touches a bucket.
 */
function probeNote(probe: string | null): string {
    return probe === 'mail' ? trans('ui.settings.probe_mail_note') : trans('ui.settings.probe_note');
}

/** The last answer for a section, or null when it has not been asked. */
function probeOf(key: string): ProbeResult | null {
    return probed[key] ?? null;
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
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ target }),
        });

        if (!response.ok) {
            // UPL-005. A refusal is not an unreachable server, and saying so
            // sent somebody looking at their storage credentials when their
            // session had simply expired. The code is read from the response
            // through the shared rule; UNREACHABLE stays the last resort, for
            // a refusal that named nothing this screen understands.
            probed[section.key] = { status: await refusalFrom(response, 'UNREACHABLE'), checks: [] };

            return;
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
                                <p v-if="fieldError(setting.variable)" class="mt-1 text-caption text-danger">
                                    {{ fieldError(setting.variable) }}
                                </p>
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
                        <div v-if="isWritable(secret.variable)" class="w-full sm:w-64">
                            <TextInput
                                :id="secret.variable"
                                v-model="draft[secret.variable]"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="trans('ui.settings.replace_placeholder')"
                            />
                            <p v-if="fieldError(secret.variable)" class="mt-1 text-caption text-danger">
                                {{ fieldError(secret.variable) }}
                            </p>
                        </div>
                    </li>
                </ul>

                <p class="mt-3 text-caption text-muted">{{ trans('ui.settings.never_shown') }}</p>
            </div>

            <!-- CFG-001. The one control on this page that reaches anything.
                 It sends back the word the server put in `probe` and nothing
                 else, so there is no address for a caller to choose. -->
            <div v-if="section.probe !== null" class="mt-4 border-t border-border pt-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-caption text-muted">{{ probeNote(section.probe) }}</p>
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

        <StandingsTable :standings="standings" />

        <div class="flex items-center justify-end gap-3">
            <p class="text-caption text-muted">{{ trans('ui.settings.blank_means_unchanged') }}</p>
            <AppButton :loading="saving" @click="save">{{ trans('ui.settings.save') }}</AppButton>
        </div>
    </div>
</template>
