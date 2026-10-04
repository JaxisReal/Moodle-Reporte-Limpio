<?php
require_once(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/completionlib.php');

require_login();

$courseid=required_param('courseid', PARAM_INT);
$course=get_course($courseid);
$cctx=context_course::instance($courseid);

// Dashboard del participante: requiere inicio de sesión y matrícula activa.
// No exigimos moodle/course:view, ya que un estudiante matriculado normalmente
// no posee la capacidad "Ver cursos sin participación".
if (!is_enrolled($cctx, $USER, '', true)) {
    throw new moodle_exception('notenrolled', 'local_reportelimpio');
}
$modinfo=get_fast_modinfo($course);

// Misma jerarquía y consolidación que utiliza el Excel de Reporte Limpio.
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

// Igual que en el Excel: la inducción se identifica como Módulo 0 / Unidad 0 / Sesión 0.
foreach($clean as $i=>$a){
    if(($a['session'] ?? null) === 0){
        if(($clean[$i]['module'] ?? null) === null) $clean[$i]['module']=0;
        if(($clean[$i]['unit'] ?? null) === null) $clean[$i]['unit']=0;
    }
}

$users=get_enrolled_users($cctx,'',0,'u.id,u.firstname,u.lastname,u.email','u.lastname ASC,u.firstname ASC');
$participants=[];
foreach($users as $u){
    if(is_siteadmin($u->id) && (int)$u->id !== (int)$USER->id) continue;
    if(has_capability('moodle/course:isincompletionreports',$cctx,$u->id)
        || (int)$u->id === (int)$USER->id){
        $participants[$u->id]=$u;
    }
}

// Seguridad adicional: si por cualquier motivo get_enrolled_users() no devolvió
// al usuario conectado, pero su matrícula está activa, lo incorporamos para
// poder calcular su avance y posición.
if(!isset($participants[$USER->id]) && is_enrolled($cctx,$USER,'',true)){
    $participants[$USER->id]=$USER;
}

$completion=new completion_info($course);
$dashboardparticipants=[];
$sessionstats=[];

foreach($participants as $u){
    $fin=0;
    $pendientes=[];

    foreach($clean as $a){
        $done=\local_reportelimpio\local\reportdata::state_for_user(
            $completion,$modinfo,$activities,$a,$u->id
        );
        if($done) $fin++;

        $sessionvalue=$a['session'] ?? null;
        $sessionlabel=($sessionvalue===null || $sessionvalue==='') ? 'Sesión general' : 'Sesión '.$sessionvalue;
        if(!isset($sessionstats[$sessionlabel])){
            $sessionstats[$sessionlabel]=['fin'=>0,'tot'=>0,'sort'=>is_numeric($sessionvalue)?(float)$sessionvalue:999999];
        }
        $sessionstats[$sessionlabel]['tot']++;
        if($done) $sessionstats[$sessionlabel]['fin']++;

        if(!$done){
            $module=$a['module'] ?? null;
            $unit=$a['unit'] ?? null;
            $pendientes[]=[
                'modulo'=>($module===null || $module==='') ? 'Módulo General' : 'Módulo '.$module,
                'unidad'=>($unit===null || $unit==='') ? 'Unidad General' : 'Unidad '.$unit,
                'sesion'=>$sessionlabel,
                'actividad'=>format_string($a['name'])
            ];
        }
    }

    $tot=count($clean);
    $no=$tot-$fin;
    $pct=$tot ? round(($fin/$tot)*100,1) : 0;

    $isme=((int)$u->id === (int)$USER->id);
    $dashboardparticipants[]=[
        'nombre'=>fullname($u),
        'fin'=>$fin,
        'no'=>$no,
        'tot'=>$tot,
        'pct'=>$pct,
        'pendientes'=>$isme ? $pendientes : [],
        'isme'=>$isme
    ];
}

uasort($sessionstats,function($a,$b){
    if($a['sort']===$b['sort']) return 0;
    return ($a['sort']<$b['sort']) ? -1 : 1;
});
$sessions=[];
foreach($sessionstats as $label=>$st){
    $sessions[$label]=$st['tot'] ? round(($st['fin']/$st['tot'])*100,1) : 0;
}

$dias=['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
        7=>'Julio',8=>'Agosto',9=>'Setiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
$timestamp=time();
$fechacorte=$dias[(int)userdate($timestamp,'%w')].', '.
    (int)userdate($timestamp,'%d').' de '.
    $meses[(int)userdate($timestamp,'%m')].' de '.
    userdate($timestamp,'%Y');

$dashboarddata=[
    'title'=>'MI DASHBOARD DE AVANCE - '.format_string($course->fullname),
    'subtitle'=>'Fecha de Corte: '.$fechacorte,
    'sessions'=>$sessions,
    'participants'=>$dashboardparticipants
];

$backurl=(new moodle_url('/local/reportelimpio/mydashboard.php'))->out(false);

$PAGE->set_url(new moodle_url('/local/reportelimpio/userdashboard.php',['courseid'=>$courseid]));
$PAGE->set_context($cctx);
$PAGE->set_title('Mi avance - '.format_string($course->fullname));
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Dashboard · Reporte de Finalización de Actividades</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>

<script>
  tailwind.config = {
    darkMode: 'class',
    theme: {
      extend: {
        fontFamily: { syne: ['Syne', 'sans-serif'], dm: ['DM Sans', 'sans-serif'] },
        colors: {
          brand: { 50:'#f0fdf9', 100:'#ccfbef', 200:'#99f6e0', 300:'#5ce7ca', 400:'#2dd4bf', 500:'#14b8a6', 600:'#0d9488', 700:'#0f766e', 800:'#115e59', 900:'#134e4a' },
          dark: { 900:'#0a0f1e', 800:'#0f1629', 700:'#161f36', 600:'#1e2a45', 500:'#263354' }
        }
      }
    }
  }
</script>

<style>
  * { box-sizing: border-box; }
  body { font-family: 'DM Sans', sans-serif; background: #0a0f1e; color: #e2e8f0; min-height: 100vh; }
  h1,h2,h3,.font-syne { font-family: 'Syne', sans-serif; }

  /* Glassmorphism cards */
  .glass {
    background: rgba(22, 31, 54, 0.75);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(45, 212, 191, 0.12);
    border-radius: 16px;
    box-shadow: 0 4px 32px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.05);
  }
  .glass-light {
    background: rgba(30, 42, 69, 0.6);
    border: 1px solid rgba(45, 212, 191, 0.08);
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.3);
  }

  /* KPI cards */
  .kpi-card {
    background: linear-gradient(135deg, rgba(22,31,54,0.9) 0%, rgba(15,22,41,0.95) 100%);
    border: 1px solid rgba(45, 212, 191, 0.15);
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.5);
    transition: all 0.3s cubic-bezier(.4,0,.2,1);
    position: relative;
    overflow: hidden;
  }
  .kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent, #2dd4bf), transparent);
  }
  .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 32px rgba(0,0,0,0.6); border-color: rgba(45,212,191,0.3); }

  /* Upload zone */
  .upload-zone {
    border: 2px dashed rgba(45,212,191,0.3);
    border-radius: 16px;
    background: rgba(13,148,136,0.05);
    transition: all 0.3s ease;
    cursor: pointer;
  }
  .upload-zone:hover, .upload-zone.drag-over {
    border-color: rgba(45,212,191,0.7);
    background: rgba(13,148,136,0.12);
  }

  /* Progress bars */
  .prog-bar { height: 6px; border-radius: 99px; background: rgba(45,212,191,0.15); overflow: hidden; }
  .prog-fill { height: 100%; border-radius: 99px; transition: width 0.8s cubic-bezier(.4,0,.2,1); }

  /* Table */
  .data-table { width: 100%; border-collapse: collapse; }
  .data-table th { background: rgba(13,148,136,0.15); color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; padding: 10px 12px; text-align: left; font-family: 'Syne', sans-serif; }
  .data-table td { padding: 10px 12px; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,0.04); }
  .data-table tr:hover td { background: rgba(45,212,191,0.04); }
  .data-table tr:last-child td { border-bottom: none; }

  /* Scrollbar */
  ::-webkit-scrollbar { width: 6px; height: 6px; }
  ::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); }
  ::-webkit-scrollbar-thumb { background: rgba(45,212,191,0.3); border-radius: 99px; }

  /* Badge */
  .badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
  .badge-green { background: rgba(16,185,129,0.15); color: #34d399; }
  .badge-red { background: rgba(239,68,68,0.15); color: #f87171; }
  .badge-yellow { background: rgba(245,158,11,0.15); color: #fbbf24; }
  .badge-blue { background: rgba(59,130,246,0.15); color: #60a5fa; }

  /* Search */
  .search-input { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 8px 12px; color: #e2e8f0; font-size: 13px; outline: none; transition: border-color .2s; }
  .search-input:focus { border-color: rgba(45,212,191,0.4); }

  /* Glow orbs background */
  .orb { position: fixed; border-radius: 50%; filter: blur(80px); opacity: 0.15; pointer-events: none; z-index: 0; }
  .orb1 { width: 400px; height: 400px; background: #0d9488; top: -100px; left: -100px; }
  .orb2 { width: 300px; height: 300px; background: #7c3aed; bottom: 100px; right: -50px; }
  .orb3 { width: 250px; height: 250px; background: #0e7490; top: 50%; left: 50%; }

  /* Animated number */
  @keyframes countUp { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: translateY(0); } }
  .anim-num { animation: countUp 0.6s ease forwards; }

  /* Fade in */
  @keyframes fadeIn { from { opacity:0; transform: translateY(16px); } to { opacity:1; transform: translateY(0); } }
  .fade-in { animation: fadeIn 0.5s ease forwards; }

  /* Tooltip */
  .tooltip { position: relative; }
  .tooltip:hover::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #1e2a45; color: #e2e8f0; font-size: 11px; padding: 4px 8px; border-radius: 6px; white-space: nowrap; pointer-events: none; z-index: 99; }

  select option { background: #0f1629; }

  #main-content { position: relative; z-index: 1; }

  .chart-container { position: relative; height: 260px; }
  .chart-container-sm { position: relative; height: 220px; }
  .chart-container-lg { position: relative; height: 300px; }
  
  .active-tab { background: rgba(20,184,166,0.15)!important; border: 1px solid rgba(20,184,166,0.3)!important; color:#2dd4bf!important; }
  .ranking-btn { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.07); color:#64748b; }

  .row-me td { background: rgba(20,184,166,0.10) !important; }
  .row-me { box-shadow: inset 3px 0 0 #2dd4bf; }
  .me-chip { display:inline-flex;align-items:center;padding:2px 7px;margin-left:6px;border-radius:99px;
    background:rgba(20,184,166,.16);border:1px solid rgba(45,212,191,.28);color:#5eead4;
    font-size:9px;font-weight:800;letter-spacing:.06em;vertical-align:middle; }


  /* V0.6.7 - filtros del modal de pendientes */
  #my-pending-modal select.filter-select {
    background: #111827 !important;
    color: #e5e7eb !important;
    border: 1px solid #334155 !important;
    border-radius: 8px !important;
    padding: 10px 36px 10px 12px !important;
    font-size: 13px !important;
    font-weight: 500 !important;
    color-scheme: dark;
  }
  #my-pending-modal select.filter-select:hover,
  #my-pending-modal select.filter-select:focus {
    border-color: #2dd4bf !important;
    outline: none !important;
    box-shadow: 0 0 0 2px rgba(45,212,191,.12) !important;
  }
  #my-pending-modal select.filter-select option {
    background: #111827 !important;
    color: #f1f5f9 !important;
  }

</style>
</head>
<body class="antialiased">

<div class="orb orb1"></div>
<div class="orb orb2"></div>
<div class="orb orb3"></div>

<div id="main-content" class="min-h-screen">

  <header class="sticky top-0 z-50" style="background:rgba(10,15,30,0.85);backdrop-filter:blur(20px);border-bottom:1px solid rgba(45,212,191,0.1);">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#14b8a6,#0d9488);border-radius:10px;display:flex;align-items:center;justify-content:center;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="white" stroke-width="2"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        </div>
        <div>
          <div class="font-syne font-700 text-sm sm:text-base text-white leading-none">UGEL ASCOPE - AGP - Mi Dashboard</div>
          <div class="text-xs text-slate-400 mt-0.5">Avance y posición dentro del curso</div>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span id="header-date" class="text-xs text-slate-400 hidden sm:block"></span>
        <a href="<?php echo $backurl; ?>" class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium transition-all" style="background:rgba(20,184,166,0.12);border:1px solid rgba(20,184,166,0.25);color:#5eead4;text-decoration:none;">← Cambiar curso</a>
      </div>
    </div>
  </header>

  <div id="dashboard" class="max-w-screen-xl mx-auto px-4 sm:px-6 pb-16 pt-6 space-y-6 fade-in">

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-syne font-800 text-xl sm:text-2xl text-white" id="report-title">Reporte de Finalización de Actividades</h1>
        <p class="text-slate-400 text-sm mt-1" id="report-subtitle"></p>
      </div>
      <div class="flex items-center gap-2">
        <input type="text" id="search-box" placeholder="Buscar participante..." class="search-input w-48 sm:w-64"/>
        <select id="filter-status" class="search-input">
          <option value="all">Todos</option>
          <option value="100">100%</option>
          <option value="high">≥ 75%</option>
          <option value="med">50–74%</option>
          <option value="low">&lt; 50%</option>
          <option value="zero">0%</option>
        </select>
      </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="kpi-card p-5" style="--accent:#2dd4bf;">
        <div class="text-xs text-slate-400 uppercase tracking-widest mb-3 font-syne">Participantes</div>
        <div class="font-syne font-800 text-3xl text-white" id="kpi-total">—</div>
        <div class="text-xs text-slate-400 mt-1">Total inscritos</div>
        <div class="mt-3 prog-bar"><div class="prog-fill" id="kpi-bar-total" style="background:linear-gradient(90deg,#14b8a6,#0d9488);width:100%;"></div></div>
      </div>
      <div class="kpi-card p-5" style="--accent:#34d399;">
        <div class="text-xs text-slate-400 uppercase tracking-widest mb-3 font-syne">Completaron</div>
        <div class="font-syne font-800 text-3xl text-white" id="kpi-completed">—</div>
        <div class="text-xs text-slate-400 mt-1" id="kpi-completed-pct">— con 100%</div>
        <div class="mt-3 prog-bar"><div class="prog-fill" id="kpi-bar-completed" style="background:linear-gradient(90deg,#34d399,#059669);"></div></div>
      </div>
      <div class="kpi-card p-5" style="--accent:#fbbf24;">
        <div class="text-xs text-slate-400 uppercase tracking-widest mb-3 font-syne">Avance Promedio</div>
        <div class="font-syne font-800 text-3xl text-white" id="kpi-avg">—</div>
        <div class="text-xs text-slate-400 mt-1">de actividades finalizadas</div>
        <div class="mt-3 prog-bar"><div class="prog-fill" id="kpi-bar-avg" style="background:linear-gradient(90deg,#fbbf24,#d97706);"></div></div>
      </div>
      <div class="kpi-card p-5" style="--accent:#f87171;">
        <div class="text-xs text-slate-400 uppercase tracking-widest mb-3 font-syne">Sin Actividad</div>
        <div class="font-syne font-800 text-3xl text-white" id="kpi-zero">—</div>
        <div class="text-xs text-slate-400 mt-1">participantes en 0%</div>
        <div class="mt-3 prog-bar"><div class="prog-fill" id="kpi-bar-zero" style="background:linear-gradient(90deg,#f87171,#dc2626);"></div></div>
      </div>
    </div>


    <div class="glass p-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <div class="text-xs text-slate-400 uppercase tracking-widest font-syne">Tu posición en el curso</div>
        <div class="text-sm text-slate-300 mt-1">Tu fila aparece resaltada con la etiqueta <span class="me-chip">TÚ</span>.</div>
      </div>
      <div class="flex items-center gap-3">
        <div class="text-right">
          <div class="font-syne font-800 text-2xl text-teal-300" id="my-position">—</div>
          <div class="text-xs text-slate-500" id="my-progress">—</div>
          <button id="btn-my-pending" type="button"
            class="mt-2 px-3 py-2 rounded-lg text-xs font-semibold border border-teal-500/30 text-teal-300 hover:bg-teal-500/10 transition-colors">
            Ver mis actividades pendientes
          </button>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div class="glass p-5 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
          <div>
            <div class="font-syne font-700 text-white text-sm">Tasa de Finalización por Sesión</div>
            <div class="text-xs text-slate-400 mt-0.5">% de actividades completadas por sesión</div>
          </div>
          <div class="badge badge-blue">Sesiones 0–8</div>
        </div>
        <div class="chart-container"><canvas id="chart-sessions"></canvas></div>
      </div>

      <div class="glass p-5">
        <div class="flex items-center justify-between mb-4">
          <div>
            <div class="font-syne font-700 text-white text-sm">Distribución de Avance</div>
            <div class="text-xs text-slate-400 mt-0.5">Participantes por rango</div>
          </div>
        </div>
        <div class="chart-container-sm"><canvas id="chart-donut"></canvas></div>
        <div class="mt-3 space-y-1.5" id="donut-legend"></div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <div class="glass p-5">
        <div class="font-syne font-700 text-white text-sm mb-1">Distribución de Participantes</div>
        <div class="text-xs text-slate-400 mb-4">Por porcentaje de finalización</div>
        <div class="chart-container"><canvas id="chart-hist"></canvas></div>
      </div>

      <div class="glass p-5">
        <div class="flex items-center gap-3 mb-4">
          <button id="btn-top" onclick="showRanking('top')" class="ranking-btn text-xs font-syne font-600 px-3 py-1.5 rounded-lg transition-all active-tab">🏆 Top 10</button>
          <button id="btn-bot" onclick="showRanking('bot')" class="ranking-btn text-xs font-syne font-600 px-3 py-1.5 rounded-lg transition-all">⚠️ Rezagados</button>
        </div>
        <div id="ranking-list" class="space-y-2 overflow-y-auto" style="max-height:240px;"></div>
      </div>
    </div>

    <div class="glass p-5">
      <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div>
          <div class="font-syne font-700 text-white text-sm">Ranking y Detalle por Participante</div>
          <div class="text-xs text-slate-400 mt-0.5" id="table-count">— participantes</div>
        </div>
        <div class="flex gap-2 flex-wrap">
          <button onclick="sortTable('name')" class="text-xs px-3 py-1.5 rounded-lg transition-all" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);color:#94a3b8;">A-Z</button>
          <button onclick="sortTable('pct')" class="text-xs px-3 py-1.5 rounded-lg transition-all" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);color:#94a3b8;">% ↓</button>
          <button onclick="sortTable('fin')" class="text-xs px-3 py-1.5 rounded-lg transition-all" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);color:#94a3b8;">Finaliz. ↓</button>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="data-table" id="participants-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Participante</th>
              <th>Finalizados</th>
              <th>No Finalizados</th>
              <th>Total</th>
              <th>Avance</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody id="table-body"></tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- Footer -->
<footer style="position:relative;z-index:1;border-top:1px solid rgba(45,212,191,0.1);background:rgba(10,15,30,0.9);backdrop-filter:blur(12px);margin-top:8px;">
  <div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-3">
    <div class="flex items-center gap-3">
      <div style="width:32px;height:32px;background:linear-gradient(135deg,#14b8a6,#0d9488);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="white" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
      </div>
      <div>
        <div class="font-syne font-700 text-sm text-white">Jerry Anderson Carril Chávez</div>
        <div class="text-[11px] text-teal-400/80 tracking-wide">Técnico en Administración e Informática · AGP – UGEL Ascope</div>
      </div>
    </div>
    <div class="flex items-center gap-4">
      <a href="tel:992304227" class="flex items-center gap-2 text-xs text-slate-400 hover:text-teal-400 transition-colors">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
        992 304 227
      </a>
      <span class="text-slate-700 text-xs hidden sm:block">·</span>
      <a href="https://www.linkedin.com/in/jerrycarril/" target="_blank" class="flex items-center gap-1.5 text-[11px] text-slate-400 hover:text-teal-400 transition-colors hidden sm:flex">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
        linkedin.com/in/jerrycarril
      </a>
    </div>
  </div>
</footer>


<div id="modal-faltantes" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4" style="background: rgba(10, 15, 30, 0.8); backdrop-filter: blur(8px);">
  <div class="glass max-w-md w-full p-6 space-y-4 fade-in max-h-[85vh] flex flex-col">
    <div class="flex items-start justify-between border-b border-slate-700/50 pb-3 flex-shrink-0">
      <div>
        <h3 id="modal-usuario" class="font-syne font-700 text-base text-white truncate max-w-[280px]">Participante</h3>
        <p class="text-xs text-red-400 mt-0.5 font-medium">Progreso pendiente por completar</p>
      </div>
      <button onclick="cerrarModal()" class="text-slate-400 hover:text-white transition-colors p-1 bg-slate-800/50 rounded-lg">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    
    <div id="modal-lista-sesiones" class="overflow-y-auto pr-1 space-y-3 flex-1">
    </div>
    
    <div class="pt-2 flex-shrink-0">
      <button onclick="cerrarModal()" class="w-full py-2 bg-slate-800 hover:bg-slate-700 transition-colors rounded-xl text-xs font-medium text-slate-300 font-syne">
        Entendido / Cerrar
      </button>
    </div>
  </div>
</div>

<script>
// ─── State ────────────────────────────────────────────────────
let allParticipants = [];
let sessionData = {};
let currentSort = { key: 'pct', dir: -1 };
let currentFilter = 'all';
let searchTerm = '';
let rankingMode = 'top';
let charts = {};

// ─── Datos entregados directamente por Reporte Limpio / Moodle ───
const MOODLE_DATA = <?php echo json_encode($dashboarddata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

// ─── Default data from uploaded file ─────────────────────────
const DEFAULT_DATA = {
  title: 'REPORTE DE FINALIZACION DE ACTIVIDADES DIRECTIVOS - GRUPO 1',
  subtitle: 'Fecha de Corte: miércoles, 17 de Junio de 2026',
  sessions: {
    'Sesión 0': 78.4, 'Sesión 1': 75.0, 'Sesión 2': 67.5,
    'Sesión 3': 65.2, 'Sesión 4': 59.3, 'Sesión 5': 55.7,
    'Sesión 6': 52.5, 'Sesión 7': 41.9, 'Sesión 8': 26.8
  },
  participants: [
    {nombre:'HERBET ABANTO CAVERO',fin:53,no:11,tot:64,pct:82.8,pendientes:[]},
    {nombre:'William Esteban Acevedo Santiago',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'Rosa Andrea Alvarado Sanchez',fin:49,no:15,tot:64,pct:76.6,pendientes:[]},
    {nombre:'Sandra Paola Ares Ponce',fin:10,no:54,tot:64,pct:15.6,pendientes:[]},
    {nombre:'YEN MARI BARROS BENITES',fin:55,no:9,tot:64,pct:85.9,pendientes:[]},
    {nombre:'Sandra Paola Beltran Namoc',fin:34,no:30,tot:64,pct:53.1,pendientes:[]},
    {nombre:'GERARDO BRAVO ZORRILLA',fin:63,no:1,tot:64,pct:98.4,pendientes:[]},
    {nombre:'Lucy Maribel Bueno Chavez',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'CIRILO CALDERON FERNANDEZ',fin:62,no:2,tot:64,pct:96.9,pendientes:[]},
    {nombre:'Ana Belci Campos Gomez',fin:63,no:1,tot:64,pct:98.4,pendientes:[]},
    {nombre:'Giovanna Patrocinia Cerquin Vzquez',fin:2,no:62,tot:64,pct:3.1,pendientes:[]},
    {nombre:'GIOVANNA DEL PILAR CHAVEZ SALDAA',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'Elizabeth Rosario Condorena Rojas',fin:28,no:36,tot:64,pct:43.8,pendientes:[]},
    {nombre:'ANA MARIA CORNETERO CONDE',fin:52,no:12,tot:64,pct:81.3,pendientes:[]},
    {nombre:'Mercedes yolanda Cosanatn Plasencia',fin:58,no:6,tot:64,pct:90.6,pendientes:[]},
    {nombre:'Jorge Luis Cuba Rodriguez',fin:1,no:63,tot:64,pct:1.6,pendientes:[]},
    {nombre:'Roberto Carlos De La Cruz Leon',fin:44,no:20,tot:64,pct:68.8,pendientes:[]},
    {nombre:'YSABEL CRISTINA DESPOSORIO ARMESTAR',fin:13,no:51,tot:64,pct:20.3,pendientes:[]},
    {nombre:'HECTOR ESPINOZA HERNANDEZ',fin:58,no:6,tot:64,pct:90.6,pendientes:[]},
    {nombre:'NELIDA FULGENCIO ROJAS',fin:1,no:63,tot:64,pct:1.6,pendientes:[]},
    {nombre:'Miriam del Rocío García Alvarez',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'MARLENI GARCIA VILCHEZ',fin:56,no:8,tot:64,pct:87.5,pendientes:[]},
    {nombre:'Dany Guerrero Flores',fin:57,no:7,tot:64,pct:89.1,pendientes:[]},
    {nombre:'ALDO GONZALCO GUTIERREZ CUEVA',fin:61,no:3,tot:64,pct:95.3,pendientes:[]},
    {nombre:'Jessica Yolanda Ibáñez de Cruz',fin:61,no:3,tot:64,pct:95.3,pendientes:[]},
    {nombre:'MISAEL NOE IDROGO MARIO',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'Edith Marisol Jara Zelada',fin:60,no:4,tot:64,pct:93.8,pendientes:[]},
    {nombre:'Jesus Cosme Joaquin Ruiz',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'Vanessa Cecilia Lavado Ocas',fin:59,no:5,tot:64,pct:92.2,pendientes:[]},
    {nombre:'MARLENI LOPEZ SILVA',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'Bertha Natividad Loyola Torres',fin:55,no:9,tot:64,pct:85.9,pendientes:[]},
    {nombre:'Maria del Pilar Lujan Rojas',fin:62,no:2,tot:64,pct:96.9,pendientes:[]},
    {nombre:'Rocio del Pilar Mallqui Torres',fin:59,no:5,tot:64,pct:92.2,pendientes:[]},
    {nombre:'Anabel Martin Llanos',fin:55,no:9,tot:64,pct:85.9,pendientes:[]},
    {nombre:'GIOVANA MARLITH MEDINA LEON',fin:62,no:2,tot:64,pct:96.9,pendientes:[]},
    {nombre:'NILDER MEDINA MEDINA',fin:46,no:18,tot:64,pct:71.9,pendientes:[]},
    {nombre:'RUTH MEREGILDO ANGULO',fin:17,no:47,tot:64,pct:26.6,pendientes:[]},
    {nombre:'HECTOR ELIAS MORI HIDALGO',fin:56,no:8,tot:64,pct:87.5,pendientes:[]},
    {nombre:'Nora Herlinda Morillas Morillas',fin:61,no:3,tot:64,pct:95.3,pendientes:[]},
    {nombre:'ALISSON NASHIRA MUÑOZ CRISANTO',fin:61,no:3,tot:64,pct:95.3,pendientes:[]},
    {nombre:'JESSICA MARLITH ORBEGOSO ORBEGOSO',fin:59,no:5,tot:64,pct:92.2,pendientes:[]},
    {nombre:'DIANA LISSETH ORTIZ REBAZA',fin:14,no:50,tot:64,pct:21.9,pendientes:[]},
    {nombre:'Leidy Paola Paredes Garcia',fin:36,no:28,tot:64,pct:56.3,pendientes:[]},
    {nombre:'América Solanghe Peña Sánchez',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'Yandira Rebeca Perez Lazaro',fin:55,no:9,tot:64,pct:85.9,pendientes:[]},
    {nombre:'KARLA ELIZABETH PONCE DE LEON MESONES',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'ANA CECILIA QUIROZ VALVERDE',fin:56,no:8,tot:64,pct:87.5,pendientes:[]},
    {nombre:'NANCY DEL PILAR QUISPE SALDAA',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'EDGAR FRANCISCO ROMERO TERRONES',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'LUIS ARMANDO ROMERO PRADO',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'Carmen Armida Rosso Romero',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'DALILA SARAI SALDAÑA ARMAS',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'SANDRA ELIZABETH SANCHEZ CHAVEZ',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'RONALD MATIAS SOLDADO ZUIGA',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'Marleny Suarez Garcia',fin:59,no:5,tot:64,pct:92.2,pendientes:[]},
    {nombre:'Claudia Milagros Terrones Cachay',fin:15,no:49,tot:64,pct:23.4,pendientes:[]},
    {nombre:'Evelyn Madeleine Terrones Torres',fin:18,no:46,tot:64,pct:28.1,pendientes:[]},
    {nombre:'ALEX MARTIN TIRADO ORTIZ',fin:20,no:44,tot:64,pct:31.3,pendientes:[]},
    {nombre:'Noelia Yojany Torres Vásquez',fin:61,no:3,tot:64,pct:95.3,pendientes:[]},
    {nombre:'JANETH ELIZABETH TRIGOSO RODRIGUEZ',fin:63,no:1,tot:64,pct:98.4,pendientes:[]},
    {nombre:'ALDO IVAN ULLOA AYALA',fin:60,no:4,tot:64,pct:93.8,pendientes:[]},
    {nombre:'Margarita Rosario Vásquez Terrones de Gutiérrez',fin:0,no:64,tot:64,pct:0,pendientes:[]},
    {nombre:'Martha Yovana Vásquez Gómez',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'Walter Edgardo Vasquez Rodriguez',fin:61,no:3,tot:64,pct:95.3,pendientes:[]},
    {nombre:'Nilda Merly Zavaleta Ruiz',fin:64,no:0,tot:64,pct:100,pendientes:[]},
    {nombre:'DIANA ZAVALETA SALDAÑA',fin:62,no:2,tot:64,pct:96.9,pendientes:[]},
  ]
};

// ─── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadData(MOODLE_DATA);
  setupFileInputs();
  setupMyPendingControls();
  document.getElementById('header-date').textContent = new Date().toLocaleDateString('es-PE', {weekday:'long',year:'numeric',month:'long',day:'numeric'});
});

function setupFileInputs() {
  ['file-input','file-input-2'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', e => handleFile(e.target.files[0]));
  });
  const dz = document.getElementById('drop-zone');
  if (dz) {
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
    dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('drag-over'); handleFile(e.dataTransfer.files[0]); });
  }
  const searchBox = document.getElementById('search-box');
  const filterStatus = document.getElementById('filter-status');

  if (searchBox) {
    searchBox.addEventListener('input', e => {
      searchTerm = (e.target.value || '').trim().toLowerCase();
      renderTable();
    });
  }

  if (filterStatus) {
    filterStatus.addEventListener('change', e => {
      currentFilter = e.target.value || 'all';
      renderTable();
    });
  }
}

// ─── File handling ────────────────────────────────────────────
function handleFile(file) {
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    try {
      if (file.name.endsWith('.csv')) {
        parseCSV(e.target.result, file.name);
      } else {
        const wb = XLSX.read(e.target.result, { type: 'array' });
        parseXLSX(wb, file.name);
      }
    } catch(err) {
      alert('Error al procesar el archivo: ' + err.message);
    }
  };
  if (file.name.endsWith('.csv')) reader.readAsText(file);
  else reader.readAsArrayBuffer(file);
}

function parseXLSX(wb, filename) {
  const sheetName = wb.SheetNames[0];
  const ws = wb.Sheets[sheetName];
  const rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });

  let headerRow = -1;
  for (let r = 0; r < Math.min(rows.length, 10); r++) {
    const row = rows[r];
    for (let c = 0; c < row.length; c++) {
      if (String(row[c]).toLowerCase().includes('participante')) { headerRow = r; break; }
    }
    if (headerRow >= 0) break;
  }

  let finCol = -1, noCol = -1, totCol = -1, pctFinCol = -1;
  if (headerRow >= 0) {
    const hrow = rows[headerRow];
    for (let c = 0; c < hrow.length; c++) {
      const v = String(hrow[c]).toLowerCase().trim();
      if (v === 'fin') finCol = c;
      else if (v === 'no') noCol = c;
      else if (v === 'tot') totCol = c;
      else if (v === 'fin%') pctFinCol = c;
    }
  }

  if (finCol < 0) {
    const sample = rows[headerRow >= 0 ? headerRow : 4] || [];
    finCol = sample.length - 5;
    noCol = sample.length - 4;
    totCol = sample.length - 3;
    pctFinCol = sample.length - 2;
  }

  const sessRow = rows[headerRow >= 0 ? Math.max(0, headerRow - 1) : 5] || [];
  const sessions = {};
  let prevSess = null, prevIdx = -1;
  for (let c = 0; c < sessRow.length; c++) {
    const v = String(sessRow[c]).toLowerCase().trim();
    if (v.includes('sesion') || v.includes('sesión')) {
      if (prevSess) sessions[prevSess] = [prevIdx, c - 1];
      prevSess = sessRow[c]; prevIdx = c;
    }
  }
  if (prevSess) sessions[prevSess] = [prevIdx, finCol - 1];

  const columnasSesiones = [];
  // Estructura del Excel:
  // Fila 4 (índice 3) = MODULO
  // Fila 5 (índice 4) = UNIDAD
  // Fila 6 (índice 5) = SESION
  // Fila 7 (índice 6) = Nombre de la actividad
  const moduloRow   = rows[3] || [];
  const unidadRow   = rows[4] || [];
  const sesionRow   = rows[5] || [];
  const actividadRow = rows[6] || [];

  // Función para rellenar hacia la izquierda (merged cells en Excel llegan vacías)
  function fillLeft(arr, c) {
    if (String(arr[c] || '').trim()) return String(arr[c]).trim();
    for (let left = c - 1; left >= 0; left--) {
      if (String(arr[left] || '').trim()) return String(arr[left]).trim();
    }
    return '';
  }

  for (let c = 0; c < finCol; c++) {
    const nombreModulo   = fillLeft(moduloRow,   c) || 'Módulo General';
    const nombreUnidad   = fillLeft(unidadRow,   c) || 'Unidad General';
    const nombreSesion   = fillLeft(sesionRow,   c) || 'Sesión General';
    const nombreActividad = String(actividadRow[c] || '').trim() || 'Actividad Obligatoria';

    columnasSesiones[c] = {
      modulo:    nombreModulo,
      unidad:    nombreUnidad,
      sesion:    nombreSesion,
      actividad: nombreActividad
    };
  }

  const participants = [];
  const dataStart = headerRow + 2;
  for (let r = dataStart; r < rows.length; r++) {
    const row = rows[r];
    const nombre = String(row[0] || '').trim();
    if (!nombre || nombre.toLowerCase().includes('total')) continue;
    const fin = parseFloat(row[finCol]) || 0;
    const no = parseFloat(row[noCol]) || 0;
    const tot = parseFloat(row[totCol]) || (fin + no);
    let pct = parseFloat(row[pctFinCol]);
    if (isNaN(pct)) pct = tot > 0 ? (fin / tot) * 100 : 0;
    else if (pct <= 1) pct = pct * 100;

    const pendientes = [];
    for (let c = 1; c < finCol; c++) {
      const valorCelda = String(row[c] || '').toLowerCase().trim();
      if (valorCelda.includes('no finalizado') || valorCelda === 'no' || valorCelda === '0') {
        let infoSesion = columnasSesiones[c] || { modulo: 'Módulo General', unidad: 'Unidad General', sesion: 'Sesión Asignada', detalle: 'Actividad Pendiente' };
        
        pendientes.push({
          modulo:    infoSesion.modulo,
          unidad:    infoSesion.unidad,
          sesion:    infoSesion.sesion,
          actividad: infoSesion.actividad
        });
      }
    }

    participants.push({ nombre, fin, no, tot, pct: Math.round(pct * 10) / 10, pendientes });
  }

  const sessCompletion = {};
  for (const [sess, [start, end]] of Object.entries(sessions)) {
    let total = 0, finalizado = 0;
    for (let r = dataStart; r < rows.length; r++) {
      const row = rows[r];
      if (!String(row[0] || '').trim()) continue;
      for (let c = start; c <= end; c++) {
        const v = String(row[c] || '').toLowerCase();
        if (v.includes('finalizado')) {
          total++;
          if (!v.includes('no')) finalizado++;
        } else if (v) total++;
      }
    }
    sessCompletion[sess] = total > 0 ? Math.round((finalizado / total) * 1000) / 10 : 0;
  }

  let tituloReporte = DEFAULT_DATA.title; 
  if (rows[0] && rows[0][0]) {
    const celdaA1 = String(rows[0][0]).trim().replace(/\*/g, '');
    if (celdaA1.length > 5) tituloReporte = celdaA1;
  }

  let subtituloReporte = 'Cargado: ' + new Date().toLocaleDateString('es-PE');
  if (rows[2] && rows[2][0]) { 
    const celdaA3 = String(rows[2][0]).trim().replace(/\*/g, '');
    if (celdaA3.toLowerCase().includes('fecha') || celdaA3.toLowerCase().includes('corte')) {
      subtituloReporte = celdaA3.replace(/de ([a-z])/g, (match, p1) => 'de ' + p1.toUpperCase());
    }
  }

  const data = {
    title: tituloReporte,
    subtitle: subtituloReporte,
    sessions: Object.keys(sessCompletion).length > 0 ? sessCompletion : DEFAULT_DATA.sessions,
    participants: participants.length > 0 ? participants : DEFAULT_DATA.participants
  };
  loadData(data);
}

function parseCSV(text, filename) {
  const rows = text.split('\n').map(r => r.split(',').map(v => v.trim().replace(/^"|"$/g, '')));
  const participants = [];
  for (let r = 1; r < rows.length; r++) {
    const row = rows[r];
    if (!row[0]) continue;
    participants.push({
      nombre: row[0],
      fin: parseFloat(row[1]) || 0,
      no: parseFloat(row[2]) || 0,
      tot: parseFloat(row[3]) || 64,
      pct: Math.round((parseFloat(row[4]) || 0) * (parseFloat(row[4]) <= 1 ? 100 : 1) * 10) / 10,
      pendientes: []
    });
  }
  let tituloReporte = (rows[0] && rows[0][0] && rows[0][0].length > 5) ? rows[0][0].replace(/\*/g, '') : filename.replace(/\.[^.]+$/, '');
  loadData({ title: tituloReporte, subtitle: 'Procesado desde CSV', sessions: DEFAULT_DATA.sessions, participants });
}

// ─── Load & render ────────────────────────────────────────────
function loadData(data) {
  allParticipants = data.participants;
  sessionData = data.sessions;
  document.getElementById('report-title').textContent = data.title;
  document.getElementById('report-subtitle').textContent = data.subtitle;
  const dash = document.getElementById('dashboard');
  dash.classList.remove('hidden');
  dash.classList.add('fade-in');

  renderKPIs();
  renderCharts();
  renderTable();
  renderRanking('top');
  renderMyPosition();
}

function renderKPIs() {
  const total = allParticipants.length;
  const completed = allParticipants.filter(p => p.pct >= 100).length;
  const zeroes = allParticipants.filter(p => p.pct === 0).length;
  const avg = allParticipants.reduce((s, p) => s + p.pct, 0) / (total || 1);

  animateNum('kpi-total', total, '');
  animateNum('kpi-completed', completed, '');
  document.getElementById('kpi-completed-pct').textContent = `${Math.round(completed / total * 100)}% con 100%`;
  animateNum('kpi-avg', Math.round(avg), '%');
  animateNum('kpi-zero', zeroes, '');

  setBar('kpi-bar-completed', completed / total * 100);
  setBar('kpi-bar-avg', avg);
  setBar('kpi-bar-zero', zeroes / total * 100);
}

function animateNum(id, target, suffix) {
  const el = document.getElementById(id);
  let start = 0;
  const step = () => {
    start += Math.ceil((target - start) / 6);
    el.textContent = start + suffix;
    if (start < target) requestAnimationFrame(step);
    else el.textContent = target + suffix;
  };
  requestAnimationFrame(step);
}

function setBar(id, pct) {
  document.getElementById(id).style.width = Math.min(100, pct) + '%';
}

// ─── Charts ───────────────────────────────────────────────────
const CHART_DEFAULTS = {
  plugins: { legend: { display: false }, tooltip: {
    backgroundColor: 'rgba(15,22,41,0.95)', borderColor: 'rgba(45,212,191,0.3)', borderWidth: 1,
    titleColor: '#e2e8f0', bodyColor: '#94a3b8', padding: 10, cornerRadius: 8
  }},
  scales: {
    x: { grid: { color: 'rgba(255,255,255,0.04)', drawBorder: false }, ticks: { color: '#64748b', font: { size: 11 } } },
    y: { grid: { color: 'rgba(255,255,255,0.04)', drawBorder: false }, ticks: { color: '#64748b', font: { size: 11 } }, border: { display: false } }
  }
};

function destroyChart(id) { if (charts[id]) { charts[id].destroy(); delete charts[id]; } }

function renderCharts() {
  renderSessionChart();
  renderDonutChart();
  renderHistChart();
}

function renderSessionChart() {
  destroyChart('sessions');
  const labels = Object.keys(sessionData);
  const vals = Object.values(sessionData);
  const ctx = document.getElementById('chart-sessions').getContext('2d');
  const grad = ctx.createLinearGradient(0, 0, 0, 250);
  grad.addColorStop(0, 'rgba(20,184,166,0.35)');
  grad.addColorStop(1, 'rgba(20,184,166,0)');
  charts['sessions'] = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        data: vals, fill: true, backgroundColor: grad,
        borderColor: '#14b8a6', borderWidth: 2.5,
        pointBackgroundColor: '#14b8a6', pointBorderColor: '#0a0f1e',
        pointBorderWidth: 2, pointRadius: 5, tension: 0.4
      }]
    },
    options: {
      ...CHART_DEFAULTS, responsive: true, maintainAspectRatio: false,
      plugins: { ...CHART_DEFAULTS.plugins,
        tooltip: { ...CHART_DEFAULTS.plugins.tooltip,
          callbacks: { label: ctx => ` ${ctx.raw}% completado` }
        }
      },
      scales: { ...CHART_DEFAULTS.scales,
        y: { ...CHART_DEFAULTS.scales.y, min: 0, max: 100, ticks: { ...CHART_DEFAULTS.scales.y.ticks, callback: v => v + '%' } }
      }
    }
  });
}

function renderDonutChart() {
  destroyChart('donut');
  const buckets = getBuckets(allParticipants);
  const labels = Object.keys(buckets);
  const vals = Object.values(buckets);
  const colors = ['#f87171','#fb923c','#fbbf24','#34d399','#818cf8','#2dd4bf'];
  const ctx = document.getElementById('chart-donut').getContext('2d');
  charts['donut'] = new Chart(ctx, {
    type: 'doughnut',
    data: { labels, datasets: [{ data: vals, backgroundColor: colors, borderColor: '#0a0f1e', borderWidth: 3, hoverOffset: 8 }] },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '70%',
      plugins: { legend: { display: false }, tooltip: { ...CHART_DEFAULTS.plugins.tooltip,
        callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw} participantes` }
      }}
    }
  });
  const leg = document.getElementById('donut-legend');
  leg.innerHTML = labels.map((l, i) => `
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <div style="width:8px;height:8px;border-radius:2px;background:${colors[i]};flex-shrink:0;"></div>
        <span class="text-xs text-slate-400">${l}</span>
      </div>
      <span class="text-xs font-syne font-600 text-slate-300">${vals[i]}</span>
    </div>
  `).join('');
}

function renderHistChart() {
  destroyChart('hist');
  const buckets = [
    { label:'0%', min:0, max:0 },
    { label:'1–25%', min:1, max:25 },
    { label:'26–50%', min:26, max:50 },
    { label:'51–75%', min:51, max:75 },
    { label:'76–99%', min:76, max:99 },
    { label:'100%', min:100, max:100 }
  ];
  const counts = buckets.map(b => allParticipants.filter(p => p.pct >= b.min && p.pct <= b.max).length);
  const colors = ['#f87171','#fb923c','#fbbf24','#4ade80','#34d399','#2dd4bf'];
  const ctx = document.getElementById('chart-hist').getContext('2d');
  charts['hist'] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: buckets.map(b => b.label),
      datasets: [{ data: counts, backgroundColor: colors, borderRadius: 8, borderSkipped: false }]
    },
    options: {
      ...CHART_DEFAULTS, responsive: true, maintainAspectRatio: false,
      plugins: { ...CHART_DEFAULTS.plugins, tooltip: { ...CHART_DEFAULTS.plugins.tooltip,
        callbacks: { label: ctx => ` ${ctx.raw} participantes` }
      }},
      scales: { ...CHART_DEFAULTS.scales,
        y: { ...CHART_DEFAULTS.scales.y, ticks: { ...CHART_DEFAULTS.scales.y.ticks, stepSize: 1 } }
      }
    }
  });
}

function getBuckets(list) {
  return {
    '0%': list.filter(p => p.pct === 0).length,
    '1–25%': list.filter(p => p.pct > 0 && p.pct <= 25).length,
    '26–50%': list.filter(p => p.pct > 25 && p.pct <= 50).length,
    '51–75%': list.filter(p => p.pct > 50 && p.pct <= 75).length,
    '76–99%': list.filter(p => p.pct > 75 && p.pct < 100).length,
    '100%': list.filter(p => p.pct >= 100).length
  };
}


function renderMyPosition() {
  const me = allParticipants.find(p => p.isme);
  const posEl = document.getElementById('my-position');
  const progEl = document.getElementById('my-progress');

  if (!me) {
    if (posEl) posEl.textContent = 'No disponible';
    if (progEl) progEl.textContent = 'No se pudo identificar tu matrícula en este curso';
    return;
  }

  const better = allParticipants.filter(p => Number(p.pct) > Number(me.pct)).length;
  const pos = better + 1;

  if (posEl) posEl.textContent = `${pos} de ${allParticipants.length}`;
  if (progEl) progEl.textContent = `${me.pct}% de avance · ${me.fin}/${me.tot} actividades finalizadas`;
}


function escHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, ch => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[ch]));
}

function myPendingItems() {
  const me = allParticipants.find(p => p.isme);
  return me && Array.isArray(me.pendientes) ? me.pendientes : [];
}

function pendingField(item, key) {
  const aliases = {
    modulo: ['modulo','module'],
    unidad: ['unidad','unit'],
    sesion: ['sesion','session'],
    actividad: ['actividad','activity']
  };
  for (const k of aliases[key]) {
    if (item && item[k] !== undefined && item[k] !== null) return String(item[k]);
  }
  return '';
}

function fillPendingFilters() {
  const items=myPendingItems();
  const defs=[
    ['pending-module-filter','modulo','Todos los módulos'],
    ['pending-unit-filter','unidad','Todas las unidades'],
    ['pending-session-filter','sesion','Todas las sesiones']
  ];
  defs.forEach(([id,key,label])=>{
    const el=document.getElementById(id);
    if(!el) return;
    const current=el.value;
    const vals=[...new Set(items.map(x=>pendingField(x,key)).filter(Boolean))]
      .sort((a,b)=>a.localeCompare(b,'es',{numeric:true}));
    el.innerHTML=`<option value="">${label}</option>`+
      vals.map(v=>`<option value="${escHtml(v)}">${escHtml(v)}</option>`).join('');
    if(vals.includes(current)) el.value=current;
  });
}

function renderMyPending() {
  const me=allParticipants.find(p=>p.isme);
  const body=document.getElementById('my-pending-body');
  const summary=document.getElementById('my-pending-summary');
  if(!body || !summary) return;

  if(!me){
    summary.textContent='No se pudo identificar tu usuario.';
    body.innerHTML='';
    return;
  }

  const mf=document.getElementById('pending-module-filter')?.value || '';
  const uf=document.getElementById('pending-unit-filter')?.value || '';
  const sf=document.getElementById('pending-session-filter')?.value || '';

  const all=myPendingItems();
  const items=all.filter(x =>
    (!mf || pendingField(x,'modulo')===mf) &&
    (!uf || pendingField(x,'unidad')===uf) &&
    (!sf || pendingField(x,'sesion')===sf)
  );

  summary.textContent=`${me.no} pendientes de ${me.tot} · ${me.fin} finalizadas · ${me.pct}% de avance`+
    (items.length!==all.length ? ` · mostrando ${items.length}` : '');

  body.innerHTML=items.length ? items.map(x=>`
    <tr class="border-t border-slate-800/80 hover:bg-slate-800/30">
      <td class="px-4 py-3 text-xs text-slate-300">${escHtml(pendingField(x,'modulo'))}</td>
      <td class="px-4 py-3 text-xs text-slate-300">${escHtml(pendingField(x,'unidad'))}</td>
      <td class="px-4 py-3 text-xs text-teal-300 font-semibold">${escHtml(pendingField(x,'sesion'))}</td>
      <td class="px-4 py-3 text-xs text-slate-200">${escHtml(pendingField(x,'actividad'))}</td>
    </tr>`).join('') :
    `<tr><td colspan="4" class="px-4 py-8 text-center text-sm text-slate-400">No hay actividades pendientes con estos filtros.</td></tr>`;
}

function openMyPending() {
  fillPendingFilters();
  renderMyPending();
  const m=document.getElementById('my-pending-modal');
  if(m){m.classList.remove('hidden');m.classList.add('flex');}
}
function closeMyPending() {
  const m=document.getElementById('my-pending-modal');
  if(m){m.classList.add('hidden');m.classList.remove('flex');}
}

function setupMyPendingControls() {
  document.getElementById('btn-my-pending')?.addEventListener('click',openMyPending);
  document.getElementById('close-my-pending')?.addEventListener('click',closeMyPending);
  ['pending-module-filter','pending-unit-filter','pending-session-filter'].forEach(id=>{
    document.getElementById(id)?.addEventListener('change',renderMyPending);
  });
  document.getElementById('my-pending-modal')?.addEventListener('click',e=>{
    if(e.target.id==='my-pending-modal') closeMyPending();
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape') closeMyPending();});
}

// ─── Ranking ──────────────────────────────────────────────────
function showRanking(mode) {
  rankingMode = mode;
  document.getElementById('btn-top').className = 'ranking-btn text-xs font-syne font-600 px-3 py-1.5 rounded-lg transition-all ' + (mode === 'top' ? 'active-tab' : '');
  document.getElementById('btn-bot').className = 'ranking-btn text-xs font-syne font-600 px-3 py-1.5 rounded-lg transition-all ' + (mode === 'bot' ? 'active-tab' : '');
  renderRanking(mode);
}

function renderRanking(mode) {
  const sorted = [...allParticipants].sort((a, b) => mode === 'top' ? b.pct - a.pct : a.pct - b.pct);
  const top = sorted.slice(0, 10);
  const container = document.getElementById('ranking-list');
  container.innerHTML = top.map((p, i) => {
    const color = p.pct >= 75 ? '#34d399' : p.pct >= 50 ? '#fbbf24' : '#f87171';
    const medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i+1}.`;
    return `
      <div class="flex items-center gap-3 py-1.5">
        <span class="text-sm w-7 text-center flex-shrink-0 text-slate-400 font-syne">${medal}</span>
        <div class="flex-1 min-w-0">
          <div class="text-xs text-slate-200 truncate ${p.isme ? 'text-teal-300 font-semibold' : ''}">${p.nombre}${p.isme ? '<span class="me-chip">TÚ</span>' : ''}</div>
          <div class="prog-bar mt-1"><div class="prog-fill" style="width:${p.pct}%;background:${color};"></div></div>
        </div>
        <span class="text-xs font-syne font-700 flex-shrink-0" style="color:${color};">${p.pct}%</span>
      </div>
    `;
  }).join('');
}

// ─── Table ────────────────────────────────────────────────────
let sortDir = { name: 1, pct: -1, fin: -1 };

function sortTable(key) {
  sortDir[key] *= -1;
  currentSort = { key, dir: sortDir[key] };
  renderTable();
}

function getFilteredParticipants() {
  return allParticipants.filter(p => {
    const nameMatch = p.nombre.toLowerCase().includes(searchTerm);
    let statusMatch = true;
    if (currentFilter === '100') statusMatch = p.pct >= 100;
    else if (currentFilter === 'high') statusMatch = p.pct >= 75;
    else if (currentFilter === 'med') statusMatch = p.pct >= 50 && p.pct < 75;
    else if (currentFilter === 'low') statusMatch = p.pct < 50 && p.pct > 0;
    else if (currentFilter === 'zero') statusMatch = p.pct === 0;
    return nameMatch && statusMatch;
  });
}

function renderTable() {
  const list = getFilteredParticipants();
  list.sort((a, b) => {
    const { key, dir } = currentSort;
    if (key === 'name') return dir * a.nombre.localeCompare(b.nombre);
    if (key === 'pct') return dir * (a.pct - b.pct);
    if (key === 'fin') return dir * (a.fin - b.fin);
    return 0;
  });

  document.getElementById('table-count').textContent = `${list.length} participante${list.length !== 1 ? 's' : ''}`;
  const tbody = document.getElementById('table-body');
  tbody.innerHTML = list.map((p, i) => {
    const pct = p.pct;
    const barColor = pct >= 75 ? '#34d399' : pct >= 50 ? '#fbbf24' : '#f87171';
    const badge = pct >= 100 ? '<span class="badge badge-green">✓ Completo</span>'
      : pct >= 75 ? '<span class="badge badge-blue">En curso</span>'
      : pct > 0 ? '<span class="badge badge-yellow">Parcial</span>'
      : '<span class="badge badge-red">Sin avance</span>';
    return `
      <tr class="${p.isme ? 'row-me' : ''}">
        <td class="text-slate-500 text-xs">${i + 1}</td>
        <td class="text-slate-200 text-xs font-medium max-w-xs">
          <div class="truncate ${p.isme ? 'text-teal-300 font-semibold' : 'text-slate-200'}"
               style="max-width:240px;" title="${p.nombre}">
            ${p.nombre}${p.isme ? '<span class="me-chip">TÚ</span>' : ''}
          </div>
        </td>
        <td class="text-emerald-400 font-syne font-600">${p.fin}</td>
        <td class="text-red-400 font-syne font-600">${p.no}</td>
        <td class="text-slate-400">${p.tot}</td>
        <td style="min-width:110px;">
          <div class="flex items-center gap-2">
            <div class="prog-bar flex-1"><div class="prog-fill" style="width:${pct}%;background:${barColor};"></div></div>
            <span class="text-xs font-syne font-600" style="color:${barColor};width:38px;text-align:right;">${pct}%</span>
          </div>
        </td>
        <td>${badge}</td>
      </tr>
    `;
  }).join('');
}

// ─── Control de la Ventana Emergente (Modal) Limpio ───────────
function abrirModalPendientes(pendientesEscapados, nombreUsuario) {
  const pendientes = JSON.parse(unescape(pendientesEscapados)) || [];
  document.getElementById('modal-usuario').textContent = nombreUsuario;
  
  const contenedorLista = document.getElementById('modal-lista-sesiones');
  contenedorLista.innerHTML = '';
  
  if (pendientes.length === 0) {
    contenedorLista.innerHTML = `
      <div class="text-center py-8 text-slate-400 text-xs space-y-2">
        <span class="text-3xl">🎉</span>
        <p class="text-emerald-400 font-semibold font-syne text-sm">¡Al día!</p>
        <p>No registra ninguna actividad pendiente en este corte.</p>
      </div>`;
  } else {
    pendientes.forEach(item => {
      const card = document.createElement('div');
      card.className = 'glass-light p-3.5 space-y-2.5 border-l-2 border-red-500/60';
      card.innerHTML = `
        <div class="flex items-center justify-between gap-2">
          <span class="text-xs font-syne font-700 text-teal-400 tracking-wide uppercase">${item.modulo}</span>
          <span class="badge badge-red text-[9px] uppercase tracking-wider font-bold py-0.5 px-2">Incompleto</span>
        </div>
        <div class="space-y-1.5 text-xs">
          <div class="flex items-center gap-2">
            <span class="text-slate-500 w-4 text-center">📂</span>
            <span class="text-slate-400 w-14 flex-shrink-0">Unidad:</span>
            <span class="text-slate-200 font-medium">${item.unidad}</span>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-slate-500 w-4 text-center">📋</span>
            <span class="text-slate-400 w-14 flex-shrink-0">Sesión:</span>
            <span class="text-slate-200 font-medium">${item.sesion || '—'}</span>
          </div>
          <div class="flex items-start gap-2 pt-0.5">
            <span class="text-slate-500 w-4 text-center mt-0.5">🎯</span>
            <span class="text-slate-400 w-14 flex-shrink-0 mt-0.5">Actividad:</span>
            <span class="text-slate-200 font-medium leading-snug bg-slate-900/40 px-1.5 py-0.5 rounded border border-slate-700/30 flex-1" title="${item.actividad}">
              ${item.actividad}
            </span>
          </div>
        </div>
      `;
      contenedorLista.appendChild(card);
    });
  }
  
  document.getElementById('modal-faltantes').classList.remove('hidden');
}

function cerrarModal() {
  document.getElementById('modal-faltantes').classList.add('hidden');
}

window.onclick = function(event) {
  const modal = document.getElementById('modal-faltantes');
  if (event.target === modal) {
    cerrarModal();
  }
}
</script>

<div id="my-pending-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background:rgba(2,6,23,.82);">
  <div class="glass w-full max-w-6xl max-h-[88vh] flex flex-col overflow-hidden">
    <div class="p-5 border-b border-slate-700/60 flex items-start justify-between gap-4">
      <div>
        <div class="font-syne font-800 text-lg text-white">Mis actividades pendientes</div>
        <div id="my-pending-summary" class="text-xs text-slate-400 mt-1"></div>
      </div>
      <button id="close-my-pending" type="button" class="text-slate-400 hover:text-white text-2xl leading-none">×</button>
    </div>

    <div class="p-4 border-b border-slate-700/60 grid grid-cols-1 md:grid-cols-3 gap-3">
      <select id="pending-module-filter" class="filter-select w-full"><option value="">Todos los módulos</option></select>
      <select id="pending-unit-filter" class="filter-select w-full"><option value="">Todas las unidades</option></select>
      <select id="pending-session-filter" class="filter-select w-full"><option value="">Todas las sesiones</option></select>
    </div>

    <div class="overflow-auto flex-1">
      <table class="w-full text-left">
        <thead class="sticky top-0 bg-slate-900 z-10">
          <tr>
            <th class="px-4 py-3 text-xs text-slate-400">Módulo</th>
            <th class="px-4 py-3 text-xs text-slate-400">Unidad</th>
            <th class="px-4 py-3 text-xs text-slate-400">Sesión</th>
            <th class="px-4 py-3 text-xs text-slate-400">Actividad pendiente</th>
          </tr>
        </thead>
        <tbody id="my-pending-body"></tbody>
      </table>
    </div>
  </div>
</div>

</body>
</html>