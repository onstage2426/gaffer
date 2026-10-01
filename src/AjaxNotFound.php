<?php

declare(strict_types=1);

namespace Gaffer;

use RuntimeException;

/**
 * A run() parameter typed as a Gaffer type got an ID that doesn't resolve (a 404).
 */
final class AjaxNotFound extends RuntimeException {}
