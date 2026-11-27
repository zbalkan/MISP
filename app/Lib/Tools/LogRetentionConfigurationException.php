<?php

declare(strict_types=1);

/** A category-specific policy error, not a database or transaction failure. */
final class LogRetentionConfigurationException extends InvalidArgumentException
{
}
