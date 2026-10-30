<?php
namespace local_reportelimpio\local;

defined('MOODLE_INTERNAL') || die();

/** Extra course-specific metadata for Gestión Escolar 2027. */
class directordata {
    public static function is_special($course): bool {
        return trim(format_string($course->fullname)) === 'Gestión Escolar en las Instituciones Educativa de la UGEL de Ascope 2027';
    }

    public static function schools(array $userids): array {
        global $DB;
        if (!$userids) return [];
        // User profile custom fields are stored in user_info_field/user_info_data.
        $fields = $DB->get_records('user_info_field', null, '', 'id,shortname,name');
        $fieldid = null;
        foreach ($fields as $field) {
            $name = \core_text::strtolower(trim($field->name));
            $short = \core_text::strtolower(trim($field->shortname));
            if (in_array($short, ['institucioneducativa','institucion_educativa','ie','colegio'], true)
                || $name === 'institución educativa' || $name === 'institucion educativa') {
                $fieldid = $field->id;
                break;
            }
        }
        if (!$fieldid) return [];
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'directoruid');
        $params['fieldid'] = $fieldid;
        $records = $DB->get_records_sql("SELECT id, userid, data FROM {user_info_data}
             WHERE fieldid = :fieldid AND userid $insql", $params);
        $result = [];
        foreach ($records as $record) {
            $result[(int)$record->userid] = trim((string)$record->data);
        }
        return $result;
    }

    public static function districts(int $courseid, array $userids): array {
        global $DB;
        if (!$userids) return [];
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'districtuid');
        $params['courseid'] = $courseid;
        $records = $DB->get_records_sql("SELECT gm.id, gm.userid, g.name
            FROM {groups_members} gm JOIN {groups} g ON g.id = gm.groupid
            WHERE g.courseid = :courseid AND gm.userid $insql
            ORDER BY g.name ASC", $params);
        $result = [];
        foreach ($records as $record) {
            $result[(int)$record->userid][] = trim((string)$record->name);
        }
        return array_map(static function($names) { return implode(', ', array_unique($names)); }, $result);
    }
}
