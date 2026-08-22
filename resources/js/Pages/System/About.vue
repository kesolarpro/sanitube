<script setup lang="ts">
import { computed } from 'vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SystemAbout, SystemCheck } from '@/Types/system';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/Types/inertia';

/**
 * What this installation is, and whether it is well.
 *
 * SYS-001. `sanitube:doctor` has reported all of this since DEP-016 — to a
 * terminal. The operator most likely to need it is the one on shared hosting
 * who chose this platform *because* they did not want a shell, and they could
 * not read a word of it.
 *
 * **A read, and only a read.** Every remediation is a sentence, and the
 * settings that would fix most of them have their own screen behind their own
 * rules. A diagnosis page with buttons is one that eventually reconfigures a
 * server from a summary somebody skimmed.
 *
 * **Findings are ordered by what needs attention**, blockers first. A page
 * that listed them in the order the checks happen to run buries the one thing
 * somebody opened it for under eighteen greens.
 */
const props = defineProps<{ about: SystemAbout }>();

const page = usePage<SharedProps>();
const locale = page.props.app.locale;

/** Blockers, then unknowns, then warnings. Greens are counted, not listed. */
const ORDER: Record<string, number> = { BLOCKER: 0, UNKNOWN: 1, WARNING: 2, READY: 3 };

const findings = computed(() =>
    props.about.diagnosis.checks
        .filter((check) => check.verdict !== 'READY')
        .sort((a, b) => (ORDER[a.verdict] ?? 9) - (ORDER[b.verdict] ?? 9)),
);

function tone(check: SystemCheck): string {
    if (check.verdict === 'BLOCKER') {
        return 'text-danger';
    }

    // Never green. An unknown is a check that could not be made, and
    // reporting no answer as a pass is how a screen reassures somebody about
    // a server that is already down.
    return check.verdict === 'UNKNOWN' ? 'text-muted' : 'text-warning';
}

const pending = computed(() => props.about.migrations.pending ?? []);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.system_about.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.system_about.description') }}</p>
        </div>

        <AppAlert v-if="about.installation.debug" tone="danger" :title="trans('ui.system_about.debug')">
            {{ trans('ui.system_about.debug_on') }}
        </AppAlert>

        <AppAlert
            v-if="about.migrations.measured && pending.length > 0"
            tone="danger"
            :title="trans('ui.system_about.migrations_pending')"
        >
            {{ trans('ui.system_about.migrations_pending_note') }}
        </AppAlert>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.system_about.identity') }}</template>

                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.system_about.version') }}</dt>
                        <dd class="text-small text-foreground">
                            <template v-if="about.installation.version !== null">
                                {{ about.installation.version }}
                            </template>
                            <span v-else class="text-muted">{{ trans('ui.system_about.not_recorded') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.system_about.commit') }}</dt>
                        <dd class="text-small text-foreground">
                            <CodeValue v-if="about.installation.commit !== null" :value="about.installation.commit" />
                            <span v-else class="text-muted">{{ trans('ui.system_about.not_a_checkout') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.system_about.environment') }}</dt>
                        <dd class="text-small text-foreground">{{ about.installation.environment }}</dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.system_about.debug') }}</dt>
                        <dd class="text-small" :class="about.installation.debug ? 'text-danger' : 'text-foreground'">
                            {{
                                about.installation.debug
                                    ? trans('ui.system_about.debug_on')
                                    : trans('ui.system_about.debug_off')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.system_about.frontend_build') }}</dt>
                        <dd class="text-small text-foreground">
                            <CodeValue
                                v-if="about.installation.frontend?.sha"
                                :value="about.installation.frontend.sha"
                            />
                            <span v-else class="text-muted">{{ trans('ui.system_about.not_recorded') }}</span>
                        </dd>
                    </div>
                    <div v-if="about.installation.frontend?.installed_at">
                        <dt class="text-caption text-muted">
                            {{ trans('ui.system_about.frontend_installed_at') }}
                        </dt>
                        <dd class="text-small text-foreground">
                            {{ dateTime(about.installation.frontend.installed_at, locale) }}
                        </dd>
                    </div>
                </dl>

                <p v-if="about.installation.version === null" class="mt-3 text-caption text-muted">
                    {{ trans('ui.system_about.not_recorded_note') }}
                </p>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.system_about.runtime') }}</template>

                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.system_about.php') }}</dt>
                        <dd class="text-small text-foreground">{{ about.runtime.php }}</dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.system_about.database') }}</dt>
                        <dd class="text-small text-foreground">
                            {{ about.runtime.database_driver }}
                            <span v-if="about.runtime.database_version !== null" class="text-muted">
                                {{ about.runtime.database_version }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.system_about.config_cached') }}</dt>
                        <dd class="text-small text-foreground">
                            {{
                                about.runtime.config_cached
                                    ? trans('ui.system_about.cached')
                                    : trans('ui.system_about.not_cached')
                            }}
                        </dd>
                    </div>
                </dl>
            </AppCard>
        </div>

        <AppCard>
            <template #header>{{ trans('ui.system_about.migrations') }}</template>

            <p v-if="!about.migrations.measured" class="text-small text-warning">
                {{ trans('ui.system_about.migrations_unmeasured') }}
            </p>

            <template v-else>
                <p class="text-small text-foreground">
                    {{ trans('ui.system_about.migrations_applied', { count: about.migrations.applied ?? 0 }) }}
                </p>

                <p v-if="about.migrations.latest !== null" class="mt-1 text-caption text-muted">
                    {{ trans('ui.system_about.migrations_latest') }}:
                    <CodeValue :value="about.migrations.latest" />
                </p>

                <p v-if="pending.length === 0" class="mt-2 text-small text-success">
                    {{ trans('ui.system_about.migrations_none_pending') }}
                </p>

                <ul v-else class="mt-2 space-y-1">
                    <li v-for="name in pending" :key="name" class="text-small text-danger">
                        <CodeValue :value="name" />
                    </li>
                </ul>
            </template>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.system_about.diagnosis') }}</template>

            <p class="text-caption text-muted">{{ trans('ui.system_about.diagnosis_note') }}</p>

            <p v-if="!about.diagnosis.measured" class="mt-3 text-small text-warning">
                {{ trans('ui.system_about.diagnosis_unmeasured') }}
            </p>

            <template v-else>
                <dl class="mt-3 flex flex-wrap gap-4">
                    <div v-for="(total, verdict) in about.diagnosis.counts" :key="verdict">
                        <dt class="text-caption text-muted">{{ verdict }}</dt>
                        <dd class="text-small text-foreground">{{ total }}</dd>
                    </div>
                </dl>

                <p v-if="findings.length === 0" class="mt-3 text-small text-success">
                    {{ trans('ui.system_about.all_ready') }}
                </p>

                <ul v-else class="mt-3 space-y-3">
                    <li
                        v-for="check in findings"
                        :key="`${check.section}:${check.key}`"
                        class="border-t border-border pt-3"
                    >
                        <p class="text-caption" :class="tone(check)">
                            {{ check.verdict }} · {{ check.section }} · <CodeValue :value="check.key" />
                        </p>
                        <p class="mt-1 text-small text-foreground">{{ check.summary }}</p>
                        <p v-if="check.remediation !== null" class="mt-1 text-caption text-muted">
                            {{ trans('ui.system_about.remediation') }}: {{ check.remediation }}
                        </p>
                    </li>
                </ul>
            </template>
        </AppCard>
    </div>
</template>
