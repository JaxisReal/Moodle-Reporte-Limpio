<?php
namespace local_reportelimpio\local;
defined('MOODLE_INTERNAL') || die();

/**
 * Decide qué usuarios se contabilizan en el reporte y en los dashboards.
 *
 * Regla: solo estudiantes.
 *  - Estudiante = rol con arquetipo "student" o shortname/name "student"/"estudiante".
 *  - Nunca se contabiliza un gestor (arquetipo manager), profesor (teacher /
 *    editingteacher), creador de cursos ni administrador, aunque esté
 *    matriculado o tenga la capacidad moodle/course:isincompletionreports.
 *  - Si un usuario tiene un rol de estudiante y además un rol de gestor o
 *    docente, se excluye: el rol de mayor responsabilidad manda.
 *
 * No modifica nada en Moodle; solo lee asignaciones de rol.
 */
class participants {
    /** Arquetipos que identifican a un estudiante. */
    private const STUDENT_ARCHETYPES=['student'];

    /** Nombres o shortnames que identifican a un estudiante. */
    private const STUDENT_NAMES=['student','estudiante','alumno','alumna'];

    /** Arquetipos que nunca se contabilizan. */
    private const STAFF_ARCHETYPES=['manager','coursecreator','teacher','editingteacher'];

    /** Nombres o shortnames de roles que nunca se contabilizan. */
    private const STAFF_NAMES=['gestor','manager','profesor','professor','teacher','docente','administrador','administrator','admin'];

    /**
     * Devuelve los usuarios que deben aparecer en el reporte: solo estudiantes.
     *
     * @param \context $context contexto del curso
     * @param array $users usuarios matriculados (get_enrolled_users)
     * @return array id => usuario
     */
    public static function forcourse($context, array $users): array {
        if (!$users) {
            return [];
        }
        $map = self::rolemap();
        $result = [];
        foreach ($users as $user) {
            if (is_siteadmin($user->id)) {
                continue;
            }
            $roleids = [];
            foreach (get_user_roles($context, (int)$user->id, true) as $assignment) {
                $roleids[] = (int)$assignment->roleid;
            }
            if (self::isstudent($roleids, $map)) {
                $result[$user->id] = $user;
            }
        }
        return $result;
    }

    /**
     * Lee los roles del sitio y los clasifica.
     *
     * @return array roleid => ['student'=>bool,'staff'=>bool]
     */
    public static function rolemap(): array {
        global $DB;
        return self::classify($DB->get_records('role', null, '', 'id,shortname,name,archetype'));
    }

    /**
     * Clasificación pura de roles (sin consultar Moodle; se puede probar aparte).
     *
     * @param array $roles registros con id, shortname, name, archetype
     * @return array roleid => ['student'=>bool,'staff'=>bool]
     */
    public static function classify(array $roles): array {
        $map = [];
        foreach ($roles as $role) {
            $short = \core_text::strtolower(trim((string)($role->shortname ?? '')));
            $name = \core_text::strtolower(trim((string)($role->name ?? '')));
            $archetype = \core_text::strtolower(trim((string)($role->archetype ?? '')));

            $staff = in_array($archetype, self::STAFF_ARCHETYPES, true)
                || self::matchname($short, self::STAFF_NAMES)
                || self::matchname($name, self::STAFF_NAMES);
            $student = !$staff && (
                in_array($archetype, self::STUDENT_ARCHETYPES, true)
                || self::matchname($short, self::STUDENT_NAMES)
                || self::matchname($name, self::STUDENT_NAMES)
            );

            $map[(int)$role->id] = ['student' => $student, 'staff' => $staff];
        }
        return $map;
    }

    /**
     * Indica si el usuario es estudiante según los roles que tiene asignados.
     * Basta un rol de gestor/docente para excluirlo.
     *
     * @param array $roleids roles asignados al usuario
     * @param array $map resultado de classify()
     */
    public static function isstudent(array $roleids, array $map): bool {
        $student = false;
        foreach ($roleids as $roleid) {
            $info = $map[(int)$roleid] ?? null;
            if ($info === null) {
                continue;
            }
            if ($info['staff']) {
                return false;
            }
            if ($info['student']) {
                $student = true;
            }
        }
        return $student;
    }

    /** Comprueba si un nombre de rol coincide con alguna de las palabras dadas. */
    private static function matchname(string $value, array $needles): bool {
        if ($value === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if (strpos($value, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
