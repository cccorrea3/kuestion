<?php

namespace App\Contracts;

/**
 * Proveedor de señales estructuradas (2.2 — Bloque 8).
 *
 * Los métodos devuelven arrays (no DTOs) a propósito: resiliencia a cambios del
 * catálogo de tools de Kuaforia y menor fricción al ajustar. Si el catálogo se
 * estabiliza, se pueden extraer DTOs después (fuera de alcance).
 *
 * Forma documentada del array retornado (normalizado desde result.content[].text):
 * - getWorkspaceHealth:  ['workspace_id' => string, 'status' => string, 'healthy' => bool, ...] (según tool)
 * - getDependencyHealthReport: ['workspace_id' => string, 'dependencies' => array{...}, ...]
 * - getCaseDetails:      ['case_id' => string, 'status' => string, ...]
 */
interface StructuredSignalProviderInterface
{
    /**
     * Salud del workspace (tool MCP: get_workspace_health).
     */
    public function getWorkspaceHealth(string $workspaceId): array;

    /**
     * Reporte de salud de dependencias (tool MCP: get_dependency_health_report).
     */
    public function getDependencyHealthReport(string $workspaceId): array;

    /**
     * Detalle de un caso (tool MCP: get_case).
     */
    public function getCaseDetails(string $caseId): array;
}
