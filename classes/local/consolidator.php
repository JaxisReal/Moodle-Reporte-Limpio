<?php
namespace local_reportelimpio\local;
defined('MOODLE_INTERNAL') || die();

/**
 * Convierte la detección en columnas del reporte.
 *
 * - Una columna por actividad visible y con seguimiento de finalización.
 * - Las copias ocultas no generan columna: solo aportan su estado.
 * - Las actividades sin seguimiento de finalización nunca son columna,
 *   aunque se hayan usado para comprobar la consecutividad.
 * - Las copias ocultas sin una original visible anterior compatible no
 *   aparecen: se informan aparte y no se contabilizan.
 */
class consolidator {
    public function build(array $activities, array $detection): array {
        $starts = [];
        $skips = [];
        foreach (($detection['groups'] ?? []) as $group) {
            $starts[$group[0]] = $group;
            foreach (array_slice($group, 1) as $idx) {
                $skips[$idx] = true;
            }
        }

        $result = [];
        foreach ($activities as $i => $activity) {
            if (isset($skips[$i]) || empty($activity['tracked'])) {
                continue;
            }
            $sources = $starts[$i] ?? [$i];
            $visible = [];
            foreach ($sources as $idx) {
                if (!empty($activities[$idx]['visible']) && !empty($activities[$idx]['tracked'])) {
                    $visible[] = $idx;
                }
            }
            if (!$visible) {
                continue;
            }
            $canonical = $activities[$visible[0]];
            $result[] = [
                'name' => $canonical['name'],
                'modname' => $canonical['modname'],
                'sectionnum' => $canonical['sectionnum'],
                'module' => $canonical['module'] ?? null,
                'unit' => $canonical['unit'] ?? null,
                'session' => $canonical['session'] ?? null,
                'sourceindexes' => $sources,
                'consolidated' => count($sources) > 1
            ];
        }
        return $result;
    }
}
