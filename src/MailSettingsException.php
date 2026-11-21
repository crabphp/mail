<?php

declare(strict_types=1);

namespace Crab\Mail;

use Crab\Contracts\Config\InvalidSettings;

/**
 * Mail settings that each fit their rule but not one another, such as
 * the smtp transport without a host, said in the framework's form:
 * `key is value; it takes what.` A secret's value is never repeated.
 */
final class MailSettingsException extends \InvalidArgumentException implements InvalidSettings
{
    /**
     * @param list<string> $problems
     */
    public function __construct(
        private readonly array $problems,
    ) {
        $heading = count($problems) === 1 ? 'A setting does not fit:' : count($problems) . ' settings do not fit:';
        parent::__construct(
            $heading . "\n"
                . implode("\n", array_map(static fn(string $problem): string => '  ' . $problem, $problems)),
        );
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
