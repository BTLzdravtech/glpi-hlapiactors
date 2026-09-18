<?php

use GlpiPlugin\Hlapiactors\ApiSchemas;

/** No tables, no configuration: nothing to install. */
function plugin_hlapiactors_install(): bool
{
    return true;
}

function plugin_hlapiactors_uninstall(): bool
{
    return true;
}

/**
 * Hooks::REDEFINE_API_SCHEMAS callback.
 *
 * @param array{controller: string, schemas: array<string, array>} $data
 * @return array{controller: string, schemas: array<string, array>}
 */
function plugin_hlapiactors_redefine_api_schemas(array $data): array
{
    return ApiSchemas::redefine($data);
}
