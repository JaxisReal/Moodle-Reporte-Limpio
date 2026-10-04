<?php
require_once(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/completionlib.php');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
require_once($CFG->libdir.'/excellib.class.php');

require_login();
require_sesskey();
require_capability('local/reportelimpio:view',context_system::instance());

$courseid=required_param('courseid',PARAM_INT);
$course=get_course($courseid);
$cctx=context_course::instance($courseid);
$modinfo=get_fast_modinfo($course);

// Same hierarchy logic already validated in V0.4.5.
$hierarchy=\local_reportelimpio\local\hierarchy::activitymap($course,$modinfo);

$activities=[];
foreach($modinfo->get_cms() as $cm){
    if($cm->deletioninprogress || empty($cm->completion)) continue;
    $h=$hierarchy[$cm->id] ?? ['module'=>null,'unit'=>null,'session'=>null];
    $activities[]=[
        'cmid'=>$cm->id,
        'name'=>$cm->name,
        'modname'=>$cm->modname,
        'sectionnum'=>$cm->sectionnum,
        'module'=>$h['module'],
        'unit'=>$h['unit'],
        'session'=>$h['session']
    ];
}

$det=(new \local_reportelimpio\local\detector())->analyse($activities);
$clean=(new \local_reportelimpio\local\consolidator())->build($activities,$det);

$users=get_enrolled_users($cctx,'',0,'u.id,u.firstname,u.lastname,u.email','u.lastname ASC,u.firstname ASC');
$participants=[];
foreach($users as $u){
    if(is_siteadmin($u->id)) continue;
    if(has_capability('moodle/course:isincompletionreports',$cctx,$u->id)) $participants[$u->id]=$u;
}

$filename=clean_filename('reporte_limpio_'.$course->shortname.'_'.date('Ymd_His').'.xlsx');
$workbook=new MoodleExcelWorkbook('-');
$workbook->send($filename);
$sheet=$workbook->add_worksheet('Reporte limpio');

// Formats.
$titlefmt=$workbook->add_format(['bold'=>1,'size'=>16,'align'=>'left','valign'=>'vcenter']);
$metafmt=$workbook->add_format(['bold'=>1,'align'=>'left']);
$level=$workbook->add_format(['bold'=>1,'bg_color'=>'#B4C6E7','border'=>1,'align'=>'center','valign'=>'vcenter','text_wrap'=>1]);
$header=$workbook->add_format(['bold'=>1,'bg_color'=>'#D9EAF7','border'=>1,'align'=>'center','valign'=>'vcenter','text_wrap'=>1]);
$calcblockfmt=$workbook->add_format(['bold'=>1,'bg_color'=>'#B4C6E7','border'=>1,'align'=>'center','valign'=>'vcenter','text_wrap'=>1]);
$normal=$workbook->add_format(['border'=>1]);
$center=$workbook->add_format(['border'=>1,'align'=>'center']);
$percentfmt=$workbook->add_format([
    'border'=>1,'align'=>'center','num_format'=>'0.00%'
]);
$finalizado=$workbook->add_format(['border'=>1,'align'=>'center','color'=>'#008000']);
$nofinalizado=$workbook->add_format(['border'=>1,'align'=>'center','color'=>'#FF0000']);
$totalfmt=$workbook->add_format(['bold'=>1,'bg_color'=>'#D9EAF7','border'=>1,'align'=>'center']);
$totalpercentfmt=$workbook->add_format([
    'bold'=>1,'bg_color'=>'#D9EAF7','border'=>1,'align'=>'center'
]);
// Moodle's native Excel writer does not reliably honor num_format in the
// constructor array, so set the number format explicitly.
if(method_exists($percentfmt,'set_num_format')){
    $percentfmt->set_num_format('0.00%');
}
if(method_exists($totalpercentfmt,'set_num_format')){
    $totalpercentfmt->set_num_format('0.00%');
}

// Layout based on the final reference structure.
$lastcol=count($clean)+5;
$sheet->merge_cells(0,0,0,$lastcol);
$sheet->write_string(0,0,'REPORTE DE FINALIZACIÓN - '.format_string($course->fullname),$titlefmt);
$dias=['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
        7=>'Julio',8=>'Agosto',9=>'Setiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
$timestamp=time();
$weekday=(int)userdate($timestamp,'%w');
$day=(int)userdate($timestamp,'%d');
$month=(int)userdate($timestamp,'%m');
$year=userdate($timestamp,'%Y');
$fechacorte=$dias[$weekday].', '.$day.' de '.$meses[$month].' de '.$year;
$sheet->write_string(2,0,'Fecha de Corte: '.$fechacorte,$metafmt);

// Rows 4-7 visually (zero-based 3-6): MÓDULO, UNIDAD, SESIÓN, ACTIVIDAD.
$sheet->write_string(3,0,'MÓDULO',$level);
$sheet->write_string(4,0,'UNIDAD',$level);
$sheet->write_string(5,0,'SESIÓN',$level);
$sheet->write_string(6,0,'PARTICIPANTE',$header);

// La inducción (Sesión 0) se representa como Módulo 0 / Unidad 0,
// igual que el reporte modelo.
foreach($clean as $i=>$a){
    if(($a['session'] ?? null) === 0){
        if(($clean[$i]['module'] ?? null) === null) $clean[$i]['module']=0;
        if(($clean[$i]['unit'] ?? null) === null) $clean[$i]['unit']=0;
    }
}

// Actividades.
foreach($clean as $i=>$a){
    $sheet->write_string(6,$i+1,$a['name'],$header);
}

// Combina horizontalmente grupos consecutivos de Módulo, Unidad y Sesión.
// Los valores vacíos (por ejemplo, inducción / Sesión 0 sin módulo o unidad)
// permanecen como celdas vacías y no se combinan con grupos posteriores.
$levels=[
    ['row'=>3,'key'=>'module','prefix'=>'Módulo '],
    ['row'=>4,'key'=>'unit','prefix'=>'Unidad '],
    ['row'=>5,'key'=>'session','prefix'=>'Sesión ']
];

foreach($levels as $lev){
    $start=0;
    while($start<count($clean)){
        $value=$clean[$start][$lev['key']] ?? null;
        $end=$start;

        while($end+1<count($clean)){
            $next=$clean[$end+1][$lev['key']] ?? null;
            if($next !== $value) break;
            $end++;
        }

        $firstcol=$start+1;
        $lastgroupcol=$end+1;
        $label=($value===null || $value==='') ? '' : $lev['prefix'].$value;

        if($label!=='' && $lastgroupcol>$firstcol){
            // Apply the border format to every cell first so the whole merged
            // Módulo/Unidad/Sesión block keeps a visible outer frame in Excel.
            for($col=$firstcol;$col<=$lastgroupcol;$col++){
                $sheet->write_blank($lev['row'],$col,$level);
            }
            $sheet->merge_cells($lev['row'],$firstcol,$lev['row'],$lastgroupcol);
            $sheet->write_string($lev['row'],$firstcol,$label,$level);
        } else {
            for($col=$firstcol;$col<=$lastgroupcol;$col++){
                $sheet->write_string($lev['row'],$col,$label,$level);
            }
        }

        $start=$end+1;
    }
}

// Summary headers align with the activity header row.
$c=count($clean)+1;
// Un solo bloque CÁLCULOS para las tres filas jerárquicas.
for($r=3;$r<=5;$r++){
    for($col=$c;$col<=$c+4;$col++){
        $sheet->write_blank($r,$col,$calcblockfmt);
    }
}
$sheet->merge_cells(3,$c,5,$c+4);
$sheet->write_string(3,$c,'CÁLCULOS',$calcblockfmt);

foreach(['FIN','NO','TOT','FIN%','NO%'] as $j=>$h){
    $sheet->write_string(6,$c+$j,$h,$header);
}

$sheet->set_row(0,26);
$sheet->set_row(3,28); $sheet->set_row(4,28); $sheet->set_row(5,28); $sheet->set_row(6,55);
$sheet->set_column(0,0,30);
if(count($clean)>0) $sheet->set_column(1,count($clean),18);
$sheet->set_column($c,$c+4,11);

$completion=new completion_info($course);
$row=7;
$grandfin=0; $grandno=0;

foreach($participants as $u){
    $sheet->write_string($row,0,fullname($u),$normal);
    $fin=0;

    foreach($clean as $i=>$a){
        $done=\local_reportelimpio\local\reportdata::state_for_user($completion,$modinfo,$activities,$a,$u->id);
        $sheet->write_string($row,$i+1,$done?'Finalizado':'No finalizado',$done?$finalizado:$nofinalizado);
        if($done) $fin++;
    }

    $no=count($clean)-$fin;
    $grandfin+=$fin; $grandno+=$no;
    $tot=$fin+$no;
    $sheet->write_number($row,$c,$fin,$center);
    $sheet->write_number($row,$c+1,$no,$center);
    $sheet->write_number($row,$c+2,$tot,$center);

    // Porcentajes numéricos reales de Excel.
    $pfin=$tot?($fin/$tot):0;
    $pno=$tot?($no/$tot):0;
    $sheet->write_number($row,$c+3,$pfin,$percentfmt);
    $sheet->write_number($row,$c+4,$pno,$percentfmt);
    $row++;
}

// Totals only in calculation columns.
$sheet->write_string($row,0,'TOTALES',$totalfmt);
$totalcells=$grandfin+$grandno;

$sheet->write_number($row,$c,$grandfin,$totalfmt);
$sheet->write_number($row,$c+1,$grandno,$totalfmt);
$sheet->write_number($row,$c+2,$totalcells,$totalfmt);
$sheet->write_number($row,$c+3,$totalcells?($grandfin/$totalcells):0,$totalpercentfmt);
$sheet->write_number($row,$c+4,$totalcells?($grandno/$totalcells):0,$totalpercentfmt);

// Acceso controlado a los objetos internos que Moodle 4.4 ya crea con
// su propia copia incluida de PhpSpreadsheet. No se instala ninguna dependencia.
$sheetref=new ReflectionObject($sheet);
$sheetprop=$sheetref->getProperty('worksheet');
$sheetprop->setAccessible(true);
$nativeSheet=$sheetprop->getValue($sheet);

$bookref=new ReflectionObject($workbook);
$bookprop=$bookref->getProperty('objspreadsheet');
$bookprop->setAccessible(true);
$nativeBook=$bookprop->getValue($workbook);

// 1) Centrado vertical real del bloque combinado CÁLCULOS.
$calcfirst=Coordinate::stringFromColumnIndex($c+1); // Moodle cols are zero-based; native are one-based.
$calclast=Coordinate::stringFromColumnIndex($c+5);
$nativeSheet->getStyle($calcfirst.'4:'.$calclast.'6')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);

// 2) FIN% y NO% con formato porcentual real de Excel.
// Encabezados están en fila 7; participantes empiezan en 8; $row es la fila TOTALES en base cero.
$finpctcol=Coordinate::stringFromColumnIndex($c+4);
$nopctcol=Coordinate::stringFromColumnIndex($c+5);
$exceltotalrow=$row+1;
$nativeSheet->getStyle($finpctcol.'8:'.$finpctcol.$exceltotalrow)
    ->getNumberFormat()->setFormatCode('0.00%');
$nativeSheet->getStyle($nopctcol.'8:'.$nopctcol.$exceltotalrow)
    ->getNumberFormat()->setFormatCode('0.00%');

// 3) Congelar panel en B8 y filtro sobre encabezados/participantes.
$nativeSheet->freezePane('B8');
$nativeSheet->setAutoFilter('A7:'.$calclast.($row)); // Hasta el último participante, antes de TOTALES.

// 4) Gráfico de pastel basado directamente en FIN% y NO% de TOTALES.
// No se crean datos auxiliares visibles.
$sheetname=str_replace("'", "''", $nativeSheet->getTitle());
$categoryRange="'".$sheetname."'!\$".$finpctcol."\$7:\$".$nopctcol."\$7";
$valueRange="'".$sheetname."'!\$".$finpctcol."\$".$exceltotalrow.":\$".$nopctcol."\$".$exceltotalrow;

$categories=[
    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,$categoryRange,null,2)
];
$values=[
    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,$valueRange,'0.00%',2)
];

$series=new DataSeries(
    DataSeries::TYPE_PIECHART,
    null,
    [0],
    [],
    $categories,
    $values
);

$layout=new Layout();
$layout->setShowPercent(true);
$layout->setShowCatName(true);
$layout->setShowVal(false);

$plotarea=new PlotArea($layout,[$series]);
$legend=new Legend(Legend::POSITION_RIGHT,null,false);
$charttitle=new Title('Porcentaje de finalización');
$chart=new Chart(
    'porcentaje_finalizacion',
    $charttitle,
    $legend,
    $plotarea,
    true,
    DataSeries::EMPTY_AS_GAP,
    null,
    null
);

// Tipografía grande para facilitar la lectura del gráfico.
// Se aplica 20 pt al título, leyenda y etiquetas cuando la versión
// incluida de PhpSpreadsheet expone estas propiedades.
if(method_exists($charttitle,'getFont')){
    $charttitle->getFont()->setSize(20);
}
if(method_exists($legend,'getFont')){
    $legend->getFont()->setSize(20);
}
if(method_exists($layout,'getFont')){
    $layout->getFont()->setSize(20);
}

// Ubicar el gráfico debajo de TOTALES y antes del bloque de cálculos.
$charttop=$exceltotalrow+2;
$chartbottom=$charttop+14;
$chart->setTopLeftPosition('B'.$charttop);
$chart->setBottomRightPosition('J'.$chartbottom);
$nativeSheet->addChart($chart);

// Firma de autoría, discreta y separada del contenido estadístico.
$creditrow=$chartbottom+2;
$nativeSheet->setCellValue(
    'B'.$creditrow,
    'Reporte Limpio | Desarrollado por Jerry Anderson Carril Chávez – Técnico en Administración e Informática | AGP – UGEL Ascope | 2026'
);
$nativeSheet->mergeCells('B'.$creditrow.':J'.$creditrow);
$nativeSheet->getStyle('B'.$creditrow)->getFont()->setItalic(true)->setSize(10);
$nativeSheet->getStyle('B'.$creditrow)->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
    ->setVertical(Alignment::VERTICAL_CENTER);

// Guardar con gráficos habilitados. Moodle 4.4 ya incluye esta biblioteca.
$nativeBook->setActiveSheetIndex(0);
$filename=clean_filename('reporte_limpio_'.$course->shortname.'_'.date('Ymd_His').'.xlsx');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="'.$filename.'"');
header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
header('Pragma: no-cache');

$writer=IOFactory::createWriter($nativeBook,'Xlsx');
if(method_exists($writer,'setIncludeCharts')){
    $writer->setIncludeCharts(true);
}
$writer->save('php://output');
exit;
