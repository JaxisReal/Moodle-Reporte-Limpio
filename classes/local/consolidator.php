<?php
namespace local_reportelimpio\local;
defined('MOODLE_INTERNAL') || die();

class consolidator {
 public function build(array $activities,array $detection): array {
   if(empty($detection['detected'])) {
     $r=[];
     foreach($activities as $i=>$a) {
       $r[]=[
         'name'=>$a['name'],'modname'=>$a['modname'],'sectionnum'=>$a['sectionnum'],
         'module'=>$a['module'] ?? null,'unit'=>$a['unit'] ?? null,'session'=>$a['session'] ?? null,
         'sourceindexes'=>[$i],'consolidated'=>false
       ];
     }
     return $r;
   }

   $first=[];$second=[];
   foreach($detection['pairs'] as $p){$first[$p[0]]=$p[1];$second[$p[1]]=true;}

   $r=[];
   foreach($activities as $i=>$a){
     if(isset($second[$i])) continue;
     $src=isset($first[$i])?[$i,$first[$i]]:[$i];
     $r[]=[
       'name'=>$a['name'],'modname'=>$a['modname'],'sectionnum'=>$a['sectionnum'],
       'module'=>$a['module'] ?? null,'unit'=>$a['unit'] ?? null,'session'=>$a['session'] ?? null,
       'sourceindexes'=>$src,'consolidated'=>count($src)===2
     ];
   }
   return $r;
 }
}
