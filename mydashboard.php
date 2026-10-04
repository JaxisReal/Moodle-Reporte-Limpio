<?php
require_once(__DIR__.'/../../config.php');
require_login();
$PAGE->set_url(new moodle_url('/local/reportelimpio/mydashboard.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Mi Dashboard de Avance');
$PAGE->set_heading('Mi Dashboard de Avance');
$courses=enrol_get_users_courses($USER->id,true,'id,fullname,shortname,visible');
$available=[];
foreach($courses as $course){
    $ctx=context_course::instance($course->id);
    if(empty($course->visible) && !has_capability('moodle/course:viewhiddencourses',$ctx,$USER->id)) continue;
    $available[]=$course;
}
usort($available,fn($a,$b)=>strcasecmp(format_string($a->fullname),format_string($b->fullname)));
echo $OUTPUT->header();
echo html_writer::start_div('container-fluid',['style'=>'max-width:900px;margin:30px auto;']);
echo html_writer::tag('h2','Mi Dashboard de Avance',['style'=>'color:#1d4ed8;font-weight:700;margin-bottom:8px;']);
echo html_writer::tag('p','Selecciona uno de los cursos en los que estás matriculado para consultar tu avance y compararlo con el progreso general del grupo.',['style'=>'font-size:1.05rem;margin-bottom:25px;']);
if(empty($available)){ echo $OUTPUT->notification('No tienes cursos disponibles para consultar en este momento.','info'); }
else {
 echo html_writer::start_tag('form',['method'=>'get','action'=>(new moodle_url('/local/reportelimpio/userdashboard.php'))->out(false),'class'=>'card p-4']);
 echo html_writer::label('Curso','courseid',false,['class'=>'font-weight-bold mb-2']);
 $options=[''=>'Seleccione un curso...']; foreach($available as $course){$options[$course->id]=format_string($course->fullname);}
 echo html_writer::select($options,'courseid','',false,['id'=>'courseid','class'=>'form-control mb-3','required'=>'required']);
 echo html_writer::empty_tag('input',['type'=>'submit','value'=>'Ver Dashboard','class'=>'btn btn-primary']); echo html_writer::end_tag('form');
}
echo html_writer::tag('p','Reporte Limpio | Desarrollado por Jerry Anderson Carril Chávez – Técnico en Administración e Informática | AGP – UGEL Ascope | 2026',['style'=>'margin-top:30px;font-size:12px;color:#6b7280;']);
echo html_writer::end_div(); echo $OUTPUT->footer();
