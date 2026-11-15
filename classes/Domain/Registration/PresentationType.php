<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

enum PresentationType: string
{
    case Oral = 'oral';
    case Poster = 'poster';
    case Online = 'online';
    case Absentee = 'absentee';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.registration.presentationType.' . $this->value;
    }
}
