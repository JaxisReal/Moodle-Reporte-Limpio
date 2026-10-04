<?php
require_once(__DIR__.'/../../config.php');

require_login();
require_capability('local/reportelimpio:view', context_system::instance());

$courseid=required_param('courseid',PARAM_INT);
$course=get_course($courseid);
$cctx=context_course::instance($courseid);

$PAGE->set_url(new moodle_url('/local/reportelimpio/structure.php',['courseid'=>$courseid]));
$PAGE->set_context($cctx);
$PAGE->set_title('Diagnóstico jerárquico');
$PAGE->set_heading('Diagnóstico jerárquico');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($course->fullname));
echo $OUTPUT->notification(
    'V0.4.5 - Solo lectura. Lee el contenido real de las etiquetas y detecta Módulo → Unidad → Sesión. No modifica el curso.',
    'info'
);

$modinfo=get_fast_modinfo($course);

function rl_plain($html) {
    $text=html_to_text((string)$html,0,false);
    $text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=preg_replace('/\x{00A0}/u',' ',$text);
    $text=preg_replace('/[ \t]+/u',' ',$text);
    $text=preg_replace('/\R{2,}/u',"\n",$text);
    return trim($text);
}

function rl_number($text,$kind) {
    // "Módúlo" is included intentionally because it exists in this course.
    $patterns=[
      'module'=>'/(?:^|\R|\s)M[oó][dD][uú]lo\s*(?:N[.°º]?\s*)?(\d+)\b/iu',
      'unit'=>'/(?:^|\R|\s)Unidad\s*(?:N[.°º]?\s*)?(\d+)\b/iu',
      'session'=>'/(?:^|\R|\s)Sesi[oó]n\s*(?:N[.°º]?\s*)?(\d+)\b/iu'
    ];
    return preg_match($patterns[$kind],$text,$m) ? (int)$m[1] : null;
}

// Construir el flujo real del curso.
$stream=[];
foreach($modinfo->get_section_info_all() as $section) {
    if(!$section) continue;

    $sectiontext=rl_plain(get_section_name($course,$section));
    if($sectiontext!=='') {
        $stream[]=['type'=>'SECCIÓN','text'=>$sectiontext,'source'=>'nombre de sección'];
    }

    foreach($modinfo->get_cms() as $cm) {
        if($cm->sectionnum != $section->section || $cm->deletioninprogress) continue;

        if($cm->modname==='label') {
            // Moodle 4.4: obtain the actual label record and read intro HTML.
            $label=$DB->get_record('label',['id'=>$cm->instance],'id,intro,introformat',IGNORE_MISSING);
            $text='';
            $source='';

            if($label && trim((string)$label->intro)!=='') {
                $text=rl_plain($label->intro);
                $source='contenido HTML de etiqueta';
            }

            // Fallback only if intro cannot be read.
            if($text==='') {
                $text=rl_plain($cm->name);
                $source='nombre de etiqueta (respaldo)';
            }

            $stream[]=['type'=>'ETIQUETA','text'=>$text,'source'=>$source];
        } else if(!empty($cm->completion)) {
            $stream[]=['type'=>'ACTIVIDAD','text'=>rl_plain($cm->name),'source'=>'actividad'];
        }
    }
}

// Busca el próximo marcador, sin atravesar un límite jerárquico superior.
function rl_next($stream,$pos,$kind) {
    for($i=$pos+1;$i<count($stream);$i++) {
        $v=rl_number($stream[$i]['text'],$kind);
        if($v!==null) return $v;

        if($kind==='session') {
            if(rl_number($stream[$i]['text'],'module')!==null ||
               rl_number($stream[$i]['text'],'unit')!==null) return null;
        }
        if($kind==='unit' && rl_number($stream[$i]['text'],'module')!==null) return null;
    }
    return null;
}

$currentmodule=null;
$currentunit=null;
$currentsession=null;

$table=new html_table();
$table->head=[
    'Orden','Elemento leído','Texto detectado','Fuente',
    'Módulo actual','Unidad actual','Sesión actual'
];

foreach($stream as $i=>$item) {
    $m=rl_number($item['text'],'module');
    $u=rl_number($item['text'],'unit');
    $ss=rl_number($item['text'],'session');

    if($m!==null) {
        // Nuevo módulo: finaliza unidad y sesión anteriores.
        $currentmodule=$m;
        $currentunit=null;
        $currentsession=null;

        // Si la misma etiqueta contiene Unidad/Sesión, se toman directamente.
        // Si no, se busca el próximo marcador explícito dentro del nuevo módulo.
        $currentunit=($u!==null) ? $u : rl_next($stream,$i,'unit');
        $currentsession=($ss!==null) ? $ss : rl_next($stream,$i,'session');

    } else if($u!==null) {
        // Nueva unidad: finaliza inmediatamente la sesión anterior.
        $currentunit=$u;
        $currentsession=null;
        $currentsession=($ss!==null) ? $ss : rl_next($stream,$i,'session');

    } else if($ss!==null) {
        $currentsession=$ss;
    }

    $table->data[]=[
        $i+1,
        $item['type'],
        s($item['text']),
        s($item['source']),
        $currentmodule===null?'—':'Módulo '.$currentmodule,
        $currentunit===null?'—':'Unidad '.$currentunit,
        $currentsession===null?'—':'Sesión '.$currentsession
    ];
}

echo html_writer::div(html_writer::table($table),'table-responsive');
echo $OUTPUT->footer();
