<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Validador mínimo de resposta contra um schema do OpenAPI: `required`, tipos (inclusive união com null),
 * `enum`, `format: date-time`, propriedades aninhadas, `items` e `$ref` local.
 */
trait OpenApiAssertions
{
    /** @var array<string, mixed> */
    private array $openApiSpec = [];

    protected function loadOpenApi(string $specFolder): void
    {
        $this->openApiSpec = Yaml::parseFile(base_path("../../docs/funcionalidades/{$specFolder}/openapi.yaml"));
    }

    /** @return list<string> erros encontrados (vazio quando o valor respeita o schema) */
    protected function schemaErrors(mixed $value, string $schemaName, string $path = ''): array
    {
        $schema = data_get($this->openApiSpec, "components.schemas.{$schemaName}");
        expect($schema)->not->toBeNull("schema {$schemaName} não existe no contrato");

        return $this->validateNode($value, $schema, $path === '' ? $schemaName : $path);
    }

    /** @return list<string> */
    private function validateNode(mixed $value, array $schema, string $path): array
    {
        if (isset($schema['$ref'])) {
            $name = substr((string) $schema['$ref'], strrpos((string) $schema['$ref'], '/') + 1);

            return $this->validateNode($value, data_get($this->openApiSpec, "components.schemas.{$name}") ?? [], $path);
        }

        $errors = [];
        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! $this->matchesAnyType($value, $types)) {
            return ["{$path}: tipo {$this->typeOf($value)} não está em [".implode(', ', $types).']'];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: valor fora do enum";
        }

        if (($schema['format'] ?? null) === 'date-time' && is_string($value) && strtotime($value) === false) {
            $errors[] = "{$path}: não é date-time";
        }

        if (is_array($value) && in_array('object', $types, true)) {
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = "{$path}: falta {$required}";
                }
            }
            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                if (array_key_exists($name, $value)) {
                    $errors = array_merge($errors, $this->validateNode($value[$name], $property, "{$path}.{$name}"));
                }
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_diff(array_keys($value), array_keys((array) ($schema['properties'] ?? []))) as $extra) {
                    $errors[] = "{$path}: propriedade inesperada {$extra}";
                }
            }
        }

        return $errors;
    }

    /** @param  list<string>  $types */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ! array_is_list($value),
                default => true,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function typeOf(mixed $value): string
    {
        return get_debug_type($value);
    }
}
