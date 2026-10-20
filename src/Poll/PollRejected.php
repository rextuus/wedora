<?php

declare(strict_types=1);

namespace App\Poll;

use RuntimeException;

/** Stimme nicht möglich; die Meldung ist für Gäste formuliert. */
final class PollRejected extends RuntimeException {}
