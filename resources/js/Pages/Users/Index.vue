<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { UserRow, UsersView } from '@/Types/system';

/**
 * Who may use this installation, and as what.
 *
 * USR-001. The platform shipped three roles and no way to assign any of them:
 * `sanitube:user:create` over SSH was the only door, and nobody could change a
 * role or stop somebody signing in.
 *
 * **The controls a reader cannot use are disabled and say why.** Somebody's own
 * row, the last owner's row, and — for an administrator — every owner's row.
 * A button that 403s teaches people to press twice; a disabled one with a
 * sentence beside it teaches them the rule.
 *
 * **There is no delete.** `audit_events.actor_id` is `restrictOnDelete`, so the
 * database refuses to remove anybody who has ever acted, and it is right to:
 * deleting an account would take the record of what they did with it.
 */
const props = defineProps<{ users: UsersView }>();

const page = usePage<SharedProps>();
const locale = page.props.app.locale;

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.user ?? null;
});

const adding = ref(false);

const form = useForm({ name: '', email: '', role: 'MEMBER', password: '' });

/** Roles this reader may actually hand out. */
const roleOptions = computed(() =>
    props.users.roles
        .filter((role) => role !== 'OWNER' || props.users.may_manage_owners)
        .map((role) => ({ value: role, label: trans(`ui.users.role_name.${role}`) })),
);

/**
 * Why a row is not editable, or null when it is.
 *
 * Ordered by which sentence is most useful: your own account first, because
 * that is the one somebody is most likely to be looking at.
 */
function locked(user: UserRow): string | null {
    if (user.is_self) {
        return trans('ui.users.self');
    }

    if (user.is_last_owner) {
        return trans('ui.users.last_owner');
    }

    if (user.role === 'OWNER' && !props.users.may_manage_owners) {
        return trans('ui.users.failure.OWNER_ONLY');
    }

    return null;
}

const pending = reactive<Record<string, boolean>>({});

function changeRole(user: UserRow, role: string | null | undefined): void {
    if (typeof role !== 'string' || role === user.role || pending[user.uuid] === true) {
        return;
    }

    pending[user.uuid] = true;
    router.patch(`/users/${user.uuid}`, { role }, {
        preserveScroll: true,
        onFinish: () => {
            pending[user.uuid] = false;
        },
    });
}

function setActive(user: UserRow, active: boolean): void {
    if (pending[user.uuid] === true) {
        return;
    }

    pending[user.uuid] = true;
    router.patch(`/users/${user.uuid}`, { is_active: active }, {
        preserveScroll: true,
        onFinish: () => {
            pending[user.uuid] = false;
        },
    });
}

function add(): void {
    form.post('/users', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            adding.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-page-title text-foreground">{{ trans('ui.users.title') }}</h1>
                <p class="mt-1 text-small text-muted">{{ trans('ui.users.description') }}</p>
            </div>
            <AppButton variant="primary" @click="adding = !adding">{{ trans('ui.users.add') }}</AppButton>
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.states.error_title')">
            {{ trans(`ui.users.failure.${refusal}`) }}
        </AppAlert>

        <AppCard v-if="adding">
            <template #header>{{ trans('ui.users.add') }}</template>

            <div class="grid gap-3 sm:grid-cols-2">
                <FormField :label="trans('ui.users.name')" :error="form.errors.name" required>
                    <TextInput v-model="form.name" autocomplete="off" />
                </FormField>
                <FormField :label="trans('ui.users.email')" :error="form.errors.email" required>
                    <TextInput v-model="form.email" type="email" autocomplete="off" />
                </FormField>
                <FormField :label="trans('ui.users.role')" :error="form.errors.role" required>
                    <SelectInput v-model="form.role" :options="roleOptions" />
                </FormField>
                <FormField
                    :label="trans('ui.users.password')"
                    :error="form.errors.password"
                    :description="trans('ui.users.password_hint')"
                    required
                >
                    <!-- new-password, never current-password: a browser that
                         offers to fill this would offer the administrator's
                         own credential into somebody else's account. -->
                    <TextInput v-model="form.password" type="password" autocomplete="new-password" />
                </FormField>
            </div>

            <div class="mt-3 flex justify-end">
                <AppButton variant="primary" :loading="form.processing" @click="add">
                    {{ trans('ui.users.add') }}
                </AppButton>
            </div>
        </AppCard>

        <AppCard :padded="false">
            <DataTable :caption="trans('ui.users.title')">
                <template #head>
                    <TableHeaderCell>{{ trans('ui.users.name') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.users.email') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.users.role') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.users.status') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.users.last_login') }}</TableHeaderCell>
                    <TableHeaderCell align="right">{{ trans('ui.users.status') }}</TableHeaderCell>
                </template>
                <tr v-for="user in users.rows" :key="user.uuid" class="border-t border-border">
                    <TableCell>
                        {{ user.name }}
                        <span v-if="locked(user)" class="ml-2 text-caption text-muted">{{ locked(user) }}</span>
                    </TableCell>
                    <TableCell>{{ user.email }}</TableCell>
                    <TableCell>
                        <SelectInput
                            v-if="locked(user) === null"
                            :model-value="user.role"
                            :options="roleOptions"
                            :disabled="pending[user.uuid] === true"
                            @update:model-value="(role) => changeRole(user, role)"
                        />
                        <span v-else>{{ trans(`ui.users.role_name.${user.role}`) }}</span>
                    </TableCell>
                    <TableCell>
                        <span :class="user.is_active ? 'text-success' : 'text-warning'">
                            {{ user.is_active ? trans('ui.users.active') : trans('ui.users.inactive') }}
                        </span>
                    </TableCell>
                    <TableCell>
                        {{ user.last_login_at ? dateTime(user.last_login_at, locale) : trans('ui.users.never') }}
                    </TableCell>
                    <TableCell align="right">
                        <AppButton
                            v-if="locked(user) === null"
                            size="sm"
                            :variant="user.is_active ? 'danger' : 'secondary'"
                            :loading="pending[user.uuid] === true"
                            @click="setActive(user, !user.is_active)"
                        >
                            {{ user.is_active ? trans('ui.users.deactivate') : trans('ui.users.reactivate') }}
                        </AppButton>
                    </TableCell>
                </tr>
            </DataTable>
        </AppCard>

        <p class="text-caption text-muted">{{ trans('ui.users.no_delete') }}</p>
    </div>
</template>
