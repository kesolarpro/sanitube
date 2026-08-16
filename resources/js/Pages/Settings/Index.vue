<script setup lang="ts">
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import { trans } from '@/Support/i18n';
import type { SettingsOverview } from '@/Types/settings';

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
 */
defineProps<{ settings: SettingsOverview }>();
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.settings.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.settings.description') }}</p>
        </div>

        <AppAlert tone="info" :title="trans('ui.settings.read_only')">
            {{ trans('ui.settings.read_only_note') }}
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
                        <dt class="text-caption text-muted"><CodeValue :value="setting.variable" /></dt>
                        <dd class="text-small text-foreground">{{ setting.value }}</dd>
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
                        <CodeValue :value="secret.variable" />
                        <!-- Two words, and no third option. There is nothing
                             to reveal because nothing was sent. -->
                        <span :class="secret.configured ? 'text-small text-success' : 'text-small text-warning'">
                            {{
                                secret.configured
                                    ? trans('ui.settings.configured')
                                    : trans('ui.settings.not_configured')
                            }}
                        </span>
                    </li>
                </ul>

                <p class="mt-3 text-caption text-muted">{{ trans('ui.settings.never_shown') }}</p>
            </div>
        </AppCard>
    </div>
</template>
