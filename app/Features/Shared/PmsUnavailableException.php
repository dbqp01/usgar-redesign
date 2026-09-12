<?php
declare(strict_types=1);

namespace App\Features\Shared;

/** Raised when the PMS/database cannot provide an availability read. */
final class PmsUnavailableException extends \RuntimeException
{
}
