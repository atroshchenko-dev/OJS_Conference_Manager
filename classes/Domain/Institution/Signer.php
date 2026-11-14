<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;

/**
 * Person who signs documents of the institution (INS-02).
 */
final class Signer
{
    /**
     * @param list<DocumentType> $documentTypes
     */
    public function __construct(
        public readonly ?int $id,
        public readonly LocalizedText $fullName,
        public readonly LocalizedText $position,
        public readonly array $documentTypes = [],
        public readonly ?string $signaturePath = null,
    ) {
    }

    public function signs(DocumentType $type): bool
    {
        return in_array($type, $this->documentTypes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->fullName->toArray(),
            'position' => $this->position->toArray(),
            'documentTypes' => array_map(static fn (DocumentType $t): string => $t->value, $this->documentTypes),
            'signaturePath' => $this->signaturePath,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $types = [];
        foreach ((array) ($data['documentTypes'] ?? []) as $value) {
            $type = is_string($value) ? DocumentType::tryFrom($value) : null;
            if ($type !== null && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }
        return new self(
            isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null,
            new LocalizedText((array) ($data['fullName'] ?? [])),
            new LocalizedText((array) ($data['position'] ?? [])),
            $types,
            isset($data['signaturePath']) && $data['signaturePath'] !== '' ? (string) $data['signaturePath'] : null,
        );
    }
}
