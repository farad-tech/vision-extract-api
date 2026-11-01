<?php

declare(strict_types=1);

namespace App\Application\Extraction\ValidateExtraction;

use App\Application\Extraction\Exception\ExtractionException;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Resolvers\SchemaResolver;

final class StructuredOutputValidator
{
    public function parseSchema(string $json): object|bool
    {
        if (strlen($json) > 65536) {
            throw new ExtractionException('invalid_schema', 'Schema exceeds 64 KiB.');
        }
        try {
            $schema = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            if (!is_object($schema) && !is_bool($schema)) {
                throw new \InvalidArgumentException();
            }
            // No external resources or executable Opis extensions from user input.
            $this->checkSchema($schema);
            $resolver = new SchemaResolver();
            $directory = dirname(__DIR__, 4).'/resources/json-schema/2020-12';
            foreach (array_merge([$directory.'/schema.json'], glob($directory.'/meta/*.json')) as $file) {
                $resolver->registerRaw(file_get_contents($file));
            }
            $metaValidator = new Validator(new SchemaLoader(resolver: $resolver));
            if (!$metaValidator->validate($schema, 'https://json-schema.org/draft/2020-12/schema')->isValid()) {
                throw new \InvalidArgumentException();
            }
            if (is_object($schema) && isset($schema->{'$schema'}) && $schema->{'$schema'} !== 'https://json-schema.org/draft/2020-12/schema') {
                throw new \InvalidArgumentException();
            }
            $loader = new SchemaLoader(); // No file/network resolver registered.
            if (is_object($schema)) {
                $loader->loadObjectSchema($schema);
            }
        } catch (\Throwable) {
            throw new ExtractionException('invalid_schema', 'Schema must be a valid, self-contained JSON Schema.');
        }

        return $schema;
    }

    public function validate(string $text, object|bool $schema): mixed
    {
        if (strlen($text) > 1048576) {
            throw new ExtractionException('invalid_output', 'Generated output exceeds the size limit.', 502);
        }
        // Only strip a single complete Markdown JSON fence; never repair data or coerce types.
        $text = trim($text);
        if (preg_match('/\A```(?:json)?\s*\n(.*)\n```\z/s', $text, $matches)) {
            $text = $matches[1];
        }
        try {
            $data = json_decode($text, false, 64, JSON_THROW_ON_ERROR);
            $validator = new Validator(new SchemaLoader());
            if (!$validator->validate($data, $schema)->isValid()) {
                throw new \UnexpectedValueException();
            }
        } catch (\Throwable) {
            throw new ExtractionException('invalid_output', 'Generated output is not valid JSON matching the requested schema.', 502);
        }

        return $data;
    }

    private function checkSchema(mixed $node): void
    {
        if (!is_object($node) && !is_array($node)) {
            return;
        }
        if (is_array($node)) {
            foreach ($node as $value) {
                $this->checkSchema($value);
            }
            return;
        }
        foreach (['$filters', '$data', '$map', '$vars', '$slots', '$inject'] as $extension) {
            if (property_exists($node, $extension)) {
                throw new \InvalidArgumentException();
            }
        }
        foreach (['$ref', '$dynamicRef', '$recursiveRef'] as $keyword) {
            if (property_exists($node, $keyword) && (!is_string($node->{$keyword}) || !str_starts_with($node->{$keyword}, '#'))) {
                throw new \InvalidArgumentException();
            }
        }
        foreach (['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'] as $keyword) {
            if (isset($node->{$keyword}) && is_object($node->{$keyword})) {
                foreach ($node->{$keyword} as $subschema) {
                    $this->checkSchema($subschema);
                }
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else', 'items', 'prefixItems', 'contains', 'additionalProperties', 'propertyNames', 'unevaluatedProperties', 'unevaluatedItems', 'contentSchema'] as $keyword) {
            if (isset($node->{$keyword})) {
                $this->checkSchema($node->{$keyword});
            }
        }
    }
}
