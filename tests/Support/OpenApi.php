<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Checks JSON responses against docs/api/openapi.json. Supports the subset of
 * JSON Schema the definition uses: type (including type lists), properties,
 * required, additionalProperties: false, items, enum and local $ref. Anything
 * else in a schema is an error, so the definition can't silently outgrow
 * the check.
 */
final class OpenApi
{
    private const KEYWORDS = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', '$ref', 'description'];

    private array $document;

    public function __construct(?string $path = null)
    {
        $this->document = json_decode(file_get_contents($path ?? base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> "METHOD /path" for every documented operation */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->document['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $operations[] = strtoupper($method).' '.$path;
            }
        }
        sort($operations);

        return $operations;
    }

    /** @return list<string> problems found; empty when the body matches */
    public function validateResponse(string $method, string $path, int $status, mixed $body): array
    {
        $operation = $this->document['paths'][$path][strtolower($method)] ?? null;
        if ($operation === null) {
            return ["{$method} {$path} is not documented."];
        }
        $response = $operation['responses'][(string) $status] ?? null;
        if ($response === null) {
            return ["{$method} {$path} does not document status {$status}."];
        }
        $response = $this->resolve($response);
        $schema = $response['content']['application/json']['schema'] ?? null;

        return $schema === null ? [] : $this->check($schema, $body, '$');
    }

    private function check(array $schema, mixed $value, string $at): array
    {
        $schema = $this->resolve($schema);
        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::KEYWORDS, true)) {
                throw new RuntimeException("Unsupported schema keyword '{$keyword}' at {$at}.");
            }
        }

        $errors = [];
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $actual = self::typeOf($value);
            // PHP decodes [] and {} alike; an empty array satisfies either.
            if ($value === [] && in_array('array', $types, true)) {
                $actual = 'array';
            }
            if (! in_array($actual, $types, true) && ! (in_array('number', $types, true) && is_int($value))) {
                return ["{$at}: expected ".implode('|', $types).", got {$actual}."];
            }
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$at}: value is not in the enum.";
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items']) && self::typeOf($value) === 'array') {
            foreach ($value as $i => $item) {
                array_push($errors, ...$this->check($schema['items'], $item, "{$at}[{$i}]"));
            }
        }
        if (self::typeOf($value) === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$at}.{$key}: required but missing.";
                }
            }
            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key])) {
                    array_push($errors, ...$this->check($schema['properties'][$key], $item, "{$at}.{$key}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$at}.{$key}: not documented.";
                }
            }
        }

        return $errors;
    }

    private function resolve(array $node): array
    {
        while (isset($node['$ref'])) {
            $node = $this->pointer($node['$ref']);
        }

        return $node;
    }

    private function pointer(string $ref): array
    {
        if (! str_starts_with($ref, '#/')) {
            throw new RuntimeException("Only local references are supported: {$ref}");
        }
        $node = $this->document;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = $node[$part] ?? throw new RuntimeException("Unresolvable reference {$ref}");
        }

        return $node;
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && ($value === [] || ! array_is_list($value)) => 'object',
            default => 'array',
        };
    }
}
