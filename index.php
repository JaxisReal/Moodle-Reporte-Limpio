<?php
require_once(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/completionlib.php');
require_login();
$ctx=context_system::instance();
require_capability('local/reportelimpio:view',$ctx);
$PAGE->set_url(new moodle_url('/local/reportelimpio/index.php'));
$PAGE->set_context($ctx); $PAGE->set_title(get_string('pluginname','local_reportelimpio'));
$PAGE->set_heading(get_string('pluginname','local_reportelimpio'));
$courseid=optional_param('courseid',0,PARAM_INT);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('heading','local_reportelimpio'));

$courses=get_courses('all','fullname ASC','c.id,c.fullname,c.visible');
$opts=[0=>get_string('selectcourse','local_reportelimpio')];
foreach($courses as $c){if((int)$c->id===SITEID)continue;$opts[$c->id]=format_string($c->fullname).(!$c->visible?' ('.get_string('hidden').')':'');}
echo html_writer::start_tag('form',['method'=>'get','action'=>new moodle_url('/local/reportelimpio/index.php'),'class'=>'mb-4']);
echo html_writer::select($opts,'courseid',$courseid,false,['class'=>'custom-select mr-2']);
echo html_writer::empty_tag('input',['type'=>'submit','value'=>get_string('analyse','local_reportelimpio'),'class'=>'btn btn-primary']);
echo html_writer::end_tag('form');

if($courseid){
 $course=get_course($courseid); $cctx=context_course::instance($courseid);
 echo $OUTPUT->notification(get_string('readonlynotice','local_reportelimpio'),'info');
 echo html_writer::tag('h3',format_string($course->fullname));

 $modinfo=get_fast_modinfo($course);
 $activities=\local_reportelimpio\local\activitysource::build($course,$modinfo,true);
 $det=(new \local_reportelimpio\local\detector())->analyse($activities);
 $clean=(new \local_reportelimpio\local\consolidator())->build($activities,$det);

 $tracked=\local_reportelimpio\local\activitysource::trackedcount($activities);
 echo html_writer::tag('p',get_string('trackedactivitycount','local_reportelimpio',$tracked));
 if($det['detected']){
   $a=(object)['pairs'=>$det['duplicatecount'],'start'=>s($det['start']),'end'=>s($det['end'])];
   echo $OUTPUT->notification(get_string('duplicatesfound','local_reportelimpio',$a),'warning');
 } else echo $OUTPUT->notification(get_string('noduplicates','local_reportelimpio'),'success');
 if($det['unmatchedcount']){
   echo $OUTPUT->notification(get_string('unmatchedfound','local_reportelimpio',$det['unmatchedcount']),'warning');
 }

 $sum=(object)['original'=>$tracked,'pairs'=>$det['duplicatecount'],'clean'=>count($clean)];
 echo $OUTPUT->notification(get_string('consolidationsummary','local_reportelimpio',$sum),'info');

 $users=get_enrolled_users($cctx,'',0,'u.id,u.firstname,u.lastname,u.email','u.lastname ASC,u.firstname ASC');
 $participants=\local_reportelimpio\local\participants::forcourse($cctx,$users);
 if(!$participants && $users){
   echo $OUTPUT->notification(get_string('nostudentsfound','local_reportelimpio'),'warning');
 }
 echo html_writer::tag('p',get_string('participantcount','local_reportelimpio',count($participants)));

 $downloadurl=new moodle_url('/local/reportelimpio/download.php',['courseid'=>$courseid,'sesskey'=>sesskey()]);
 echo html_writer::link($downloadurl,get_string('downloadexcel','local_reportelimpio'),['class'=>'btn btn-success mb-4 mr-2']);

 $dashboardurl=new moodle_url('/local/reportelimpio/dashboard.php',['courseid'=>$courseid]);
 echo html_writer::link($dashboardurl,'Ver Dashboard',['class'=>'btn btn-primary mb-4 mr-2','target'=>'_blank']);

 $structureurl=new moodle_url('/local/reportelimpio/structure.php',['courseid'=>$courseid]);
 echo html_writer::link($structureurl,'Diagnosticar Módulo / Unidad / Sesión',['class'=>'btn btn-secondary mb-4']);

 $completion=new completion_info($course);
 $pu=array_slice($participants,0,10,true); $pa=array_slice($clean,0,20);
 echo html_writer::tag('h4',get_string('previewtitle','local_reportelimpio'));
 if($pu && $pa){
   $t=new html_table(); $t->attributes['class']='generaltable table-sm';
   $t->head=[get_string('participant','local_reportelimpio')];
   foreach($pa as $a)$t->head[]=format_string($a['name']).($a['consolidated']?' '.get_string('mergedmark','local_reportelimpio'):'');
   foreach($pu as $u){
     $row=[fullname($u)];
     foreach($pa as $a)$row[]=\local_reportelimpio\local\reportdata::state_for_user($completion,$modinfo,$activities,$a,$u->id)
       ? get_string('completed','local_reportelimpio'):get_string('notcompleted','local_reportelimpio');
     $t->data[]=$row;
   }
   echo html_writer::div(html_writer::table($t),'table-responsive');
 }
}

echo html_writer::start_div('card mt-4 mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5','Reporte Limpio',['class'=>'card-title']);
echo html_writer::tag('p',
    '<strong>Plugin:</strong> Reporte Limpio<br>'.
    '<strong>Desarrollador:</strong> Jerry Anderson Carril Chávez<br>'.
    '<strong>Cargo:</strong> Técnico en Administración e Informática<br>'.
    '<strong>Área:</strong> AGP – UGEL Ascope<br>'.
    '<strong>Versión:</strong> 0.6.19<br>'.
    '<strong>Año:</strong> 2026<br>'.
    '<strong>Descripción:</strong> Plugin para Moodle orientado a la generación y consolidación de reportes de finalización, incluyendo detección jerárquica de módulos, unidades y sesiones.',
    ['class'=>'card-text']
);
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
