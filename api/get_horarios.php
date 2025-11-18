<?php
/**
 * API: Obtener horarios disponibles
 */

require_once '../config/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$medico_id = $_GET['medico_id'] ?? '';
$fecha = $_GET['fecha'] ?? '';

if (empty($medico_id) || empty($fecha)) {
    echo json_encode(['success' => false, 'message' => 'Parámetros incompletos']);
    exit;
}

try {
    $db = getDB();
    
// Obtener día de la semana en español
    $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $dia_semana = $dias[date('w', strtotime($fecha))];
    
    // Verificar si hay bloqueo para esa fecha
    $bloqueado = $db->fetchOne("
        SELECT id FROM bloqueos_agenda
        WHERE medico_id = :medico_id
        AND :fecha BETWEEN fecha_inicio AND fecha_fin
    ", [
        'medico_id' => $medico_id,
        'fecha' => $fecha
    ]);
    
    if ($bloqueado) {
        echo json_encode([
            'success' => false,
            'message' => 'El médico no tiene disponibilidad en esta fecha'
        ]);
        exit;
    }
    
    // Obtener agenda del médico para ese día
    $agenda = $db->fetchAll("
        SELECT hora_inicio, hora_fin, cupos_por_hora
        FROM agenda_medicos
        WHERE medico_id = :medico_id
        AND dia_semana = :dia_semana
        AND estado = 'activo'
    ", [
        'medico_id' => $medico_id,
        'dia_semana' => $dia_semana
    ]);
    
    if (empty($agenda)) {
        echo json_encode([
            'success' => false,
            'message' => 'El médico no atiende este día'
        ]);
        exit;
    }
    
    // Generar horarios disponibles
    $horarios_disponibles = [];
    
    foreach ($agenda as $bloque) {
        $hora_inicio = strtotime($bloque['hora_inicio']);
        $hora_fin = strtotime($bloque['hora_fin']);
        $duracion_consulta = 30 * 60; // 30 minutos en segundos
        
        for ($hora = $hora_inicio; $hora < $hora_fin; $hora += $duracion_consulta) {
            $hora_str = date('H:i:s', $hora);
            
            // Contar citas ya agendadas en ese horario
            $citas_agendadas = $db->fetchOne("
                SELECT COUNT(*) as total
                FROM citas
                WHERE medico_id = :medico_id
                AND fecha = :fecha
                AND hora = :hora
                AND estado_cita_id NOT IN (6, 7)
            ", [
                'medico_id' => $medico_id,
                'fecha' => $fecha,
                'hora' => $hora_str
            ])['total'];
            
            // Si hay cupos disponibles, agregar el horario
            if ($citas_agendadas < $bloque['cupos_por_hora']) {
                $horarios_disponibles[] = date('H:i', $hora);
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'horarios' => $horarios_disponibles
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Error en get_horarios.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener horarios'
    ]);
}
?>