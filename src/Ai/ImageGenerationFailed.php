<?php

declare(strict_types=1);

namespace App\Ai;

use RuntimeException;

/** Anbieter nicht erreichbar, Anfrage abgelehnt (z. B. Inhaltsfilter) oder keine Bilddaten erhalten. */
final class ImageGenerationFailed extends RuntimeException {}
