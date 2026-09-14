<?php

namespace Shamimstack\AssetShield\Exceptions;

use RuntimeException;

/**
 * Raised when the configured obfuscation engine cannot run or its output is
 * unusable. Never thrown for a deployed-build concern: the registry build
 * simply reflects whatever bytes the plugin produced.
 */
class ObfuscationException extends RuntimeException
{
}