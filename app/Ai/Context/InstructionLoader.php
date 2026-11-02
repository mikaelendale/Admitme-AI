<?php

namespace App\Ai\Context;

use InvalidArgumentException;

/**
 * Loads trusted runtime instructions from resources/ai.
 *
 * Only files on the allow-list can be loaded, so student uploads, retrieved pages or model output can
 * never become instruction files. Placeholders like {name} are replaced from application values only.
 */
final class InstructionLoader
{
    /**
     * @var list<string>
     */
    public const FILES = [
        'AGENTS.md',
        'agents/mentor.md',
        'agents/summarizer.md',
        'agents/document-extractor.md',
        'agents/requirements-extractor.md',
        'agents/researcher.md',
        'agents/college-researcher.md',
        'agents/writing-coach.md',
        'agents/application-planner.md',
        'modes/ask.md',
        'modes/agent.md',
        'subagents/researcher.md',
        'subagents/college-scout.md',
        'subagents/application-planner.md',
        'subagents/writing-coach.md',
    ];

    /**
     * @var array<string, string>
     */
    private array $loaded = [];

    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? resource_path('ai');
    }

    /**
     * @param  array<string, string>  $replacements  Placeholder name (without braces) => trusted value.
     */
    public function load(string $file, array $replacements = []): string
    {
        if (! in_array($file, self::FILES, true)) {
            throw new InvalidArgumentException("[{$file}] is not a registered AdmitMe instruction file.");
        }

        $this->loaded[$file] ??= trim((string) file_get_contents($this->basePath.DIRECTORY_SEPARATOR.$file));

        $placeholders = [];
        foreach ($replacements as $name => $value) {
            $placeholders['{'.$name.'}'] = $value;
        }

        return strtr($this->loaded[$file], $placeholders);
    }

    /**
     * A short fingerprint of every instruction file, recorded with each run for traceability.
     */
    public function version(): string
    {
        $contents = '';

        foreach (self::FILES as $file) {
            $path = $this->basePath.DIRECTORY_SEPARATOR.$file;
            $contents .= $file."\n".(is_file($path) ? (string) file_get_contents($path) : '')."\n";
        }

        return substr(sha1($contents), 0, 12);
    }
}
