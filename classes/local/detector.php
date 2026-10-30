<?php
namespace local_reportelimpio\local;
defined('MOODLE_INTERNAL') || die();

/**
 * Agrupa copias ocultas con su actividad original visible.
 *
 * Regla:
 *  1. Original = actividad visible y con seguimiento de finalización.
 *  2. Copia = actividad oculta con el mismo tipo, nombre normalizado, sección
 *     y jerarquía (módulo/unidad/sesión).
 *  3. Cada copia pertenece a la ÚLTIMA actividad original visible anterior que
 *     coincida. Esa asignación es la correcta: cuando aparece una nueva original
 *     visible, el grupo anterior se cierra y las copias siguientes son suyas.
 *  4. Otras actividades en medio no rompen la relación.
 *  5. Una copia antes de cualquier original compatible queda sin asignar.
 *
 * El módulo, unidad y sesión forman parte de la clave, así que al cambiar de
 * sesión, unidad o módulo se reinicia la búsqueda de originales.
 *
 * No modifica ningún registro de Moodle.
 */
class detector {
    public function analyse(array $activities): array {
        $originals = [];
        $byoriginal = [];
        $unmatched = [];

        foreach ($activities as $index => $activity) {
            $key = $this->key($activity);
            if ($key === null) {
                continue;
            }

            if (!empty($activity['visible'])) {
                // Una nueva original visible reemplaza a la anterior para esta clave.
                if (!empty($activity['tracked'])) {
                    $originals[$key] = $index;
                }
                continue;
            }

            // Copia oculta: solo cuentan las que tienen seguimiento, porque son
            // las únicas que pueden aportar estado de finalización.
            if (empty($activity['tracked'])) {
                continue;
            }

            if (!array_key_exists($key, $originals)) {
                $unmatched[] = $index;
                continue;
            }

            $original = $originals[$key];
            if (!isset($byoriginal[$original])) {
                $byoriginal[$original] = [$original];
            }
            $byoriginal[$original][] = $index;
        }

        ksort($byoriginal, SORT_NUMERIC);
        $groups = array_values($byoriginal);
        $groupmap = [];
        $extra = 0;
        foreach ($groups as $groupindex => $group) {
            $extra += count($group) - 1;
            foreach ($group as $idx) {
                $groupmap[$idx] = $groupindex + 1;
            }
        }
        $first = $groups ? $groups[0][0] : null;
        $last = $groups ? $groups[count($groups) - 1][0] : null;
        return [
            'detected' => !empty($groups),
            'groups' => $groups,
            'groupmap' => $groupmap,
            'groupcount' => count($groups),
            'duplicatecount' => $extra,
            'paircount' => $extra,
            'unmatched' => $unmatched,
            'unmatchedcount' => count($unmatched),
            'start' => $first !== null ? $activities[$first]['name'] : '',
            'end' => $last !== null ? $activities[$last]['name'] : ''
        ];
    }

    private function key(array $activity): ?string {
        $name = \core_text::strtolower(
            preg_replace('/\s+/u', ' ', trim((string)($activity['name'] ?? '')))
        );
        if ($name === '') {
            return null;
        }
        return json_encode([
            (int)($activity['sectionnum'] ?? -1),
            $activity['module'] ?? null,
            $activity['unit'] ?? null,
            $activity['session'] ?? null,
            (string)($activity['modname'] ?? ''),
            $name
        ], JSON_UNESCAPED_UNICODE);
    }
}
