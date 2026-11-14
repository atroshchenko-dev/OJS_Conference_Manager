<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

enum EventFormat: string
{
    case InPerson = 'in_person';
    case Online = 'online';
    case Hybrid = 'hybrid';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.edition.format.' . $this->value;
    }
}
