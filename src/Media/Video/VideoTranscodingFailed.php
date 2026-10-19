<?php

declare(strict_types=1);

namespace App\Media\Video;

use RuntimeException;

/**
 * Technischer Fehler bei der Verarbeitung (Retry sinnvoll).
 */
final class VideoTranscodingFailed extends RuntimeException {}
