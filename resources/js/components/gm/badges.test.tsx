import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TitleBelt, TitleBeltImage } from '@/components/gm/badges';
import { BrandBug } from '@/components/gm/brand-bug';

describe('TitleBeltImage', () => {
    it("shows the title's belt, named for screen readers", () => {
        render(<TitleBeltImage name="Women's Championship" />);

        expect(
            screen.getByRole('img', { name: "Women's Championship" }),
        ).toHaveAttribute('src', expect.stringContaining('womens'));
    });

    it('hides a belt shown beside the name from screen readers', () => {
        render(<TitleBeltImage name="WWE Championship" decorative />);

        expect(screen.getByTestId('title-belt')).toHaveAttribute('alt', '');
        expect(screen.queryByRole('img')).toBeNull();
    });

    it('renders nothing for a title with no belt', () => {
        const { container } = render(
            <TitleBeltImage name="Hardcore Championship" />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});

describe('TitleBelt', () => {
    it('puts the belt where the diamond was', () => {
        render(<TitleBelt name="Intercontinental Championship" />);

        expect(screen.getByTestId('title-belt')).toBeInTheDocument();
        expect(screen.getByText('Intercontinental')).not.toHaveTextContent('◆');
    });

    it('keeps the diamond for a title with no belt', () => {
        render(<TitleBelt name="Hardcore Championship" />);

        expect(screen.queryByTestId('title-belt')).toBeNull();
        expect(screen.getByText('◆')).toBeInTheDocument();
    });
});

describe('BrandBug', () => {
    it("shows each brand's logo", () => {
        render(
            <>
                <BrandBug brand="raw" />
                <BrandBug brand="smackdown" size="sm" />
            </>,
        );

        expect(screen.getByRole('img', { name: 'Raw' })).toHaveAttribute(
            'src',
            expect.stringContaining('raw'),
        );
        expect(screen.getByRole('img', { name: 'Smackdown' })).toHaveClass(
            'h-6',
        );
    });
});
