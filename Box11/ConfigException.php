<?php

declare(strict_types=1);

/**
 * ConfigException
 *
 * Base exception for configuration-related errors.
 * Allows catching config errors separately from other runtime exceptions.
 */
class ConfigException extends RuntimeException {}

/**
 * ConfigValidationException
 *
 * Thrown when configuration validation fails.
 */
class ConfigValidationException extends ConfigException {}

/**
 * ConfigFileException
 *
 * Thrown when configuration file operations fail.
 */
class ConfigFileException extends ConfigException {}

/**
 * ConfigSecurityException
 *
 * Thrown when security constraints are violated (path traversal, etc.)
 */
class ConfigSecurityException extends ConfigException {}
