<?php
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_reportelimpio',
        get_string('pluginname', 'local_reportelimpio'),
        new moodle_url('/local/reportelimpio/index.php'),
        'local/reportelimpio:view'
    ));
}
