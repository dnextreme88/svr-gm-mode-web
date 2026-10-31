import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import gm from '@/routes/gm';
import type { TitleVacancyPrompt } from '@/types/gm';

/**
 * House rule: a title was vacated with a #1 contender waiting. Award it to
 * the contender(s), or forfeit the contendership; each choice is confirmed.
 * The week can't air until it's answered.
 */
export function TitleVacancyModal({
    saveId,
    prompt,
}: {
    saveId: number;
    prompt: TitleVacancyPrompt;
}) {
    const [choice, setChoice] = useState<'award' | 'forfeit' | null>(null);
    const they = prompt.contender_count > 1 ? 'they' : 'the contender';

    const answer = (what: 'award' | 'forfeit') =>
        router.post(
            what === 'award'
                ? gm.titles.prompts.award.url({
                      save: saveId,
                      titleVacancyPrompt: prompt.id,
                  })
                : gm.titles.prompts.forfeit.url({
                      save: saveId,
                      titleVacancyPrompt: prompt.id,
                  }),
            {},
            { preserveScroll: true, onFinish: () => setChoice(null) },
        );

    return (
        <>
            <ConfirmDialog
                open={choice === null}
                title={`The ${prompt.title} is vacant`}
                message={
                    <p>
                        {prompt.contenders}{' '}
                        {prompt.contender_count > 1 ? 'were' : 'was'} waiting as
                        #1 contender for it. Award the title to {they} now, or
                        forfeit the contendership and fill it another way.
                    </p>
                }
                cancelLabel="Forfeit"
                confirmLabel="Award the title"
                onCancel={() => setChoice('forfeit')}
                onConfirm={() => setChoice('award')}
            />
            <ConfirmDialog
                open={choice !== null}
                title={
                    choice === 'award'
                        ? `Award the ${prompt.title}?`
                        : 'Forfeit the contendership?'
                }
                message={
                    choice === 'award'
                        ? `${prompt.contenders} become${prompt.contender_count > 1 ? '' : 's'} champion without a match.`
                        : `${prompt.contenders} lose${prompt.contender_count > 1 ? '' : 's'} the #1 contender spot, and the title stays vacant.`
                }
                cancelLabel="Back"
                confirmLabel={choice === 'award' ? 'Award it' : 'Forfeit'}
                danger={choice === 'forfeit'}
                onCancel={() => setChoice(null)}
                onConfirm={() => choice && answer(choice)}
            />
        </>
    );
}
