<?php
$string['pluginname']='Clean report';
$string['heading']='Clean completion report';
$string['reportelimpio:view']='View and download Clean report';
$string['selectcourse']='Select a course';
$string['analyse']='Analyse course';
$string['readonlynotice']='Version 0.6.19: read-only plugin. It generates Excel without modifying courses, activities, participants, grades or completion states. Only students are counted.';
$string['trackedactivitycount']='Completion-tracked activities detected: {$a}';
$string['participantcount']='Students included in the report: {$a}';
$string['nostudentsfound']='No user with the student role was found in this course. Managers, teachers and administrators are not counted. Please check the roles on the Participants page.';
$string['noduplicates']='No hidden copies associated with visible originals were detected.';
$string['duplicatesfound']='Detected {$a->pairs} additional copies associated with a compatible visible original. First: "{$a->start}". Last: "{$a->end}".';
$string['unmatchedfound']='{$a} hidden copies have no compatible visible original above them. They are not counted. This usually means the original activity has no completion tracking.';
$string['consolidationsummary']='Summary: {$a->original} activities with completion tracking in the course, {$a->pairs} of them are consolidated hidden copies. The clean report has {$a->clean} columns.';
$string['downloadexcel']='Download clean Excel';
$string['previewtitle']='Clean status preview';
$string['participant']='Participant';
$string['completed']='Completed';
$string['notcompleted']='Not completed';
$string['mergedmark']='(consolidated)';

$string['notenrolled'] = 'You are not enrolled in, or do not have access to, this course.';
