<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Letters to participants from templates the journal edits (COM-01) and
 * the letters of a mailing (COM-02). Values are plain text; the mailer
 * escapes them.
 */
interface ParticipantMailer
{
    /**
     * @param array<string, string> $data values of the template variables
     * @throws \Throwable when the letter cannot be sent
     */
    public function sendTemplate(ContextInfo $context, string $templateKey, string $email, ?string $name, array $data, string $locale): void;

    /**
     * A letter written by the manager: plain text with variables, subject
     * and body as typed.
     *
     * @param array<string, string> $data
     * @throws \Throwable when the letter cannot be sent
     */
    public function sendText(ContextInfo $context, string $subject, string $text, string $email, ?string $name, array $data): void;

    /** Address of the participant's cabinet in the journal. */
    public function cabinetUrl(ContextInfo $context): string;

    /** Public page of an edition. */
    public function editionUrl(ContextInfo $context, string $path): string;
}
