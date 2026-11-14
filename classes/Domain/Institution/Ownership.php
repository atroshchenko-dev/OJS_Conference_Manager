<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

enum Ownership: string
{
    case State = 'state';
    case Communal = 'communal';
    case Private = 'private';
    case Corporate = 'corporate';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.institution.ownership.' . $this->value;
    }
}
