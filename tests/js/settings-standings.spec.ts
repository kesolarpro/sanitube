import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StandingsTable from '@/Components/Settings/StandingsTable.vue';
import type { CertificationStatus, ProviderStanding } from '@/Types/settings';
import { sharedProps } from './support/inertia';

vi.mock('@inertiajs/vue3', () => import('./support/inertia'));

/**
 * Has anybody actually proved this works?
 *
 * CFG-003. Six words and never a boolean. Each means something different and
 * points at a different next step: *configured but uncertified* is a button
 * away from an answer, *blocked externally* is waiting on somebody who is not
 * here, and *unavailable* is the world rather than this installation. Rendering
 * any of them as "working / not working" would collapse three different next
 * steps into one wrong one.
 */

const ALL: CertificationStatus[] = [
    'NOT_CONFIGURED',
    'CODE_READY',
    'CONFIGURED_UNCERTIFIED',
    'CERTIFIED',
    'BLOCKED_EXTERNAL',
    'UNAVAILABLE',
];

function standing(status: CertificationStatus, extra: Partial<ProviderStanding> = {}): ProviderStanding {
    return {
        key: status.toLowerCase(),
        label: `Provider ${status}`,
        status,
        detail: `Detail for ${status}.`,
        certified_at: null,
        ...extra,
    };
}

describe('the certification standings', () => {
    beforeEach(() => {
        sharedProps.translations = {};
    });

    it('renders every word in the vocabulary as its own sentence', () => {
        const wrapper = mount(StandingsTable, { props: { standings: ALL.map((status) => standing(status)) } });

        for (const status of ALL) {
            expect(wrapper.text()).toContain(`ui.settings.standing.${status}`);
            expect(wrapper.text()).toContain(`Detail for ${status}.`);
        }
    });

    it('says when a certification passed, and stays silent when none has', () => {
        const wrapper = mount(StandingsTable, {
            props: {
                standings: [
                    standing('CERTIFIED', { certified_at: '2026-08-22 03:00:00' }),
                    standing('CONFIGURED_UNCERTIFIED'),
                ],
            },
        });

        expect(wrapper.text()).toContain('2026-08-22 03:00:00');

        // One date, not two. A standing that has never been proved must not
        // borrow the row above it.
        expect(wrapper.text().match(/ui\.settings\.certified_at/g)).toHaveLength(1);
    });

    it('reads as a table however many providers there are', () => {
        const wrapper = mount(StandingsTable, { props: { standings: [] } });

        // The heading and the caveat stand on their own: an installation whose
        // ledger is empty still has to be told what this panel is.
        expect(wrapper.text()).toContain('ui.settings.standings_note');
        expect(wrapper.findAll('tbody tr')).toHaveLength(0);
    });
});
