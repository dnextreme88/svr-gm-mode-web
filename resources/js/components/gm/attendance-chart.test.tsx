import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
    AttendanceChart,
    attendanceSummary,
    brandLine,
    yMax,
} from '@/components/gm/attendance-chart';
import type { AttendancePoint } from '@/components/gm/attendance-chart';

const night = (overrides: Partial<AttendancePoint>): AttendancePoint => ({
    season: 1,
    week: 1,
    name: 'Monday Night Raw',
    ppv: false,
    brands: ['raw'],
    crowd: 8000,
    gate: 240000,
    ...overrides,
});

const points: AttendancePoint[] = [
    night({ week: 1, crowd: 8000 }),
    night({ week: 2, crowd: 10000 }),
    night({
        week: 1,
        name: 'Friday Night Smackdown',
        brands: ['smackdown'],
        crowd: 12000,
    }),
    night({
        week: 2,
        name: 'Friday Night Smackdown',
        brands: ['smackdown'],
        crowd: 9000,
    }),
    night({
        week: 4,
        name: 'Backlash',
        ppv: true,
        crowd: 21500,
        gate: 1075000,
    }),
];

describe('AttendanceChart', () => {
    it('sums the season up: weekly averages per brand and the biggest night', () => {
        expect(attendanceSummary(points)).toBe(
            'Raw averages 9,000 a week, Smackdown averages 10,500 a week. Biggest crowd: Backlash, 21,500 fans (week 4).',
        );
    });

    it('marks the week axis with the shared PPVs’ logos, not week numbers', () => {
        const { container } = render(
            <AttendanceChart
                points={points}
                sharedPpvs={[
                    { key: 'summerslam', name: 'SummerSlam', week: 21 },
                    {
                        key: 'survivor_series',
                        name: 'Survivor Series',
                        week: 35,
                    },
                    { key: 'royal_rumble', name: 'Royal Rumble', week: 44 },
                    { key: 'wrestlemania', name: 'WrestleMania', week: 52 },
                ]}
            />,
        );

        for (const name of [
            'SummerSlam, week 21',
            'Survivor Series, week 35',
            'Royal Rumble, week 44',
            'WrestleMania, week 52',
        ]) {
            expect(screen.getByRole('img', { name })).toBeInTheDocument();
        }
        expect(container.querySelector('svg')).not.toHaveTextContent(/Wk \d/);
    });

    it('tops the axis at the next 10,000, never below 20,000', () => {
        expect(yMax([night({ crowd: 6000 })])).toBe(20000);
        expect(yMax(points)).toBe(30000);
        expect(yMax([night({ crowd: 41302 })])).toBe(50000);
    });

    it('draws each brand’s weekly shows as its own line and PPVs as diamonds', () => {
        const { container } = render(<AttendanceChart points={points} />);

        expect(
            container.querySelectorAll('[data-series="raw"] circle'),
        ).toHaveLength(2);
        expect(
            container.querySelectorAll('[data-series="smackdown"] rect'),
        ).toHaveLength(2);
        // Smackdown is dashed, so the lines differ by more than colour.
        expect(
            container
                .querySelector('[data-series="smackdown"] polyline')
                ?.getAttribute('stroke-dasharray'),
        ).toBe('6 4');
        expect(container.querySelector('[data-ppv="Backlash"]')).not.toBeNull();
        expect(screen.getByTestId('attendance-summary')).toHaveTextContent(
            'Biggest crowd: Backlash',
        );
    });

    it('runs each brand’s line through its PPV nights (house rule, 2026-11-08)', () => {
        const withShared = [
            ...points,
            night({
                week: 5,
                name: 'Royal Rumble',
                ppv: true,
                brands: ['raw', 'smackdown'],
                crowd: 30000,
            }),
            night({ week: 4, crowd: 9500 }),
        ];

        // Backlash (Raw, week 4) comes before Raw's week 4 show, and the
        // shared Royal Rumble ends both lines.
        expect(
            brandLine(withShared, 'raw').map((p) => `${p.week} ${p.name}`),
        ).toEqual([
            '1 Monday Night Raw',
            '2 Monday Night Raw',
            '4 Backlash',
            '4 Monday Night Raw',
            '5 Royal Rumble',
        ]);
        expect(brandLine(withShared, 'smackdown').map((p) => p.name)).toEqual([
            'Friday Night Smackdown',
            'Friday Night Smackdown',
            'Royal Rumble',
        ]);

        const { container } = render(<AttendanceChart points={withShared} />);
        const raw = container
            .querySelector('[data-series="raw"] polyline')
            ?.getAttribute('points')
            ?.split(' ');
        expect(raw).toHaveLength(5);
        // The PPV markers stay gold diamonds, not brand markers.
        expect(
            container.querySelectorAll('[data-series="raw"] circle'),
        ).toHaveLength(3);
        expect(
            container.querySelector('[data-ppv="Royal Rumble"]'),
        ).not.toBeNull();
    });

    it('shows a single night as its marker alone, with no line', () => {
        const { container } = render(
            <AttendanceChart points={[night({ week: 1 })]} />,
        );

        expect(
            container.querySelector('[data-series="raw"] polyline'),
        ).toBeNull();
        expect(
            container.querySelectorAll('[data-series="raw"] circle'),
        ).toHaveLength(1);
    });

    it('shows a night’s crowd and gate on focus or hover', () => {
        render(<AttendanceChart points={points} />);

        const backlash = screen.getByRole('img', {
            name: 'Week 4, Backlash: 21,500 fans, gate $1,075,000',
        });
        fireEvent.focus(backlash);

        expect(screen.getByRole('status')).toHaveTextContent(
            'BacklashWeek 4 · Raw21,500 fans · Gate $1,075,000',
        );

        fireEvent.blur(backlash);
        expect(screen.queryByRole('status')).toBeNull();
    });

    it('lists every night in a table too', async () => {
        const user = userEvent.setup();
        render(<AttendanceChart points={points} />);

        await user.click(screen.getByText('Show as a table'));
        const rows = within(screen.getByRole('table')).getAllByRole('row');

        expect(rows).toHaveLength(points.length + 1);
        expect(rows[5]).toHaveTextContent('4BacklashRaw21,500$1,075,000');
    });

    it('switches between seasons, starting on the latest', async () => {
        const user = userEvent.setup();
        render(
            <AttendanceChart
                points={[
                    ...points,
                    night({ season: 2, week: 1, crowd: 14000 }),
                ]}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Season 2' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByTestId('attendance-summary')).toHaveTextContent(
            'Raw averages 14,000 a week',
        );

        await user.click(screen.getByRole('button', { name: 'Season 1' }));

        expect(screen.getByTestId('attendance-summary')).toHaveTextContent(
            'Raw averages 9,000 a week',
        );
    });

    it('says so before any show has aired', () => {
        render(<AttendanceChart points={[]} />);

        expect(
            screen.getByText(/No crowds yet this season/),
        ).toBeInTheDocument();
    });
});
