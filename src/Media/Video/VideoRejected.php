<?php

declare(strict_types=1);

namespace App\Media\Video;

use RuntimeException;

/**
 * Die Datei ist kein verarbeitbares Video oder verletzt Limits (fachlicher Fehler, kein Retry).
 */
final class VideoRejected extends RuntimeException {}
