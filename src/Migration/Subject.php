<?php

declare(strict_types=1);

namespace Difflock\Migration;

/**
 * What kind of thing a finding is about, so the JSON report can name it with the
 * right key and the console can say "column" rather than "subject".
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
enum Subject: string
{
    case Table = 'table';
    case Column = 'column';
    case Index = 'index';
    case Constraint = 'constraint';

    /** The finding is about the migration as a whole. */
    case None = 'none';
}
