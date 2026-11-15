<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

/** What the fee is for (FEE-02). "any" applies when nothing more specific does. */
enum MaterialType: string
{
    case Abstract = 'abstract';
    case Paper = 'paper';
    case Listener = 'listener';
    case Any = 'any';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.fee.material.' . $this->value;
    }
}
