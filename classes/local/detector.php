<?php
namespace local_reportelimpio\local;
defined('MOODLE_INTERNAL') || die();

class detector {
 public function analyse(array $activities): array {
   $pairs=[]; $pairmap=[]; $n=count($activities); $i=0;
   while ($i < $n-1) {
     if ($this->same($activities[$i], $activities[$i+1])) {
       $p=count($pairs)+1; $pairs[]=[$i,$i+1];
       $pairmap[$i]=$p; $pairmap[$i+1]=$p; $i+=2; continue;
     }
     $i++;
   }
   if (count($pairs) < 3) {
     return ['detected'=>false,'pairs'=>[],'paircount'=>0,'start'=>'','end'=>'','pairmap'=>[]];
   }
   $first=$pairs[0][0]; $last=$pairs[count($pairs)-1][1];
   return ['detected'=>true,'pairs'=>$pairs,'paircount'=>count($pairs),
     'start'=>$activities[$first]['name'],'end'=>$activities[$last]['name'],'pairmap'=>$pairmap];
 }
 private function same(array $a,array $b): bool {
   return $a['modname']===$b['modname'] && $this->norm($a['name'])!=='' &&
          $this->norm($a['name'])===$this->norm($b['name']);
 }
 private function norm(string $s): string {
   return \core_text::strtolower(preg_replace('/\s+/u',' ',trim($s)));
 }
}
