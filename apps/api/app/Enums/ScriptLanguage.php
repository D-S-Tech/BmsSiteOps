<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Target language / output format for a generated script.
 *
 * Drives the prompt shaping in the worker (a BACnet config gets different
 * "context priming" than a Python script) and the syntax-highlight hint on
 * the web side.
 */
enum ScriptLanguage: string implements HasLabel
{
    case Python = 'python';
    case JavaScript = 'javascript';
    case TypeScript = 'typescript';
    case Shell = 'shell';
    case EspHomeYaml = 'esphome_yaml';
    case NodeRedFlow = 'nodered_flow';
    case BacnetConfig = 'bacnet_config';
    case NiagaraProgram = 'niagara_program';
    case Generic = 'generic';

    public function getLabel(): string
    {
        return match ($this) {
            self::Python => 'Python',
            self::JavaScript => 'JavaScript',
            self::TypeScript => 'TypeScript',
            self::Shell => 'Shell (bash)',
            self::EspHomeYaml => 'ESPHome YAML',
            self::NodeRedFlow => 'Node-RED flow',
            self::BacnetConfig => 'BACnet config',
            self::NiagaraProgram => 'Niagara program',
            self::Generic => 'Generic',
        };
    }

    /** Hint used by syntax highlighters (Prism/Highlight.js identifiers). */
    public function highlightHint(): string
    {
        return match ($this) {
            self::Python => 'python',
            self::JavaScript => 'javascript',
            self::TypeScript => 'typescript',
            self::Shell => 'bash',
            self::EspHomeYaml => 'yaml',
            self::NodeRedFlow => 'json',
            self::BacnetConfig, self::NiagaraProgram, self::Generic => 'plaintext',
        };
    }
}
