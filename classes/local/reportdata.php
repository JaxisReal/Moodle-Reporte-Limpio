<?php
namespace local_reportelimpio\local;
defined('MOODLE_INTERNAL') || die();

class reportdata {
 public static function state_for_user($completion,$modinfo,array $activities,array $reportactivity,int $userid): bool {
   foreach($reportactivity['sourceindexes'] as $idx){
     $cm=$modinfo->get_cm($activities[$idx]['cmid']);
     $d=$completion->get_data($cm,false,$userid);
     if(in_array((int)$d->completionstate,[COMPLETION_COMPLETE,COMPLETION_COMPLETE_PASS,COMPLETION_COMPLETE_FAIL],true)){
       return true;
     }
   }
   return false;
 }
}
