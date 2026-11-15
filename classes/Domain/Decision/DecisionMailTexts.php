<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Decision;

/**
 * Default letters of the decisions 901–904 and of a presentation type
 * change (DEC-02, DEC-05). They become OJS email templates, so the journal
 * edits them in Settings → Workflow → Emails like any other letter.
 *
 * Variables: the usual decision ones ({$recipientName}, {$submissionTitle},
 * {$contextName}, {$signature}) and ours: {$conferenceName},
 * {$presentationType}, {$oldPresentationType}, {$cabinetUrl}.
 */
final class DecisionMailTexts
{
    public const TYPE_CHANGED = 'CONF_TYPE_CHANGED';

    /** @return array<string, array<string, array{name: string, subject: string, body: string}>> key => locale => text */
    public static function all(): array
    {
        $uk = [
            'oral' => ['усна доповідь', 'Тези прийнято: усна доповідь', 'як <strong>усну доповідь</strong>. Програму й час виступу ми надішлемо окремо.'],
            'poster' => ['стендова доповідь', 'Тези прийнято: стендова доповідь', 'як <strong>стендову доповідь</strong>. Вимоги до постера ми надішлемо окремо.'],
            'online' => ['онлайн-доповідь', 'Тези прийнято: онлайн-доповідь', 'як <strong>онлайн-доповідь</strong>. Посилання на трансляцію ми надішлемо перед заходом.'],
            'absentee' => ['заочна участь', 'Тези прийнято: заочна участь', 'для <strong>заочної участі</strong>: тези буде опубліковано у збірнику без виступу.'],
        ];
        $en = [
            'oral' => ['oral presentation', 'Abstract accepted: oral presentation', 'as an <strong>oral presentation</strong>. We will send the programme and your time slot separately.'],
            'poster' => ['poster presentation', 'Abstract accepted: poster presentation', 'as a <strong>poster presentation</strong>. We will send the poster requirements separately.'],
            'online' => ['online presentation', 'Abstract accepted: online presentation', 'as an <strong>online presentation</strong>. We will send the streaming link before the event.'],
            'absentee' => ['publication only', 'Abstract accepted: publication only', 'for <strong>publication only</strong>: the abstract will appear in the proceedings without a presentation.'],
        ];
        $out = [];
        foreach (AcceptanceDecision::cases() as $d) {
            $type = $d->presentationType()->value;
            $out[$d->emailTemplateKey()] = [
                'uk' => [
                    'name' => 'Рішення: прийнято, ' . $uk[$type][0],
                    'subject' => $uk[$type][1],
                    'body' => '<p>Шановна/шановний {$recipientName}!</p>'
                        . '<p>Раді повідомити, що тези «{$submissionTitle}» прийнято до заходу «{$conferenceName}» ' . $uk[$type][2] . '</p>'
                        . '<p>Якщо участь платна, рахунок і реквізити для оплати є в кабінеті учасника: <a href="{$cabinetUrl}">{$cabinetUrl}</a>. Там же можна надіслати квитанцію.</p>'
                        . '<p>{$signature}</p>',
                ],
                'en' => [
                    'name' => 'Decision: accepted, ' . $en[$type][0],
                    'subject' => $en[$type][1],
                    'body' => '<p>Dear {$recipientName},</p>'
                        . '<p>We are pleased to tell you that the abstract "{$submissionTitle}" has been accepted for "{$conferenceName}" ' . $en[$type][2] . '</p>'
                        . '<p>If participation is paid, the invoice and payment details are in your participant cabinet: <a href="{$cabinetUrl}">{$cabinetUrl}</a>. You can send the receipt there too.</p>'
                        . '<p>{$signature}</p>',
                ],
            ];
        }
        $out[self::TYPE_CHANGED] = [
            'uk' => [
                'name' => 'Змінено тип виступу',
                'subject' => 'Змінено тип виступу: {$conferenceName}',
                'body' => '<p>Шановна/шановний {$recipientName}!</p>'
                    . '<p>Тип виступу за тезами «{$submissionTitle}» на заході «{$conferenceName}» змінено: було «{$oldPresentationType}», тепер «{$presentationType}».</p>'
                    . '<p>Якщо участь платна, перевірте рахунок у кабінеті учасника: <a href="{$cabinetUrl}">{$cabinetUrl}</a>.</p>'
                    . '<p>{$signature}</p>',
            ],
            'en' => [
                'name' => 'Presentation type changed',
                'subject' => 'Presentation type changed: {$conferenceName}',
                'body' => '<p>Dear {$recipientName},</p>'
                    . '<p>The presentation type of the abstract "{$submissionTitle}" at "{$conferenceName}" has changed from "{$oldPresentationType}" to "{$presentationType}".</p>'
                    . '<p>If participation is paid, please check the invoice in your participant cabinet: <a href="{$cabinetUrl}">{$cabinetUrl}</a>.</p>'
                    . '<p>{$signature}</p>',
            ],
        ];
        return $out;
    }
}
