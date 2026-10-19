<?php

declare(strict_types=1);

namespace App\Guest\Portrait;

use RuntimeException;

/** Anfrage abgelehnt; die Meldung ist für Gäste formuliert. */
final class PortraitRejected extends RuntimeException {}
