# Moodle-Reporte-Limpio

Plugin para **Moodle** orientado a la generación, procesamiento y visualización de reportes de finalización de actividades. Integra exportación a Excel, detección jerárquica de contenidos y dashboards de seguimiento para administradores y participantes.

**Desarrollador:** Jerry Anderson Carril Chávez  
**Cargo:** Técnico en Administración e Informática  
**Área:** AGP – UGEL Ascope  
**Versión estable:** 0.6.8  
**Año:** 2026  
**Licencia:** MIT

## Origen del proyecto

El proyecto nació ante la necesidad de mejorar el seguimiento de los participantes de programas de formación gestionados mediante Moodle. Los reportes de finalización exportados por la plataforma contenían la información necesaria, pero requerían limpieza, reorganización y cálculos adicionales antes de poder utilizarlos para el seguimiento.

La solución evolucionó en tres etapas principales:

### 1. Automatización con Python

La primera solución consistió en una aplicación en Python para procesar automáticamente los reportes exportados desde Moodle. Permitió limpiar y reorganizar la información, consolidar actividades, calcular estados de finalización y generar indicadores como **FIN, NO, TOT, FIN% y NO%**.

Posteriormente se incorporó la identificación de la estructura pedagógica:

`Módulo → Unidad → Sesión → Actividad`

Esto permitió transformar el reporte original en información más útil para el seguimiento académico.

### 2. Dashboard con JavaScript

La segunda etapa incorporó un dashboard interactivo desarrollado con tecnologías web para visualizar los resultados de forma más rápida. Se añadieron indicadores, gráficos, búsqueda de participantes, filtros por porcentaje de avance, ranking y visualización de participantes con mayor o menor progreso.

### 3. Integración como plugin PHP para Moodle

Finalmente, la solución se integró directamente en Moodle mediante un plugin local desarrollado principalmente en PHP, complementado con JavaScript, HTML y CSS. De esta manera, el procesamiento y la visualización dejaron de depender exclusivamente de herramientas externas.

El plugin permite generar el reporte Excel, consultar el dashboard administrativo y proporcionar a cada participante un dashboard personal según sus cursos y matrícula activa.

## Funcionalidades principales

- Generación de reportes de finalización en Excel.
- Detección jerárquica de módulos, unidades y sesiones.
- Consolidación de actividades duplicadas según la lógica del reporte.
- Estados **Finalizado** y **No finalizado** con diferenciación visual.
- Cálculos automáticos de FIN, NO, TOT, FIN% y NO%.
- Gráfico global de finalización.
- Dashboard administrativo integrado.
- Búsqueda y filtros por nivel de avance.
- Ranking de participantes.
- Dashboard personal del participante.
- Visualización de posición dentro del curso.
- Consulta de actividades pendientes propias.
- Filtros de pendientes por módulo, unidad y sesión.
- Protección mediante autenticación y matrícula activa.

## Tecnologías utilizadas

- PHP
- JavaScript
- HTML / CSS
- Moodle API
- PHPSpreadsheet / herramientas de exportación de Moodle
- Chart.js
- Tailwind CSS (interfaz del dashboard)
- Python y openpyxl en la etapa inicial de automatización
- Microsoft Excel como formato de salida del reporte

## Flujo de la solución

`Moodle → procesamiento y consolidación → reporte Excel → dashboard administrativo / dashboard del participante`

La evolución histórica del proyecto fue:

`Reporte Moodle → Python → Dashboard JavaScript → Plugin PHP integrado en Moodle`

## Dashboard del participante

El participante autenticado puede seleccionar uno de los cursos en los que mantiene una matrícula activa y consultar:

- porcentaje de avance;
- actividades finalizadas y pendientes;
- posición dentro del curso;
- ranking general;
- actividades pendientes organizadas por módulo, unidad y sesión.

Los detalles de las actividades pendientes de otros participantes no se muestran.

## Instalación

1. Copiar la carpeta del plugin como `reportelimpio` dentro de `local/` en la instalación de Moodle.
2. Ingresar a Moodle como administrador.
3. Acceder a **Administración del sitio → Notificaciones**.
4. Completar la instalación/actualización del plugin.
5. Configurar los permisos necesarios según los roles utilizados en la plataforma.

> Se recomienda probar primero el plugin en un entorno de pruebas antes de instalarlo o actualizarlo en producción.

## Evolución reciente

### V0.6.4
Corrige la búsqueda de participantes y los filtros de porcentaje de avance en los dashboards.

### V0.6.5
Corrige el cálculo y visualización de la posición del usuario autenticado dentro del curso.

### V0.6.6
Añade la consulta de actividades pendientes propias, organizadas por módulo, unidad y sesión.

### V0.6.7
Mejora el contraste y legibilidad de los filtros del modal de actividades pendientes.

### V0.6.8
Corrige el acceso de usuarios con rol Estudiante a **Mi Dashboard**, manteniendo la protección mediante inicio de sesión y matrícula activa.

## Privacidad y seguridad

El repositorio no debe incluir bases de datos, credenciales, contraseñas, tokens, archivos de configuración privados ni información personal de los participantes. Los datos mostrados por el plugin se obtienen desde la instalación Moodle donde se ejecuta.

## Autor

**Jerry Anderson Carril Chávez**  
Técnico en Administración e Informática  
AGP – UGEL Ascope  
2026

## Licencia

Este proyecto se distribuye bajo la **Licencia MIT**. Consulta el archivo `LICENSE` para más información.
