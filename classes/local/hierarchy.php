<?php
namespace local_reportelimpio\local;
defined('MOODLE_INTERNAL') || die();

class hierarchy {
    private static function plain($html): string {
        $text=html_to_text((string)$html,0,false);
        $text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=preg_replace('/\x{00A0}/u',' ',$text);
        $text=preg_replace('/[ \t]+/u',' ',$text);
        $text=preg_replace('/\R{2,}/u',"\n",$text);
        return trim($text);
    }

    private static function number($text,$kind) {
        $patterns=[
          'module'=>'/(?:^|\R|\s)M[oó][dD][uú]lo\s*(?:N[.°º]?\s*)?(\d+)\b/iu',
          'unit'=>'/(?:^|\R|\s)Unidad\s*(?:N[.°º]?\s*)?(\d+)\b/iu',
          'session'=>'/(?:^|\R|\s)Sesi[oó]n\s*(?:N[.°º]?\s*)?(\d+)\b/iu'
        ];
        return preg_match($patterns[$kind],$text,$m) ? (int)$m[1] : null;
    }

    private static function nextmarker(array $stream,int $pos,string $kind) {
        for($i=$pos+1;$i<count($stream);$i++) {
            $v=self::number($stream[$i]['text'],$kind);
            if($v!==null) return $v;
            if($kind==='session' &&
              (self::number($stream[$i]['text'],'module')!==null ||
               self::number($stream[$i]['text'],'unit')!==null)) return null;
            if($kind==='unit' && self::number($stream[$i]['text'],'module')!==null) return null;
        }
        return null;
    }

    public static function activitymap($course,$modinfo): array {
        global $DB;
        $stream=[];

        foreach($modinfo->get_section_info_all() as $section) {
            if(!$section) continue;

            $sectiontext=self::plain(get_section_name($course,$section));
            if($sectiontext!=='') {
                $stream[]=['type'=>'section','text'=>$sectiontext,'cmid'=>0];
            }

            foreach($modinfo->get_cms() as $cm) {
                if($cm->sectionnum != $section->section || $cm->deletioninprogress) continue;

                if($cm->modname==='label') {
                    $label=$DB->get_record('label',['id'=>$cm->instance],'id,intro,introformat',IGNORE_MISSING);
                    $text='';
                    if($label && trim((string)$label->intro)!=='') $text=self::plain($label->intro);
                    if($text==='') $text=self::plain($cm->name);
                    $stream[]=['type'=>'label','text'=>$text,'cmid'=>0];
                } else {
                    // All activities participate in hierarchy traversal, but only
                    // completion-tracked ones are later exported.
                    $stream[]=['type'=>'activity','text'=>self::plain($cm->name),'cmid'=>$cm->id];
                }
            }
        }

        $module=null; $unit=null; $session=null; $map=[];

        foreach($stream as $i=>$item) {
            $m=self::number($item['text'],'module');
            $u=self::number($item['text'],'unit');
            $ss=self::number($item['text'],'session');

            if($m!==null) {
                $module=$m; $unit=null; $session=null;
                $unit=($u!==null)?$u:self::nextmarker($stream,$i,'unit');
                $session=($ss!==null)?$ss:self::nextmarker($stream,$i,'session');
            } else if($u!==null) {
                $unit=$u; $session=null;
                $session=($ss!==null)?$ss:self::nextmarker($stream,$i,'session');
            } else if($ss!==null) {
                $session=$ss;
            }

            if($item['type']==='activity' && $item['cmid']) {
                $map[$item['cmid']]=[
                    'module'=>$module,
                    'unit'=>$unit,
                    'session'=>$session
                ];
            }
        }
        return $map;
    }
}
