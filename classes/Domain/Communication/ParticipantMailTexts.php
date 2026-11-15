<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Communication;

/**
 * Default letters to participants (COM-01): confirmation of co-authorship,
 * invoices, receipts and reminders. They become OJS email templates, so
 * the journal edits them in Settings → Workflow → Emails; an update of the
 * plugin never overwrites a letter the journal has changed.
 *
 * Variables of every letter: {$recipientName}, {$conferenceName},
 * {$cabinetUrl}, {$contextName}; the others are listed per letter in VARIABLES.
 */
final class ParticipantMailTexts
{
    public const CONFIRM = 'CONF_PARTICIPATION_CONFIRM';
    public const INVOICE = 'CONF_INVOICE_ISSUED';
    public const PROOF_ACCEPTED = 'CONF_PROOF_ACCEPTED';
    public const PROOF_REJECTED = 'CONF_PROOF_REJECTED';
    public const REMIND_PAYMENT = 'CONF_REMINDER_PAYMENT';
    public const REMIND_PAPER = 'CONF_REMINDER_PAPER';
    public const REMIND_EVENT = 'CONF_REMINDER_EVENT';

    public const COMMON = ['recipientName', 'conferenceName', 'cabinetUrl', 'contextName'];

    /** Variables of each letter besides the common ones. */
    public const VARIABLES = [
        self::CONFIRM => ['submitterName', 'submissionTitle', 'participantRole', 'confirmUrl', 'expiresAt'],
        self::INVOICE => ['invoiceNumber', 'amount', 'paymentPurpose', 'paymentReference', 'paymentInstructions', 'dueDate'],
        self::PROOF_ACCEPTED => ['invoiceNumber', 'invoiceStatus', 'amountDue'],
        self::PROOF_REJECTED => ['invoiceNumber', 'amountDue', 'reviewNote'],
        self::REMIND_PAYMENT => ['invoiceNumber', 'amountDue', 'dueDate', 'paymentReference'],
        self::REMIND_PAPER => ['submissionTitle', 'paperDeadline'],
        self::REMIND_EVENT => ['startsAt', 'venue', 'eventUrl'],
    ];

    /** @return array<string, array<string, array{name: string, subject: string, body: string}>> key => locale => text */
    public static function all(): array
    {
        $uk = [
            self::CONFIRM => ['Учасники: підтвердження співавторства', 'Підтвердьте участь: {$conferenceName}',
                '<p>Шановна/шановний {$recipientName}!</p><p>{$submitterName} подає матеріал «{$submissionTitle}» на захід «{$conferenceName}» і вказує вас як автора. Ваша роль: {$participantRole}.</p>'
                . '<p>Підтвердьте, будь ласка, участь або відмовтеся за посиланням (обліковий запис не потрібен):<br><a href="{$confirmUrl}">{$confirmUrl}</a></p>'
                . '<p>Посилання діє до {$expiresAt} і спрацьовує один раз. Якщо ви не маєте стосунку до цього подання, просто проігноруйте лист.</p><p>{$contextName}</p>'],
            self::INVOICE => ['Учасники: рахунок виставлено', 'Рахунок № {$invoiceNumber}: {$conferenceName}',
                '<p>Шановна/шановний {$recipientName}!</p><p>Для участі в заході «{$conferenceName}» виставлено рахунок № {$invoiceNumber} на суму {$amount}.</p>'
                . '<p>Призначення платежу: {$paymentPurpose}<br>Код платежу: {$paymentReference}</p><p>{$paymentInstructions}</p>'
                . '<p>Рахунок у PDF і стан оплати — в особистому кабінеті: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p><p>{$contextName}</p>'],
            self::PROOF_ACCEPTED => ['Учасники: квитанцію прийнято', 'Оплату підтверджено: рахунок {$invoiceNumber}, {$conferenceName}',
                '<p>Шановна/шановний {$recipientName}!</p><p>Вашу квитанцію до рахунку {$invoiceNumber} ({$conferenceName}) перевірено й прийнято. Стан рахунку: {$invoiceStatus}. Залишок до сплати: {$amountDue}.</p>'
                . '<p>Подробиці в особистому кабінеті: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p><p>{$contextName}</p>'],
            self::PROOF_REJECTED => ['Учасники: квитанцію відхилено', 'Квитанцію не прийнято: рахунок {$invoiceNumber}, {$conferenceName}',
                '<p>Шановна/шановний {$recipientName}!</p><p>Вашу квитанцію до рахунку {$invoiceNumber} ({$conferenceName}) не прийнято.</p><p>Причина: {$reviewNote}</p>'
                . '<p>До сплати: {$amountDue}. Правильну квитанцію можна надіслати в особистому кабінеті: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p><p>{$contextName}</p>'],
            self::REMIND_PAYMENT => ['Нагадування: оплата участі', 'Нагадування про оплату: {$conferenceName}',
                '<p>Шановна/шановний {$recipientName}!</p><p>Нагадуємо, що рахунок № {$invoiceNumber} за участь у заході «{$conferenceName}» ще не оплачено. До сплати: {$amountDue}. Строк оплати: {$dueDate}.</p>'
                . '<p>Код платежу: {$paymentReference}. Реквізити, PDF рахунку й форма для квитанції — в особистому кабінеті: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p>'
                . '<p>Якщо ви вже оплатили, надішліть, будь ласка, квитанцію в кабінеті. Тоді нагадувань більше не буде.</p><p>{$contextName}</p>'],
            self::REMIND_PAPER => ['Нагадування: повний текст', 'Нагадування: повний текст до {$paperDeadline}',
                '<p>Шановна/шановний {$recipientName}!</p><p>Нагадуємо, що повний текст матеріалу «{$submissionTitle}» для заходу «{$conferenceName}» треба надіслати до {$paperDeadline}.</p>'
                . '<p>Завантажте файл у своєму поданні в журналі. Якщо ви вже надіслали текст, проігноруйте цей лист.</p><p>{$contextName}</p>'],
            self::REMIND_EVENT => ['Нагадування: початок заходу', 'Скоро початок: {$conferenceName}',
                '<p>Шановна/шановний {$recipientName}!</p><p>Нагадуємо, що захід «{$conferenceName}» починається {$startsAt}.</p><p>Місце: {$venue}</p>'
                . '<p>Програма й подробиці: <a href="{$eventUrl}">{$eventUrl}</a><br>Ваша участь і документи — в особистому кабінеті: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p><p>{$contextName}</p>'],
        ];
        $en = [
            self::CONFIRM => ['Participants: co-authorship confirmation', 'Please confirm your participation: {$conferenceName}',
                '<p>Dear {$recipientName},</p><p>{$submitterName} is submitting “{$submissionTitle}” to “{$conferenceName}” and lists you as an author. Your role: {$participantRole}.</p>'
                . '<p>Please confirm or decline your participation at this link (no account needed):<br><a href="{$confirmUrl}">{$confirmUrl}</a></p>'
                . '<p>The link works until {$expiresAt} and only once. If you have nothing to do with this submission, please ignore this letter.</p><p>{$contextName}</p>'],
            self::INVOICE => ['Participants: invoice issued', 'Invoice No. {$invoiceNumber}: {$conferenceName}',
                '<p>Dear {$recipientName},</p><p>An invoice No. {$invoiceNumber} for {$amount} has been issued for your participation in “{$conferenceName}”.</p>'
                . '<p>Payment purpose: {$paymentPurpose}<br>Payment reference: {$paymentReference}</p><p>{$paymentInstructions}</p>'
                . '<p>The invoice PDF and the payment status are in your cabinet: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p><p>{$contextName}</p>'],
            self::PROOF_ACCEPTED => ['Participants: receipt accepted', 'Payment confirmed: invoice {$invoiceNumber}, {$conferenceName}',
                '<p>Dear {$recipientName},</p><p>Your receipt for invoice {$invoiceNumber} ({$conferenceName}) has been checked and accepted. Invoice status: {$invoiceStatus}. Still due: {$amountDue}.</p>'
                . '<p>Details are in your cabinet: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p><p>{$contextName}</p>'],
            self::PROOF_REJECTED => ['Participants: receipt rejected', 'Receipt not accepted: invoice {$invoiceNumber}, {$conferenceName}',
                '<p>Dear {$recipientName},</p><p>Your receipt for invoice {$invoiceNumber} ({$conferenceName}) was not accepted.</p><p>Reason: {$reviewNote}</p>'
                . '<p>Due: {$amountDue}. You can send the correct receipt in your cabinet: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p><p>{$contextName}</p>'],
            self::REMIND_PAYMENT => ['Reminder: participation fee', 'Payment reminder: {$conferenceName}',
                '<p>Dear {$recipientName},</p><p>This is a reminder that invoice No. {$invoiceNumber} for “{$conferenceName}” is not paid yet. Due: {$amountDue}. Payment deadline: {$dueDate}.</p>'
                . '<p>Payment reference: {$paymentReference}. Bank details, the invoice PDF and the receipt form are in your cabinet: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p>'
                . '<p>If you have already paid, please send the receipt in your cabinet, and the reminders will stop.</p><p>{$contextName}</p>'],
            self::REMIND_PAPER => ['Reminder: full text', 'Reminder: full text due {$paperDeadline}',
                '<p>Dear {$recipientName},</p><p>This is a reminder that the full text of “{$submissionTitle}” for “{$conferenceName}” is due by {$paperDeadline}.</p>'
                . '<p>Please upload the file in your submission in the journal. If you have already sent it, please ignore this letter.</p><p>{$contextName}</p>'],
            self::REMIND_EVENT => ['Reminder: event start', 'Starting soon: {$conferenceName}',
                '<p>Dear {$recipientName},</p><p>This is a reminder that “{$conferenceName}” starts on {$startsAt}.</p><p>Venue: {$venue}</p>'
                . '<p>Programme and details: <a href="{$eventUrl}">{$eventUrl}</a><br>Your participation and documents are in your cabinet: <a href="{$cabinetUrl}">{$cabinetUrl}</a></p><p>{$contextName}</p>'],
        ];
        $out = [];
        foreach ($uk as $key => [$name, $subject, $body]) {
            $out[$key] = [
                'uk' => ['name' => $name, 'subject' => $subject, 'body' => $body],
                'en' => ['name' => $en[$key][0], 'subject' => $en[$key][1], 'body' => $en[$key][2]],
            ];
        }
        return $out;
    }
}
