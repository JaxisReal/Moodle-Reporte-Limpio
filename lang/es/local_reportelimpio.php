<?php
$string['pluginname']='Reporte limpio';
$string['heading']='Reporte limpio de finalización';
$string['reportelimpio:view']='Ver y descargar Reporte limpio';
$string['selectcourse']='Seleccione un curso';
$string['analyse']='Analizar curso';
$string['readonlynotice']='Versión 0.6.19: complemento de solo lectura. Genera el Excel sin modificar cursos, actividades, participantes, calificaciones ni estados de finalización. Contabiliza únicamente estudiantes.';
$string['trackedactivitycount']='Actividades con seguimiento de finalización detectadas: {$a}';
$string['participantcount']='Estudiantes incluidos en el reporte: {$a}';
$string['nostudentsfound']='No se encontró ningún usuario con rol de estudiante en este curso. Los gestores, docentes y administradores no se contabilizan. Revise los roles en la página Participantes.';
$string['noduplicates']='No se detectaron copias ocultas asociadas a originales visibles.';
$string['duplicatesfound']='Se detectaron {$a->pairs} copias adicionales asociadas a una original visible compatible por tipo, nombre y jerarquía. Primera: "{$a->start}". Última: "{$a->end}".';
$string['unmatchedfound']='{$a} copias ocultas no tienen una actividad original visible anterior compatible. No se contabilizan. Se suelen deber a que la actividad original no tiene seguimiento de finalización.';
$string['consolidationsummary']='Resumen: {$a->original} actividades con seguimiento en el curso, {$a->pairs} de ellas son copias ocultas consolidadas. El reporte limpio tiene {$a->clean} columnas.';
$string['downloadexcel']='Descargar Excel limpio';
$string['previewtitle']='Vista previa limpia de estados';
$string['participant']='Participante';
$string['completed']='Finalizado';
$string['notcompleted']='No finalizado';
$string['mergedmark']='(consolidada)';

$string['notenrolled'] = 'No estás matriculado o no tienes acceso a este curso.';
