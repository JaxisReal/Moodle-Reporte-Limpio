<?php
namespace local_reportelimpio\local;
defined('MOODLE_INTERNAL') || die();

/**
 * Construye la lista de actividades de un curso en el orden real de Moodle.
 *
 * Se incluyen también las actividades sin seguimiento de finalización porque,
 * aunque no se exportan, interrumpen la consecutividad entre una original y
 * sus copias. El orden de get_cms() es el orden real del curso: Moodle lee
 * course_sections ordenado por "section" y luego la secuencia de cada sección.
 *
 * No modifica nada en Moodle; solo lee.
 */
class activitysource {
    /**
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param bool $withsectionname incluir el nombre de la sección (vista previa)
     * @return array
     */
    public static function build($course, $modinfo, bool $withsectionname = false): array {
        $hierarchy = hierarchy::activitymap($course, $modinfo);
        $activities = [];

        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            $h = $hierarchy[$cm->id] ?? ['module' => null, 'unit' => null, 'session' => null];
            $activity = [
                'cmid' => $cm->id,
                'name' => $cm->name,
                'modname' => $cm->modname,
                'sectionnum' => $cm->sectionnum,
                'visible' => (int)$cm->visible,
                'tracked' => !empty($cm->completion),
                'module' => $h['module'],
                'unit' => $h['unit'],
                'session' => $h['session']
            ];
            if ($withsectionname) {
                $sectioninfo = $modinfo->get_section_info($cm->sectionnum);
                $activity['sectionname'] = $sectioninfo ? get_section_name($course, $sectioninfo) : '';
            }
            $activities[] = $activity;
        }

        return $activities;
    }

    /**
     * Cantidad de actividades con seguimiento de finalización (columnas reales).
     */
    public static function trackedcount(array $activities): int {
        $total = 0;
        foreach ($activities as $activity) {
            if (!empty($activity['tracked'])) {
                $total++;
            }
        }
        return $total;
    }
}
