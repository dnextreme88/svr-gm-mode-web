import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ProposalLauncher } from '@/components/gm/proposal-launcher';
import type { ProposalResult } from '@/components/gm/proposal-launcher';
import type { ProposalView } from '@/components/gm/proposal-modal';
import { HttpError } from '@/lib/http';

function proposal(
    position: number,
    names: string[][],
    cost = 0,
    title: number | null = null,
): ProposalView {
    return {
        position,
        match_type_id: 1,
        match_type: 'Single — No Manager',
        cost,
        sides: names.map((side, i) => side.map((_, j) => i * 10 + j)),
        side_names: names,
        title_championship_id: title,
    };
}

function result(
    proposals: ProposalView[],
    extra: Partial<ProposalResult> = {},
): ProposalResult {
    return {
        proposals,
        unfilled_slots: [],
        total_cost: 0,
        deficit_warning: false,
        ...extra,
    };
}

describe('Randomize / Preset modal (§24)', () => {
    it('Randomize opens the preview modal with the proposed booking', async () => {
        const user = userEvent.setup();
        const fetchProposal = vi
            .fn()
            .mockResolvedValue(
                result([proposal(1, [['Edge'], ['Kane']], 0, 7)]),
            );

        render(
            <ProposalLauncher
                label="Randomize"
                mode="randomize"
                fetchProposal={fetchProposal}
                onConfirm={vi.fn()}
                titleNames={{ 7: 'WWE Championship' }}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Randomize' }));

        const dialog = await screen.findByRole('dialog', {
            name: 'Randomize match',
        });
        expect(
            await within(dialog).findByText('Edge vs Kane'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('◆ WWE Championship'),
        ).toBeInTheDocument();
        expect(fetchProposal).toHaveBeenCalledWith(0);
    });

    it('Preset opens the preview modal with the proposed card', async () => {
        const user = userEvent.setup();
        const fetchProposal = vi
            .fn()
            .mockResolvedValue(
                result([
                    proposal(1, [['Edge'], ['Kane']]),
                    proposal(2, [['Carlito'], ['Big Show']]),
                ]),
            );

        render(
            <ProposalLauncher
                label="Preset card"
                mode="preset"
                fetchProposal={fetchProposal}
                onConfirm={vi.fn()}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Preset card' }));

        const list = await screen.findByRole('list', {
            name: 'Proposed matches',
        });
        expect(within(list).getAllByRole('listitem')).toHaveLength(2);
    });

    it('shows a 422 message in the modal and does not change the card', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();
        const fetchProposal = vi
            .fn()
            .mockRejectedValue(
                new HttpError(
                    422,
                    'Not enough available wrestlers to book this match (need 2, have 1).',
                ),
            );

        render(
            <ProposalLauncher
                label="Randomize"
                mode="randomize"
                fetchProposal={fetchProposal}
                onConfirm={onConfirm}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Randomize' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Not enough available wrestlers to book this match (need 2, have 1).',
        );
        expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('Confirm saves the proposal and closes the modal', async () => {
        const user = userEvent.setup();
        const proposals = [proposal(1, [['Edge'], ['Kane']])];
        const onConfirm = vi.fn().mockResolvedValue(undefined);

        render(
            <ProposalLauncher
                label="Randomize"
                mode="randomize"
                fetchProposal={vi.fn().mockResolvedValue(result(proposals))}
                onConfirm={onConfirm}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Randomize' }));
        await screen.findByText('Edge vs Kane');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(onConfirm).toHaveBeenCalledWith(proposals);
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('Cancel leaves the card untouched', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();

        render(
            <ProposalLauncher
                label="Randomize"
                mode="randomize"
                fetchProposal={vi
                    .fn()
                    .mockResolvedValue(
                        result([proposal(1, [['Edge'], ['Kane']])]),
                    )}
                onConfirm={onConfirm}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Randomize' }));
        await screen.findByText('Edge vs Kane');
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('Re-roll requests a new proposal', async () => {
        const user = userEvent.setup();
        const fetchProposal = vi
            .fn()
            .mockResolvedValueOnce(result([proposal(1, [['Edge'], ['Kane']])]))
            .mockResolvedValueOnce(
                result([proposal(1, [['Carlito'], ['Big Show']])]),
            );

        render(
            <ProposalLauncher
                label="Randomize"
                mode="randomize"
                fetchProposal={fetchProposal}
                onConfirm={vi.fn()}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Randomize' }));
        await screen.findByText('Edge vs Kane');
        await user.click(screen.getByRole('button', { name: 'Re-roll' }));

        expect(
            await screen.findByText('Carlito vs Big Show'),
        ).toBeInTheDocument();
        expect(fetchProposal).toHaveBeenLastCalledWith(1);
    });

    it('Preset lists unfilled slots and shows the cost warning', async () => {
        const user = userEvent.setup();
        const fetchProposal = vi.fn().mockResolvedValue(
            result([proposal(1, [['Edge'], ['Kane']], 50000)], {
                unfilled_slots: [5, 6],
                total_cost: 50000,
                deficit_warning: true,
            }),
        );

        render(
            <ProposalLauncher
                label="Preset card"
                mode="preset"
                fetchProposal={fetchProposal}
                onConfirm={vi.fn()}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Preset card' }));

        expect(
            await screen.findByText(/Couldn’t fill matches 5, 6/),
        ).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'would put the brand into deficit',
        );
        expect(screen.getAllByText('$50,000').length).toBeGreaterThan(0);
    });
});
